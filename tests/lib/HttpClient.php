<?php
/*
 * A tiny curl wrapper with a cookie jar, for driving the app exactly the
 * way this project family's own README/CLAUDE.md files already describe
 * as their standard manual-verification method ("php -S + curl with
 * cookie jars") -- just scripted and repeatable instead of by hand.
 */
class TestHttpClient {
    private string $baseUrl;
    private string $cookieJar;

    function __construct(string $baseUrl) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $jar = tempnam(sys_get_temp_dir(), 'zpms_test_cookies_');
        if ($jar === false) {
            throw new RuntimeException('Could not create a temporary cookie jar file.');
        }
        $this->cookieJar = $jar;
    }

    function __destruct() {
        if (is_file($this->cookieJar)) {
            @unlink($this->cookieJar);
        }
    }

    /** Drops all cookies, e.g. to start a fresh, logged-out session mid-test. */
    function resetCookies(): void {
        @unlink($this->cookieJar);
    }

    /** @return array{status:int, headers:string, body:string, location:?string} */
    function get(string $path): array {
        return $this->request('GET', $path);
    }

    /** @param array<string,string> $fields */
    function post(string $path, array $fields): array {
        return $this->request('POST', $path, $fields);
    }

    /**
     * @param array<string,string> $fields
     * @param array<string,string> $filePaths field name => local file path to upload
     */
    function postMultipart(string $path, array $fields, array $filePaths): array {
        foreach ($filePaths as $field => $localPath) {
            $fields[$field] = new CURLFile($localPath);
        }
        return $this->request('POST', $path, $fields, true);
    }

    /**
     * @param array<string,string> $headers extra request headers, e.g. ['X-CSRF-Token' => $token]
     */
    function postWithHeaders(string $path, array $fields, array $headers): array {
        return $this->request('POST', $path, $fields, false, $headers);
    }

    private function request(string $method, string $path, array $fields = [], bool $multipart = false, array $extraHeaders = []): array {
        $ch = curl_init($this->baseUrl . $path);
        $headerLines = [];
        foreach ($extraHeaders as $name => $value) {
            $headerLines[] = "$name: $value";
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_COOKIEJAR => $this->cookieJar,
            CURLOPT_COOKIEFILE => $this->cookieJar,
            CURLOPT_FOLLOWLOCATION => false, // tests inspect redirects themselves
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_TIMEOUT => 15,
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $multipart ? $fields : http_build_query($fields));
        }

        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("curl request to $path failed: $err");
        }
        $info = curl_getinfo($ch);
        curl_close($ch);

        $headerSize = $info['header_size'];
        $headers = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize);

        $location = null;
        if (preg_match('/^location:\s*(.+?)\r?$/mi', $headers, $m)) {
            $location = trim($m[1]);
        }

        return [
            'status' => $info['http_code'],
            'headers' => $headers,
            'body' => $body,
            'location' => $location,
        ];
    }

    /** Pulls the csrf_token hidden-field value out of a rendered form (csrf_field()'s exact markup). */
    static function extractCsrfToken(string $html): ?string {
        if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m)) {
            return html_entity_decode($m[1], ENT_QUOTES);
        }
        return null;
    }
}
