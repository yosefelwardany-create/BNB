<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Serving the admin application.
 *
 * The route exists for a deployment behind a web server that passes unknown
 * paths to PHP without an SPA rewrite — a stock nginx or Apache virtual host.
 * There, `/app/` works and `/app/reservations` returns a Laravel 404 the moment
 * somebody refreshes or follows a link into the application, which reads as a
 * broken product rather than a missing rewrite rule.
 *
 * Production does not use it (Caddy serves `/app/*` directly and the request
 * never reaches PHP) and neither can `php artisan serve` — once
 * `public/app/index.html` exists, PHP's built-in server sets
 * `SCRIPT_NAME=/app/index.html` for sub-paths and Laravel never sees the real
 * URI. Both facts are in the controller's own documentation; what is tested
 * here is the behaviour of the path that does run.
 */
class AdminBundleTest extends TestCase
{
    use RefreshDatabase;

    private function bundleAt(string $contents = '<!doctype html><title>Habitat</title>'): string
    {
        $path = storage_path('framework/testing/admin-index.html');

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        file_put_contents($path, $contents);
        config(['pms.admin.index_path' => $path]);

        return $path;
    }

    protected function tearDown(): void
    {
        @unlink(storage_path('framework/testing/admin-index.html'));

        parent::tearDown();
    }

    public function test_a_client_side_route_serves_the_application_shell(): void
    {
        $path = $this->bundleAt();

        // The router, not the server, has to answer this: a hard refresh on a
        // screen deep in the application is the common case, not the unusual
        // one.
        $response = $this->get('/app/reservations')->assertOk();

        // A file response streams rather than buffering, so the assertion is
        // on which file was served.
        $this->assertSame(
            $path,
            $response->baseResponse->getFile()->getPathname(),
        );
    }

    public function test_every_depth_of_client_side_route_resolves(): void
    {
        $this->bundleAt();

        $this->get('/app/platform/tenants')->assertOk();
        $this->get('/app/')->assertOk();
    }

    public function test_the_shell_is_never_cached(): void
    {
        $this->bundleAt();

        // It names the hashed asset bundles, so a cached copy points at files a
        // later deployment has already replaced. Symfony reorders and
        // supplements the directive list, so the assertion is on the one that
        // carries the meaning rather than on the exact string.
        $header = (string) $this->get('/app/reservations')
            ->assertOk()
            ->headers->get('Cache-Control');

        $this->assertStringContainsString('no-cache', $header);
        $this->assertStringContainsString('must-revalidate', $header);
    }

    public function test_an_unbuilt_application_says_which_step_was_missed(): void
    {
        config(['pms.admin.index_path' => storage_path('framework/testing/does-not-exist.html')]);

        $response = $this->get('/app/reservations');

        // 503, not 404: the route is right and the application is real, the
        // bundle simply has not been built. A 404 would send somebody looking
        // for a missing route.
        $response->assertStatus(503);

        $this->assertStringContainsString('npm run build', $response->getContent());
        $this->assertStringContainsString('/api/v1', $response->getContent());
    }

    public function test_the_api_is_not_shadowed_by_the_catch_all(): void
    {
        $this->bundleAt();

        // The SPA route is greedy by design, so the check that matters is that
        // it did not swallow anything else. An unauthenticated API call must
        // still be refused by the API, not answered with an HTML page.
        $this->getJson('/api/v1/properties')->assertUnauthorized();
    }

    public function test_the_root_leads_to_the_application(): void
    {
        $this->get('/')->assertRedirect('/app/');
        $this->get('/admin')->assertRedirect('/app/');
    }
}
