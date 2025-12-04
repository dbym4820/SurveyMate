<?php

namespace App\Services;

use App\Models\PushSubscription;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WebPushService
{
    private string $publicKey;
    private string $privateKey;
    private string $subject;

    public function __construct()
    {
        $this->publicKey = config('services.webpush.public_key', '');
        $this->privateKey = config('services.webpush.private_key', '');
        $this->subject = config('services.webpush.subject', 'mailto:admin@example.com');
    }

    /**
     * Check if VAPID keys are configured
     */
    public function isConfigured(): bool
    {
        return !empty($this->publicKey) && !empty($this->privateKey);
    }

    /**
     * Get VAPID public key for client subscription
     */
    public function getPublicKey(): string
    {
        return $this->publicKey;
    }

    /**
     * Send notification to a single subscription
     */
    public function sendNotification(PushSubscription $subscription, array $payload): bool
    {
        if (!$this->isConfigured()) {
            Log::warning('WebPush: VAPID keys not configured');
            return false;
        }

        try {
            $jwt = $this->createJWT($subscription->endpoint);
            $encryptedPayload = $this->encryptPayload($subscription, json_encode($payload));

            $response = Http::withHeaders([
                'Authorization' => 'vapid t=' . $jwt . ', k=' . $this->publicKey,
                'Content-Type' => 'application/octet-stream',
                'Content-Encoding' => 'aes128gcm',
                'TTL' => '86400',
            ])->withBody($encryptedPayload, 'application/octet-stream')
              ->post($subscription->endpoint);

            if ($response->status() === 201) {
                $subscription->update(['last_used_at' => now()]);
                return true;
            }

            // Handle subscription expiry
            if ($response->status() === 404 || $response->status() === 410) {
                $subscription->update(['is_active' => false]);
                Log::info('WebPush: Subscription expired', ['id' => $subscription->id]);
            }

            Log::warning('WebPush: Failed to send', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        } catch (\Exception $e) {
            Log::error('WebPush: Exception', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Send notification to all active subscriptions
     */
    public function sendToAll(array $payload): array
    {
        $subscriptions = PushSubscription::active()->get();
        $results = ['success' => 0, 'failed' => 0];

        foreach ($subscriptions as $subscription) {
            if ($this->sendNotification($subscription, $payload)) {
                $results['success']++;
            } else {
                $results['failed']++;
            }
        }

        return $results;
    }

    /**
     * Create JWT for VAPID authentication
     * Simplified version - for production, use a proper library
     */
    private function createJWT(string $endpoint): string
    {
        $audience = parse_url($endpoint, PHP_URL_SCHEME) . '://' . parse_url($endpoint, PHP_URL_HOST);
        $expiry = time() + 86400;

        $header = $this->base64UrlEncode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $payload = $this->base64UrlEncode(json_encode([
            'aud' => $audience,
            'exp' => $expiry,
            'sub' => $this->subject,
        ]));

        $signature = $this->sign($header . '.' . $payload);

        return $header . '.' . $payload . '.' . $signature;
    }

    /**
     * Sign data with private key (ES256)
     */
    private function sign(string $data): string
    {
        // Decode base64url private key
        $privateKeyDer = $this->base64UrlDecode($this->privateKey);

        // For ES256, we need to convert to PEM format
        $pem = "-----BEGIN EC PRIVATE KEY-----\n" .
               chunk_split(base64_encode($this->createEcPrivateKeyDer($privateKeyDer)), 64, "\n") .
               "-----END EC PRIVATE KEY-----";

        $privateKey = openssl_pkey_get_private($pem);
        if (!$privateKey) {
            // If PEM conversion fails, use raw signing
            Log::warning('WebPush: Could not parse private key, using fallback');
            return $this->base64UrlEncode(hash('sha256', $data . $this->privateKey, true));
        }

        openssl_sign($data, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        // Convert DER signature to raw format (r || s)
        $signature = $this->derToRaw($signature);

        return $this->base64UrlEncode($signature);
    }

    /**
     * Create EC private key in DER format
     */
    private function createEcPrivateKeyDer(string $rawKey): string
    {
        // This is a simplified implementation
        // The raw key is just the 32-byte private scalar
        $prefix = "\x30\x77\x02\x01\x01\x04\x20";
        $curve = "\xa0\x0a\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";
        return $prefix . $rawKey . $curve;
    }

    /**
     * Convert DER signature to raw format
     */
    private function derToRaw(string $der): string
    {
        // Skip sequence header and get r and s
        $offset = 2;
        if (ord($der[$offset]) & 0x80) {
            $offset += ord($der[$offset]) & 0x7f;
        }
        $offset++;

        $rLen = ord($der[$offset++]);
        $r = substr($der, $offset, $rLen);
        $offset += $rLen + 1;

        $sLen = ord($der[$offset++]);
        $s = substr($der, $offset, $sLen);

        // Pad to 32 bytes each
        $r = str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT);
        $s = str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);

        return $r . $s;
    }

    /**
     * Encrypt payload (simplified - for production use proper Web Push encryption)
     */
    private function encryptPayload(PushSubscription $subscription, string $payload): string
    {
        // This is a simplified implementation
        // For production, you should use a proper Web Push library like minishlink/web-push

        $publicKey = $this->base64UrlDecode($subscription->p256dh_key);
        $authSecret = $this->base64UrlDecode($subscription->auth_token);

        // Generate local key pair
        $localPrivateKey = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);

        if (!$localPrivateKey) {
            // Fallback: return unencrypted (won't work in production)
            Log::warning('WebPush: Could not generate local key pair');
            return $payload;
        }

        $details = openssl_pkey_get_details($localPrivateKey);
        $localPublicKey = $details['ec']['x'] . $details['ec']['y'];

        // Compute shared secret (simplified)
        $sharedSecret = hash('sha256', $publicKey . $authSecret . $localPublicKey, true);

        // Encrypt with AES-128-GCM
        $iv = random_bytes(12);
        $encrypted = openssl_encrypt($payload, 'aes-128-gcm', substr($sharedSecret, 0, 16), OPENSSL_RAW_DATA, $iv, $tag);

        // Construct record
        $salt = random_bytes(16);
        $recordSize = pack('N', 4096);
        $keyIdLen = chr(65);

        // Simplified header
        return $salt . $recordSize . $keyIdLen . chr(4) . $localPublicKey . $iv . $encrypted . $tag;
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', 3 - (3 + strlen($data)) % 4));
    }
}
