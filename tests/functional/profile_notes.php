<?php
/* The notes box on the home page, saved through the userprofile module
 * (POST /profile, action=save_notes): saved
 * and shown back, cleaned of anything scriptable, private to its owner,
 * CSRF-protected, size-limited, and audited without its text. Driven over
 * real HTTP like the other functional suites. */

require_once __DIR__ . '/../../web/modules/userprofile/notes_sanitizer.php';

const ZPMS_NOTES_TEST_SECRET = 'Μυστική σημείωση 4711';

function zpms_notes_test_user_id(string $uname): int {
    $st = dbConnection::getConnection()->prepare('SELECT id FROM users WHERE uname = ?');
    $st->execute([$uname]);
    return (int)$st->fetchColumn();
}

function zpms_notes_test_stored(string $uname): ?string {
    $st = dbConnection::getConnection()->prepare('SELECT notes FROM user_notes WHERE user_id = ?');
    $st->execute([zpms_notes_test_user_id($uname)]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string)$v;
}

/** Signs in, opens the home page and returns [client, csrf token, page body]. */
function zpms_notes_test_session(string $baseUrl, string $who): array {
    $http = new TestHttpClient($baseUrl);
    if ($who === 'secretary') {
        $exists = (int)dbConnection::getConnection()->query("SELECT COUNT(*) FROM users WHERE uname = '" . TestFixtures::SECRETARY_USERNAME . "'")->fetchColumn();
        if (!$exists) TestFixtures::createSecretaryUser();
        TestFixtures::loginAsSecretary($http);
    } else {
        TestFixtures::loginAsTestUser($http);
    }
    $page = $http->get('/');
    assert_equal(200, $page['status'], 'GET / did not return 200');
    $token = TestHttpClient::extractCsrfToken($page['body']);
    assert_not_null($token, 'no csrf_token on the home page');
    return [$http, $token, $page['body']];
}

function zpms_notes_test_save(TestHttpClient $http, ?string $token, string $notes): array {
    $fields = ['action' => 'save_notes', 'notes' => $notes, 'use_ajax' => '1'];
    if ($token !== null) $fields['csrf_token'] = $token;
    return $http->postWithHeaders('/profile', $fields, ['X-Requested-With' => 'XMLHttpRequest']);
}

