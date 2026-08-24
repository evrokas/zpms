<?php

/**
 * ErnsAuthClient — Drop-in PHP class for client apps.
 *
 * Usage:
 *   $client = new ErnsAuthClient('https://example.com/apps/ernsauth/web/sso-api.php', 'your-api-key');
 *   $challenge = $client->createChallenge($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'] ?? '');
 *   $poll = $client->pollChallenge($challenge['challenge_id']);
 *   $user = $client->exchangeCode($poll['auth_code']);
 */
class ErnsAuthClient
{
    private $baseUrl;
    private $apiKey;
    private $timeout;

    public function __construct(string $baseUrl, string $apiKey, int $timeout = 15)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey  = $apiKey;
        $this->timeout = $timeout;
    }

    // ── SSO Flow ──────────────────────────────────────────────────────────

    public function createChallenge(string $clientIp, string $userAgent): array
    {
        return $this->request('POST', 'create_challenge', [
            'client_ip'         => $clientIp,
            'client_user_agent' => $userAgent,
        ]);
    }

    public function pollChallenge(string $challengeId): array
    {
        return $this->request('GET', 'poll_challenge', [
            'challenge_id' => $challengeId,
        ]);
    }

    public function exchangeCode(string $authCode): array
    {
        return $this->request('POST', 'exchange_code', [
            'auth_code' => $authCode,
        ]);
    }

    // ── OTP Flow ──────────────────────────────────────────────────────────

    public function sendOtp(string $email): array
    {
        return $this->request('POST', 'send_otp', [
            'email' => $email,
        ]);
    }

    public function verifyOtp(string $otpId, string $code): array
    {
        return $this->request('POST', 'verify_otp', [
            'otp_id' => $otpId,
            'code'   => $code,
        ]);
    }

    // ── Password Reset ────────────────────────────────────────────────────

    public function requestPasswordReset(string $email): array
    {
        return $this->request('POST', 'request_password_reset', [
            'email' => $email,
        ]);
    }

    public function verifyPasswordReset(string $email, string $code, string $newPassword): array
    {
        return $this->request('POST', 'verify_password_reset', [
            'email'        => $email,
            'code'         => $code,
            'new_password' => $newPassword,
        ]);
    }

    // ── Internal ──────────────────────────────────────────────────────────

    private function request(string $method, string $action, array $data = []): array
    {
        $url = $this->baseUrl . '?action=' . urlencode($action);

        if ($method === 'GET' && !empty($data)) {
            $url .= '&' . http_build_query($data);
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER     => [
                'X-API-Key: ' . $this->apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException("ErnsAuth request failed: {$error}");
        }

        $decoded = json_decode($response, true);
        if ($decoded === null) {
            throw new RuntimeException("ErnsAuth returned invalid JSON (HTTP {$httpCode})");
        }

        if ($httpCode >= 400) {
            $msg = $decoded['error'] ?? "HTTP {$httpCode}";
            throw new RuntimeException("ErnsAuth error: {$msg}");
        }

        return $decoded;
    }
}
