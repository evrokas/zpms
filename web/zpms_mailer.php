<?php

/**
 * SMTP mailer for the appointment-assignment notification email -- see
 * README.md, "Appointment email notifications". Same manually-vendored
 * PHPMailer + settings-table pattern as apyweb's own mailer (evrokas/apyweb,
 * src/handlers/fee_sync.php) and DocArc's docarc_build_mailer()
 * (evrokas/docarc, includes/mailer.php): every failure mode here degrades
 * to a logged warning, never a fatal error or a thrown exception a caller
 * has to remember to catch -- a booking that already happened over the
 * phone must be saved regardless of whether the notification email
 * actually goes out.
 */

/**
 * True once the three PHPMailer files this app actually uses (the SMTP
 * send path only -- no OAuth2/POP-before-SMTP/DSN files) are present
 * under lib/phpmailer/ (git-ignored -- see README.md for the clone
 * command, same "manually vendored, not Composer" convention as
 * lib/ernsauth).
 */
function zpms_phpmailer_available(): bool {
    foreach (['Exception.php', 'PHPMailer.php', 'SMTP.php'] as $f) {
        if (!is_file(__APPDIR__ . "/lib/phpmailer/src/$f")) {
            return false;
        }
    }
    return true;
}

/**
 * The one mail_settings row (id=1), or null if the table doesn't exist
 * yet (an un-migrated database -- see bin/migrate_appointment_email.php)
 * or has no row at all (shouldn't happen once migrated, but a raw DELETE
 * by hand is always possible) -- either way, treated as "not configured"
 * rather than a fatal error.
 */
function zpms_mail_settings(): ?mailSettingsClass {
    try {
        return mailSettingsClass::sgetById(1);
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * Builds a ready-to-send PHPMailer instance from the stored SMTP
 * settings, or returns a friendly Greek error string on any failure --
 * never throws. Checked in order, same as docarc_build_mailer(): the
 * settings row exists and has at least a host + from address configured,
 * then that PHPMailer is actually vendored.
 *
 * @return \PHPMailer\PHPMailer\PHPMailer|string
 */
function zpms_build_mailer() {
    // t() needs the running web app; bin/sync_google_calendar.php sends mail
    // without one, so the English text stands there.
    $say = fn(string $text) => isset($GLOBALS['kernel']) ? t($text) : $text;
    $settings = zpms_mail_settings();
    if (!$settings || !$settings->getsmtp_host() || !$settings->getfrom_email()) {
        return $say('The SMTP details have not been set up. Go to Settings -> Email.');
    }
    if (!zpms_phpmailer_available()) {
        return $say('The PHPMailer library is not installed (lib/phpmailer) -- see README.md.');
    }

    require_once __APPDIR__ . '/lib/phpmailer/src/Exception.php';
    require_once __APPDIR__ . '/lib/phpmailer/src/PHPMailer.php';
    require_once __APPDIR__ . '/lib/phpmailer/src/SMTP.php';

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    $mail->CharSet = 'UTF-8';
    $mail->isSMTP();
    $mail->Host = $settings->getsmtp_host();
    $mail->Port = (int)($settings->getsmtp_port() ?: 587);
    $mail->SMTPAuth = (bool)$settings->getsmtp_username();
    if ($mail->SMTPAuth) {
        $mail->Username = $settings->getsmtp_username();
        $mail->Password = (string)$settings->getsmtp_password();
    }

    // No SQL-level default for smtp_encryption (see mail_settings.yaml's
    // own comment) -- an unset/blank value falls back to 'tls' here,
    // same "blank = fall back to a sensible default" convention this app
    // already uses elsewhere.
    $encryption = $settings->getsmtp_encryption() ?: 'tls';
    if ($encryption === 'ssl') {
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
    } elseif ($encryption === 'none') {
        $mail->SMTPSecure = '';
        $mail->SMTPAutoTLS = false;
    } else {
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    }

    $mail->setFrom($settings->getfrom_email(), $settings->getfrom_name() ?: '');

    return $mail;
}

/**
 * Renders web/templates/email/consultation_assigned.zetem via
 * Renderer::render() -- a plain, standalone template with no page chrome
 * (Renderer::render() just compiles+executes the one file, it has no
 * knowledge of "regions"/main.zetem; that composition only happens inside
 * Kernel::renderPage(), which this never goes through) -- and emails it to
 * the assigned doctor. Never throws and never blocks the caller: a
 * booking that already happened over the phone must be saved regardless
 * of whether this notification succeeds. Returns true on success, false
 * (with the reason logged via error_log()) otherwise.
 */
function zpms_send_appointment_assignment_email(pendingAppointmentsClass $pending, array $doctor): bool {
    if (empty($doctor['email'])) {
        error_log('zpms_send_appointment_assignment_email: doctor #' . ($doctor['id'] ?? '?') . ' has no email on file -- skipped');
        return false;
    }

    $mail = zpms_build_mailer();
    if (!is_object($mail)) {
        error_log('zpms_send_appointment_assignment_email: ' . $mail);
        return false;
    }

    try {
        $mail->addAddress($doctor['email'], $doctor['name'] ?: $doctor['uname']);
        $mail->isHTML(true);
        $mail->Subject = 'Νέο Ραντεβού: ' . $pending->getpatient_name();
        $mail->Body = Renderer::render('consultation_assigned.zetem', [
            'doctor_name' => $doctor['name'] ?: $doctor['uname'],
            'patient_name' => $pending->getpatient_name(),
            'patient_phone' => $pending->getpatient_phone(),
            'appointment_date' => date('d/m/Y', strtotime($pending->getappointment_datetime())),
            'appointment_time' => date('H:i', strtotime($pending->getappointment_datetime())),
            'location' => $pending->getlocation(),
            'notes' => $pending->getnotes(),
        ]);
        $mail->AltBody = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $mail->Body)));
        $mail->send();
        return true;
    } catch (Exception $e) {
        // PHPMailer\PHPMailer\Exception extends the global \Exception, so
        // this also catches that -- same reasoning apyweb's own mailer
        // code already documents.
        error_log('zpms_send_appointment_assignment_email: send failed -- ' . $e->getMessage());
        return false;
    }
}


