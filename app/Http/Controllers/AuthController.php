<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use App\Models\User;
use App\Models\Session;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'ユーザー名とパスワードは必須です'], 400);
        }

        $user = User::where('username', $request->username)->first();

        if (!$user || !$user->verifyPassword($request->password)) {
            return response()->json(['error' => 'ユーザー名またはパスワードが正しくありません'], 401);
        }

        if (!$user->is_active) {
            return response()->json(['error' => 'アカウントが無効化されています'], 401);
        }

        // Update last login
        $user->update(['last_login_at' => now()]);

        // Create session
        $sessionLifetime = config('session.lifetime', 1440) * 60; // Convert minutes to seconds
        $session = Session::createForUser($user, $sessionLifetime);

        return response()->json([
            'success' => true,
            'message' => 'ログインに成功しました',
            'user' => [
                'id' => $user->id,
                'username' => $user->username,
                'email' => $user->email,
                'isAdmin' => $user->is_admin,
            ],
        ])->cookie('session_id', $session->id, $sessionLifetime / 60, '/', null, false, true);
    }

    public function logout(Request $request): JsonResponse
    {
        $session = $request->attributes->get('session');

        if ($session) {
            $session->delete();
        }

        return response()->json([
            'success' => true,
            'message' => 'ログアウトしました',
        ])->cookie('session_id', '', -1);
    }

    public function register(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'username' => 'required|string|min:3|max:100|unique:users',
            'password' => 'required|string|min:6',
            'email' => 'nullable|email|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 400);
        }

        $user = User::create([
            'username' => $request->username,
            'password' => $request->password, // Will be hashed by mutator
            'email' => $request->email,
            'is_admin' => false,
            'is_active' => true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'ユーザーを作成しました',
            'user' => [
                'id' => $user->id,
                'username' => $user->username,
                'email' => $user->email,
            ],
        ], 201);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        return response()->json([
            'success' => true,
            'authenticated' => true,
            'user' => [
                'id' => $user->id,
                'username' => $user->username,
                'email' => $user->email,
                'isAdmin' => $user->is_admin,
                'last_login_at' => $user->last_login_at,
            ],
        ]);
    }
}
