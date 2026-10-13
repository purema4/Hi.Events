<?php

namespace HiEvents\Http\Middleware;

use Closure;
use HiEvents\DomainObjects\Enums\ApiKeyAbility;
use HiEvents\Services\Domain\Auth\AuthUserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Psr\Log\LoggerInterface;
use stdClass;
use Symfony\Component\HttpFoundation\Response;

class EnforceApiKeyScope
{
    public const ERROR_CODE = 'API_KEY_SCOPE_DENIED';

    public function __construct(
        private readonly AuthUserService $authUserService,
        private readonly LoggerInterface $logger,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $accessToken = $this->authUserService->getAccessToken();

        if ($accessToken === null) {
            return $next($request);
        }

        $this->recordLastUsedIp($accessToken, $request);

        $method = $request->isMethod('HEAD') ? 'GET' : $request->method();
        $route = $method.' '.$request->route()->uri();

        if (! in_array($route, config('api-keys.open_routes'), true)) {
            $ability = $this->abilityFor($method, $route);

            if ($ability === null || ! in_array($ability->value, (array) $accessToken->abilities, true)) {
                return $this->deny($accessToken, $route, $ability);
            }
        }

        $startedAt = microtime(true);
        $response = $next($request);

        if ($response instanceof JsonResponse) {
            $response->setData($this->withoutPublicShortIds($response->getData(), $this->publicShortIdPattern()));
        }

        $this->logger->info('API key request', [
            'api_key_id' => $accessToken->id,
            'account_id' => $accessToken->account_id,
            'user_id' => $accessToken->tokenable_id,
            'route' => $route,
            'status' => $response->getStatusCode(),
            'ip' => $request->ip(),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        return $response;
    }

    private function abilityFor(string $method, string $route): ?ApiKeyAbility
    {
        $ability = ApiKeyAbility::tryFrom(config('api-keys.routes')[$route] ?? '');

        if ($ability !== null && $method !== 'GET' && ! $ability->allowsWrites()) {
            return null;
        }

        return $ability;
    }

    private function deny(PersonalAccessToken $accessToken, string $route, ?ApiKeyAbility $ability): Response
    {
        $this->logger->warning('API key request denied', [
            'api_key_id' => $accessToken->id,
            'account_id' => $accessToken->account_id,
            'route' => $route,
            'missing_ability' => $ability?->value,
        ]);

        return response()->json([
            'message' => $ability === null
                ? __('This route is not available to API keys.')
                : __('This API key does not have the :ability ability.', ['ability' => $ability->value]),
            'error_code' => self::ERROR_CODE,
            'ability' => $ability?->value,
        ], Response::HTTP_FORBIDDEN);
    }

    private function withoutPublicShortIds(mixed $data, string $pattern): mixed
    {
        if (is_array($data)) {
            return array_map(fn (mixed $value) => $this->withoutPublicShortIds($value, $pattern), $data);
        }

        if ($data instanceof stdClass) {
            foreach (get_object_vars($data) as $key => $value) {
                $data->{$key} = $this->withoutPublicShortIds($value, $pattern);
            }

            return $data;
        }

        if (is_string($data) && preg_match($pattern, $data) === 1) {
            return null;
        }

        return $data;
    }

    private function publicShortIdPattern(): string
    {
        $prefixes = array_map(
            static fn (string $prefix) => preg_quote($prefix, '/'),
            config('api-keys.hidden_short_id_prefixes'),
        );

        return '/^(?:'.implode('|', $prefixes).')_[A-Za-z0-9]{13}$/';
    }

    private function recordLastUsedIp(PersonalAccessToken $accessToken, Request $request): void
    {
        if ($accessToken->last_used_ip !== $request->ip()) {
            $accessToken->forceFill(['last_used_ip' => $request->ip()])->save();
        }
    }
}