/** True when a pending appointment's date or time (to the minute) really changed. */
function zpms_pending_time_changed(?string $old, ?string $new): bool {
    $a = $old ? strtotime($old) : false;
    $b = $new ? strtotime($new) : false;
    return $a !== false && $b !== false && date('Y-m-d H:i', $a) !== date('Y-m-d H:i', $b);
}

/** What the "time changed" email shows: the new and the previous time, and the booking's details. */
function zpms_reschedule_email_vars(pendingAppointmentsClass $pending, array $doctor, string $oldDatetime, string $source = ''): array {
    $new = strtotime($pending->getappointment_datetime());
    $old = strtotime($oldDatetime);
    $remote = '';
    if ($pending->getremote_platform() && function_exists('zpms_remote_label')) {
        $remote = zpms_remote_label($pending->getremote_platform()) . ': ' . $pending->getremote_contact()
            . ($pending->getremote_code() ? ' (' . $pending->getremote_code() . ')' : '');
    }
    return [
        'doctor_name' => $doctor['name'] ?: $doctor['uname'],
        'patient_name' => $pending->getpatient_name(),
        'patient_phone' => $pending->getpatient_phone(),
        'new_date' => date('d/m/Y', $new),
        'new_time' => date('H:i', $new),
        'old_date' => date('d/m/Y', $old),
        'old_time' => date('H:i', $old),
        'location' => $pending->getlocation(),
        'remote' => $remote,
        'notes' => $pending->getnotes(),
        'source' => $source,
    ];
}

/**
 * The account a pending appointment is assigned to, as the mailer needs it
 * (id, uname, name, email), or null when none is assigned or it is no longer
 * an active doctor account. Plain SQL, so the Calendar sync script can use it.
 */
function zpms_assigned_doctor_for(pendingAppointmentsClass $pending): ?array {
    $id = (int)$pending->getassigned_user_id();
    if (!$id) {
        return null;
    }
    $st = dbConnection::getConnection()->prepare(
        "SELECT u.id, u.uname, u.name, u.email
           FROM users u
           JOIN user_roles ur ON ur.user_id = u.id
           JOIN roles r ON r.id = ur.role_id AND r.name = 'doctor'
          WHERE u.id = ? AND u.active = 1 AND u.expired = 0
          LIMIT 1"
    );
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ? ['id' => (int)$row['id'], 'uname' => $row['uname'], 'name' => $row['name'], 'email' => $row['email']] : null;
}

/**
 * Tells the assigned doctor that a pending appointment was moved to another
 * date or time (web/templates/email/consultation_rescheduled.zetem). Same
 * rules as zpms_send_appointment_assignment_email(): never throws, never
 * blocks the save, true on success. $mail lets a test pass a stand-in mailer.
 */
function zpms_send_appointment_rescheduled_email(pendingAppointmentsClass $pending, array $doctor, string $oldDatetime, $mail = null, string $source = ''): bool {
    if (empty($doctor['email'])) {
        error_log('zpms_send_appointment_rescheduled_email: doctor #' . ($doctor['id'] ?? '?') . ' has no email on file -- skipped');
        return false;
    }
    $mail = $mail ?? zpms_build_mailer();
    if (!is_object($mail)) {
        error_log('zpms_send_appointment_rescheduled_email: ' . $mail);
        return false;
    }
    try {
        $vars = zpms_reschedule_email_vars($pending, $doctor, $oldDatetime, $source);
        $mail->addAddress($doctor['email'], $vars['doctor_name']);
        $mail->isHTML(true);
        $mail->Subject = 'Αλλαγή ώρας ραντεβού: ' . $pending->getpatient_name();
        $mail->Body = Renderer::render('consultation_rescheduled.zetem', $vars);
        $mail->AltBody = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $mail->Body)));
        $mail->send();
        return true;
    } catch (Throwable $e) {
        error_log('zpms_send_appointment_rescheduled_email: send failed -- ' . $e->getMessage());
        return false;
    }
}
