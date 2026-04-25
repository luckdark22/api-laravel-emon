<?php

namespace App\Services;

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FcmService
{
    protected $accessToken;
    protected $credentialsData;

    public function __construct()
    {
        $path = storage_path('app/private/firebase_credentials.json');
        Log::info('Checking FCM Credentials at: ' . $path);

        if (file_exists($path)) {
            $content = file_get_contents($path);
            $this->credentialsData = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error('FCM Credentials JSON Decode Error: ' . json_last_error_msg());
            } else {
                Log::info('FCM Credentials loaded successfully.');
            }
        } else {
            Log::error('FCM Credentials file NOT found at: ' . $path);
        }
    }

    private function getAccessToken()
    {
        if ($this->accessToken) {
            return $this->accessToken;
        }

        if (!$this->credentialsData) {
            Log::error('FCM Credentials file not found.');
            return null;
        }

        $now_seconds = time();
        $payload = array(
            "iss" => $this->credentialsData['client_email'],
            "sub" => $this->credentialsData['client_email'],
            "aud" => "https://oauth2.googleapis.com/token",
            "iat" => $now_seconds,
            "exp" => $now_seconds + (60 * 60),
            "scope" => "https://www.googleapis.com/auth/firebase.messaging"
        );

        try {
            $jwt = JWT::encode($payload, $this->credentialsData['private_key'], 'RS256');

            $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt
            ]);

            if ($response->successful()) {
                $this->accessToken = $response->json()['access_token'];
                return $this->accessToken;
            }

            Log::error('FCM Token Exchange Failed: ' . $response->body());
            return null;
        } catch (\Exception $e) {
            Log::error('FCM Token Generation Error: ' . $e->getMessage());
            return null;
        }
    }

    public function sendNotification($token, $title, $body, $data = [])
    {
        $accessToken = $this->getAccessToken();
        if (!$accessToken) {
            return false;
        }

        $projectId = $this->credentialsData['project_id'];
        $url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

        $message = [
            'message' => [
                'token' => $token,
                'notification' => [
                    'title' => $title,
                    'body' => $body,
                ],
                'data' => (object)$data // Use object to ensure JSON {} if empty
            ]
        ];

        $response = Http::withToken($accessToken)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->post($url, $message);

        if ($response->failed()) {
            Log::error('FCM Send Failed: ' . $response->body());
            return false;
        }

        return true;
    }

    public function sendMulticast(array $tokens, $title, $body, $data = [])
    {
        $successCount = 0;
        foreach ($tokens as $token) {
            if ($this->sendNotification($token, $title, $body, $data)) {
                $successCount++;
            }
        }
        return $successCount;
    }
}
