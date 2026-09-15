<?php

/**
 * Client for the Google Calendar consultation-scheduling sync -- see
 * README.md, "Google Calendar sync", for the full design. Two directions
 * share this one class:
 *  - push (ZPMS -> Calendar): createEvent()/updateEvent()/deleteEvent(),
 *    called synchronously from the "Νέο Ραντεβού" booking screen and from
 *    appointment edit/delete, right after the local appointments row is
 *    saved.
 *  - pull (Calendar -> ZPMS): listChangedEvents(), called from the
 *    periodic bin/sync_google_calendar.php cron.
 *
 * Auth is a service-account JWT bearer flow (RFC 7523), hand-rolled via
 * openssl_sign()+cURL rather than Google's official PHP client library --
 * this app family has no Composer/SDK dependency anywhere (see this
 * project's own CLAUDE.md, "no build step"), and the flow itself is short
 * enough not to need one. No OAuth consent screen, no refresh token to
 * expire and need re-authorizing: a service account's JSON key is a
 * long-lived credential the app signs its own short-lived (1h) access
 * tokens with, on every process that needs one.
 *
 * Fails closed on every error path, same convention as
 * zpms_apyweb_client.php/zeusfw's ernsauthClass/recaptchaClass: a missing
 * config file, an unreachable Google, a malformed response -- none of
 * these should ever be mistaken for "nothing to sync" or, worse, block a
 * real phone booking from saving locally. Every public method returns
 * null/false on failure and logs the reason via error_log(); nothing here
 * throws.
 */

define('GOOGLE_CALENDAR_API_BASE', 'https://www.googleapis.com/calendar/v3');
define('GOOGLE_CALENDAR_TOKEN_URL', 'https://oauth2.googleapis.com/token');
define('GOOGLE_CALENDAR_SCOPE', 'https://www.googleapis.com/auth/calendar');
define('GOOGLE_CALENDAR_TIMEOUT_SECONDS', 8);

// The extendedProperties.private key an event carries when it originated
// from ZPMS's own booking screen -- how listChangedEvents() tells "this
// is one of ours, just check for a date/cancel change" apart from "this
// was created directly in the Calendar app, queue it for review". See
// calendar_pending_events.yaml's own docblock for the review-queue half.
define('GOOGLE_CALENDAR_APPOINTMENT_PROPERTY', 'zpms_appointment_id');

class googleCalendarClass {

    private static ?array $config = null;
    private static bool $configLoaded = false;
    private static ?string $cachedAccessToken = null;
    private static int $cachedAccessTokenExpiresAt = 0;

    /**
     * @return array{service_account_key_path: string, calendar_id: string}|null
     */
    private static function getConfig(): ?array {
        if (self::$configLoaded) {
            return self::$config;
        }
        self::$configLoaded = true;

        $file = __DIR__ . '/../config/google_calendar.php';
        if (!is_file($file)) {
            return null;
        }
        $cfg = require $file;
        if (
            !is_array($cfg)
            || empty($cfg['service_account_key_path'])
            || empty($cfg['calendar_id'])
            || !is_file($cfg['service_account_key_path'])
        ) {
            return null;
        }

        self::$config = $cfg;
        return $cfg;
    }

    static function isEnabled(): bool {
        return function_exists('curl_init')
            && function_exists('openssl_sign')
            && self::getConfig() !== null;
    }

