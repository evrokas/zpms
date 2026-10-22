<?php
/**
 * "Update from git" (/system/update): pick a branch, optionally also the
 * ZeusFW checkout (fw/), optionally back up the database first, preview, run.
 *
 * This page does NOT run git itself and never holds the GitHub key. Every call
 * goes through the root-owned wrapper deploy/zpms-git-update (installed as
 * /usr/local/sbin/zpms-git-update), which sudo lets the web server run as the
 * dedicated deploy user -- see README "Updating from git" and
 * deploy/setup_update_user.sh. The wrapper accepts a fixed set of commands and
 * options; branch names are checked again here before they are handed over, and
 * once more by the wrapper and by bin/update_from_git.sh.
 *
 * Needs ZPMS_PERM_SYSTEM_UPDATE (an administrator -- is_superuser -- has it).
 * Starting an update is audit-logged with branch names and flags only.
 */

/** The command that reaches the wrapper: sudo -n -u <deploy user> <wrapper>. */
function zpms_update_command(): array {
    // Test suite only: run a stand-in script instead of sudo + the real wrapper.
    // Honoured only against a database whose name ends in _test (the suite refuses
    // to run against anything else), never on a real installation.
    $stub = getenv('ZPMS_UPDATE_TEST_WRAPPER');
    if ($stub !== false && $stub !== '' && defined('DB_NAME') && substr((string)DB_NAME, -5) === '_test') {
        return [$stub];
    }
    $user = zpms_update_setting('update_deploy_user', 'zpmsdeploy');
    $wrapper = zpms_update_setting('update_wrapper', '/usr/local/sbin/zpms-git-update');
    // These two end up in a command line: only plain names and absolute paths.
    if (!preg_match('/^[a-z_][a-z0-9_-]*$/i', $user)) $user = 'zpmsdeploy';
    if (!preg_match('#^/[A-Za-z0-9_./-]+$#', $wrapper)) $wrapper = '/usr/local/sbin/zpms-git-update';
    return ['sudo', '-n', '-u', $user, $wrapper];
}

/**
 * A text setting from config/site.info.yaml, or $default. Kernel::getConfig() answers
 * an empty ARRAY for a key that is not set (and an array when two config layers both
 * set it), so a plain string cast would give the text "Array".
 */
function zpms_update_setting(string $key, string $default): string {
    global $kernel;
    $v = isset($kernel) ? $kernel->getConfig($key) : null;
    if (is_array($v)) $v = end($v);
    return (is_string($v) && trim($v) !== '') ? trim($v) : $default;
}

/** Is the wrapper installed at all? (A missing one means "not set up yet", not an error.) */
function zpms_update_available(?array $command = null): bool {
    $command = $command ?? zpms_update_command();
    return is_file(end($command));
}

/**
 * Run the wrapper with these arguments. Never throws: returns
 * ['ok' => bool, 'code' => int, 'stdout' => string, 'stderr' => string, 'timeout' => bool].
 * $command is only replaced by tests.
 */
