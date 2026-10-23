<?php
require_once __DIR__ . '/schedule.php';
/* Session lifetime (zeusfw core/lib/SessionPolicy.php, config in settings.info.yaml): a sliding
 * idle logout, a hard cap, a "remember me" session with no idle limit, and the two endpoints the
 * browser warning uses. The rules are unit-tested with a Kernel built from a throw-away config;
 * the HTTP part signs in for real and ages the server-side session file to test the logout. */

function zpms_session_with_policy(array $cfg, callable $fn): void {
    $k = (new ReflectionClass('Kernel'))->newInstanceWithoutConstructor();
    $p = new ReflectionProperty('Kernel', 'config');
    $p->setAccessible(true);
    $p->setValue($k, $cfg);
    $old = $GLOBALS['kernel'] ?? null;
    $oldSession = $_SESSION ?? [];
    $GLOBALS['kernel'] = $k;
    $_SESSION = [];
    try { $fn(); } finally { $GLOBALS['kernel'] = $old; $_SESSION = $oldSession; }
}

/** The session file of this client, as PHP's default serialization: name|serialized... */
function zpms_session_file(TestHttpClient $http): string {
    $id = $http->cookie('zpms_sess');
    assert_not_null($id, 'no zpms_sess cookie (own session name)');
    return ZPMS_TEST_APPDIR . '/var/sessions/sess_' . $id;
}

function zpms_session_field(string $file, string $name): ?int {
    return preg_match('/' . preg_quote($name, '/') . '\|i:(\d+);/', (string)file_get_contents($file), $m) ? (int)$m[1] : null;
}

function zpms_session_set(string $file, string $name, int $value): void {
    $raw = (string)file_get_contents($file);
    $new = preg_replace('/' . preg_quote($name, '/') . '\|i:\d+;/', $name . '|i:' . $value . ';', $raw, -1, $n);
    assert_equal(1, $n, "$name not found in the session file");
    file_put_contents($file, $new);
}

function zpms_session_json(array $res): array {
    $start = strpos($res['body'], '{');
    $j = $start === false ? null : json_decode(substr($res['body'], $start), true);
    assert_true(is_array($j), 'not JSON: ' . substr($res['body'], 0, 200));
    return $j;
}

