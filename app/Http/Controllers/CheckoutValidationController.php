<?php

namespace App\Http\Controllers;

use App\Http\Requests\Xs2\CheckoutValidationRequest;
use App\Services\Xs2\Xs2CheckoutValidationService;
use Illuminate\Http\JsonResponse;

class CheckoutValidationController extends Controller
{
    public function __construct(private readonly Xs2CheckoutValidationService $validation) {}

    public function validate(CheckoutValidationRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $guests = is_array($validated['guests'] ?? null) ? $validated['guests'] : [];

        $result = $guests === []
            ? $this->validation->validate(
                (string) $validated['seller_reference'],
                (int) $validated['quantity'],
                (int) $validated['expected_price'],
                $validated['expected_currency'] ?? null,
            )
            : $this->validation->validateWithGuests(
                (string) $validated['seller_reference'],
                (int) $validated['quantity'],
                (int) $validated['expected_price'],
                $guests,
                $validated['expected_currency'] ?? null,
            );

        return response()->json([
            'message' => $result['valid'] ? 'Checkout validation passed.' : 'Checkout validation failed.',
            'data' => $result,
        ], $result['valid'] ? 200 : 422);
    }
}
