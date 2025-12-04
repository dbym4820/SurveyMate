<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->attributes->get('user');

        if (!$user || !$user->is_admin) {
            return response()->json(['error' => '管理者権限が必要です'], 403);
        }

        return $next($request);
    }
}
