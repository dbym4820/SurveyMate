<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use App\Models\PushSubscription;
use App\Services\WebPushService;

class PushController extends Controller
{
    private WebPushService $pushService;

    public function __construct(WebPushService $pushService)
    {
        $this->pushService = $pushService;
    }

    /**
     * Get VAPID public key for client
     */
    public function publicKey(): JsonResponse
    {
        if (!$this->pushService->isConfigured()) {
            return response()->json([
                'success' => false,
                'error' => 'Push notifications are not configured',
            ], 503);
        }

        return response()->json([
            'success' => true,
            'publicKey' => $this->pushService->getPublicKey(),
        ]);
    }

    /**
     * Subscribe to push notifications
     */
    public function subscribe(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'endpoint' => 'required|url',
            'keys.p256dh' => 'required|string',
            'keys.auth' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => $validator->errors()->first(),
            ], 400);
        }

        $user = $request->attributes->get('user');

        // Check if subscription already exists
        $existing = PushSubscription::findByEndpoint($request->endpoint);

        if ($existing) {
            // Update existing subscription
            $existing->update([
                'user_id' => $user ? $user->id : null,
                'p256dh_key' => $request->input('keys.p256dh'),
                'auth_token' => $request->input('keys.auth'),
                'is_active' => true,
            ]);
            $subscription = $existing;
        } else {
            // Create new subscription
            $subscription = PushSubscription::create([
                'user_id' => $user ? $user->id : null,
                'endpoint' => $request->endpoint,
                'p256dh_key' => $request->input('keys.p256dh'),
                'auth_token' => $request->input('keys.auth'),
                'is_active' => true,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Successfully subscribed to push notifications',
            'subscription_id' => $subscription->id,
        ]);
    }

    /**
     * Unsubscribe from push notifications
     */
    public function unsubscribe(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'endpoint' => 'required|url',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => $validator->errors()->first(),
            ], 400);
        }

        $subscription = PushSubscription::findByEndpoint($request->endpoint);

        if ($subscription) {
            $subscription->update(['is_active' => false]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Successfully unsubscribed from push notifications',
        ]);
    }

    /**
     * Get subscription status
     */
    public function status(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        $query = PushSubscription::active();
        if ($user) {
            $query->where('user_id', $user->id);
        }

        $count = $query->count();

        return response()->json([
            'success' => true,
            'configured' => $this->pushService->isConfigured(),
            'subscribed' => $count > 0,
            'subscription_count' => $count,
        ]);
    }
}
