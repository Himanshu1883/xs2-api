<?php

namespace App\Support;

use App\Exceptions\Integrations\SellerApiConfigurationException;
use App\Exceptions\Integrations\Xs2ConfigurationException;

class EndpointTemplateResolver
{
    /**
     * Resolve configured endpoint templates without allowing unresolved values
     * to reach an outbound request. Values are encoded one placeholder at a
     * time, so a supplier ID can never alter the path structure.
     *
     * @param  array<string, scalar>  $parameters
     */
    public function resolve(string $template, array $parameters = [], string $integration = 'XS2'): string
    {
        if (blank($template)) {
            $this->configurationError($integration, 'endpoint template is not configured.');
        }

        foreach ($parameters as $name => $value) {
            $placeholder = '{'.trim((string) $name, '{}').'}';

            if (! str_contains($template, $placeholder)) {
                continue;
            }

            if ($value === '' || $value === null) {
                $this->configurationError($integration, "required endpoint placeholder {$placeholder} is missing.");
            }

            $template = str_replace($placeholder, rawurlencode((string) $value), $template);
        }

        if (preg_match('/\{[^}]+\}/', $template, $matches) === 1) {
            $this->configurationError($integration, "endpoint template has unresolved placeholder {$matches[0]}.");
        }

        return $template;
    }

    private function configurationError(string $integration, string $reason): never
    {
        $message = trim($integration).' integration configuration error: '.$reason;

        if (strtoupper($integration) === 'SELLER API') {
            throw new SellerApiConfigurationException($message);
        }

        throw new Xs2ConfigurationException($message);
    }
}
