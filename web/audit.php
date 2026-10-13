<?php
/**
 * GDPR audit log -- an accountability trail (Art. 5(2), Art. 32) of who did
 * what to patient data and when. Modelled on DocArc's audit log.
 *
 * What is logged: sign-ins (successful, failed, refused, lockouts) and
 * sign-outs; viewing, creating, changing and deleting a patient record;
 * creating, changing and deleting appointments; uploading, opening and
 * deleting attached files; the pending-appointment workflow; DocArc's patient
 * search; email-settings changes; changes to one's own account; user/role
 * management on /admin/*; and every change made with bin/zpmscli.php.
 * Reads are logged, not just writes -- who looked at a patient's record is
 * exactly the question an accountability trail has to answer.
 *
 * **No patient names or other free text ever go into a row.** Audit rows are
 * never deleted, so a name written here would outlive a later erasure of the
 * patient's record forever. A row therefore holds only non-identifying
 * references: patient id and guid, appointment id, the *names* of the fields
 * that changed (never their values), counts. File names are left out too
 * (staff name scans after the patient), and so are search terms.
 *
 * Two copies:
 *   - the audit_log table (web/classes/yaml/audit_log.yaml), the source of
 *     truth, shown on /audit to holders of audit-log-view;
 *   - logs/audit.log at the project root (outside the web root), one line per
 *     event, for anyone who would rather tail/grep/logrotate a file -- see
 *     deploy/zpms-logrotate.
 *
 * Writing a row must never break the action it records: every failure is
 * caught and sent to the PHP error log instead.
 */

const ZPMS_AUDIT_EVENT_LABELS = [
    'login_success' => 'Signed in',
    'login_remembered' => 'Signed in (remembered device)',
    'login_failed' => 'Failed sign-in',
    'login_refused' => 'Sign-in refused (locked or inactive account)',
    'account_locked' => 'Account locked',
    'logout' => 'Signed out',
    'patient_viewed' => 'Patient record viewed',
    'patient_created' => 'Patient record created',
    'patient_updated' => 'Patient record changed',
    'patient_deleted' => 'Patient record deleted',
    'patient_purged' => 'Patient record permanently removed',
    'patients_reassigned' => 'Records reassigned to another user',
    'patient_search_api' => 'Patient search by DocArc',
    'appointment_created' => 'Appointment created',
    'appointment_updated' => 'Appointment changed',
    'appointment_deleted' => 'Appointment deleted',
    'appointment_purged' => 'Appointment permanently removed',
    'file_uploaded' => 'File uploaded',
    'file_downloaded' => 'File opened',
    'file_deleted' => 'File deleted',
    'pending_created' => 'Pending appointment created',
    'pending_updated' => 'Pending appointment changed',
    'pending_cancelled' => 'Pending appointment cancelled',
    'pending_converted' => 'Pending appointment turned into a patient record',
    'schedule_pattern_changed' => 'Schedule: weekly pattern changed',
    'schedule_override_set' => 'Schedule: date changed',
    'schedule_override_cleared' => 'Schedule: date back to the pattern',
    'schedule_travel_changed' => 'Schedule: travel times changed',
    'slot_reserved' => 'Schedule: slot booked',
    'slot_released' => 'Schedule: slot released',
    'mail_settings_updated' => 'Email settings changed',
    'profile_updated' => 'Own account changed',
    'device_revoked' => 'Remembered device removed',
    'admin_created' => 'User management: created',
    'admin_updated' => 'User management: changed',
    'admin_deleted' => 'User management: deleted',
    'admin_user_roles_changed' => 'User management: roles changed',
    'cleanup_deleted' => 'Deleted records purged',
    'cleanup_orphans' => 'Orphaned rows removed',
];

const ZPMS_AUDIT_ACTOR_TYPES = ['staff', 'anonymous', 'api', 'cli'];

/** Where the plain-text copy goes (outside the web root). */
function zpms_audit_log_file(string $appDir): string {
    return $appDir . '/logs/audit.log';
}

/**
 * Makes a request-influenced string safe for one log line: control
 * characters (CR/LF included) removed, so a crafted username can't forge
 * extra lines; cut to $max characters.
 */
function zpms_audit_sanitize(?string $value, int $max): ?string {
    if ($value === null) return null;
    $value = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '');
    if ($value === '') return null;
    return mb_substr($value, 0, $max);
}

