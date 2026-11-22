<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsCustomer
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->is_admin) {
            if ($request->isMethod('GET') && ! $request->expectsJson()) {
                return to_route('admin.reports');
            }

            abort(403, 'Operator admin accounts cannot access customer generation or billing actions.');
        }

        return $next($request);
    }
}
