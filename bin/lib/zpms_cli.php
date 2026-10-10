<?php
/*
 * The logic behind bin/zpmscli.php -- housekeeping on the ZPMS database and
 * its attachment files. Kept here, apart from the command-line wrapper, so
 * the test suite runs exactly the code the command line runs.
 *
 * Plain PDO on purpose, not the generated entity classes or the framework:
 * this has to work from a shell with nothing booted, and the tables it
 * touches are simple. The database has no foreign keys, so every "cascade"
 * below is written out by hand and runs inside one transaction.
 *
 * Every function that changes anything takes $apply. With $apply = false it
 * does the same lookups and returns the same report, but writes nothing --
 * that is the dry run, and it is what the command line does unless --yes is
 * given.
 *
 * Reports are plain arrays of counts (never patient names or other free
 * text) so they are safe to write to the run log.
 */

const ZPMSCLI_REASSIGN_SCOPES = ['patients', 'appointments', 'files', 'history', 'operations'];

/** Splits argv into [command, positional arguments, options] -- options may sit anywhere. */
function zpmscli_parse_argv(array $argv): array {
    $options = [];
    $positional = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--')) {
            $arg = substr($arg, 2);
            if (str_contains($arg, '=')) {
                [$name, $value] = explode('=', $arg, 2);
                $options[$name] = $value;
            } else {
                $options[$arg] = true;
            }
        } else {
            $positional[] = $arg;
        }
    }
    $command = array_shift($positional);
    return [$command, $positional, $options];
}

/** Reads one top-level `key: value` line from a YAML config file (no YAML library needed). */
function zpmscli_config_value(string $file, string $key, ?string $default = null): ?string {
    $text = is_file($file) ? file_get_contents($file) : false;
    if ($text !== false && preg_match('/^' . preg_quote($key, '/') . ':\s*["\']?([^"\'\s#]+)/m', $text, $m)) {
        return $m[1];
    }
    return $default;
}

/** The attachment folder: <app>/web/<libpath>/appointment_files, as the web app resolves it. */
function zpmscli_files_dir(string $appDir): string {
    $libpath = zpmscli_config_value($appDir . '/config/site.info.yaml', 'libpath', 'files/');
    return $appDir . '/web/' . trim(trim($libpath), '/') . '/appointment_files';
}

/** Timestamps the web app writes use the configured time zone, not the shell's. */
function zpmscli_timezone(string $appDir): string {
    return zpmscli_config_value($appDir . '/config/settings.info.yaml', 'tz', 'Europe/Athens');
}

function zpmscli_table_exists(PDO $db, string $table): bool {
    $st = $db->prepare('SHOW TABLES LIKE ?');
    $st->execute([$table]);
    return (bool)$st->fetchColumn();
}

/** "1,2,3" or a list of ids/guids -> array of non-empty strings. */
function zpmscli_split_list($value): array {
    if ($value === null || $value === true || $value === '') return [];
    $items = is_array($value) ? $value : explode(',', (string)$value);
    $out = [];
    foreach ($items as $item) {
        foreach (explode(',', (string)$item) as $part) {
            $part = trim($part);
            if ($part !== '') $out[] = $part;
        }
    }
    return array_values(array_unique($out));
}

function zpmscli_in(array $values): string {
    return implode(',', array_fill(0, count($values), '?'));
}

/**
 * Patients matching numeric ids / guids, and/or created by a user.
 * Includes soft-deleted ones when $includeDeleted. Returns rows with
 * id, guid, pname, cuser, deleted.
 */