function zpms_update_call(array $args, int $timeoutSeconds = 120, ?array $command = null): array {
    $command = $command ?? zpms_update_command();
    $result = ['ok' => false, 'code' => -1, 'stdout' => '', 'stderr' => '', 'timeout' => false];
    $descriptors = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = @proc_open(array_merge($command, $args), $descriptors, $pipes, null, ['PATH' => '/usr/local/bin:/usr/bin:/bin']);
    if (!is_resource($proc)) {
        $result['stderr'] = 'could not start the update wrapper';
        return $result;
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $deadline = microtime(true) + $timeoutSeconds;
    $open = [1 => $pipes[1], 2 => $pipes[2]];
    $out = [1 => '', 2 => ''];
    while ($open) {
        $read = array_values($open);
        $write = $except = null;
        if (@stream_select($read, $write, $except, 1) === false) break;
        foreach ($read as $stream) {
            $i = array_search($stream, $open, true);
            $chunk = fread($stream, 65536);
            if ($chunk === false || ($chunk === '' && feof($stream))) {
                fclose($stream);
                unset($open[$i]);
            } else {
                $out[$i] .= $chunk;
            }
        }
        if (microtime(true) > $deadline) {
            $result['timeout'] = true;
            proc_terminate($proc, 15);
            break;
        }
    }
    foreach ($open as $stream) @fclose($stream);
    $code = proc_close($proc);
    $result['code'] = $code;
    $result['stdout'] = $out[1];
    $result['stderr'] = $out[2];
    $result['ok'] = !$result['timeout'] && $code === 0;
    return $result;
}

/** A branch name we are willing to hand to the wrapper (the wrapper checks it again). */
function zpms_update_valid_branch(string $b): bool {
    if (strlen($b) > 100 || !preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]*$#', $b)) return false;
    return !(strpos($b, '..') !== false || substr($b, -1) === '/' || substr($b, -5) === '.lock' || strpos($b, '//') !== false);
}

/**
 * The wrapper's option list for what the form posted.
 * Returns [args, summary] or [null, error].
 */
function zpms_update_options(array $post): array {
    $branch = trim((string)($post['branch'] ?? ''));
    if ($branch === '' || !zpms_update_valid_branch($branch)) return [null, isset($GLOBALS['kernel']) ? t('Choose a valid branch.') : 'Choose a valid branch.'];
    $args = ['--branch', $branch];
    $fw = !empty($post['fw']);
    $fwBranch = '';
    if ($fw) {
        $fwBranch = trim((string)($post['fw_branch'] ?? ''));
        if ($fwBranch === '' || !zpms_update_valid_branch($fwBranch)) return [null, isset($GLOBALS['kernel']) ? t('Choose a valid framework branch.') : 'Choose a valid framework branch.'];
        array_push($args, '--fw', '--fw-branch', $fwBranch);
    }
    $backup = !empty($post['backup']);
    $args[] = $backup ? '--backup' : '--no-backup';
    $updateSh = !empty($post['update_sh']);
    if (!$updateSh) $args[] = '--no-update-sh';
    $schema = $updateSh && !empty($post['apply_schema']);
    if ($schema) $args[] = '--apply-schema';
    $dict = !empty($post['dictionary']);
    if (!$dict) $args[] = '--no-dictionary';
    $summary = 'branch=' . $branch . ($fw ? ' fw=' . $fwBranch : '') . ' backup=' . (int)$backup . ' update_sh=' . (int)$updateSh
        . ' schema=' . (int)$schema . ' dictionary=' . (int)$dict;
    return [$args, $summary];
}

function zpms_update_json(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit();
}

/** Permission + (for POST) CSRF, as JSON errors; same shape as zpms_sched_api_guard(). */
function zpms_update_api_guard(bool $post): void {
    if (rbacClass::require(ZPMS_PERM_SYSTEM_UPDATE)) {
        zpms_update_json(['ok' => false, 'error' => 'forbidden'], 403);
    }
    if ($post && !csrfClass::verifyRequest()) {
        zpms_update_json(['ok' => false, 'error' => 'csrf'], 403);
    }
    if (!zpms_update_available()) {
        zpms_update_json(['ok' => false, 'error' => 'not_set_up'], 503);
    }
}

/** The page. */
function system_update_view($params) {
    if (($errmsg = rbacClass::require(ZPMS_PERM_SYSTEM_UPDATE))) return $errmsg;

    $available = zpms_update_available();
    $state = null;
    $error = '';
    if ($available) {
        $res = zpms_update_call(['status'], 30);
        $state = $res['ok'] ? json_decode($res['stdout'], true) : null;
        if (!is_array($state)) {
            $error = trim($res['stderr']) !== '' ? trim($res['stderr']) : 'The update wrapper did not answer.';
        }
    }
    $repos = is_array($state) ? ($state['repos'] ?? []) : [];
    return Renderer::render('system_update.zetem', [
        'available' => $available,
        'looked_for' => end(zpms_update_command()),
        'error' => $error,
        'app' => $repos['app'] ?? ['available' => false],
        'fw' => $repos['fw'] ?? ['available' => false],
        'last' => is_array($state) ? ($state['last'] ?? null) : null,
        'running' => is_array($state) && !empty($state['running']),
        'urls' => [
            'branches' => rel_url('/system/update/branches'),
            'status' => rel_url('/system/update/status'),
            'preview' => rel_url('/system/update/preview'),
            'run' => rel_url('/system/update/run'),
        ],
    ]);
}

/** GET: fetch from the remote and list the allowed branches. */
function system_update_branches($params) {
    zpms_update_api_guard(false);
    $res = zpms_update_call(['branches'], 180);
    $data = $res['ok'] ? json_decode($res['stdout'], true) : null;
    if (!is_array($data)) zpms_update_json(['ok' => false, 'error' => 'failed', 'detail' => trim($res['stderr'])], 502);
    zpms_update_json(['ok' => true, 'repos' => $data]);
}

/** GET: running?, the last run and the tail of the log, for the page to poll. */
function system_update_status($params) {
    zpms_update_api_guard(false);
    $res = zpms_update_call(['status'], 30);
    $state = $res['ok'] ? json_decode($res['stdout'], true) : null;
    if (!is_array($state)) zpms_update_json(['ok' => false, 'error' => 'failed', 'detail' => trim($res['stderr'])], 502);
    $log = zpms_update_call(['log', '300'], 30);
    zpms_update_json(['ok' => true, 'running' => !empty($state['running']), 'last' => $state['last'] ?? null,
        'repos' => $state['repos'] ?? null, 'log' => $log['ok'] ? $log['stdout'] : '']);
}

/** POST: dry run -- fetch and list what would come in; changes nothing. */
function system_update_preview($params) {
    zpms_update_api_guard(true);
    [$args, $summary] = zpms_update_options($_POST);
    if ($args === null) zpms_update_json(['ok' => false, 'error' => 'bad_request', 'detail' => $summary], 400);
    $res = zpms_update_call(array_merge(['preview'], $args), 300);
    zpms_update_json(['ok' => $res['ok'], 'output' => $res['stdout'] . ($res['stderr'] !== '' ? "\n" . $res['stderr'] : ''),
        'timeout' => $res['timeout']], $res['ok'] ? 200 : 502);
}

/** POST: start the update in the background. */
function system_update_run($params) {
    zpms_update_api_guard(true);
    if (($_POST['confirm'] ?? '') !== '1') zpms_update_json(['ok' => false, 'error' => 'bad_request', 'detail' => t('Confirmation missing.')], 400);
    [$args, $summary] = zpms_update_options($_POST);
    if ($args === null) zpms_update_json(['ok' => false, 'error' => 'bad_request', 'detail' => $summary], 400);
    // Logged before it starts: branch names and flags only, nothing free-text.
    zpms_audit('system_update_started', ['detail' => $summary]);
    $res = zpms_update_call(array_merge(['run'], $args), 30);
    if (!$res['ok']) {
        zpms_update_json(['ok' => false, 'error' => 'failed', 'detail' => trim($res['stderr'])], 409);
    }
    zpms_update_json(['ok' => true]);
}
