<?php

use Dotenv\Dotenv;

use App\Exceptions\Integrations\SellerApiConfigurationException;
use App\Exceptions\Integrations\SellerApiRequestException;
use App\Exceptions\Integrations\Xs2ConfigurationException;
use App\Exceptions\Integrations\Xs2RequestException;
use App\Exceptions\Integrations\Xs2ResponseException;
use App\Http\Middleware\EnsureAdmin;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

$application = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin' => EnsureAdmin::class,
        ]);

        // API routes must return 401 JSON, not redirect to a missing web login route.
        $middleware->redirectGuestsTo(function (Request $request): ?string {
            if ($request->is('api/*') || $request->expectsJson()) {
                return null;
            }

            return null;
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        $exceptions->render(function (ValidationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $errors = $exception->errors();
            $firstMessage = collect($errors)->flatten()->first();

            return response()->json([
                'message' => is_string($firstMessage) && $firstMessage !== ''
                    ? $firstMessage
                    : 'The provided data is invalid.',
                'errors' => $errors,
            ], $exception->status);
        });

        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json(['message' => 'Unauthenticated.'], 401);
        });

        $exceptions->render(function (HttpExceptionInterface $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $status = $exception->getStatusCode();
            $exceptionMessage = trim($exception->getMessage());
            $message = match ($status) {
                403 => 'You are not authorized to perform this action.',
                404 => 'The requested resource was not found.',
                405 => 'The requested method is not allowed.',
                409 => $exceptionMessage !== '' ? $exceptionMessage : 'This mapping changed before the request could be completed. Refresh and try again.',
                429 => 'Too many requests. Please try again later.',
                default => $exceptionMessage !== '' ? $exceptionMessage : 'The request could not be completed.',
            };

            return response()->json(['message' => $message], $status);
        });

        // Misconfigured integrations should surface a clear admin-facing message
        // instead of the generic 500 payload the Next client maps to "unavailable".
        $exceptions->render(function (
            Xs2ConfigurationException|SellerApiConfigurationException $exception,
            Request $request,
        ) {
            if (! $request->is('api/*')) {
                return null;
            }

            $message = trim($exception->getMessage());

            return response()->json([
                'message' => $message !== ''
                    ? $message
                    : 'Integration configuration is incomplete.',
            ], 503);
        });

        $exceptions->render(function (
            Xs2RequestException|Xs2ResponseException|SellerApiRequestException $exception,
            Request $request,
        ) {
            if (! $request->is('api/*')) {
                return null;
            }

            $message = trim($exception->getMessage());
            $status = 502;
            if ($exception instanceof Xs2RequestException && $exception->status === 429) {
                $status = 429;
            }

            $payload = [
                'message' => $message !== ''
                    ? $message
                    : 'The upstream integration request failed.',
            ];

            if ($exception instanceof SellerApiRequestException && $exception->context !== []) {
                $payload['debug'] = $exception->context;
            }

            return response()->json($payload, $status);
        });

        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $payload = [
                'message' => 'An unexpected error occurred. Please try again later.',
            ];

            if ($request->is('api/admin/*') || config('app.debug')) {
                $cause = trim($exception->getMessage());
                $payload['debug'] = array_filter([
                    'exception' => $exception::class,
                    'cause' => $cause !== '' ? $cause : null,
                    'file' => config('app.debug') ? $exception->getFile() : null,
                    'line' => config('app.debug') ? $exception->getLine() : null,
                ], static fn (mixed $value): bool => $value !== null && $value !== '');
            }

            return response()->json($payload, 500);
        });
    })
    ->create();

// Local developer overrides (API keys, sandbox credentials) live in .env.local.
// Load after .env so secrets are available before config files are read.
$application->afterLoadingEnvironment(function () use ($application): void {
    // phpunit.xml sets sqlite :memory: — .env.local must not override DB in tests.
    if (env('APP_ENV') === 'testing') {
        return;
    }

    $localEnvFile = $application->basePath('.env.local');

    if (is_file($localEnvFile)) {
        Dotenv::createMutable($application->basePath(), '.env.local')->safeLoad();
    }
});

return $application;
