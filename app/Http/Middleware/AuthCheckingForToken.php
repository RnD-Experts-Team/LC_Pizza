<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

class AuthCheckingForToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $authHeader = $request->header('Authorization');
        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            return response()->json(['ok' => false, 'error' => 'Missing or invalid Authorization header.', 'status' => 401], 401);
        }

        $userToken = trim(substr($authHeader, 7));
        $cfg = config('services.auth_server');
        $url = rtrim($cfg['base_url'], '/') . '/' . ltrim($cfg['verify_path'], '/');

        // short cache to reduce latency
        $ctxKey   = ($request->route()?->getName()) ?: ($request->method() . ' ' . $request->getPathInfo());
        $cacheKey = 'verify:v1:' . hash('sha256', $userToken) . ':' . md5($ctxKey) . ':' . ($cfg['service_name'] ?? 'svc');

        if ($cached = Cache::get($cacheKey)) {
            return $this->apply($cached, $request, $next);
        }

        $http = Http::acceptJson()
            ->timeout($cfg['timeout'])
            ->retry($cfg['retries'], $cfg['retry_ms'])
            ->withToken($cfg['call_token']); // proves which service is calling

        try {
            $resp = $http->asForm()->post($url, [
                'service' => $cfg['service_name'],
                'token'   => $userToken,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'error' => 'Auth service unreachable.',
                'detail' => config('app.debug') ? $e->getMessage() : null,
                'status' => 401,
            ], 401);
        }

        if (!$resp->ok()) {
            return response()->json(['ok' => false, 'error' => 'Unauthorized.', 'status' => 401], 401);
        }

        $data = $resp->json();

        if (!($data['active'] ?? false)) {
            return response()->json(['ok' => false, 'error' => 'Unauthorized (inactive token).', 'status' => 401], 401);
        }

        // attach user
        $userArr = (array) ($data['user'] ?? []);
        if (!array_key_exists('id', $userArr)) {
            return response()->json(['ok' => false, 'error' => 'Auth payload missing user id.', 'status' => 401], 401);
        }

        $userArr['roles']       = $data['roles'] ?? [];
        $userArr['permissions'] = $data['permissions'] ?? [];

        $genericUser = new GenericUser($userArr);
        Auth::setUser($genericUser);

        // cache (bounded by token exp if present)
        $ttl = max(1, (int) ($cfg['cache_ttl'] ?? 30));
        if (isset($data['exp']) && is_int($data['exp'])) {
            $secondsLeft = $data['exp'] - time();
            if ($secondsLeft > 0) $ttl = min($ttl, $secondsLeft);
        }
        Cache::put($cacheKey, $data, now()->addSeconds($ttl));

        return $next($request);
    }

    private function apply(array $data, Request $request, Closure $next): Response
    {
        if (!($data['active'] ?? false)) {
            return response()->json(['ok' => false, 'error' => 'Unauthorized (inactive token).', 'status' => 401], 401);
        }

        $userArr = (array) ($data['user'] ?? []);
        $userArr['roles']       = $data['roles'] ?? [];
        $userArr['permissions'] = $data['permissions'] ?? [];

        $genericUser = new \Illuminate\Auth\GenericUser($userArr);
        \Illuminate\Support\Facades\Auth::setUser($genericUser);

        return $next($request);
    }
}
