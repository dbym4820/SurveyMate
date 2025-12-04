<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\Session;

class SessionAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $sessionId = $this->getSessionId($request);

        if (!$sessionId) {
            return response()->json(['error' => '認証が必要です'], 401);
        }

        $session = Session::with('user')->find($sessionId);

        if (!$session) {
            return response()->json(['error' => 'セッションが無効です'], 401);
        }

        if ($session->isExpired()) {
            $session->delete();
            return response()->json(['error' => 'セッションが期限切れです'], 401);
        }

        if (!$session->user->is_active) {
            return response()->json(['error' => 'アカウントが無効化されています'], 401);
        }

        // Store user in request for later use
        $request->attributes->set('user', $session->user);
        $request->attributes->set('session', $session);

        return $next($request);
    }

    private function getSessionId(Request $request): ?string
    {
        // Check cookie first
        $sessionId = $request->cookie('session_id');
        if ($sessionId) {
            return $sessionId;
        }

        // Check Authorization header (Bearer token)
        $authHeader = $request->header('Authorization');
        if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
            return substr($authHeader, 7);
        }

        return null;
    }
}