function zpms_functional_profile_notes(TestRunner $runner, string $baseUrl): void {
    $runner->add('the editor is on the home page (not on /profile); an autosave stores the notes and the page shows them back', function () use ($baseUrl) {
        TestSchema::assertSafeToMutate();
        [$http, $token, $body] = zpms_notes_test_session($baseUrl, 'user');
        assert_contains('id="profile-notes-editor"', $body, 'notes editor missing from the home page');
        assert_contains('quill.js', $body, 'Quill script not loaded on the home page');
        assert_contains('profile-notes.js', $body, 'profile-notes.js not loaded on the home page');
        assert_not_contains('id="profile-notes-editor"', $http->get('/profile')['body'], 'the notes editor is still on /profile');

        $html = '<h2>Λίστα</h2><p>Πρώτη <strong>γραμμή</strong> ' . ZPMS_NOTES_TEST_SECRET . '</p>'
              . '<ol><li data-list="bullet">ένα</li><li data-list="ordered">δύο</li></ol>';
        $res = zpms_notes_test_save($http, $token, $html);
        assert_equal(200, $res['status'], 'save_notes did not return 200: ' . $res['body']);
        // The body ends with the JSON reply. (The test server prints PHP
        // warnings about the DB constants being defined twice ahead of it --
        // a harness quirk: tests/lib defines them before web/index.php
        // includes config/db.php -- so only the end is checked.)
        assert_true(str_ends_with(trim($res['body']), '{"ok":true}'), 'unexpected JSON reply: ' . substr($res['body'], -300));
        assert_equal($html, zpms_notes_test_stored(TestFixtures::USERNAME), 'stored notes differ from what was sent');

        $page = $http->get('/');
        assert_contains($html, $page['body'], 'saved notes not rendered into the editor');
    });

    $runner->add('script, event handlers and javascript: links are stripped before saving', function () use ($baseUrl) {
        [$http, $token] = zpms_notes_test_session($baseUrl, 'user');
        $evil = '<p>ok<script>alert(1)</script><img src=x onerror="alert(2)"></p>'
              . '<p onclick="alert(3)"><a href="javascript:alert(4)">a</a> <a href="https://example.com">b</a></p>'
              . '<svg><script>alert(5)</script></svg><iframe src="https://example.com"></iframe>'
              . '<p style="color: rgb(230, 0, 0); background-image: url(x)">c</p>';
        $res = zpms_notes_test_save($http, $token, $evil);
        assert_equal(200, $res['status'], 'save_notes refused the input');
        $stored = (string)zpms_notes_test_stored(TestFixtures::USERNAME);
        foreach (['<script', 'onerror', 'onclick', 'javascript:', '<svg', '<iframe', '<img', 'url('] as $bad) {
            assert_not_contains($bad, $stored, "stored notes still contain $bad");
        }
        assert_contains('<a href="https://example.com" target="_blank" rel="noopener noreferrer">b</a>', $stored, 'safe link not kept');
        assert_contains('style="color: rgb(230, 0, 0);"', $stored, 'text colour not kept');

        // Emptying the editor clears the notes.
        zpms_notes_test_save($http, $token, '<p><br></p>');
        assert_equal('', zpms_notes_test_stored(TestFixtures::USERNAME), 'an emptied editor did not clear the notes');
    });

    $runner->add('notes are private: another account neither sees nor overwrites them', function () use ($baseUrl) {
        [$http, $token] = zpms_notes_test_session($baseUrl, 'user');
        zpms_notes_test_save($http, $token, '<p>' . ZPMS_NOTES_TEST_SECRET . '</p>');

        [$other, $otherToken, $otherBody] = zpms_notes_test_session($baseUrl, 'secretary');
        assert_not_contains(ZPMS_NOTES_TEST_SECRET, $otherBody, "another account's home page shows the notes");

        // A forged user id in the request is ignored: the row is always the signer's own.
        $other->postWithHeaders('/profile', ['action' => 'save_notes', 'notes' => '<p>secretary</p>',
            'user_id' => (string)zpms_notes_test_user_id(TestFixtures::USERNAME), 'csrf_token' => $otherToken, 'use_ajax' => '1'],
            ['X-Requested-With' => 'XMLHttpRequest']);
        assert_equal('<p>' . ZPMS_NOTES_TEST_SECRET . '</p>', zpms_notes_test_stored(TestFixtures::USERNAME), "the other account overwrote the notes");
        assert_equal('<p>secretary</p>', zpms_notes_test_stored(TestFixtures::SECRETARY_USERNAME), 'the other account could not save its own notes');

        // Signed out: nothing is saved.
        $anon = new TestHttpClient($baseUrl);
        $res = $anon->post('/profile', ['action' => 'save_notes', 'notes' => '<p>anon</p>']);
        assert_true($res['status'] !== 200 || !str_contains($res['body'], '"ok":true'), 'a signed-out request was accepted');
    });

    $runner->add('a missing or wrong CSRF token, or oversized notes, are refused and change nothing', function () use ($baseUrl) {
        [$http, $token] = zpms_notes_test_session($baseUrl, 'user');
        zpms_notes_test_save($http, $token, '<p>before</p>');

        $res = zpms_notes_test_save($http, null, '<p>no token</p>');
        assert_equal(403, $res['status'], 'a save without a CSRF token was not refused');
        $res = zpms_notes_test_save($http, 'tampered', '<p>bad token</p>');
        assert_equal(403, $res['status'], 'a save with a wrong CSRF token was not refused');

        $res = zpms_notes_test_save($http, $token, '<p>' . str_repeat('x', ZPMS_NOTES_MAX_BYTES) . '</p>');
        assert_equal(413, $res['status'], 'oversized notes were not refused');

        assert_equal('<p>before</p>', zpms_notes_test_stored(TestFixtures::USERNAME), 'a refused save changed the notes');
    });

    $runner->add('a change is audited by event only -- never the text; an unchanged save is not audited', function () use ($baseUrl) {
        [$http, $token] = zpms_notes_test_session($baseUrl, 'user');
        $db = dbConnection::getConnection();
        $start = (int)$db->query('SELECT COALESCE(MAX(id), 0) FROM audit_log')->fetchColumn();

        zpms_notes_test_save($http, $token, '<p>' . ZPMS_NOTES_TEST_SECRET . ' audit</p>');
        zpms_notes_test_save($http, $token, '<p>' . ZPMS_NOTES_TEST_SECRET . ' audit</p>');

        $rows = $db->query('SELECT * FROM audit_log WHERE id > ' . $start . " AND event_type = 'profile_notes_updated'")->fetchAll(PDO::FETCH_ASSOC);
        assert_equal(1, count($rows), 'expected exactly one profile_notes_updated row');
        foreach ($rows as $row) {
            foreach ($row as $col => $val) {
                assert_true($val === null || !str_contains((string)$val, ZPMS_NOTES_TEST_SECRET), "audit_log.$col contains the notes text");
            }
        }
    });
}
