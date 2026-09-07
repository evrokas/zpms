<?php

/**
 * Client for APYweb's read-only financial-lookup endpoint
 * (api_financial.php) -- shows a read-only financial-info block on the
 * patient page when that patient has any matching invoice/operation/fee-
 * report history in APYweb, a separate accounting app for the same
 * doctor's practice. Modeled directly on DocArc's own
 * includes/zpms_client.php (that integration's client side, roles
 * reversed -- see APYweb's CLAUDE.md, "Link to ZPMS", for the served
 * side), and on zpms's own web/api_patients.php for the request/response
 * auth shape.
 *
 * Matching is by patient name only -- there is no shared ID between the
 * two apps (APYweb's private-patient ΑΦΜ is usually blank, same reasoning
 * DocArc's own ZPMS integration documents for its own name-only lookup).
 * This is a passive block on a page the user didn't ask to search, so
 * unlike docarc_zpms_search_patients() there is no user-facing $error --
 * any failure (disabled, unreachable, slow, malformed response) just
 * means the block doesn't render; failures are error_log()'d only.
 */

define('ZPMS_APYWEB_TIMEOUT_SECONDS', 3);

function zpms_apyweb_enabled(): bool {
    return defined('APYWEB_API_URL') && APYWEB_API_URL !== ''
        && defined('APYWEB_API_KEY') && APYWEB_API_KEY !== '';
}

/**
 * @return array{invoices: list<array>, operations: list<array>, fee_reports: list<array>}|null
 */
function zpms_apyweb_fetch_financials(string $patientName): ?array {
    if (!zpms_apyweb_enabled() || !function_exists('curl_init')) {
        return null;
    }

    $patientName = trim($patientName);
    if ($patientName === '') {
        return null;
    }

    $url = rtrim(APYWEB_API_URL, '/') . '/api_financial.php?name=' . urlencode($patientName);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['X-ZPMS-Api-Key: ' . APYWEB_API_KEY],
        CURLOPT_TIMEOUT => ZPMS_APYWEB_TIMEOUT_SECONDS,
        CURLOPT_CONNECTTIMEOUT => ZPMS_APYWEB_TIMEOUT_SECONDS,
        CURLOPT_FAILONERROR => false,
    ]);
    $body = curl_exec($ch);
    if ($body === false) {
        error_log('[apyweb-client] could not reach APYweb: ' . curl_error($ch));
        curl_close($ch);
        return null;
    }
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status !== 200) {
        error_log("[apyweb-client] APYweb returned an unexpected status ({$status})");
        return null;
    }

    $decoded = json_decode((string)$body, true);
    if (!is_array($decoded)
        || !isset($decoded['invoices'], $decoded['operations'], $decoded['fee_reports'])
        || !is_array($decoded['invoices'])
        || !is_array($decoded['operations'])
        || !is_array($decoded['fee_reports'])
    ) {
        error_log('[apyweb-client] APYweb returned an unexpected response shape');
        return null;
    }

    // Never trust the remote shape blindly -- keep only rows that are
    // themselves arrays, same rule docarc_zpms_search_patients() follows.
    $result = [];
    foreach (['invoices', 'operations', 'fee_reports'] as $key) {
        $result[$key] = array_values(array_filter($decoded[$key], 'is_array'));
    }

    return $result;
}
