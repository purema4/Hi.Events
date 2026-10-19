<?php

namespace Tests\Feature\ApiKeys;

use HiEvents\DomainObjects\Enums\ApiKeyAbility;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

class ApiKeyRouteCoverageTest extends TestCase
{
    private const SCOPE_MIDDLEWARE = 'api-key.scope';

    public function test_every_authenticated_route_is_classified_for_api_keys(): void
    {
        $classified = array_merge(
            array_keys(config('api-keys.routes')),
            config('api-keys.open_routes'),
            config('api-keys.forbidden_routes'),
        );

        $unclassified = array_values(array_diff($this->authenticatedRoutes(), $classified));

        $this->assertSame([], $unclassified, 'Classify these routes in config/api-keys.php');
    }

    public function test_every_classified_route_exists_and_is_classified_once(): void
    {
        $classified = array_merge(
            array_keys(config('api-keys.routes')),
            config('api-keys.open_routes'),
            config('api-keys.forbidden_routes'),
        );

        $this->assertSame([], array_values(array_diff($classified, $this->authenticatedRoutes())), 'Unknown routes');
        $this->assertSame([], array_values(array_diff_assoc($classified, array_unique($classified))), 'Duplicates');
    }

    public function test_every_route_that_accepts_an_api_key_enforces_its_scope(): void
    {
        $this->assertSame([], $this->routesWithoutScope(), 'Add the api-key.scope middleware to these routes');
    }

    public function test_a_route_accepting_keys_under_any_sanctum_guard_is_checked(): void
    {
        RouteFacade::middleware(['auth:sanctum'])->get('/api-key-coverage-probe', static fn () => null);

        $this->assertContains('GET api-key-coverage-probe', $this->authenticatedRoutes());
        $this->assertSame(['GET api-key-coverage-probe'], $this->routesWithoutScope());
    }

    public function test_read_abilities_only_open_get_routes(): void
    {
        foreach (config('api-keys.routes') as $route => $ability) {
            $ability = ApiKeyAbility::from($ability);
            [$method] = explode(' ', $route, 2);

            if (! $ability->allowsWrites()) {
                $this->assertSame('GET', $method, "$route can't be opened by $ability->value");
            }
        }

        $this->assertSame(['GET api-key'], config('api-keys.open_routes'));
    }

    public function test_management_and_session_routes_are_forbidden(): void
    {
        $forbidden = config('api-keys.forbidden_routes');

        foreach ([
            'GET auth/logout',
            'POST auth/refresh',
            'GET accounts/{account_id}/api-keys',
            'POST accounts/{account_id}/api-keys',
            'DELETE accounts/{account_id}/api-keys/{api_key_id}',
            'GET users',
            'GET users/me',
            'POST organizers/{organizerId}/stripe/connect',
            'POST events/{event_id}/orders/export',
            'POST events/{event_id}/attendees/export',
        ] as $route) {
            $this->assertContains($route, $forbidden);
        }
    }

    /**
     * @return string[]
     */
    private function authenticatedRoutes(): array
    {
        return $this->describe($this->routesAcceptingApiKeys());
    }

    /**
     * @return string[]
     */
    private function routesWithoutScope(): array
    {
        return $this->describe(array_filter(
            $this->routesAcceptingApiKeys(),
            static fn (Route $route) => ! in_array(self::SCOPE_MIDDLEWARE, $route->gatherMiddleware(), true),
        ));
    }

    /**
     * @return Route[]
     */
    private function routesAcceptingApiKeys(): array
    {
        return array_values(array_filter(
            RouteFacade::getRoutes()->getRoutes(),
            fn (Route $route) => $this->acceptsApiKeys($route),
        ));
    }

    private function acceptsApiKeys(Route $route): bool
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware)
                && str_starts_with($middleware, 'auth:')
                && in_array('sanctum', explode(',', substr($middleware, strlen('auth:'))), true)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Route[]  $routes
     * @return string[]
     */
    private function describe(array $routes): array
    {
        $described = [];

        foreach ($routes as $route) {
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $described[] = $method.' '.$route->uri();
            }
        }

        sort($described);

        return $described;
    }
}
