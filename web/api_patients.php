<?php

/**
 * External, API-key-authenticated patient search for DocArc's typeahead
 * integration (see DocArc's CLAUDE.md, "Patient lookup (ZPMS
 * integration)"). Deliberately NOT SecurityClass/session-gated -- the
 * caller is another server on the same private network, not a logged-in
 * browser -- see the DOCARC_API_KEY check below instead. The
 * patients_search_api route has no `access:` key in
 * config/settings.info.yaml (same pattern already used by
 * patients_search_post), so Router.php does zero automatic gating and
 * this function is the sole guard.
 *
 * Reuses the exact same patientsClassEx::search() the session-gated
 * /patients/searchajax/{term} page already uses (web/index.php,
 * patients_list_search_ajax()) rather than duplicating search logic --
 * only the transport/auth and the response shape (name + amka + guid
 * only) differ, since this response crosses a different trust boundary.
 */
function docarc_patients_search_api($params) {
    header('Content-Type: application/json');

    if (!defined('DOCARC_API_KEY') || DOCARC_API_KEY === '') {
        http_response_code(503);
        echo json_encode(['error' => 'unavailable']);
        exit();
    }

    $presented = $_SERVER['HTTP_X_DOCARC_API_KEY'] ?? '';
    if (!is_string($presented) || $presented === '' || !hash_equals(DOCARC_API_KEY, $presented)) {
        error_log('[docarc-api] unauthorized patient search attempt from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        http_response_code(401);
        echo json_encode(['error' => 'unauthorized']);
        exit();
    }

    // urldecode() mirrors the existing patients_list_search handler
    // (index.php) -- this router's {term} tokens arrive un-decoded.
    $term = trim(urldecode((string)($params['term'] ?? '')));
    if (mb_strlen($term) < 2) {
        http_response_code(400);
        echo json_encode(['error' => 'term too short']);
        exit();
    }

    // Scope limited to pname/pamka -- no reason to search or expose ptel
    // here. excludeDeleted=true and limit=10 are the new
    // patientsClassEx::search() params.
    $patients = patientsClassEx::search($term, ['pname', 'pamka'], true, 10);

    $matches = [];
    foreach ((array)$patients as $p) {
        $matches[] = [
            'guid' => $p->getguid(),
            'name' => $p->getpname(),
            'amka' => $p->getpamka(),
            // Deliberately NOT included: id (raw sequential PK), ptel,
            // paddr, pemail, pdob, pnote -- see PII scope decision in
            // DocArc's CLAUDE.md.
        ];
    }

    error_log('[docarc-api] patient search ok: ' . count($matches)
        . ' match(es), term_len=' . mb_strlen($term)
        . ', from=' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

    echo json_encode(['matches' => $matches]);
    exit();
}
