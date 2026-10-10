<?php
/* bin/zpmscli.php's logic (bin/lib/zpms_cli.php) against the test database
 * and a throwaway attachment folder -- no web server involved. Every test
 * builds its own records and removes nothing it did not create, so the tests
 * do not depend on each other or on what other suites left behind. */

require_once __DIR__ . '/../../bin/lib/zpms_cli.php';

function zpms_cli_test_db(): PDO {
    return dbConnection::getConnection();
}

function zpms_cli_test_user(string $uname): void {
    $db = zpms_cli_test_db();
    if ((int)$db->query('SELECT COUNT(*) FROM users WHERE uname = ' . $db->quote($uname))->fetchColumn() === 0) {
        (new usersClass([
            'name' => $uname, 'email' => "$uname@example.invalid", 'uname' => $uname,
            'upass' => hash('sha256', 'unused'), 'active' => 1, 'expired' => 0, 'wrongpasscount' => 0, 'roles' => 'doctor',
        ]))->insert();
    }
}

/** A patient with one appointment that has one attachment (a real file) and one history row. */
function zpms_cli_test_patient(string $filesDir, string $cuser, string $name): array {
    $db = zpms_cli_test_db();
    $pguid = guid();
    $db->prepare('INSERT INTO patients (guid, cdate, cuser, pname) VALUES (?,?,?,?)')->execute([$pguid, getDBtime(), $cuser, $name]);
    $patientId = (int)$db->lastInsertId();

    $db->prepare('INSERT INTO appointments (guid, cdate, cuser, pguid, adate, aplace, anote, atype) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([guid(), getDBtime(), $cuser, $pguid, '2024-03-04 10:00:00', 'Athens', 'note', 'appointment']);
    $appointmentId = (int)$db->lastInsertId();

    $relative = "patient_$patientId/2024-03-04/scan.pdf";
    @mkdir(dirname("$filesDir/$relative"), 0777, true);
    file_put_contents("$filesDir/$relative", 'pdf-bytes');
    $thumb = "patient_$patientId/2024-03-04/thumb.jpg";
    file_put_contents("$filesDir/$thumb", 'jpg');
    $db->prepare('INSERT INTO appointment_files (guid, cdate, cuser, appointment_id, file_name, file_path, file_size, mime_type, file_hash, thumbnail_path) VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([guid(), getDBtime(), $cuser, $appointmentId, 'scan.pdf', $relative, 9, 'application/pdf', str_repeat('a', 64), $thumb]);
    $fileId = (int)$db->lastInsertId();

    $db->prepare('INSERT INTO appointment_history (guid, cdate, cuser, appointment_id, last_change_at, changed_fields, change_count) VALUES (?,?,?,?,?,?,1)')
        ->execute([guid(), getDBtime(), $cuser, $appointmentId, getDBtime(), 'appointment-notes']);

    return ['id' => $patientId, 'guid' => $pguid, 'appointment' => $appointmentId, 'file' => $fileId, 'path' => "$filesDir/$relative", 'dir' => "$filesDir/patient_$patientId"];
}

function zpms_cli_test_count(string $sql): int {
    return (int)zpms_cli_test_db()->query($sql)->fetchColumn();
}

function zpms_cli_test_files_dir(): string {
    $dir = sys_get_temp_dir() . '/zpmscli_test_' . getmypid() . '_' . bin2hex(random_bytes(3)) . '/appointment_files';
    mkdir($dir, 0777, true);
    return $dir;
}

function zpmscli_functional_tests(TestRunner $runner): void {
    $runner->add('argv parsing: options may sit before or after the command', function () {
        [$command, $args, $options] = zpmscli_parse_argv(['zpmscli.php', '--to=anna', 'patients:reassign', '12', '--yes', '--only=files,history', '13']);
        assert_equal('patients:reassign', $command, 'wrong command');
        assert_equal(['12', '13'], $args, 'wrong positional arguments');
        assert_equal('anna', $options['to'], '--to= not read');
        assert_equal(true, $options['yes'], 'bare --yes not read');
        assert_equal(['files', 'history'], zpmscli_split_list($options['only']), 'list option not split');
    });

    $runner->add('reassign: a dry run writes nothing, --yes changes only the named user\'s rows', function () {
        TestSchema::assertSafeToMutate();
        $dir = zpms_cli_test_files_dir();
        zpms_cli_test_user('cli_from'); zpms_cli_test_user('cli_to'); zpms_cli_test_user('cli_other');
        $mine = zpms_cli_test_patient($dir, 'cli_from', 'Reassign Mine');
        $db = zpms_cli_test_db();
        // Someone else added an appointment to the same patient.
        $db->prepare('INSERT INTO appointments (guid, cdate, cuser, pguid, adate, atype) VALUES (?,?,?,?,?,?)')
            ->execute([guid(), getDBtime(), 'cli_other', $mine['guid'], '2024-05-05 09:00:00', 'operation']);

        $dry = zpmscli_reassign($db, 'cli_from', 'cli_to', [(string)$mine['id']], [], false);
        assert_equal(1, $dry['patients'], 'dry run should count the patient');
        assert_equal(1, zpms_cli_test_count("SELECT COUNT(*) FROM patients WHERE id = {$mine['id']} AND cuser = 'cli_from'"), 'a dry run changed a row');

        $done = zpmscli_reassign($db, 'cli_from', 'cli_to', [(string)$mine['id']], [], true);
        assert_equal(['patients' => 1, 'appointments' => 1, 'files' => 1, 'history' => 1, 'operations' => $done['operations']], $done, 'wrong counts');
        assert_equal(1, zpms_cli_test_count("SELECT COUNT(*) FROM patients WHERE id = {$mine['id']} AND cuser = 'cli_to'"), 'patient not reassigned');
        assert_equal(1, zpms_cli_test_count("SELECT COUNT(*) FROM appointment_files WHERE id = {$mine['file']} AND cuser = 'cli_to'"), 'attachment not reassigned');
        assert_equal(1, zpms_cli_test_count("SELECT COUNT(*) FROM appointments WHERE pguid = '{$mine['guid']}' AND cuser = 'cli_other'"), "another user's appointment was reassigned");
    });

    $runner->add('reassign: --only narrows it, --from alone covers everything that user created, bad requests are refused', function () {
        TestSchema::assertSafeToMutate();
        $dir = zpms_cli_test_files_dir();
        zpms_cli_test_user('cli_a'); zpms_cli_test_user('cli_b');
        $p = zpms_cli_test_patient($dir, 'cli_a', 'Reassign Only');
        $db = zpms_cli_test_db();

        zpmscli_reassign($db, 'cli_a', 'cli_b', [(string)$p['id']], ['files'], true);
        assert_equal(1, zpms_cli_test_count("SELECT COUNT(*) FROM appointment_files WHERE id = {$p['file']} AND cuser = 'cli_b'"), '--only=files did not change the file');
        assert_equal(1, zpms_cli_test_count("SELECT COUNT(*) FROM patients WHERE id = {$p['id']} AND cuser = 'cli_a'"), '--only=files changed the patient');

        $all = zpmscli_reassign($db, 'cli_a', 'cli_b', [], [], true);
        assert_true($all['patients'] >= 1, '--from alone should find the patient');
        assert_equal(0, zpms_cli_test_count("SELECT COUNT(*) FROM patients WHERE id = {$p['id']} AND cuser = 'cli_a'"), '--from alone left the patient behind');

        foreach ([
            [fn() => zpmscli_reassign($db, null, 'cli_b', [], [], true), 'no selection'],
            [fn() => zpmscli_reassign($db, 'cli_b', 'cli_b', [], [], true), 'same user'],
            [fn() => zpmscli_reassign($db, 'cli_a', 'nobody_here', [], [], true), 'unknown user'],
            [fn() => zpmscli_reassign($db, 'cli_a', 'cli_b', [], ['bogus'], true), 'unknown scope'],
        ] as [$call, $label]) {
            $refused = false;
            try { $call(); } catch (InvalidArgumentException $e) { $refused = true; }
            assert_true($refused, "reassign did not refuse: $label");
        }
        zpmscli_reassign($db, 'cli_b', 'forced_user', [(string)$p['id']], ['patients'], true, true);
        assert_equal(1, zpms_cli_test_count("SELECT COUNT(*) FROM patients WHERE id = {$p['id']} AND cuser = 'forced_user'"), '--force did not allow an unknown user');
    });

    $runner->add('soft delete: one shared time for the patient and its appointments, nothing removed, dry run writes nothing', function () {
        TestSchema::assertSafeToMutate();
        $dir = zpms_cli_test_files_dir();
        $p = zpms_cli_test_patient($dir, 'cli_a', 'Soft Delete');
        $db = zpms_cli_test_db();
        $patients = zpmscli_find_patients($db, [(string)$p['id']]);

        $dry = zpmscli_soft_delete_patients($db, $patients, '2025-01-02 03:04:05', false);
        assert_equal(['patients' => 1, 'appointments' => 1], $dry, 'wrong dry-run counts');
        assert_equal(0, zpms_cli_test_count("SELECT COUNT(*) FROM patients WHERE id = {$p['id']} AND deleted IS NOT NULL"), 'a dry run deleted the patient');

        zpmscli_soft_delete_patients($db, $patients, '2025-01-02 03:04:05', true);
        assert_equal(1, zpms_cli_test_count("SELECT COUNT(*) FROM patients WHERE id = {$p['id']} AND deleted = '2025-01-02 03:04:05'"), 'patient not marked deleted');
        assert_equal(1, zpms_cli_test_count("SELECT COUNT(*) FROM appointments WHERE pguid = '{$p['guid']}' AND deleted = '2025-01-02 03:04:05'"), 'appointment not given the same time');
        assert_true(is_file($p['path']), 'a soft delete removed a file');

        // Running it again must not move the original time.
        zpmscli_soft_delete_patients($db, zpmscli_find_patients($db, [(string)$p['id']]), '2026-06-06 06:06:06', true);
        assert_equal(1, zpms_cli_test_count("SELECT COUNT(*) FROM patients WHERE id = {$p['id']} AND deleted = '2025-01-02 03:04:05'"), 'a second delete changed the deleted time');
    });

    $runner->add('purge: removes the patient, appointments, attachments, history and files; leaves other patients and unrelated files alone', function () {
        TestSchema::assertSafeToMutate();
        $dir = zpms_cli_test_files_dir();
        $gone = zpms_cli_test_patient($dir, 'cli_a', 'Purge Me');
        $kept = zpms_cli_test_patient($dir, 'cli_a', 'Keep Me');
        $db = zpms_cli_test_db();
        $db->prepare('INSERT INTO pending_appointments (guid, cuser, patient_name, converted_patient_id, converted_appointment_id) VALUES (?,?,?,?,?)')
            ->execute([guid(), 'cli_a', 'Pending', $gone['id'], $gone['appointment']]);
        $pendingId = (int)$db->lastInsertId();

        $dry = zpmscli_purge_patients($db, zpmscli_find_patients($db, [(string)$gone['id']]), $dir, false);
        assert_equal(1, $dry['patients'], 'dry-run patient count'); assert_equal(1, $dry['attachments'], 'dry-run attachment count');
        assert_equal(2, $dry['files'], 'dry run should find the file and its thumbnail');
        assert_true(is_file($gone['path']), 'a dry run removed a file');
        assert_equal(1, zpms_cli_test_count("SELECT COUNT(*) FROM patients WHERE id = {$gone['id']}"), 'a dry run removed the patient');

        $report = zpmscli_purge_patients($db, zpmscli_find_patients($db, [(string)$gone['id']]), $dir, true);
        assert_equal(1, $report['pending_unlinked'], 'pending link not counted');
        assert_equal(0, zpms_cli_test_count("SELECT COUNT(*) FROM patients WHERE id = {$gone['id']}"), 'patient still there');
        assert_equal(0, zpms_cli_test_count("SELECT COUNT(*) FROM appointments WHERE pguid = '{$gone['guid']}'"), 'appointment still there');
        assert_equal(0, zpms_cli_test_count("SELECT COUNT(*) FROM appointment_files WHERE id = {$gone['file']}"), 'attachment row still there');
        assert_equal(0, zpms_cli_test_count("SELECT COUNT(*) FROM appointment_history WHERE appointment_id = {$gone['appointment']}"), 'history still there');
        assert_true(!is_file($gone['path']), 'the file is still on disk');
        assert_true(!is_dir($gone['dir']), "the patient's empty folder was left behind");
        assert_equal(1, zpms_cli_test_count("SELECT COUNT(*) FROM pending_appointments WHERE id = $pendingId AND converted_patient_id IS NULL AND converted_appointment_id IS NULL"), 'pending appointment still points at the purged patient');

        assert_equal(1, zpms_cli_test_count("SELECT COUNT(*) FROM patients WHERE id = {$kept['id']}"), 'another patient was removed');
        assert_true(is_file($kept['path']), "another patient's file was removed");
        assert_true(is_dir($dir), 'the attachment folder itself was removed');
    });

    $runner->add('purge: a damaged row cannot make it delete a file outside the attachment folder', function () {
        TestSchema::assertSafeToMutate();
        $dir = zpms_cli_test_files_dir();
        $p = zpms_cli_test_patient($dir, 'cli_a', 'Escape Attempt');
        $outside = dirname($dir) . '/outside.txt';
        file_put_contents($outside, 'do not delete');
        $db = zpms_cli_test_db();
        $db->prepare('UPDATE appointment_files SET file_path = ? WHERE id = ?')->execute(['../outside.txt', $p['file']]);

        zpmscli_purge_patients($db, zpmscli_find_patients($db, [(string)$p['id']]), $dir, true);
        assert_true(is_file($outside), 'a file outside the attachment folder was deleted');
        assert_equal(0, zpms_cli_test_count("SELECT COUNT(*) FROM patients WHERE id = {$p['id']}"), 'the rows should still be purged');
    });

    $runner->add('appointments: soft delete then purge one appointment, the patient stays', function () {
        TestSchema::assertSafeToMutate();
        $dir = zpms_cli_test_files_dir();
        $p = zpms_cli_test_patient($dir, 'cli_a', 'One Appointment');
        $db = zpms_cli_test_db();
        $ap = zpmscli_find_appointments($db, [(string)$p['appointment']]);

        zpmscli_soft_delete_appointments($db, $ap, '2025-02-02 02:02:02', true);
        assert_equal(1, zpms_cli_test_count("SELECT COUNT(*) FROM appointments WHERE id = {$p['appointment']} AND deleted = '2025-02-02 02:02:02'"), 'appointment not soft deleted');

        zpmscli_purge_appointments($db, zpmscli_find_appointments($db, [(string)$p['appointment']]), $dir, true);
        assert_equal(0, zpms_cli_test_count("SELECT COUNT(*) FROM appointments WHERE id = {$p['appointment']}"), 'appointment still there');
        assert_true(!is_file($p['path']), 'its file is still on disk');
        assert_equal(1, zpms_cli_test_count("SELECT COUNT(*) FROM patients WHERE id = {$p['id']}"), 'the patient was removed with the appointment');
    });

    $runner->add('cleanup:deleted purges only what is old enough; --all purges all of it', function () {
        TestSchema::assertSafeToMutate();
        $dir = zpms_cli_test_files_dir();
        $old = zpms_cli_test_patient($dir, 'cli_a', 'Old Deleted');
        $recent = zpms_cli_test_patient($dir, 'cli_a', 'Recent Deleted');
        $live = zpms_cli_test_patient($dir, 'cli_a', 'Never Deleted');
        $db = zpms_cli_test_db();
        $now = '2026-01-31 12:00:00';
        foreach ([[$old, '2025-01-01 00:00:00'], [$recent, '2026-01-30 00:00:00']] as [$p, $when]) {
            zpmscli_soft_delete_patients($db, zpmscli_find_patients($db, [(string)$p['id']]), $when, true);
        }
        // An appointment deleted on its own, on the live patient.
        $db->prepare('INSERT INTO appointments (guid, cdate, cuser, pguid, adate, atype, deleted) VALUES (?,?,?,?,?,?,?)')
            ->execute([guid(), getDBtime(), 'cli_a', $live['guid'], '2024-01-01 09:00:00', 'appointment', '2025-01-01 00:00:00']);
        $lonelyId = (int)$db->lastInsertId();

        $dry = zpmscli_cleanup_deleted($db, 30, $now, $dir, false);
        assert_true($dry['patients'] >= 1 && $dry['appointments'] >= 2, 'dry run should find the old patient and the lone appointment');
        assert_equal(1, zpms_cli_test_count("SELECT COUNT(*) FROM patients WHERE id = {$old['id']}"), 'a dry run purged a patient');

        zpmscli_cleanup_deleted($db, 30, $now, $dir, true);
        assert_equal(0, zpms_cli_test_count("SELECT COUNT(*) FROM patients WHERE id = {$old['id']}"), 'the old deleted patient survived');
        assert_true(!is_file($old['path']), "the old patient's file survived");
        assert_equal(0, zpms_cli_test_count("SELECT COUNT(*) FROM appointments WHERE id = $lonelyId"), 'the old lone appointment survived');
        assert_equal(1, zpms_cli_test_count("SELECT COUNT(*) FROM patients WHERE id = {$recent['id']}"), 'a recently deleted patient was purged');
        assert_equal(1, zpms_cli_test_count("SELECT COUNT(*) FROM patients WHERE id = {$live['id']}"), 'a patient that was never deleted was purged');
        assert_true(is_file($live['path']), "a live patient's file was removed");

        zpmscli_cleanup_deleted($db, null, $now, $dir, true);
        assert_equal(0, zpms_cli_test_count("SELECT COUNT(*) FROM patients WHERE id = {$recent['id']}"), '--all left a deleted patient');
        assert_equal(1, zpms_cli_test_count("SELECT COUNT(*) FROM patients WHERE id = {$live['id']}"), '--all purged a live patient');
    });

    $runner->add('cleanup:orphans removes orphaned attachment and history rows, only reports the rest', function () {
        TestSchema::assertSafeToMutate();
        $dir = zpms_cli_test_files_dir();
        $p = zpms_cli_test_patient($dir, 'cli_a', 'Orphans');
        $db = zpms_cli_test_db();
        // Orphans: delete the appointment row by hand, leaving its attachment and history behind.
        $db->exec("DELETE FROM appointments WHERE id = {$p['appointment']}");
        // An appointment whose patient is gone (reported only).
        $db->prepare('INSERT INTO appointments (guid, cdate, cuser, pguid, adate, atype) VALUES (?,?,?,?,?,?)')
            ->execute([guid(), getDBtime(), 'cli_a', guid(), '2024-01-01 09:00:00', 'appointment']);
        $strayAppointment = (int)$db->lastInsertId();
        // A file no row points at (reported only).
        $stray = "$dir/stray/loose.pdf";
        mkdir(dirname($stray), 0777, true);
        file_put_contents($stray, 'loose');

        $dry = zpmscli_cleanup_orphans($db, $dir, false);
        assert_true($dry['orphan_attachments'] >= 1 && $dry['orphan_history'] >= 1, 'dry run missed the orphans');
        assert_true($dry['appointments_without_patient'] >= 1, 'appointment without patient not reported');
        assert_true($dry['unreferenced_files'] >= 1, 'stray file not reported');
        assert_equal(1, zpms_cli_test_count("SELECT COUNT(*) FROM appointment_files WHERE id = {$p['file']}"), 'a dry run removed the orphan');

        zpmscli_cleanup_orphans($db, $dir, true);
        assert_equal(0, zpms_cli_test_count("SELECT COUNT(*) FROM appointment_files WHERE id = {$p['file']}"), 'orphan attachment survived');
        assert_equal(0, zpms_cli_test_count("SELECT COUNT(*) FROM appointment_history WHERE appointment_id = {$p['appointment']}"), 'orphan history survived');
        assert_true(!is_file($p['path']), "the orphan attachment's file survived");
        assert_true(is_file($stray), 'a file no row points at was deleted -- that is only reported');
        assert_equal(1, zpms_cli_test_count("SELECT COUNT(*) FROM appointments WHERE id = $strayAppointment"), 'an appointment without a patient was deleted -- that is only reported');
    });

    $runner->add('cache:clean deletes compiled .php templates and nothing else', function () {
        $dir = sys_get_temp_dir() . '/zpmscli_cache_' . getmypid() . '_' . bin2hex(random_bytes(3));
        mkdir("$dir/sub", 0777, true);
        file_put_contents("$dir/a.php", '<?php //');
        file_put_contents("$dir/b.php", '<?php //');
        file_put_contents("$dir/keep.txt", 'x');
        file_put_contents("$dir/sub/c.php", '<?php //');

        $dry = zpmscli_clean_cache($dir, false);
        assert_equal(2, $dry['files'], 'dry run should count two files');
        assert_true(is_file("$dir/a.php"), 'a dry run deleted a file');

        zpmscli_clean_cache($dir, true);
        assert_true(!is_file("$dir/a.php") && !is_file("$dir/b.php"), 'compiled templates survived');
        assert_true(is_file("$dir/keep.txt"), 'a non-.php file was deleted');
        assert_true(is_file("$dir/sub/c.php"), 'a file in a sub-folder was deleted');
    });

    $runner->add('run log: one line of counts, no names', function () {
        $log = sys_get_temp_dir() . '/zpmscli_log_' . getmypid() . '_' . bin2hex(random_bytes(3)) . '/zpmscli.log';
        zpmscli_log_run($log, 'patients:purge', ['patients' => 2, 'files' => 3, 'note' => 'Παπαδόπουλος']);
        $line = (string)file_get_contents($log);
        assert_contains('command=patients:purge patients=2 files=3', $line, 'counts not logged');
        assert_not_contains('Παπαδόπουλος', $line, 'a non-count value was logged');
    });

    $runner->add('command line: unknown command and missing arguments fail, a dry run prints DRY RUN and exit 0', function () {
        $env = 'ZPMS_DB_CONFIG=' . escapeshellarg(ZPMS_TEST_APPDIR . '/config/db.test.php');
        $run = function (string $args) use ($env): array {
            $out = [];
            exec($env . ' php ' . escapeshellarg(ZPMS_TEST_APPDIR . '/bin/zpmscli.php') . ' ' . $args . ' 2>&1', $out, $code);
            return [$code, implode("\n", $out)];
        };
        [$code] = $run('nonsense:command');
        assert_equal(1, $code, 'unknown command should exit 1');
        [$code, $out] = $run('patients:purge');
        assert_equal(1, $code, 'purge with no ids should exit 1'); assert_contains('Usage', $out, 'no usage message');
        [$code, $out] = $run('cleanup:deleted');
        assert_equal(1, $code, 'cleanup:deleted with no age should exit 1');
        [$code, $out] = $run('cleanup:orphans');
        assert_equal(0, $code, "dry run failed: $out"); assert_contains('DRY RUN', $out, 'a dry run did not say so');
        [$code, $out] = $run('--to=cli_to patients:reassign');
        assert_equal(1, $code, 'reassign with nothing selected should exit 1');
        [$code, $out] = $run('help');
        assert_equal(0, $code, 'help should exit 0'); assert_contains('patients:purge', $out, 'help does not list commands');
    });
}
