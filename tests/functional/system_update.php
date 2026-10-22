<?php
/* "Update from git" (web/system_update.php, /system/update). The sudo wrapper is
 * replaced by a stand-in script (ZPMS_UPDATE_TEST_WRAPPER, set in run_all.php
 * before the test server starts) that records how it was called: the point is
 * who may use the page, what the form turns into wrapper options, and that
 * nothing unvalidated reaches the wrapper. */

require_once __DIR__ . '/../../web/system_update.php';
require_once __DIR__ . '/audit_log.php';

const ZPMS_SU_STATUS = '{"repos":{"app":{"available":true,"current":"main","commit":"abc1234","dirty":false,"branches":["main","dev","release/1.2"]},'
    . '"fw":{"available":true,"current":"main","commit":"def5678","dirty":false,"branches":["main","fw-dev"]}},'
    . '"last":{"started":"2026-10-22T01:00:00Z","finished":"2026-10-22T01:01:00Z","result":"success","app":{"branch":"main","old":"aaaaaaaaaaaa","new":"bbbbbbbbbbbb"},"fw":{"branch":"","old":"","new":""}},"running":false}';

/** Creates the stand-in wrapper in a temp folder and returns its path. */
function zpms_su_stub(): string {
    $dir = sys_get_temp_dir() . '/zpms-su-stub-' . getmypid();
    @mkdir($dir, 0700, true);
    file_put_contents($dir . '/status.json', ZPMS_SU_STATUS);
    file_put_contents($dir . '/branches.json', '{"app":{"available":true,"current":"main","commit":"abc1234","dirty":false,"branches":["main","dev","hotfix"]},"fw":{"available":false}}');
    file_put_contents($dir . '/log.txt', "line one\nline two\n");
    @unlink($dir . '/calls.log');
    file_put_contents($dir . '/stub', <<<'SH'
#!/bin/bash
D=$(dirname "$0")
echo "$*" >> "$D/calls.log"
case $1 in
  status)   cat "$D/status.json" ;;
  branches) cat "$D/branches.json" ;;
  log)      cat "$D/log.txt" ;;
  preview)  echo "== app: main =="; echo "Incoming commits: 1"; echo "ARGS: $*" ;;
  run)      echo started ;;
  slow)     sleep 5 ;;
  fail)     echo "boom" >&2; exit 3 ;;
  *)        exit 1 ;;
esac
SH);
    chmod($dir . '/stub', 0700);
    return $dir . '/stub';
}

function zpms_su_calls(string $stub): array {
    $f = dirname($stub) . '/calls.log';
    return is_file($f) ? array_values(array_filter(explode("\n", (string)file_get_contents($f)))) : [];
}

function zpms_su_meta_token(string $html): string {
    assert_true((bool)preg_match('/<meta name="csrf-token" content="([^"]+)"/', $html, $m), 'no csrf-token meta tag on the page');
    return html_entity_decode($m[1], ENT_QUOTES);
}