    /**
     * Base64url encode -- Google's JWT/token endpoints use the URL-safe
     * variant (RFC 4648 §5: '-'/'_' instead of '+'/'/', no padding), not
     * plain base64_encode()'s output.
     */
    private static function base64UrlEncode(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Signs a fresh service-account JWT and exchanges it for a short-lived
     * OAuth2 access token (RFC 7523 grant type). Cached per-process for
     * its actual remaining lifetime minus a 60s safety margin -- a single
     * cron run pulling several pages of events, or a booking request that
     * makes more than one API call, reuses one token instead of minting a
     * fresh one per call.
     */
    private static function getAccessToken(): ?string {
        if (self::$cachedAccessToken !== null && time() < self::$cachedAccessTokenExpiresAt) {
            return self::$cachedAccessToken;
        }

        $config = self::getConfig();
        if ($config === null) {
            return null;
        }

        $keyJson = @file_get_contents($config['service_account_key_path']);
        if ($keyJson === false) {
            error_log('[google-calendar] could not read service account key file');
            return null;
        }
        $key = json_decode($keyJson, true);
        if (!is_array($key) || empty($key['client_email']) || empty($key['private_key'])) {
            error_log('[google-calendar] service account key file is malformed');
            return null;
        }

        $now = time();
        $header = self::base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = self::base64UrlEncode(json_encode([
            'iss'   => $key['client_email'],
            'scope' => GOOGLE_CALENDAR_SCOPE,
            'aud'   => GOOGLE_CALENDAR_TOKEN_URL,
            'iat'   => $now,
            'exp'   => $now + 3600,
        ]));
        $signingInput = $header . '.' . $claims;

        $signature = '';
        $signed = openssl_sign($signingInput, $signature, $key['private_key'], OPENSSL_ALGO_SHA256);
        if (!$signed) {
            error_log('[google-calendar] JWT signing failed -- is the private key in the key file valid?');
            return null;
        }
        $jwt = $signingInput . '.' . self::base64UrlEncode($signature);

        $ch = curl_init(GOOGLE_CALENDAR_TOKEN_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ]),
            CURLOPT_TIMEOUT => GOOGLE_CALENDAR_TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => GOOGLE_CALENDAR_TIMEOUT_SECONDS,
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            error_log('[google-calendar] token exchange failed: ' . curl_error($ch));
            curl_close($ch);
            return null;
        }
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $resp = json_decode((string)$body, true);
        if ($status !== 200 || !is_array($resp) || empty($resp['access_token'])) {
            error_log("[google-calendar] token exchange returned HTTP $status: " . substr((string)$body, 0, 500));
            return null;
        }

