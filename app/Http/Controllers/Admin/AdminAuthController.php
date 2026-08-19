<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Throwable;

class AdminAuthController extends Controller
{
    /**
     * Authenticate an administrator and issue a bearer token.
     */
    public function login(Request $request): JsonResponse
    {
        try {
            $credentials = $request->validate([
                'email' => ['required', 'email'],
                'password' => ['required', 'string'],
            ]);

            $user = User::query()
                ->where('email', $credentials['email'])
                ->where('store_id', (int) config('provider-auth.store_id'))
                ->first();

            if (! $user || ! Hash::check($credentials['password'], $user->password)) {
                return response()->json([
                    'message' => 'Invalid credentials.',
                ], 401);
            }

            if (! $user->canAccessProviderConsole()) {
                return response()->json([
                    'message' => 'You are not authorized to perform this action.',
                ], 403);
            }

            $expiresAt = now()->addMinutes((int) config('provider-auth.token_ttl_minutes'));
            $plainTextToken = $user->createToken('admin-login', ['*'], $expiresAt)->plainTextToken;

            return response()->json([
                'message' => 'Login successful.',
                'data' => [
                    'token' => $plainTextToken,
                    'expires_at' => $expiresAt->toIso8601String(),
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->display_name,
                        'email' => $user->email,
                    ],
                ],
            ]);
        } catch (Throwable $e) {
            Log::error('Provider login failed', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'message' => 'An error occurred while processing the login request.',
            ], 500);
        }
    }

    /**
     * Return the authenticated administrator and token expiry.
     */
    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $token = $user->currentAccessToken();

        return response()->json([
            'message' => 'Authenticated.',
            'data' => [
                'expires_at' => $token?->expires_at?->toIso8601String(),
                'user' => [
                    'id' => $user->id,
                    'name' => $user->display_name,
                    'email' => $user->email,
                ],
            ],
        ]);
    }

    /**
     * Revoke the bearer token used by this request.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json([
            'message' => 'Logged out successfully.',
            'data' => new \stdClass,
        ]);
    }
}
