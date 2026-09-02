<?php

use App\Http\Controllers\Admin\AdminWebhookController;
use App\Http\Controllers\Admin\AdminApiConfigController;
use App\Http\Controllers\Admin\AdminAuthController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\AdminListingPublishRulesController;
use App\Http\Controllers\Admin\AdminCronConfigController;
use App\Http\Controllers\Admin\AdminCronJobController;
use App\Http\Controllers\Admin\AdminPipelineController;
use App\Http\Controllers\Admin\AdminQueueController;
use App\Http\Controllers\Admin\AdminEventCatalogController;
use App\Http\Controllers\Admin\AdminEventSearchController;
use App\Http\Controllers\Admin\ListingSplitController;
use App\Http\Controllers\Admin\SbOrderController;
use App\Http\Controllers\Admin\SellerApiEventController;
use App\Http\Controllers\Admin\SellerApiVenueSyncController;
use App\Http\Controllers\Admin\Xs2OrderController;
use App\Http\Controllers\Admin\Xs2CatalogController;
use App\Http\Controllers\Admin\Xs2CategoryMappingController;
use App\Http\Controllers\Admin\Xs2EventMappingController;
use App\Http\Controllers\Admin\Xs2InventoryController;
use App\Http\Controllers\Admin\Xs2MappingOptionController;
use App\Http\Controllers\Admin\Xs2StadiumMappingController;
use App\Http\Controllers\Admin\Xs2SyncController;
use App\Http\Controllers\Admin\Xs2SandboxTestController;
use App\Http\Controllers\Admin\AdminXs2ResetController;
use App\Http\Controllers\CheckoutValidationController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\SbOrderWebhookController;
use App\Http\Controllers\VenueController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'message' => 'Seatsbroker Provider API is running.',
        'data' => new stdClass,
    ]);
});

Route::prefix('auth')->group(function (): void {
    Route::post('login', [AdminAuthController::class, 'login'])->middleware('throttle:5,1');
    Route::get('me', [AdminAuthController::class, 'me'])->middleware('auth:sanctum');
    Route::post('logout', [AdminAuthController::class, 'logout'])->middleware('auth:sanctum');
});

/*
 * Public local-event contract. Route binding resolves the legacy local event
 * key (`match_info.m_id`), never an XS2 database record ID.
 */
Route::get('events', [EventController::class, 'index']);
Route::get('events/filter-options', [EventController::class, 'filterOptions']);
Route::get('events/{event}', [EventController::class, 'show']);
Route::get('venues', [VenueController::class, 'index']);
Route::get('venues/filter-options', [VenueController::class, 'filterOptions']);
Route::get('venues/{venue}/events', [VenueController::class, 'events'])->whereNumber('venue');
Route::get('venues/{venue}/categories', [VenueController::class, 'categories'])->whereNumber('venue');
Route::get('venues/{venue}/sections', [VenueController::class, 'sections'])->whereNumber('venue');
Route::post('checkout/validate', [CheckoutValidationController::class, 'validate']);

Route::post('webhooks/sb/orders', [SbOrderWebhookController::class, 'store'])
    ->middleware('throttle:120,1');