        self::$cachedAccessToken = $resp['access_token'];
        self::$cachedAccessTokenExpiresAt = $now + (int)($resp['expires_in'] ?? 3600) - 60;
        return self::$cachedAccessToken;
    }

    /**
     * One authenticated Calendar API request. $method is the HTTP verb;
     * $path is appended to GOOGLE_CALENDAR_API_BASE (already
     * urlencoded/query-stringed by the caller); $jsonBody, if given, is
     * sent as the request body. Returns the decoded JSON body on any 2xx
     * response, or null (logged) on anything else -- auth failure,
     * network error, non-2xx status, or a body that isn't valid JSON.
     */
    private static function request(string $method, string $path, ?array $jsonBody = null): ?array {
        $token = self::getAccessToken();
        if ($token === null) {
            return null;
        }

        $headers = ['Authorization: Bearer ' . $token];
        $ch = curl_init(GOOGLE_CALENDAR_API_BASE . $path);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_TIMEOUT => GOOGLE_CALENDAR_TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => GOOGLE_CALENDAR_TIMEOUT_SECONDS,
        ];
        if ($jsonBody !== null) {
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_POSTFIELDS] = json_encode($jsonBody);
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);

        $body = curl_exec($ch);
        if ($body === false) {
            error_log("[google-calendar] $method $path failed: " . curl_error($ch));
            curl_close($ch);
            return null;
        }
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // DELETE succeeds with an empty 204 body -- not JSON, and not an
        // error either. Every other 2xx response is expected to be JSON.
        if ($status === 204) {
            return [];
        }
        $decoded = ($body === '') ? [] : json_decode($body, true);
        if ($status < 200 || $status >= 300 || !is_array($decoded)) {
            error_log("[google-calendar] $method $path returned HTTP $status: " . substr((string)$body, 0, 500));
            // 410 Gone on a syncToken-bearing list call is a real, expected
            // signal (see listChangedEvents()'s own docblock), not a plain
            // failure -- surfaced to the caller via the status code rather
            // than swallowed into the same null every other error returns.
            return $status === 410 ? ['__status' => 410] : null;
        }
        return $decoded;
    }

    /**
     * Creates a Calendar event for a freshly-booked appointment and
     * returns its event id, or null on failure. $appointmentId/$patientName/
     * $startDateTime ('Y-m-d H:i:s')/$durationMinutes are required;
     * $phone/$amka/$email/$notes are folded into the event description
     * when present, never into extendedProperties (those are for the
     * event/appointment *link*, not clinical/contact data -- keeping PII
     * out of a field this class also reads back verbatim on every sync
     * pass is deliberate, not an oversight).
     */
    static function createEvent(
        int $appointmentId,
        string $patientName,
        string $startDateTime,
        int $durationMinutes,
        string $phone = '',
        string $amka = '',
        string $email = '',
        string $notes = ''
    ): ?string {
        if (!self::isEnabled()) {
            return null;
        }
        $config = self::getConfig();

        $start = new DateTime($startDateTime);
        $end = (clone $start)->modify("+{$durationMinutes} minutes");

        $descriptionLines = ["Ασθενής: {$patientName}"];
        if ($phone !== '') $descriptionLines[] = "Τηλέφωνο: {$phone}";
        if ($amka !== '') $descriptionLines[] = "ΑΜΚΑ: {$amka}";
        if ($email !== '') $descriptionLines[] = "Email: {$email}";
        if ($notes !== '') $descriptionLines[] = "Σημειώσεις: {$notes}";

        $event = [
            'summary' => $patientName,
            'description' => implode("\n", $descriptionLines),
            'start' => ['dateTime' => $start->format(DateTime::RFC3339)],
            'end' => ['dateTime' => $end->format(DateTime::RFC3339)],
            'extendedProperties' => [
                'private' => [GOOGLE_CALENDAR_APPOINTMENT_PROPERTY => (string)$appointmentId],
            ],
        ];

        $calendarId = rawurlencode($config['calendar_id']);
        $resp = self::request('POST', "/calendars/{$calendarId}/events", $event);
        return (is_array($resp) && !empty($resp['id'])) ? $resp['id'] : null;
    }

    /**
     * Pushes a rescheduled appointment's new time onto its already-linked
     * event. Only start/end are patched -- deliberately, the same
     * "only date/time crosses this boundary" boundary listChangedEvents()
     * enforces on the way back in.
     */
    static function updateEventTime(string $eventId, string $startDateTime, int $durationMinutes): bool {
        if (!self::isEnabled()) {
            return false;
        }
        $config = self::getConfig();

        $start = new DateTime($startDateTime);
        $end = (clone $start)->modify("+{$durationMinutes} minutes");

        $calendarId = rawurlencode($config['calendar_id']);
        $eventId = rawurlencode($eventId);
        $resp = self::request('PATCH', "/calendars/{$calendarId}/events/{$eventId}", [
            'start' => ['dateTime' => $start->format(DateTime::RFC3339)],
            'end' => ['dateTime' => $end->format(DateTime::RFC3339)],
        ]);
        return $resp !== null;
    }

    static function deleteEvent(string $eventId): bool {
        if (!self::isEnabled()) {
            return false;
        }
        $config = self::getConfig();

        $calendarId = rawurlencode($config['calendar_id']);
        $eventId = rawurlencode($eventId);
        $resp = self::request('DELETE', "/calendars/{$calendarId}/events/{$eventId}");
        return $resp !== null;
    }

    /**
     * Pulls everything that changed since the given sync token, following
     * every page (Calendar API's own pagination, via pageToken -- distinct
     * from the syncToken, which only ever appears on the *last* page of a
     * given pass). $syncToken is passed by reference: on a successful
     * call it's overwritten with the fresh token to store for next time;
     * on a `410 Gone` (Google's documented signal that a token is too old/
     * invalid to resume from -- happens if a sync hasn't run in a long
     * time, or after a token is manually cleared) it's set back to null,
     * telling the caller a full resync is needed on the next run rather
     * than silently missing whatever changed in between.
     *
     * A first-ever sync ($syncToken === null going in) scopes to events
     * starting from now, not this calendar's entire history -- there is
     * no reason to pull years-old events on day one.
     *
     * @return list<array>|null null only on a real failure (not
     *   configured, unreachable, malformed response) -- an empty array is
     *   a perfectly normal "nothing changed" result, never confused with
     *   failure.
     */
    static function listChangedEvents(?string &$syncToken): ?array {
        if (!self::isEnabled()) {
            return null;
        }
        $config = self::getConfig();
        $calendarId = rawurlencode($config['calendar_id']);

        $events = [];
        $pageToken = null;
        $nextSyncToken = null;

        do {
            $params = ['singleEvents' => 'true', 'maxResults' => '250'];
            if ($syncToken !== null) {
                $params['syncToken'] = $syncToken;
            } else {
                $params['timeMin'] = (new DateTime())->format(DateTime::RFC3339);
            }
            if ($pageToken !== null) {
                $params['pageToken'] = $pageToken;
            }

            $resp = self::request('GET', "/calendars/{$calendarId}/events?" . http_build_query($params));

            if ($resp !== null && ($resp['__status'] ?? null) === 410) {
                error_log('[google-calendar] sync token expired/invalid -- next run will do a full resync');
                $syncToken = null;
                return null;
            }
            if ($resp === null) {
                return null;
            }

            foreach ($resp['items'] ?? [] as $item) {
                $events[] = $item;
            }
            $pageToken = $resp['nextPageToken'] ?? null;
            if (isset($resp['nextSyncToken'])) {
                $nextSyncToken = $resp['nextSyncToken'];
            }
        } while ($pageToken !== null);

        if ($nextSyncToken !== null) {
            $syncToken = $nextSyncToken;
        }
        return $events;
    }
}