/**
 * Writes one event: a row in audit_log and a line in the plain-text copy.
 * $row keys (all optional): actor, actor_type, ip, patient_id, patient_guid,
 * appointment_id, target_user, detail, at (Y-m-d H:i:s; default now).
 * Usable without the framework (bin/zpmscli.php calls it with its own PDO).
 */
function zpms_audit_write(?PDO $db, string $event, array $row, ?string $mirrorFile): void {
    $actorType = in_array($row['actor_type'] ?? '', ZPMS_AUDIT_ACTOR_TYPES, true) ? $row['actor_type'] : 'staff';
    $values = [
        'guid' => function_exists('guid') ? guid() : sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0x0fff) | 0x4000,
            random_int(0, 0x3fff) | 0x8000, random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff)),
        'cdate' => $row['at'] ?? date('Y-m-d H:i:s'),
        'cuser' => zpms_audit_sanitize($row['actor'] ?? null, 32) ?? '-',
        'event_type' => mb_substr($event, 0, 48),
        'actor_type' => $actorType,
        'ip' => zpms_audit_sanitize($row['ip'] ?? null, 45),
        'patient_id' => isset($row['patient_id']) ? (int)$row['patient_id'] : null,
        'patient_guid' => zpms_audit_sanitize($row['patient_guid'] ?? null, 36),
        'appointment_id' => isset($row['appointment_id']) ? (int)$row['appointment_id'] : null,
        'target_user' => zpms_audit_sanitize($row['target_user'] ?? null, 64),
        'detail' => zpms_audit_sanitize($row['detail'] ?? null, 255),
    ];

    if ($db) {
        try {
            $st = $db->prepare('INSERT INTO audit_log (guid, cdate, cuser, event_type, actor_type, ip, patient_id, patient_guid, appointment_id, target_user, detail)
                VALUES (:guid, :cdate, :cuser, :event_type, :actor_type, :ip, :patient_id, :patient_guid, :appointment_id, :target_user, :detail)');
            $st->execute($values);
        } catch (Throwable $e) {
            error_log('audit_log insert failed (' . $event . '): ' . $e->getMessage());
        }
    }

    if ($mirrorFile) {
        try {
            $dir = dirname($mirrorFile);
            if (!is_dir($dir)) @mkdir($dir, 0770, true);
            $line = sprintf('[%s] event=%s actor=%s actor_type=%s ip=%s patient=%s appointment=%s target_user=%s detail="%s"',
                date('c', strtotime($values['cdate']) ?: time()),
                $values['event_type'], $values['cuser'], $values['actor_type'], $values['ip'] ?? '-',
                $values['patient_id'] ?? '-', $values['appointment_id'] ?? '-', $values['target_user'] ?? '-',
                str_replace('"', "'", $values['detail'] ?? ''));
            @file_put_contents($mirrorFile, $line . "\n", FILE_APPEND | LOCK_EX);
        } catch (Throwable $e) {
            error_log('audit.log write failed (' . $event . '): ' . $e->getMessage());
        }
    }
}

/**
 * Logs an event from a web request: the actor is the signed-in user (or "-"),
 * the IP the request's own. $context: patient (a patientsClass, sets id and
 * guid), patient_id, patient_guid, appointment_id, target_user, detail, and
 * actor / actor_type to override the defaults.
 */
function zpms_audit(string $event, array $context = []): void {
    global $kernel;
    try {
        $actor = $context['actor'] ?? (isset($kernel) ? $kernel->getUserName() : null);
        $row = [
            'actor' => $actor,
            'actor_type' => $context['actor_type'] ?? ($actor ? 'staff' : 'anonymous'),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            'patient_id' => $context['patient_id'] ?? null,
            'patient_guid' => $context['patient_guid'] ?? null,
            'appointment_id' => $context['appointment_id'] ?? null,
            'target_user' => $context['target_user'] ?? null,
            'detail' => $context['detail'] ?? null,
            'at' => getDBtime(),
        ];
        if (isset($context['patient']) && is_object($context['patient'])) {
            $row['patient_id'] = $context['patient']->getid();
            $row['patient_guid'] = $context['patient']->getguid();
        }
        zpms_audit_write(dbConnection::getConnection(), $event, $row, zpms_audit_log_file(__APPDIR__));
    } catch (Throwable $e) {
        error_log('zpms_audit(' . $event . ') failed: ' . $e->getMessage());
    }
}