function zpmscli_find_patients(PDO $db, array $selectors, ?string $user = null, bool $includeDeleted = true): array {
    $where = [];
    $params = [];
    if ($selectors) {
        $ids = array_values(array_filter($selectors, 'ctype_digit'));
        $guids = array_values(array_diff($selectors, $ids));
        $parts = [];
        if ($ids) { $parts[] = 'id IN (' . zpmscli_in($ids) . ')'; array_push($params, ...$ids); }
        if ($guids) { $parts[] = 'guid IN (' . zpmscli_in($guids) . ')'; array_push($params, ...$guids); }
        $where[] = '(' . implode(' OR ', $parts) . ')';
    }
    if ($user !== null) {
        $where[] = 'cuser = ?';
        $params[] = $user;
    }
    if (!$includeDeleted) {
        $where[] = 'deleted IS NULL';
    }
    $sql = 'SELECT id, guid, pname, cuser, deleted FROM patients'
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY id';
    $st = $db->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Patients for patients:list -- name/AMKA search, creator, deleted-only, newest first. */
function zpmscli_list_patients(PDO $db, ?string $q, ?string $user, bool $deletedOnly, int $limit): array {
    $where = [];
    $params = [];
    if ($q !== null && $q !== '') {
        $where[] = '(pname LIKE ? OR pamka LIKE ?)';
        $params[] = '%' . $q . '%';
        $params[] = $q . '%';
    }
    if ($user !== null) {
        $where[] = 'cuser = ?';
        $params[] = $user;
    }
    if ($deletedOnly) $where[] = 'deleted IS NOT NULL';
    $sql = 'SELECT id, guid, pname, pamka, cuser, cdate, deleted FROM patients'
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY id DESC LIMIT ' . max(1, $limit);
    $st = $db->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Appointments of the given patient guids (deleted ones included). */
function zpmscli_appointments_of(PDO $db, array $patientGuids): array {
    if (!$patientGuids) return [];
    $st = $db->prepare('SELECT id, guid, pguid, cuser, adate, atype, deleted FROM appointments WHERE pguid IN ('
        . zpmscli_in($patientGuids) . ') ORDER BY id');
    $st->execute($patientGuids);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Appointments by numeric id / guid. */
function zpmscli_find_appointments(PDO $db, array $selectors): array {
    if (!$selectors) return [];
    $ids = array_values(array_filter($selectors, 'ctype_digit'));
    $guids = array_values(array_diff($selectors, $ids));
    $parts = [];
    $params = [];
    if ($ids) { $parts[] = 'id IN (' . zpmscli_in($ids) . ')'; array_push($params, ...$ids); }
    if ($guids) { $parts[] = 'guid IN (' . zpmscli_in($guids) . ')'; array_push($params, ...$guids); }
    $st = $db->prepare('SELECT id, guid, pguid, cuser, adate, atype, deleted FROM appointments WHERE ' . implode(' OR ', $parts) . ' ORDER BY id');
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function zpmscli_files_of(PDO $db, array $appointmentIds): array {
    if (!$appointmentIds) return [];
    $st = $db->prepare('SELECT id, appointment_id, cuser, file_path, thumbnail_path, file_size FROM appointment_files WHERE appointment_id IN ('
        . zpmscli_in($appointmentIds) . ') ORDER BY id');
    $st->execute($appointmentIds);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function zpmscli_user_exists(PDO $db, string $uname): bool {
    $st = $db->prepare('SELECT COUNT(*) FROM users WHERE uname = ?');
    $st->execute([$uname]);
    return (int)$st->fetchColumn() > 0;
}

// --------------------------------------------------------------------------
// patients:reassign
// --------------------------------------------------------------------------

/**
 * Makes records look as if $to had created them. Dates are not touched.
 *
 * Which records: with $patientSelectors (ids/guids), the rows on those
 * patients -- narrowed to the ones $from authored when $from is given, so what
 * other people added stays theirs. With only $from, everything $from created,
 * anywhere. $scopes narrows it to some of ZPMSCLI_REASSIGN_SCOPES.
 *
 * @return array{patients:int, appointments:int, files:int, history:int, operations:int}
 * @throws InvalidArgumentException for a request that makes no sense
 */
function zpmscli_reassign(PDO $db, ?string $from, string $to, array $patientSelectors, array $scopes, bool $apply, bool $force = false): array {
    if ($from === null && !$patientSelectors) {
        throw new InvalidArgumentException('Name the records to change: --from=<user> and/or --patient=<id,...>.');
    }
    if ($from !== null && $from === $to) {
        throw new InvalidArgumentException('--from and --to are the same user.');
    }
    if (!$scopes) $scopes = ZPMSCLI_REASSIGN_SCOPES;
    $bad = array_diff($scopes, ZPMSCLI_REASSIGN_SCOPES);
    if ($bad) {
        throw new InvalidArgumentException('Unknown --only value: ' . implode(', ', $bad) . ' (use ' . implode(', ', ZPMSCLI_REASSIGN_SCOPES) . ').');
    }
    if (!$force && !zpmscli_user_exists($db, $to)) {
        throw new InvalidArgumentException("No user '$to' (see users:list). Use --force to write it anyway.");
    }

    $report = ['patients' => 0, 'appointments' => 0, 'files' => 0, 'history' => 0, 'operations' => 0];
    $hasOperations = zpmscli_table_exists($db, 'operations');

    // [report key, table, column that links the row to the chosen patients]
    $tables = [
        ['patients', 'patients', 'guid'],
        ['appointments', 'appointments', 'pguid'],
        ['files', 'appointment_files', 'appointment_id'],
        ['history', 'appointment_history', 'appointment_id'],
    ];
    if ($hasOperations) $tables[] = ['operations', 'operations', 'pguid'];

    // With patients named: every row on those patients (narrowed to $from's
    // when given). With only --from: everything $from created, anywhere.
    $patientGuids = [];
    $appointmentIds = [];
    if ($patientSelectors) {
        $patients = zpmscli_find_patients($db, $patientSelectors);
        $patientGuids = array_column($patients, 'guid');
        $appointmentIds = array_column(zpmscli_appointments_of($db, $patientGuids), 'id');
    }

    if ($apply) $db->beginTransaction();
    try {
        foreach ($tables as [$key, $table, $column]) {
            if (!in_array($key, $scopes, true)) continue;
            $conditions = [];
            $params = [];
            if ($patientSelectors) {
                $values = in_array($column, ['guid', 'pguid'], true) ? $patientGuids : $appointmentIds;
                if (!$values) continue;
                $conditions[] = "$column IN (" . zpmscli_in($values) . ')';
                $params = $values;
            }
            if ($from !== null) {
                $conditions[] = 'cuser = ?';
                $params[] = $from;
            }
            $where = implode(' AND ', $conditions);
            $count = $db->prepare("SELECT COUNT(*) FROM $table WHERE $where");
            $count->execute($params);
            $report[$key] = (int)$count->fetchColumn();
            if ($apply && $report[$key] > 0) {
                $db->prepare("UPDATE $table SET cuser = ? WHERE $where")->execute(array_merge([$to], $params));
            }
        }
        if ($apply) $db->commit();
    } catch (Throwable $e) {
        if ($apply && $db->inTransaction()) $db->rollBack();
        throw $e;
    }
    return $report;
}

// --------------------------------------------------------------------------
// soft delete (the same thing the web interface does)
// --------------------------------------------------------------------------

/**
 * Marks patients deleted, and every appointment of theirs, with one shared
 * timestamp -- exactly what patient_delete() in web/index.php does, so the
 * records can still be recovered. Already-deleted rows keep their old time.
 *
 * @return array{patients:int, appointments:int}
 */
function zpmscli_soft_delete_patients(PDO $db, array $patients, string $now, bool $apply): array {
    $report = ['patients' => 0, 'appointments' => 0];
    $guids = [];
    foreach ($patients as $p) {
        if ($p['deleted'] === null) $report['patients']++;
        $guids[] = $p['guid'];
    }
    if ($guids) {
        $st = $db->prepare('SELECT COUNT(*) FROM appointments WHERE deleted IS NULL AND pguid IN (' . zpmscli_in($guids) . ')');
        $st->execute($guids);
        $report['appointments'] = (int)$st->fetchColumn();
    }
    if ($apply && $guids) {
        $db->beginTransaction();
        try {
            $in = zpmscli_in($guids);
            $db->prepare("UPDATE appointments SET deleted = ? WHERE deleted IS NULL AND pguid IN ($in)")->execute(array_merge([$now], $guids));
            $db->prepare("UPDATE patients SET deleted = ? WHERE deleted IS NULL AND guid IN ($in)")->execute(array_merge([$now], $guids));
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }
    return $report;
}

/** @return array{appointments:int} */
function zpmscli_soft_delete_appointments(PDO $db, array $appointments, string $now, bool $apply): array {
    $ids = [];
    foreach ($appointments as $a) {
        if ($a['deleted'] === null) $ids[] = $a['id'];
    }
    if ($apply && $ids) {
        $db->prepare('UPDATE appointments SET deleted = ? WHERE id IN (' . zpmscli_in($ids) . ')')->execute(array_merge([$now], $ids));
    }
    return ['appointments' => count($ids)];
}

// --------------------------------------------------------------------------
// hard delete
// --------------------------------------------------------------------------

/**
 * Absolute path of an attachment, only if it really lies inside the
 * attachment folder. Returns null for anything else (a path that escapes the
 * folder, a symlink pointing out of it, an empty value) so a damaged row can
 * never make this delete a file elsewhere. A file that does not exist is
 * also null -- there is nothing to remove.
 */
function zpmscli_safe_file_path(string $baseDir, ?string $relative): ?string {
    if ($relative === null || trim($relative) === '') return null;
    $base = realpath($baseDir);
    if ($base === false) return null;
    $real = realpath($base . '/' . ltrim($relative, '/'));
    if ($real === false || !is_file($real)) return null;
    return str_starts_with($real, $base . DIRECTORY_SEPARATOR) ? $real : null;
}

/** Removes the (now empty) folders a deleted file lived in, never the attachment folder itself. */
function zpmscli_prune_empty_dirs(string $baseDir, string $filePath): void {
    $base = realpath($baseDir);
    if ($base === false) return;
    $dir = dirname($filePath);
    while (str_starts_with($dir, $base . DIRECTORY_SEPARATOR) && $dir !== $base) {
        if (!@rmdir($dir)) break;   // not empty (or not ours to remove)
        $dir = dirname($dir);
    }
}

/**
 * Removes the files behind attachment rows. Called only after the database
 * rows are gone. @return array{files:int, missing:int, bytes:int}
 */
function zpmscli_remove_files(array $fileRows, string $baseDir, bool $apply): array {
    $report = ['files' => 0, 'missing' => 0, 'bytes' => 0];
    foreach ($fileRows as $row) {
        foreach (['file_path', 'thumbnail_path'] as $column) {
            if ($column === 'thumbnail_path' && empty($row['thumbnail_path'])) continue;
            $path = zpmscli_safe_file_path($baseDir, $row[$column] ?? null);
            if ($path === null) {
                if ($column === 'file_path') $report['missing']++;
                continue;
            }
            $report['files']++;
            $report['bytes'] += (int)@filesize($path);
            if ($apply) {
                @unlink($path);
                zpmscli_prune_empty_dirs($baseDir, $path);
            }
        }
    }
    return $report;
}

/**
 * Deletes patients for good: their appointments, attachment rows and files,
 * edit history, and legacy operation rows; clears the "converted to" links
 * on pending appointments that pointed at them.
 *
 * @return array{patients:int, appointments:int, attachments:int, history:int, operations:int, pending_unlinked:int, files:int, missing:int, bytes:int}
 */
function zpmscli_purge_patients(PDO $db, array $patients, string $filesDir, bool $apply): array {
    $guids = array_column($patients, 'guid');
    $patientIds = array_column($patients, 'id');
    $appointments = zpmscli_appointments_of($db, $guids);
    return zpmscli_purge($db, $patientIds, $guids, $appointments, $filesDir, $apply);
}

/** Deletes single appointments for good (the patient stays). */
function zpmscli_purge_appointments(PDO $db, array $appointments, string $filesDir, bool $apply): array {
    return zpmscli_purge($db, [], [], $appointments, $filesDir, $apply);
}

function zpmscli_purge(PDO $db, array $patientIds, array $patientGuids, array $appointments, string $filesDir, bool $apply): array {
    $apIds = array_column($appointments, 'id');
    $fileRows = zpmscli_files_of($db, $apIds);
    $hasOperations = $patientGuids && zpmscli_table_exists($db, 'operations');

    $count = function (string $sql, array $params) use ($db): int {
        if (!$params) return 0;
        $st = $db->prepare($sql);
        $st->execute($params);
        return (int)$st->fetchColumn();
    };
    $apIn = $apIds ? zpmscli_in($apIds) : '';
    $report = [
        'patients' => count($patientIds),
        'appointments' => count($apIds),
        'attachments' => count($fileRows),
        'history' => $count("SELECT COUNT(*) FROM appointment_history WHERE appointment_id IN ($apIn)", $apIds),
        'operations' => $hasOperations ? $count('SELECT COUNT(*) FROM operations WHERE pguid IN (' . zpmscli_in($patientGuids) . ')', $patientGuids) : 0,
        'pending_unlinked' => 0,
    ];
    if ($patientIds) {
        $report['pending_unlinked'] += $count('SELECT COUNT(*) FROM pending_appointments WHERE converted_patient_id IN (' . zpmscli_in($patientIds) . ')', $patientIds);
    }
    if ($apIds) {
        $report['pending_unlinked'] += $count("SELECT COUNT(*) FROM pending_appointments WHERE converted_appointment_id IN ($apIn) AND converted_patient_id IS NULL", $apIds);
    }

    if ($apply) {
        $db->beginTransaction();
        try {
            if ($apIds) {
                $db->prepare("DELETE FROM appointment_files WHERE appointment_id IN ($apIn)")->execute($apIds);
                $db->prepare("DELETE FROM appointment_history WHERE appointment_id IN ($apIn)")->execute($apIds);
                $db->prepare("UPDATE pending_appointments SET converted_appointment_id = NULL WHERE converted_appointment_id IN ($apIn)")->execute($apIds);
                $db->prepare("DELETE FROM appointments WHERE id IN ($apIn)")->execute($apIds);
            }
            if ($patientIds) {
                $db->prepare('UPDATE pending_appointments SET converted_patient_id = NULL WHERE converted_patient_id IN (' . zpmscli_in($patientIds) . ')')->execute($patientIds);
                if ($hasOperations) {
                    $db->prepare('DELETE FROM operations WHERE pguid IN (' . zpmscli_in($patientGuids) . ')')->execute($patientGuids);
                }
                $db->prepare('DELETE FROM patients WHERE id IN (' . zpmscli_in($patientIds) . ')')->execute($patientIds);
            }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    // Files go only after the database commit: a leftover file is harmless
    // clutter, a row pointing at a file that is gone is a broken record.
    return $report + zpmscli_remove_files($fileRows, $filesDir, $apply);
}

// --------------------------------------------------------------------------
// cleanup
// --------------------------------------------------------------------------

/**
 * Purges everything that is already soft-deleted: patients (with their
 * appointments) and appointments deleted on their own. $olderThanDays = null
 * means regardless of age.
 */
function zpmscli_cleanup_deleted(PDO $db, ?int $olderThanDays, string $now, string $filesDir, bool $apply): array {
    $cutoff = $olderThanDays !== null ? date('Y-m-d H:i:s', strtotime($now) - $olderThanDays * 86400) : null;
    $ageSql = $cutoff !== null ? ' AND deleted <= ?' : '';
    $ageParams = $cutoff !== null ? [$cutoff] : [];

    $st = $db->prepare("SELECT id, guid, pname, cuser, deleted FROM patients WHERE deleted IS NOT NULL$ageSql ORDER BY id");
    $st->execute($ageParams);
    $patients = $st->fetchAll(PDO::FETCH_ASSOC);
    $patientGuids = array_column($patients, 'guid');

    $st = $db->prepare("SELECT id, guid, pguid, cuser, adate, atype, deleted FROM appointments WHERE deleted IS NOT NULL$ageSql ORDER BY id");
    $st->execute($ageParams);
    $alone = array_values(array_filter($st->fetchAll(PDO::FETCH_ASSOC), fn($a) => !in_array($a['pguid'], $patientGuids, true)));

    $report = zpmscli_purge_patients($db, $patients, $filesDir, $apply);
    $second = zpmscli_purge_appointments($db, $alone, $filesDir, $apply);
    foreach ($second as $key => $value) {
        $report[$key] = ($report[$key] ?? 0) + $value;
    }
    return $report;
}

/**
 * Rows left behind by earlier hard deletes (or by hand): attachment and
 * history rows whose appointment no longer exists are removed (with $apply);
 * appointments whose patient is gone, and files on disk that no row points
 * at, are only reported -- those are for a person to look at.
 *
 * @return array{orphan_attachments:int, orphan_history:int, appointments_without_patient:int, unreferenced_files:int, unreferenced_bytes:int, files:int, missing:int, bytes:int}
 */
function zpmscli_cleanup_orphans(PDO $db, string $filesDir, bool $apply): array {
    $files = $db->query('SELECT f.id, f.appointment_id, f.cuser, f.file_path, f.thumbnail_path, f.file_size FROM appointment_files f LEFT JOIN appointments a ON a.id = f.appointment_id WHERE a.id IS NULL')->fetchAll(PDO::FETCH_ASSOC);
    $historyIds = $db->query('SELECT h.id FROM appointment_history h LEFT JOIN appointments a ON a.id = h.appointment_id WHERE a.id IS NULL')->fetchAll(PDO::FETCH_COLUMN);
    $withoutPatient = (int)$db->query('SELECT COUNT(*) FROM appointments a LEFT JOIN patients p ON p.guid = a.pguid WHERE p.id IS NULL')->fetchColumn();

    // Files on disk that no attachment row (or thumbnail) refers to.
    $known = [];
    foreach ($db->query('SELECT file_path, thumbnail_path FROM appointment_files')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        foreach (['file_path', 'thumbnail_path'] as $column) {
            if (!empty($row[$column])) $known[$row[$column]] = true;
        }
    }
    $unreferenced = 0;
    $unreferencedBytes = 0;
    $base = realpath($filesDir);
    if ($base !== false) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $entry) {
            if (!$entry->isFile()) continue;
            $relative = substr($entry->getPathname(), strlen($base) + 1);
            if (!isset($known[$relative])) {
                $unreferenced++;
                $unreferencedBytes += $entry->getSize();
            }
        }
    }

    if ($apply) {
        $db->beginTransaction();
        try {
            if ($files) {
                $ids = array_column($files, 'id');
                $db->prepare('DELETE FROM appointment_files WHERE id IN (' . zpmscli_in($ids) . ')')->execute($ids);
            }
            if ($historyIds) {
                $db->prepare('DELETE FROM appointment_history WHERE id IN (' . zpmscli_in($historyIds) . ')')->execute($historyIds);
            }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    return [
        'orphan_attachments' => count($files),
        'orphan_history' => count($historyIds),
        'appointments_without_patient' => $withoutPatient,
        'unreferenced_files' => $unreferenced,
        'unreferenced_bytes' => $unreferencedBytes,
    ] + zpmscli_remove_files($files, $filesDir, $apply);
}

// --------------------------------------------------------------------------
// cache
// --------------------------------------------------------------------------

/**
 * Deletes the compiled templates in web/cache/*.php (they are rebuilt on the
 * next request). Only regular .php files directly inside that folder.
 *
 * @return array{files:int, bytes:int}
 */
function zpmscli_clean_cache(string $cacheDir, bool $apply): array {
    $report = ['files' => 0, 'bytes' => 0];
    foreach (glob($cacheDir . '/*.php') ?: [] as $file) {
        if (!is_file($file) || is_link($file)) continue;
        $report['files']++;
        $report['bytes'] += (int)@filesize($file);
        if ($apply) @unlink($file);
    }
    return $report;
}

// --------------------------------------------------------------------------
// run log
// --------------------------------------------------------------------------

/**
 * One line per run that changed something: when, who ran it, which command,
 * and the counts -- never names or any other patient data.
 */
function zpmscli_log_run(string $logFile, string $command, array $report): void {
    $dir = dirname($logFile);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) return;
    $who = function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? 'unknown') : get_current_user();
    $counts = [];
    foreach ($report as $key => $value) {
        if (is_int($value)) $counts[] = "$key=$value";
    }
    @file_put_contents($logFile, date('c') . " user=$who command=$command " . implode(' ', $counts) . "\n", FILE_APPEND | LOCK_EX);
}
