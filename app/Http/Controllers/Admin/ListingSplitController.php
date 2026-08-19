<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Xs2\SplitListingPreviewRequest;
use App\Http\Requests\Xs2\SplitListingPublishRequest;
use App\Jobs\DeleteSplitListings;
use App\Jobs\PublishSplitListings;
use App\Jobs\SyncSplitListings;
use App\Models\ListingSplit;
use App\Models\Xs2Ticket;
use App\Services\SellerApi\SellerApiDebugRecorder;
use App\Services\SplitListings\SplitListingService;
use App\Services\Xs2\Xs2TicketMappingStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ListingSplitController extends Controller
{
    public function show(Xs2Ticket $ticket, SplitListingService $splits): JsonResponse
    {
        return response()->json([
            'data' => $splits->status($ticket),
        ]);
    }

    public function preview(
        SplitListingPreviewRequest $request,
        Xs2Ticket $ticket,
        SplitListingService $splits,
    ): JsonResponse {
        $preview = $splits->preview($ticket, $request->validated());

        return response()->json(['data' => $preview]);
    }

    public function publish(
        SplitListingPublishRequest $request,
        Xs2Ticket $ticket,
        SplitListingService $splits,
    ): JsonResponse {
        $this->assertReadyToPublish($ticket);
        $config = collect($request->validated())->except('sync')->all();
        $validation = $splits->validateConfiguration($ticket, $config + ['stock' => $ticket->stock]);
        if (! $validation['valid']) {
            throw ValidationException::withMessages(['split' => $validation['errors']]);
        }

        if ($request->boolean('sync')) {
            return $this->runSplitActionSynchronously(
                $ticket,
                fn () => $splits->publishListings($ticket, $config),
                'Split listings published successfully.',
                'Split listing publish failed.',
                fn (Xs2Ticket $ticket, SplitListingService $splits, ?array $sellerApiDebug, mixed $result = null) => $splits->status($ticket) + [
                    'queued' => false,
                    'result' => $result,
                    'seller_api_debug' => $sellerApiDebug,
                ],
            );
        }

        $ticket->update([
            'split_enabled' => true,
            'split_quantity' => (int) $config['split_quantity'],
            'price_increment_type' => (string) $config['price_increment_type'],
            'price_increment_value' => (float) $config['price_increment_value'],
            'split_sync_status' => 'publishing',
            'split_sync_error' => null,
            'sync_status' => 'pending',
        ]);

        PublishSplitListings::dispatch($ticket->id, $config);

        return response()->json([
            'message' => 'Split listing publish queued successfully.',
            'data' => $splits->status($ticket->fresh()) + ['queued' => true],
        ], 202);
    }

    public function sync(Request $request, Xs2Ticket $ticket, SplitListingService $splits): JsonResponse
    {
        if (! $ticket->split_enabled) {
            throw ValidationException::withMessages([
                'ticket' => ['Split listings are not enabled for this ticket.'],
            ]);
        }

        if ($request->boolean('sync')) {
            return $this->runSplitActionSynchronously(
                $ticket,
                fn () => $splits->syncListings($ticket),
                'Split listings synced successfully.',
                'Split listing sync failed.',
                fn (Xs2Ticket $ticket, SplitListingService $splits, ?array $sellerApiDebug, mixed $result = null) => $splits->status($ticket) + [
                    'queued' => false,
                    'result' => $result,
                    'seller_api_debug' => $sellerApiDebug,
                ],
            );
        }

        $ticket->update(['split_sync_status' => 'syncing', 'sync_status' => 'pending']);
        SyncSplitListings::dispatch($ticket->id);

        return response()->json([
            'message' => 'Split listing sync queued successfully.',
            'data' => $splits->status($ticket->fresh()) + ['queued' => true],
        ], 202);
    }

    public function destroy(Request $request, Xs2Ticket $ticket, SplitListingService $splits): JsonResponse
    {
        if ($request->boolean('sync')) {
            return $this->runSplitActionSynchronously(
                $ticket,
                fn () => $splits->deleteAllListings($ticket),
                'Split listings deleted successfully.',
                'Split listing deletion failed.',
                fn (Xs2Ticket $ticket, SplitListingService $splits, ?array $sellerApiDebug, mixed $result = null) => $splits->status($ticket) + [
                    'queued' => false,
                    'result' => $result,
                    'seller_api_debug' => $sellerApiDebug,
                ],
            );
        }

        DeleteSplitListings::dispatch($ticket->id);

        return response()->json([
            'message' => 'Split listing deletion queued successfully.',
            'data' => ['ticket_id' => $ticket->id, 'queued' => true],
        ], 202);
    }

    public function destroyOne(
        Request $request,
        Xs2Ticket $ticket,
        ListingSplit $split,
        SplitListingService $splits,
    ): JsonResponse {
        if ($request->boolean('sync')) {
            return $this->runSplitActionSynchronously(
                $ticket,
                fn () => $splits->deleteOneSplitListingCascade($ticket, $split),
                'All split listings deleted successfully.',
                'Split listing deletion failed.',
                fn (Xs2Ticket $ticket, SplitListingService $splits, ?array $sellerApiDebug, mixed $result = null) => $splits->status($ticket) + [
                    'queued' => false,
                    'result' => $result,
                    'seller_api_debug' => $sellerApiDebug,
                    'trigger_split_id' => $split->id,
                ],
            );
        }

        DeleteSplitListings::dispatch($ticket->id, $split->id);

        return response()->json([
            'message' => 'Split listing cascade deletion queued successfully.',
            'data' => [
                'ticket_id' => $ticket->id,
                'trigger_split_id' => $split->id,
                'queued' => true,
            ],
        ], 202);
    }

    private function assertReadyToPublish(Xs2Ticket $ticket): void
    {
        $mappingStates = app(Xs2TicketMappingStatusService::class);
        $status = $mappingStates->manualPublishStatus($ticket);
        if (! $mappingStates->isManualPublishable($status)) {
            throw ValidationException::withMessages([
                'ticket' => ['The ticket is not ready to publish. Confirm the event and venue mappings first.'],
            ]);
        }
    }

    /**
     * @param  callable(): mixed  $run
     * @param  callable(Xs2Ticket, SplitListingService, ?array, mixed): array<string, mixed>  $payload
     */
    private function runSplitActionSynchronously(
        Xs2Ticket $ticket,
        callable $run,
        string $successMessage,
        string $failureMessage,
        callable $payload,
    ): JsonResponse {
        $recorder = app(SellerApiDebugRecorder::class);
        $splits = app(SplitListingService::class);
        $recorder->enable();

        try {
            try {
                $result = $run();
            } catch (ValidationException $e) {
                throw $e;
            } catch (\Throwable $e) {
                $ticket->refresh();

                return response()->json([
                    'message' => $failureMessage,
                    'data' => $payload($ticket, $splits, $recorder->flush(), null) + [
                        'last_error' => $e->getMessage(),
                    ],
                ], 422);
            }

            $ticket->refresh();

            return response()->json([
                'message' => $successMessage,
                'data' => $payload($ticket->fresh(), $splits, $recorder->flush(), $result),
            ]);
        } finally {
            $recorder->disable();
        }
    }
}
