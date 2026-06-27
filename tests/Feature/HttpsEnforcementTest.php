<?php

namespace Tests\Feature;

use Tests\TestCase;

class HttpsEnforcementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'https.force' => true,
            'https.allow_http' => false,
            'https.hsts.enabled' => true,
            'https.hsts.max_age' => 31536000,
            'https.hsts.include_subdomains' => true,
        ]);
    }

    public function test_plain_http_requests_are_redirected_to_https(): void
    {
        $response = $this->get('/login');

        $response->assertRedirect();
        $this->assertStringStartsWith('https://', $response->headers->get('Location'));
    }

    public function test_loopback_health_check_is_not_redirected(): void
    {
        $response = $this->call('GET', '/up', [], [], [], [
            'REMOTE_ADDR' => '127.0.0.1',
        ]);

        $response->assertOk();
        $response->assertHeaderMissing('Location');
    }

    public function test_secure_requests_receive_hsts_header(): void
    {
        $response = $this->call('GET', '/login', [], [], [], [
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);

        $response->assertHeader(
            'Strict-Transport-Security',
            'max-age=31536000; includeSubDomains'
        );
    }

    public function test_http_is_allowed_when_explicitly_enabled(): void
    {
        config(['https.allow_http' => true]);

        $response = $this->get('/login');

        $response->assertOk();
    }
}