/** "fields=pname,ptel" style detail from a list of field names; null when empty. */
function zpms_audit_fields(array $fields): ?string {
    $fields = array_values(array_unique(array_filter($fields, 'strlen')));
    return $fields ? 'fields=' . implode(',', $fields) : null;
}

/**
 * zeusfw core's hook (zeusfw_audit_event() in core/kernel/utils.php): sign-in
 * and sign-out events from core/lib/UserLogin.php and ErnsAuth, and user/role
 * changes from the /admin pages.
 */
function zeusfw_app_audit_event(string $event, array $context): void {
    $username = isset($context['username']) ? (string)$context['username'] : null;
    $detail = [];
    foreach (['method', 'reason', 'entity'] as $key) {
        if (!empty($context[$key])) $detail[] = $key . '=' . $context[$key];
    }
    if (!empty($context['entity_id'])) $detail[] = 'id=' . (int)$context['entity_id'];
    if (!empty($context['fields'])) $detail[] = 'fields=' . implode(',', (array)$context['fields']);
    if (!empty($context['added'])) $detail[] = 'added_roles=' . implode(',', array_map('intval', (array)$context['added']));
    if (!empty($context['removed'])) $detail[] = 'removed_roles=' . implode(',', array_map('intval', (array)$context['removed']));

    $row = ['detail' => $detail ? implode(' ', $detail) : null];
    switch ($event) {
        case 'login_success':
        case 'login_remembered':
        case 'logout':
            $row += ['actor' => $username, 'actor_type' => 'staff'];
            break;
        case 'login_failed':
        case 'login_refused':
        case 'account_locked':
            // Nobody is signed in; the typed or affected username is the target.
            $row += ['actor' => null, 'actor_type' => 'anonymous', 'target_user' => $username];
            break;
        default:
            $row['target_user'] = $username;
    }
    zpms_audit($event, $row);
}

/**
 * Builds the WHERE clause for the /audit page's filters (whitelisted, all
 * values bound). $filters: event, actor, patient (id), date_from / date_to
 * (Y-m-d, inclusive).
 */
