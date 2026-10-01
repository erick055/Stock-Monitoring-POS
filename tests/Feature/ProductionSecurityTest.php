<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ProductionSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_http_and_error_responses_have_security_headers(): void
    {
        $this->get(route('login'))->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Content-Security-Policy', "base-uri 'self'; object-src 'none'; frame-ancestors 'self'")
            ->assertHeaderMissing('Strict-Transport-Security');
        $response = $this->get('/nonexistent-security-test')->assertNotFound()
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_production_requires_https_and_does_not_trust_spoofed_forwarded_headers(): void
    {
        $this->app->instance('env', 'production');
        $this->get('http://localhost/up')->assertStatus(400)->assertSee('HTTPS is required.');
        $this->withHeader('X-Forwarded-Proto', 'https')->get('http://localhost/up')->assertStatus(400);
        $this->get('https://localhost/up')->assertOk()
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }

    public function test_production_config_overrides_unsafe_debug_and_cookie_settings(): void
    {
        $settings = ['APP_ENV' => 'production', 'APP_DEBUG' => 'true',
            'SESSION_SECURE_COOKIE' => 'false', 'SESSION_ENCRYPT' => 'false',
            'SESSION_HTTP_ONLY' => 'false', 'SESSION_SAME_SITE' => 'none'];
        $process = new Process([PHP_BINARY, '-r',
            'require "vendor/autoload.php"; require "bootstrap/app.php"; $app = require "config/app.php"; $session = require "config/session.php"; echo json_encode([$app["debug"], $session["secure"], $session["encrypt"], $session["http_only"], $session["same_site"]]);',
        ], base_path(), $settings);
        $process->mustRun();
        $this->assertSame([false, true, true, true, 'lax'], json_decode($process->getOutput(), true));
    }
}