Route::middleware(['auth:sanctum', 'admin'])->prefix('admin')->group(function (): void {
    Route::get('events/categories', [AdminEventCatalogController::class, 'categories']);
    Route::get('events/tournaments', [AdminEventCatalogController::class, 'tournaments']);
    Route::get('events/teams', [AdminEventCatalogController::class, 'teams']);
    Route::get('events/cities', [AdminEventCatalogController::class, 'cities']);
    Route::get('events/venues', [AdminEventCatalogController::class, 'venues']);
    Route::get('events/search', [AdminEventSearchController::class, 'index']);
    Route::get('users', [AdminUserController::class, 'index']);
    Route::post('users', [AdminUserController::class, 'store']);
    Route::patch('users/{user}', [AdminUserController::class, 'update'])->whereNumber('user');
    Route::put('users/{user}', [AdminUserController::class, 'update'])->whereNumber('user');
    Route::put('users/{user}/password', [AdminUserController::class, 'changePassword'])->whereNumber('user');
    Route::delete('users/{user}', [AdminUserController::class, 'destroy'])->whereNumber('user');

    Route::get('api-config', [AdminApiConfigController::class, 'index']);
    Route::get('api-config/environment', [AdminApiConfigController::class, 'showEnvironment']);
    Route::patch('api-config/environment', [AdminApiConfigController::class, 'updateEnvironment']);
    Route::patch('api-config/seller-api', [AdminApiConfigController::class, 'updateSellerApi']);
    Route::patch('api-config/seller-api/catalog', [AdminApiConfigController::class, 'updateSellerCatalogApi']);
    Route::patch('api-config/xs2', [AdminApiConfigController::class, 'updateXs2']);
    Route::patch('api-config/xs2/sandbox', [AdminApiConfigController::class, 'updateXs2Sandbox']);
    Route::get('cron-config', [AdminCronConfigController::class, 'index']);
    Route::get('cron-config/logs', [AdminCronConfigController::class, 'logs']);
    Route::post('cron-config/stop-all', [AdminCronConfigController::class, 'stopAll']);
    Route::post('cron-config/start-all', [AdminCronConfigController::class, 'startAll']);
    Route::post('cron-config/set-start-all', [AdminCronConfigController::class, 'setStartAll']);
    Route::post('cron-config/toggle-cron', [AdminCronConfigController::class, 'toggleCron']);
    Route::post('cron-config/toggle-sb-order-sync', [AdminCronConfigController::class, 'toggleSbOrderSync']);
    Route::get('queue/cron-jobs', [AdminCronJobController::class, 'index']);
    Route::get('queue/cron-jobs/{cronJobId}/logs', [AdminCronJobController::class, 'logs']);
    Route::get('queue/cron-execution-logs/{logId}', [AdminCronJobController::class, 'executionLog'])->whereNumber('logId');
    Route::post('queue/cron-jobs/{cronJobId}/run', [AdminCronJobController::class, 'run']);
    Route::patch('queue/cron-jobs/{cronJobId}/interval', [AdminCronJobController::class, 'updateInterval']);
    Route::get('webhooks/settings', [AdminWebhookController::class, 'showSettings']);
    Route::patch('webhooks/settings', [AdminWebhookController::class, 'updateSettings']);
    Route::get('webhooks/logs', [AdminWebhookController::class, 'logs']);
    Route::get('settings/listing-publish-rules', [AdminListingPublishRulesController::class, 'show']);
    Route::patch('settings/listing-publish-rules', [AdminListingPublishRulesController::class, 'update']);
    Route::post('settings/listing-publish-rules/preview', [AdminListingPublishRulesController::class, 'preview']);
    Route::post('xs2/cron/sync-inventory-by-league', [AdminCronConfigController::class, 'syncInventoryByLeague']);
    Route::get('queue/live-stats', [AdminQueueController::class, 'liveStats']);
    Route::get('pipeline/workload', [AdminPipelineController::class, 'workload']);
    Route::get('pipeline/runs', [AdminPipelineController::class, 'runs']);
    Route::get('pipeline/runs/{correlationId}', [AdminPipelineController::class, 'showRun']);
    Route::get('pipeline/events/{xs2EventId}/status', [AdminPipelineController::class, 'eventStatus'])->whereNumber('xs2EventId');
    Route::get('queues', [AdminQueueController::class, 'index']);
    Route::get('queues/failed-jobs', [AdminQueueController::class, 'failedJobs']);
    Route::post('queues/failed-jobs/retry-all', [AdminQueueController::class, 'retryAllFailedJobs']);
    Route::post('queues/failed-jobs/{uuid}/retry', [AdminQueueController::class, 'retryFailedJob']);
    Route::delete('queues/failed-jobs/{uuid}', [AdminQueueController::class, 'deleteFailedJob']);
    Route::post('queues/clear', [AdminQueueController::class, 'clear']);
    Route::post('queues/stop', [AdminQueueController::class, 'stop']);
    Route::post('queues/promote-delayed', [AdminQueueController::class, 'promoteDelayed']);
    Route::post('queues/profile', [AdminQueueController::class, 'applyProfile']);
    Route::get('queues/supervisor-config', [AdminQueueController::class, 'supervisorConfig']);
    Route::post('seller-api/sync-venues', [SellerApiVenueSyncController::class, 'sync']);
    Route::get('seller-api/events/search', [SellerApiEventController::class, 'search']);
    Route::get('seller-api/events/search-by-tournament', [SellerApiEventController::class, 'searchByTournament']);
    Route::get('seller-api/events/import/preview', [SellerApiEventController::class, 'importPreview']);
    Route::post('seller-api/events/import', [SellerApiEventController::class, 'import']);
    Route::post('seller-api/events/bulk-import', [SellerApiEventController::class, 'bulkImport']);
    Route::get('seller-api/events/bulk-sync/preview', [SellerApiEventController::class, 'bulkSyncPreview']);
    Route::post('seller-api/events/bulk-sync', [SellerApiEventController::class, 'bulkSync']);
    Route::get('seller-api/events/bulk-sync/{syncId}', [SellerApiEventController::class, 'bulkSyncStatus']);

    Route::get('sb-orders', [SbOrderController::class, 'index']);
    Route::post('sb-orders/sync', [SbOrderController::class, 'sync']);
    Route::post('sb-orders/{sbOrder}/refresh', [SbOrderController::class, 'refresh'])->whereNumber('sbOrder');
    Route::post('sb-orders/{sbOrder}/fetch-attendees', [SbOrderController::class, 'fetchAttendees'])->whereNumber('sbOrder');
    Route::post('sb-orders/{sbOrder}/create-xs2-order', [SbOrderController::class, 'createXs2Order'])->whereNumber('sbOrder');
    Route::post('sb-orders/{sbOrder}/move-to-xs2', [SbOrderController::class, 'moveToXs2Order'])->whereNumber('sbOrder');
    Route::get('sb-orders/{sbOrder}/xs2-sync-log', [SbOrderController::class, 'xs2SyncLog'])->whereNumber('sbOrder');
    Route::get('sb-orders/{sbOrder}', [SbOrderController::class, 'show'])->whereNumber('sbOrder');

    Route::get('xs2-orders', [Xs2OrderController::class, 'index']);
    Route::post('xs2-orders/sync', [Xs2OrderController::class, 'sync']);
    Route::post('xs2-orders/{xs2Order}/push-guest-data', [Xs2OrderController::class, 'pushGuestData'])->whereNumber('xs2Order');
    Route::post('xs2-orders/{xs2Order}/get-ticket', [Xs2OrderController::class, 'getTicket'])->whereNumber('xs2Order');
    Route::get('xs2-orders/{xs2Order}', [Xs2OrderController::class, 'show'])->whereNumber('xs2Order');

    Route::prefix('xs2')->group(function (): void {
        Route::post('reset-all', [AdminXs2ResetController::class, 'resetAll']);

        Route::get('sync-status', [Xs2SyncController::class, 'status']);
        Route::post('sync-events', [Xs2SyncController::class, 'queue']);
        Route::get('catalog/events/preview', [Xs2CatalogController::class, 'previewEvents']);
        Route::get('catalog/events/search', [Xs2CatalogController::class, 'searchEvents']);
        Route::post('catalog/events/sync', [Xs2CatalogController::class, 'syncEvent']);
        Route::post('catalog/events/bulk-sync', [Xs2CatalogController::class, 'bulkSyncEvents']);
        Route::post('sync-venues', [Xs2SyncController::class, 'queueVenues']);
        Route::post('sync-categories', [Xs2SyncController::class, 'queueCategories']);
        Route::get('event-mappings/summary', [Xs2EventMappingController::class, 'summary']);
        Route::get('event-mappings/tournaments', [Xs2EventMappingController::class, 'tournaments']);
        Route::get('event-mappings/currencies', [Xs2EventMappingController::class, 'currencies']);
        Route::get('event-mappings', [Xs2EventMappingController::class, 'index']);
        Route::get('event-mappings/{mapping}', [Xs2EventMappingController::class, 'show']);
        Route::post('event-mappings/{mapping}/map', [Xs2EventMappingController::class, 'map']);
        Route::post('event-mappings/{mapping}/create-event', [Xs2EventMappingController::class, 'createEvent']);
        Route::post('event-mappings/{mapping}/ignore', [Xs2EventMappingController::class, 'ignore']);
        Route::post('event-mappings/{mapping}/reopen', [Xs2EventMappingController::class, 'reopen']);
        Route::post('event-mappings/{mapping}/recalculate', [Xs2EventMappingController::class, 'recalculate']);

        Route::get('stadium-mappings', [Xs2StadiumMappingController::class, 'index']);
        Route::get('stadium-mappings/summary', [Xs2StadiumMappingController::class, 'summary']);
        Route::get('stadium-mappings/tournaments', [Xs2StadiumMappingController::class, 'tournaments']);
        Route::get('stadium-mappings/{mapping}', [Xs2StadiumMappingController::class, 'show']);
        Route::get('stadium-mappings/{mapping}/stadium-options', [Xs2MappingOptionController::class, 'stadiums']);
        Route::post('stadium-mappings/{mapping}/confirm', [Xs2StadiumMappingController::class, 'confirm']);
        Route::post('stadium-mappings/{mapping}/change', [Xs2StadiumMappingController::class, 'change']);
        Route::post('stadium-mappings/{mapping}/ignore', [Xs2StadiumMappingController::class, 'ignore']);

        Route::get('category-mappings', [Xs2CategoryMappingController::class, 'index']);
        Route::get('category-mappings/category-options', [Xs2MappingOptionController::class, 'categoriesForStadium']);
        Route::post('category-mappings/bulk-confirm', [Xs2CategoryMappingController::class, 'bulkConfirm']);
        Route::post('category-mappings/bulk-remove', [Xs2CategoryMappingController::class, 'bulkRemove']);
        Route::get('category-mappings/{mapping}', [Xs2CategoryMappingController::class, 'show']);
        Route::get('category-mappings/{mapping}/category-options', [Xs2MappingOptionController::class, 'categories']);
        Route::post('category-mappings/{mapping}/confirm', [Xs2CategoryMappingController::class, 'confirm']);
        Route::post('category-mappings/{mapping}/change', [Xs2CategoryMappingController::class, 'change']);
        Route::post('category-mappings/{mapping}/ignore', [Xs2CategoryMappingController::class, 'ignore']);

        Route::post('events/{mapping}/sync', [Xs2InventoryController::class, 'sync']);
        Route::get('events/{mapping}/sync-status', [Xs2InventoryController::class, 'status']);
        Route::post('events/{mapping}/fetch-inventory', [Xs2InventoryController::class, 'fetchInventory']);
        Route::get('events/{mapping}/last-api-debug', [Xs2InventoryController::class, 'lastApiDebug']);
        Route::get('events/{mapping}/tickets/xs2-preview', [Xs2InventoryController::class, 'previewXs2Tickets']);
        Route::get('events/{mapping}/tickets', [Xs2InventoryController::class, 'tickets']);
        Route::get('tickets/summary', [Xs2InventoryController::class, 'ticketSummary']);
        Route::get('tickets', [Xs2InventoryController::class, 'allTickets']);
        Route::get('tickets/{ticket}', [Xs2InventoryController::class, 'show']);
        Route::post('tickets/{ticket}/retry-listing', [Xs2InventoryController::class, 'retryListing']);
        Route::post('tickets/{ticket}/disable-listing', [Xs2InventoryController::class, 'disableListing']);
        Route::post('tickets/{ticket}/delete-listing', [Xs2InventoryController::class, 'deleteListing']);

        Route::get('listings/{ticket}/split', [ListingSplitController::class, 'show']);
        Route::post('listings/{ticket}/split/preview', [ListingSplitController::class, 'preview']);
        Route::post('listings/{ticket}/split/publish', [ListingSplitController::class, 'publish']);
        Route::post('listings/{ticket}/split/sync', [ListingSplitController::class, 'sync']);
        Route::delete('listings/{ticket}/split/{split}', [ListingSplitController::class, 'destroyOne']);
        Route::delete('listings/{ticket}/split', [ListingSplitController::class, 'destroy']);

        Route::prefix('sandbox-test')->group(function (): void {
            Route::get('event', [Xs2SandboxTestController::class, 'event']);
            Route::get('listing', [Xs2SandboxTestController::class, 'listing']);
            Route::get('xs2-bookingorders', [Xs2SandboxTestController::class, 'xs2BookingOrders']);
            Route::get('orders', [Xs2SandboxTestController::class, 'index']);
            Route::post('orders', [Xs2SandboxTestController::class, 'createDummyOrder']);
            Route::post('orders/import-from-xs2', [Xs2SandboxTestController::class, 'importFromXs2']);
            Route::get('orders/{xs2SandboxTestOrder}', [Xs2SandboxTestController::class, 'show'])->whereNumber('xs2SandboxTestOrder');
            Route::post('orders/{xs2SandboxTestOrder}/xs2-order', [Xs2SandboxTestController::class, 'createXs2Order'])->whereNumber('xs2SandboxTestOrder');
            Route::post('orders/{xs2SandboxTestOrder}/refresh-from-xs2', [Xs2SandboxTestController::class, 'refreshFromXs2'])->whereNumber('xs2SandboxTestOrder');
            Route::get('orders/{xs2SandboxTestOrder}/guest-data', [Xs2SandboxTestController::class, 'guestDataForm'])->whereNumber('xs2SandboxTestOrder');
            Route::put('orders/{xs2SandboxTestOrder}/guest-data', [Xs2SandboxTestController::class, 'updateGuestData'])->whereNumber('xs2SandboxTestOrder');
            Route::get('orders/{xs2SandboxTestOrder}/eticket', [Xs2SandboxTestController::class, 'downloadEticket'])->whereNumber('xs2SandboxTestOrder');
        });
    });
});
