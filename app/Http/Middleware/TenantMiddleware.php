<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\TenantResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TenantMiddleware
{
    public function __construct(protected TenantResolver $tenantResolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();

        if ($user) {
            $tenant = $this->tenantResolver->resolveForUser($user);

            if (!$tenant) {
                abort(403, 'No tenant associated with this user.');
            }
        }

        return $next($request);
    }
}