function zpms_session_policy_tests(TestRunner $runner, string $baseUrl): void {
    $cfg = ['session_idle_minutes' => 60, 'session_max_hours' => 12, 'remember_me_days' => 15];

    $runner->add('policy: off unless session_idle_minutes is set; values are clamped', function () use ($cfg) {
        zpms_session_with_policy([], fn() => assert_equal(null, zeusfw_session_policy()));
        zpms_session_with_policy($cfg, function () {
            $p = zeusfw_session_policy();
            assert_equal([3600, 43200, 1296000, 120], [$p['idle'], $p['cap'], $p['remember'], $p['warn']]);
        });
        zpms_session_with_policy(['session_idle_minutes' => [30, 45]] + $cfg, fn() => assert_equal(2700, zeusfw_session_policy()['idle']));
    });

    $runner->add('expiry: idle limit, hard cap, remembered sessions have no idle limit', function () use ($cfg) {
        zpms_session_with_policy($cfg, function () {
            $p = zeusfw_session_policy();
            $now = 1000000;
            $_SESSION = ['zfw_login_at' => $now - 10000, 'zfw_last_seen' => $now - 100, 'zfw_remember' => 0, 'zfw_ttl' => $p['cap']];
            assert_equal(null, zeusfw_session_expired($p, $now), 'fresh');
            $_SESSION['zfw_last_seen'] = $now - 3601;
            assert_equal('idle', zeusfw_session_expired($p, $now), 'idle over the limit');
            $_SESSION['zfw_last_seen'] = $now - 3599;
            assert_equal(null, zeusfw_session_expired($p, $now), 'idle just inside');
            $_SESSION = ['zfw_login_at' => $now - 43201, 'zfw_last_seen' => $now - 1, 'zfw_remember' => 0, 'zfw_ttl' => $p['cap']];
            assert_equal('cap', zeusfw_session_expired($p, $now), 'busy, but past the 12 h cap');
            // remembered
            $_SESSION = ['zfw_login_at' => $now - 5 * 86400, 'zfw_last_seen' => $now - 5 * 86400, 'zfw_remember' => 1, 'zfw_ttl' => $p['remember']];
            assert_equal(null, zeusfw_session_expired($p, $now), 'remembered: days idle is fine');
            $_SESSION['zfw_login_at'] = $now - 15 * 86400 - 1;
            assert_equal('cap', zeusfw_session_expired($p, $now), 'remembered: past 15 days');
        });
    });

    $runner->add('ttl: a missing, oversized or string value falls back to the short cap; no last_seen counts from login', function () use ($cfg) {
        zpms_session_with_policy($cfg, function () {
            $p = zeusfw_session_policy();
            $now = 1000000;
            foreach ([null, 99 * 86400, '999999', -5, 10] as $bad) {
                $_SESSION = ['zfw_login_at' => $now - 50000, 'zfw_last_seen' => $now - 10, 'zfw_remember' => 0];
                if ($bad !== null) $_SESSION['zfw_ttl'] = $bad;
                assert_equal('cap', zeusfw_session_expired($p, $now), 'ttl ' . json_encode($bad) . ' must not extend a session');
            }
            // a "remember" ttl on an ordinary session is out of range too
            $_SESSION = ['zfw_login_at' => $now - 50000, 'zfw_last_seen' => $now - 10, 'zfw_remember' => 0, 'zfw_ttl' => $p['remember']];
            assert_equal('cap', zeusfw_session_expired($p, $now));
            $_SESSION = ['zfw_login_at' => $now - 4000, 'zfw_remember' => 0, 'zfw_ttl' => $p['cap']];
            assert_equal('idle', zeusfw_session_expired($p, $now), 'no last_seen: idle time counts from the login');
        });
    });

    $runner->add('seconds left: the smaller of idle and cap, never touches the session', function () use ($cfg) {
        zpms_session_with_policy($cfg, function () {
            $p = zeusfw_session_policy();
            $now = 1000000;
            $_SESSION = ['zfw_login_at' => $now - 600, 'zfw_last_seen' => $now - 100, 'zfw_remember' => 0, 'zfw_ttl' => $p['cap']];
            $before = $_SESSION;
            assert_equal(['left' => 3500, 'limit' => 'idle'], zeusfw_session_seconds_left($p, $now));
            $_SESSION['zfw_login_at'] = $now - 43200 + 60;
            assert_equal(['left' => 60, 'limit' => 'cap'], zeusfw_session_seconds_left($p, $now));
            $_SESSION = $before;
            zeusfw_session_seconds_left($p, $now);
            assert_equal($before, $_SESSION);
        });
    });

    $runner->add('HTTP: status is read-only, ping needs the CSRF header and is the only thing that renews', function () use ($baseUrl) {
        $http = new TestHttpClient($baseUrl);
        TestFixtures::loginAsTestUser($http);
        $file = zpms_session_file($http);
        $page = $http->get('/patients')['body'];
        assert_contains('id="zfw-session"', $page, 'the warning banner is on the page');
        assert_contains('/session/ping', $page);
        assert_not_contains('id="zfw-session"', (new TestHttpClient($baseUrl))->get('/login')['body'], 'not for a signed-out visitor');
        $token = zpms_sched_meta_token($page);
        assert_not_null($token);

        // age the idle clock by 10 minutes
        $seen = time() - 600;
        zpms_session_set($file, 'zfw_last_seen', $seen);
        $s = zpms_session_json($http->get('/session/status'));
        assert_true($s['ok'] && $s['enabled'] && !$s['remember'], 'status answers');
        assert_equal('idle', $s['limit']);
        assert_true($s['left'] <= 3000 && $s['left'] > 2900, 'about 50 minutes left, got ' . $s['left']);
        assert_equal($seen, zpms_session_field($file, 'zfw_last_seen'), 'GET /session/status must not touch the session');

        $noToken = $http->post('/session/ping', []);
        assert_equal(403, $noToken['status'], 'ping without the CSRF header');
        assert_equal($seen, zpms_session_field($file, 'zfw_last_seen'), 'a refused ping renews nothing');

        $ping = zpms_session_json($http->postWithHeaders('/session/ping', [], ['X-CSRF-Token' => $token]));
        assert_true($ping['ok'] && $ping['left'] > 3500, 'ping renews: ' . json_encode($ping));
        assert_true(zpms_session_field($file, 'zfw_last_seen') >= time() - 5, 'last_seen moved');

        // an ordinary page load counts as activity, polling routes do not
        zpms_session_set($file, 'zfw_last_seen', $seen);
        $http->get('/patients');
        assert_true(zpms_session_field($file, 'zfw_last_seen') >= time() - 5, 'a page load is activity');

        $anon = new TestHttpClient($baseUrl);
        assert_equal(401, $anon->get('/session/status')['status'], 'signed out: 401');
    });

    $runner->add('HTTP: idle and cap end the session (back to the sign-in page); the event is audited', function () use ($baseUrl) {
        $db = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $before = (int)$db->query('SELECT COALESCE(MAX(id),0) FROM audit_log')->fetchColumn();

        $http = new TestHttpClient($baseUrl);
        TestFixtures::loginAsTestUser($http);
        $file = zpms_session_file($http);
        assert_equal(200, $http->get('/patients')['status']);
        zpms_session_set($file, 'zfw_last_seen', time() - 3601);
        $res = $http->get('/patients');
        assert_equal(302, $res['status'], 'idle for over an hour: sent to the sign-in page, got ' . $res['status']);
        assert_contains('/login', (string)$res['location']);
        $login = $http->get('/login')['body'];
        assert_true(str_contains($login, 'Η σύνδεσή σας έληξε') || str_contains($login, 'Your session has ended'), 'and told why');

        $http2 = new TestHttpClient($baseUrl);
        TestFixtures::loginAsTestUser($http2);
        zpms_session_set(zpms_session_file($http2), 'zfw_login_at', time() - 12 * 3600 - 5);
        zpms_session_set(zpms_session_file($http2), 'zfw_last_seen', time());
        assert_equal(302, $http2->get('/patients')['status'], 'active, but past the 12 hour cap');

        $reasons = $db->query("SELECT detail FROM audit_log WHERE id > $before AND event_type = 'session_expired' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        assert_equal(['reason=idle', 'reason=cap'], $reasons);
    });

    $runner->add('HTTP: "remember me" has no idle limit; signing in without it drops the remember cookie', function () use ($baseUrl) {
        $http = new TestHttpClient($baseUrl);
        $page = $http->get('/login');
        $res = $http->post('/login', ['csrf_token' => TestHttpClient::extractCsrfToken($page['body']), 'username' => TestFixtures::USERNAME,
            'password' => TestFixtures::PASSWORD, 'rememberme' => 'on']);
        assert_equal(302, $res['status'], 'remember-me sign-in: ' . substr(strip_tags($res['body']), 0, 300));
        $file = zpms_session_file($http);
        assert_equal(1, zpms_session_field($file, 'zfw_remember'));
        assert_not_null($http->cookie('zeusfwrememberme'), 'remember-me cookie issued');
        zpms_session_set($file, 'zfw_last_seen', time() - 5 * 86400);
        assert_equal(200, $http->get('/patients')['status'], 'days idle, still signed in');
        $s = zpms_session_json($http->get('/session/status'));
        assert_true($s['remember'] && $s['left'] > 14 * 86400 - 3600, 'about 15 days left: ' . json_encode($s));

        // the same device signs in again WITHOUT remember me: the old cookie must go
        $page = $http->get('/logout');
        $http->resetCookies();
        $http3 = new TestHttpClient($baseUrl);
        TestFixtures::loginAsTestUser($http3);
        assert_equal(null, $http3->cookie('zeusfwrememberme'), 'no remember-me cookie without the checkbox');
        assert_equal(0, zpms_session_field(zpms_session_file($http3), 'zfw_remember'));
    });
}