function zpms_system_update_tests(TestRunner $runner, string $baseUrl, string $stub): void {
    $runner->add('branch names: only safe ones are accepted', function () {
        foreach (['main', 'release/1.2', 'feature_x-1', 'v2.0.1'] as $ok) assert_true(zpms_update_valid_branch($ok), "$ok should be valid");
        foreach (['', '-main', '--upload-pack=x', 'a b', 'a;b', 'x..y', 'a/', 'a.lock', 'a//b', '$(id)', '`id`', "a\nb", str_repeat('a', 101)] as $bad) {
            assert_false(zpms_update_valid_branch($bad), 'accepted: ' . json_encode($bad));
        }
    });

    $runner->add('form -> wrapper options', function () {
        [$a, $summary] = zpms_update_options(['branch' => 'main', 'backup' => '1', 'update_sh' => '1', 'dictionary' => '1']);
        assert_equal(['--branch', 'main', '--backup'], $a);
        [$a] = zpms_update_options(['branch' => 'dev', 'fw' => '1', 'fw_branch' => 'fw-dev']);
        assert_equal(['--branch', 'dev', '--fw', '--fw-branch', 'fw-dev', '--no-backup', '--no-update-sh', '--no-dictionary'], $a, 'unticked boxes become --no-* options');
        [$a] = zpms_update_options(['branch' => 'main', 'backup' => '1', 'update_sh' => '1', 'apply_schema' => '1', 'dictionary' => '1']);
        assert_true(in_array('--apply-schema', $a, true), '--apply-schema missing');
        [$a] = zpms_update_options(['branch' => 'main', 'apply_schema' => '1']);
        assert_false(in_array('--apply-schema', $a, true), 'schema changes asked for without update.sh');
        foreach ([['branch' => ''], ['branch' => 'a;b'], ['branch' => 'main', 'fw' => '1', 'fw_branch' => '--x'], ['branch' => 'main', 'fw' => '1']] as $bad) {
            [$a, $err] = zpms_update_options($bad);
            assert_true($a === null && $err !== '', 'accepted ' . json_encode($bad));
        }
    });

    $runner->add('the wrapper call: output, exit status, stderr, timeout', function () use ($stub) {
        $r = zpms_update_call(['status'], 10, [$stub]);
        assert_true($r['ok'] && strpos($r['stdout'], '"app"') !== false, 'status call failed');
        $r = zpms_update_call(['fail'], 10, [$stub]);
        assert_true(!$r['ok'] && $r['code'] === 3 && trim($r['stderr']) === 'boom', 'a failing wrapper was not reported');
        $r = zpms_update_call(['slow'], 1, [$stub]);
        assert_true(!$r['ok'] && $r['timeout'], 'a slow wrapper was not stopped');
        $r = zpms_update_call(['status'], 5, ['/nonexistent/wrapper']);
        assert_false($r['ok'], 'a missing wrapper reported success');
        assert_false(zpms_update_available(['/nonexistent/wrapper']), 'a missing wrapper counted as installed');
    });

    $runner->add('the page: refused to others, shown to an administrator with the real branches', function () use ($baseUrl, $stub) {
        $doctor = new TestHttpClient($baseUrl);
        TestFixtures::loginAsTestUser($doctor);
        assert_contains('401', $doctor->get('/system/update')['body'], 'a doctor (no system-update) could open the page');
        assert_not_contains('/system/update', $doctor->get('/patients')['body'], 'the menu offers the page to someone who cannot open it');
        assert_equal(403, $doctor->get('/system/update/status')['status'], 'the status endpoint answered a doctor');

        zpms_audit_test_admin();
        $admin = new TestHttpClient($baseUrl);
        TestFixtures::loginAsAdministrator($admin);
        $page = $admin->get('/system/update');
        assert_equal(200, $page['status']);
        $body = $page['body'];
        foreach (['abc1234', 'def5678', 'release/1.2', 'fw-dev', 'id="su-fw"', 'id="su-backup"', 'id="su-run"'] as $needle) {
            assert_contains($needle, $body, "the page lacks $needle");
        }
        assert_true((bool)preg_match('/id="su-backup"[^>]*checked/', $body), 'the backup box is not ticked by default');
        assert_false((bool)preg_match('/id="su-schema"[^>]*checked/', $body), 'applying schema changes is ticked by default');
        assert_contains('href="/system/update"', $admin->get('/patients')['body'], 'the menu does not offer the page to an administrator');
    });

    $runner->add('JSON endpoints: CSRF and validation, then what reaches the wrapper', function () use ($baseUrl, $stub) {
        $admin = new TestHttpClient($baseUrl);
        TestFixtures::loginAsAdministrator($admin);
        $token = zpms_su_meta_token($admin->get('/system/update')['body']);
        @unlink(dirname($stub) . '/calls.log');

        $r = $admin->post('/system/update/preview', ['branch' => 'main']);
        assert_equal(403, $r['status'], 'a preview without a CSRF token was accepted');
        $r = $admin->postWithHeaders('/system/update/preview', ['branch' => 'a;b'], ['X-CSRF-Token' => $token]);
        assert_equal(400, $r['status'], 'an invalid branch reached the wrapper');
        $r = $admin->postWithHeaders('/system/update/run', ['branch' => 'main', 'backup' => '1'], ['X-CSRF-Token' => $token]);
        assert_equal(400, $r['status'], 'run without confirm was accepted');
        assert_equal([], zpms_su_calls($stub), 'the wrapper was called for a bad request');

        $r = $admin->postWithHeaders('/system/update/preview', ['branch' => 'dev', 'backup' => '1', 'update_sh' => '1', 'dictionary' => '1'], ['X-CSRF-Token' => $token]);
        $j = json_decode(preg_replace('/^[^{]*/', '', $r['body']), true);
        assert_true($r['status'] === 200 && $j['ok'] && strpos($j['output'], 'Incoming commits: 1') !== false, 'preview failed: ' . $r['body']);
        assert_equal(['preview --branch dev --backup'], zpms_su_calls($stub));

        $start = zpms_audit_test_last_id();
        $r = $admin->postWithHeaders('/system/update/run', ['branch' => 'release/1.2', 'fw' => '1', 'fw_branch' => 'fw-dev', 'backup' => '1', 'update_sh' => '1',
            'apply_schema' => '1', 'dictionary' => '1', 'confirm' => '1'], ['X-CSRF-Token' => $token]);
        assert_equal(200, $r['status'], 'run failed: ' . $r['body']);
        assert_equal('preview --branch dev --backup', zpms_su_calls($stub)[0]);
        assert_equal('run --branch release/1.2 --fw --fw-branch fw-dev --backup --apply-schema', zpms_su_calls($stub)[1]);

        $rows = zpms_audit_test_rows($start, 'system_update_started');
        assert_equal(1, count($rows), 'starting an update was not audit-logged exactly once');
        assert_contains('branch=release/1.2', (string)$rows[0]['detail']);
        assert_contains('schema=1', (string)$rows[0]['detail']);

        $r = $admin->get('/system/update/status');
        $j = json_decode(preg_replace('/^[^{]*/', '', $r['body']), true);
        assert_true($j['ok'] && $j['running'] === false && $j['last']['result'] === 'success' && strpos($j['log'], 'line two') !== false, 'status endpoint: ' . $r['body']);
        $r = $admin->get('/system/update/branches');
        $j = json_decode(preg_replace('/^[^{]*/', '', $r['body']), true);
        assert_true($j['ok'] && in_array('hotfix', $j['repos']['app']['branches'], true), 'branches endpoint: ' . $r['body']);
    });
}