function zpms_audit_filter_sql(array $filters): array {
    $where = [];
    $params = [];
    if (!empty($filters['event']) && array_key_exists($filters['event'], ZPMS_AUDIT_EVENT_LABELS)) {
        $where[] = 'event_type = :event';
        $params[':event'] = $filters['event'];
    }
    if (!empty($filters['actor'])) {
        $where[] = 'cuser = :actor';
        $params[':actor'] = (string)$filters['actor'];
    }
    if (!empty($filters['patient']) && ctype_digit((string)$filters['patient'])) {
        $where[] = 'patient_id = :patient';
        $params[':patient'] = (int)$filters['patient'];
    }
    if (!empty($filters['date_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['date_from'])) {
        $where[] = 'cdate >= :from';
        $params[':from'] = $filters['date_from'] . ' 00:00:00';
    }
    if (!empty($filters['date_to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['date_to'])) {
        $where[] = 'cdate <= :to';
        $params[':to'] = $filters['date_to'] . ' 23:59:59';
    }
    return [$where ? 'WHERE ' . implode(' AND ', $where) : '', $params];
}

/** zpms_audit() for an appointment: resolves its patient's id/guid itself. */
function zpms_audit_appointment(string $event, $appointment, ?string $detail = null): void {
    $context = ['appointment_id' => $appointment->getid(), 'patient_guid' => $appointment->getpguid(), 'detail' => $detail];
    try {
        $pat = patientsClassEx::sgetByGuid($appointment->getpguid());
        if ($pat) $context['patient_id'] = $pat->getid();
    } catch (Throwable $e) {
        // the guid alone still identifies the patient
    }
    zpms_audit($event, $context);
}

/** zpms_audit() for an attached file, by appointment id and file id. */
function zpms_audit_file(string $event, int $appointmentId, int $fileId): void {
    try {
        $ap = appointmentsClass::sgetById($appointmentId);
    } catch (Throwable $e) {
        $ap = null;
    }
    if ($ap) {
        zpms_audit_appointment($event, $ap, 'file_id=' . $fileId);
    } else {
        zpms_audit($event, ['appointment_id' => $appointmentId, 'detail' => 'file_id=' . $fileId]);
    }
}

/**
 * /audit -- the audit log, newest first, 50 per page, filtered by event,
 * actor, patient id and date range. Read-only; needs ZPMS_PERM_AUDIT_VIEW.
 */
function audit_log_view($params) {
    global $Request;

    if (($errmsg = rbacClass::require(ZPMS_PERM_AUDIT_VIEW))) return $errmsg;

    $get = isset($Request) ? $Request->getParams() : $_GET;
    $filters = [
        'event' => (string)($get['event'] ?? ''),
        'actor' => (string)($get['actor'] ?? ''),
        'patient' => (string)($get['patient'] ?? ''),
        'date_from' => (string)($get['date_from'] ?? ''),
        'date_to' => (string)($get['date_to'] ?? ''),
    ];
    $perPage = 50;
    $page = max(1, (int)($get['page'] ?? 1));

    $db = dbConnection::getConnection();
    if (!$db->query("SHOW TABLES LIKE 'audit_log'")->fetchColumn()) {
        // An install that has not created the table yet (see README).
        return Renderer::render('audit_log.zetem', ['missing_table' => true, 'rows' => [], 'filters' => $filters,
            'events' => [], 'actors' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'prev_url' => null, 'next_url' => null, 'filtered' => false]);
    }
    [$whereSql, $params] = zpms_audit_filter_sql($filters);

    $count = $db->prepare("SELECT COUNT(*) FROM audit_log $whereSql");
    $count->execute($params);
    $total = (int)$count->fetchColumn();
    $pages = max(1, (int)ceil($total / $perPage));
    $page = min($page, $pages);

    $st = $db->prepare("SELECT * FROM audit_log $whereSql ORDER BY id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage));
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    // Which of the referenced patients still exist (and are not deleted), so
    // only those get a link -- a purged patient shows just its id.
    $patientIds = array_values(array_unique(array_filter(array_map(fn($r) => (int)$r['patient_id'], $rows))));
    $livePatients = [];
    if ($patientIds) {
        $in = implode(',', array_fill(0, count($patientIds), '?'));
        $p = $db->prepare("SELECT id FROM patients WHERE deleted IS NULL AND id IN ($in)");
        $p->execute($patientIds);
        $livePatients = array_flip(array_map('intval', $p->fetchAll(PDO::FETCH_COLUMN)));
    }

    $events = [];
    foreach (ZPMS_AUDIT_EVENT_LABELS as $key => $label) {
        $events[$key] = t($label);
    }
    $actorTypes = ['staff' => t('Staff'), 'anonymous' => t('Not signed in'), 'api' => t('Another application'), 'cli' => t('Command line')];

    $display = [];
    foreach ($rows as $r) {
        $pid = $r['patient_id'] !== null ? (int)$r['patient_id'] : null;
        $display[] = [
            'when' => $r['cdate'] ? date('d-m-Y H:i:s', strtotime($r['cdate'])) : '',
            'event' => $events[$r['event_type']] ?? $r['event_type'],
            'event_type' => $r['event_type'],
            'actor' => $r['cuser'],
            'actor_type' => $actorTypes[$r['actor_type']] ?? $r['actor_type'],
            'ip' => $r['ip'] ?? '',
            'patient_id' => $pid,
            'patient_url' => ($pid && isset($livePatients[$pid])) ? rel_url('/patient/' . $pid . '/edit') : null,
            'appointment_id' => $r['appointment_id'] !== null ? (int)$r['appointment_id'] : null,
            'target_user' => $r['target_user'] ?? '',
            'detail' => $r['detail'] ?? '',
        ];
    }

    $actors = $db->query('SELECT DISTINCT cuser FROM audit_log ORDER BY cuser')->fetchAll(PDO::FETCH_COLUMN);

    $query = array_filter($filters, fn($v) => $v !== '');
    $pageUrl = fn(int $n) => rel_url('/audit') . '?' . http_build_query($query + ['page' => $n]);

    return Renderer::render('audit_log.zetem', [
        'rows' => $display,
        'filters' => $filters,
        'events' => $events,
        'actors' => $actors,
        'total' => $total,
        'page' => $page,
        'pages' => $pages,
        'prev_url' => $page > 1 ? $pageUrl($page - 1) : null,
        'next_url' => $page < $pages ? $pageUrl($page + 1) : null,
        'filtered' => !empty($query),
        'missing_table' => false,
    ]);
}
