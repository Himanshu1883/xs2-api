<?php

namespace Tests\Unit;

use App\Exceptions\Integrations\SellerApiConfigurationException;
use App\Exceptions\Integrations\Xs2ConfigurationException;
use App\Support\EndpointTemplateResolver;
use Tests\TestCase;

class EndpointTemplateResolverTest extends TestCase
{
    public function test_it_url_encodes_configured_endpoint_values(): void
    {
        $path = app(EndpointTemplateResolver::class)->resolve('/v1/events/{event_id}', ['event_id' => 'A/B C']);

        $this->assertSame('/v1/events/A%2FB%20C', $path);
    }

    public function test_it_rejects_unresolved_xs2_placeholders(): void
    {
        $this->expectException(Xs2ConfigurationException::class);
        $this->expectExceptionMessage('unresolved placeholder {event_id}');

        app(EndpointTemplateResolver::class)->resolve('/v1/events/{event_id}');
    }

    public function test_it_uses_a_seller_configuration_exception_when_requested(): void
    {
        $this->expectException(SellerApiConfigurationException::class);

        app(EndpointTemplateResolver::class)->resolve('', [], 'Seller API');
    }
}
