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
/**
 * Server-to-server key check shared by the patient API endpoints. $allowed lists the callers that may
 * use the endpoint ('docarc' = X-DocArc-Api-Key / DOCARC_API_KEY, 'medscribe' = X-MedScribe-Api-Key /
 * MEDSCRIBE_API_KEY). Returns the caller's name, or answers and exits:
 *   503 {"error":"unavailable"}   none of the allowed callers has a key configured
 *   401 {"error":"unauthorized"}  no key, or a key that matches none (logged with REMOTE_ADDR)
 * Every comparison uses hash_equals(); a caller whose key is not configured can never match.
 */
function zpms_api_authenticate(array $allowed): string {
    $callers = [
        'docarc'    => ['const' => 'DOCARC_API_KEY',    'header' => 'HTTP_X_DOCARC_API_KEY'],
        'medscribe' => ['const' => 'MEDSCRIBE_API_KEY', 'header' => 'HTTP_X_MEDSCRIBE_API_KEY'],
    ];
    $configured = false;
    foreach ($allowed as $name) {
        $c = $callers[$name];
        if (!defined($c['const']) || constant($c['const']) === '') continue;
        $configured = true;
        $presented = $_SERVER[$c['header']] ?? '';
        if (is_string($presented) && $presented !== '' && hash_equals(constant($c['const']), $presented)) {
            return $name;
        }
    }
    if (!$configured) {
        http_response_code(503);
        echo json_encode(['error' => 'unavailable']);
        exit();
    }
    error_log('[patient-api] unauthorized request from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit();
}

/**
 * Link target of the patient page's "New document" button, or null when MedScribe is not configured.
 */
function zpms_medscribe_new_document_url(string $guid): ?string {
    if (!defined('MEDSCRIBE_BASE_URL') || MEDSCRIBE_BASE_URL === '' || $guid === '') return null;
    return rtrim(MEDSCRIBE_BASE_URL, '/') . '/documents/new?patient=' . rawurlencode($guid);
}

/**
 * GET /api/v1/patients/{guid}: one patient for MedScribe's documents. MedScribe's key only.
 * Returns {"patient":{"guid","name","dob" (YYYY-MM-DD or null),"gender" ("M"|"F"|null),"amka"}}.
 * Minimal disclosure: no phone, address, e-mail or note; add a field here only when a document type
 * needs it. 404 for an unknown or soft-deleted patient. The audit entry records who asked, never the
 * patient.
 */
function medscribe_patient_get_api($params) {
    header('Content-Type: application/json');
    $actor = zpms_api_authenticate(['medscribe']);

    $guid = (string)($params['guid'] ?? '');
    if (!preg_match('/^[0-9a-fA-F-]{36}$/', $guid)) {
        http_response_code(400);
        echo json_encode(['error' => 'bad guid']);
        exit();
    }
    $p = patientsClassEx::sgetByGuid($guid);
    if (!$p || $p->getdeleted()) {
        zpms_audit('patient_get_api', ['actor' => $actor, 'actor_type' => 'api', 'detail' => 'found=0']);
        http_response_code(404);
        echo json_encode(['error' => 'not found']);
        exit();
    }
    $dob = $p->getpdob();
    $gender = $p->getpgender();
    zpms_audit('patient_get_api', ['actor' => $actor, 'actor_type' => 'api', 'detail' => 'found=1']);
    echo json_encode(['patient' => [
        'guid'   => $p->getguid(),
        'name'   => $p->getpname(),
        'dob'    => $dob ? substr((string)$dob, 0, 10) : null,
        'gender' => in_array($gender, ['M', 'F'], true) ? $gender : null,
        'amka'   => $p->getpamka() ?: null,
    ]], JSON_UNESCAPED_UNICODE);
    exit();
}

function docarc_patients_search_api($params) {
    header('Content-Type: application/json');

    // DocArc's key (X-DocArc-Api-Key) or MedScribe's (X-MedScribe-Api-Key); $actor is whoever matched.
    $actor = zpms_api_authenticate(['docarc', 'medscribe']);

    if (!function_exists('mb_strlen')) {
        error_log('[docarc-api] mbstring extension not available -- patient search request rejected');
        http_response_code(503);
        echo json_encode(['error' => 'unavailable']);
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

    error_log('[' . $actor . '-api] patient search ok: ' . count($matches)
        . ' match(es), term_len=' . mb_strlen($term)
        . ', from=' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

    // Who asked and how many matched -- never the search term or the names.
    zpms_audit('patient_search_api', ['actor' => $actor, 'actor_type' => 'api', 'detail' => 'matches=' . count($matches)]);

    echo json_encode(['matches' => $matches]);
    exit();
}
