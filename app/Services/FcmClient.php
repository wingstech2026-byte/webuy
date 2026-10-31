<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Firebase Cloud Messaging (HTTP v1). Off until FCM_PROJECT_ID and FCM_CREDENTIALS (the path to the
 * Firebase service account JSON file) are set in .env. The access token is signed here with the
 * service account key (no extra package) and cached for 50 minutes.
 */
class FcmClient
{
    public static function configured(): bool
    {
        $path = config('services.fcm.credentials');

        return filled(config('services.fcm.project_id')) && filled($path) && is_file($path);
    }

    /**
     * @return bool false when the token is no longer valid (the app was uninstalled): delete it
     *
     * @throws RuntimeException on other errors
     */
    public function send(string $token, string $title, string $body, array $data = []): bool
    {
        $response = Http::withToken($this->accessToken())->timeout(15)
            ->post('https://fcm.googleapis.com/v1/projects/'.config('services.fcm.project_id').'/messages:send', [
                'message' => [
                    'token' => $token,
                    'notification' => ['title' => $title, 'body' => $body],
                    'data' => array_map('strval', $data),
                    'android' => ['priority' => 'high'],
                ],
            ]);

        if ($response->status() === 404 || $response->json('error.status') === 'UNREGISTERED') {
            return false;
        }

        if ($response->failed()) {
            throw new RuntimeException('FCM answered '.$response->status());
        }

        return true;
    }

    private function accessToken(): string
    {
        return Cache::remember('fcm-access-token', 3000, function () {
            $account = json_decode((string) file_get_contents(config('services.fcm.credentials')), true);
            $now = time();
            $encode = fn (array $part) => rtrim(strtr(base64_encode(json_encode($part)), '+/', '-_'), '=');

            $unsigned = $encode(['alg' => 'RS256', 'typ' => 'JWT']).'.'.$encode([
                'iss' => $account['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $now + 3600,
            ]);

            if (! openssl_sign($unsigned, $signature, $account['private_key'], OPENSSL_ALGO_SHA256)) {
                throw new RuntimeException('Could not sign the FCM token request.');
            }

            $jwt = $unsigned.'.'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');

            return Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ])->throw()->json('access_token');
        });
    }
}
