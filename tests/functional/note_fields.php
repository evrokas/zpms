<?php
/* Clinical tags, phase 1 (docs/CLINICAL_TAGS_PLAN.md): saving a patient note
 * or an appointment note through the real forms writes the right note_fields
 * rows; a soft delete hides them; a purge removes them; `note-fields:rebuild`
 * reproduces them; the starter lists are seeded once. Over real HTTP for the
 * web paths, straight against the test database for the CLI ones. */

require_once ZPMS_TEST_APPDIR . '/bin/lib/zpms_cli.php';

/** The note_fields rows of a note, in note order, without ids/times. */
function zpms_nf_test_rows(string $where, array $params = []): array {
    $st = dbConnection::getConnection()->prepare(
        "SELECT source, appointment_id, patient_id, tag_key, value_text, normalized, valid, pos, origin, rx_drug, rx_strength,
                rx_per_day_text, rx_per_dose, rx_per_day, rx_days, rx_note, rx_total, deleted
         FROM note_fields WHERE $where ORDER BY source, appointment_id, pos, id");
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** [tag, value] pairs, for short assertions. */
function zpms_nf_test_pairs(array $rows): array {
    return array_map(fn($r) => [$r['tag_key'], $r['value_text']], $rows);
}

function zpms_nf_test_token(TestHttpClient $http, string $path): string {
    $page = $http->get($path);
    assert_equal(200, $page['status'], "GET $path did not return 200");
    $token = TestHttpClient::extractCsrfToken($page['body']);
    assert_not_null($token, "no csrf_token on $path");
    return $token;
}

function zpms_functional_note_fields(TestRunner $runner, string $baseUrl): void {
    $http = new TestHttpClient($baseUrl);
    TestFixtures::loginAsTestUser($http);
    $db = dbConnection::getConnection();
    $state = [];

    $runner->add('note-lists:seed fills every list from config/note_tags.yaml once, never twice', function () use ($db) {
        TestSchema::assertSafeToMutate();
        $dry = zpms_note_lists_seed($db, 'test', '2026-01-01 00:00:00', false);
        assert_equal(0, (int)$db->query('SELECT COUNT(*) FROM note_list_entries')->fetchColumn(), 'a dry run wrote entries');
        $done = zpms_note_lists_seed($db, 'test', '2026-01-01 00:00:00', true);
        assert_equal($dry, $done, 'dry run and real run disagree');
        assert_equal(['dx', 'alg', 'exam', 'proc', 'thx', 'pt', 'med', 'rx'], array_keys($done), 'seeded tags');
        assert_equal(6, $done['rx'], 'drugs seeded');
        $lyrica = $db->query("SELECT aliases, rx_strengths FROM note_list_entries WHERE tag_key = 'rx' AND text = 'Lyrica'")->fetch(PDO::FETCH_ASSOC);
        assert_equal('λυρικα', $lyrica['aliases'], 'drug alias');
        assert_equal(['25mg', '75mg', '150mg', '300mg'], json_decode($lyrica['rx_strengths'], true), 'drug strengths');
        $exam = $db->query("SELECT next_words FROM note_list_entries WHERE tag_key = 'exam' LIMIT 1")->fetchColumn();
        assert_contains('ΟΜΣΣ', (string)$exam, 'exam entries have no next words');

        // A deactivated entry stays deactivated: a list with rows is never seeded again.
        $db->exec("UPDATE note_list_entries SET active = 0 WHERE tag_key = 'dx'");
        assert_equal([], zpms_note_lists_seed($db, 'test', '2026-01-02 00:00:00', true), 'a second seed added entries');
        assert_equal(0, (int)$db->query("SELECT COUNT(*) FROM note_list_entries WHERE tag_key = 'dx' AND active = 1")->fetchColumn(), 'deactivated entries came back');
        $db->exec("UPDATE note_list_entries SET active = 1 WHERE tag_key = 'dx'");
    });

    $runner->add('a new patient: the patient note\'s tags are stored', function () use ($http, $db, &$state) {
        TestSchema::assertSafeToMutate();
        $token = zpms_nf_test_token($http, '/patient/new');
        $res = $http->post('/patient/new', [
            'csrf_token' => $token, 'submit' => '1',
            'patient-name' => 'Ασθενής Ετικετών', 'patient-dob' => '1970-03-03', 'patient-amka' => '33300000001',
            'patient-telephone' => '', 'patient-address' => '', 'patient-email' => '',
            'patient-note' => "@αλ πενικιλλίνη· γράψτε στο a@b.gr\n@δγ σπονδυλική στένωση",
        ]);
        assert_equal(302, $res['status'], 'new-patient POST did not redirect');
        $p = $db->query("SELECT id, guid FROM patients WHERE pamka = '33300000001'")->fetch(PDO::FETCH_ASSOC);
        $state['pid'] = (int)$p['id'];
        $state['guid'] = $p['guid'];
        $rows = zpms_nf_test_rows('patient_id = ?', [$state['pid']]);
        assert_equal([['alg', 'πενικιλλίνη'], ['dx', 'σπονδυλική στένωση']], zpms_nf_test_pairs($rows), 'patient-note rows');
        assert_equal('patient', $rows[0]['source'], 'source');
        assert_null($rows[0]['appointment_id'], 'a patient-note row has an appointment id');
        assert_equal($state['guid'], $db->query("SELECT patient_guid FROM note_fields WHERE patient_id = {$state['pid']} LIMIT 1")->fetchColumn(), 'patient guid');
    });

    $runner->add('editing the patient note replaces its rows; a save that leaves the note alone keeps them', function () use ($http, $db, &$state) {
        TestSchema::assertSafeToMutate();
        $pid = $state['pid'];
        $form = [
            'patient-name' => 'Ασθενής Ετικετών', 'patient-dob' => '1970-03-03', 'patient-amka' => '33300000001',
            'patient-telephone' => '', 'patient-address' => '', 'patient-email' => '',
            'patient-note' => '@αλλεργία λάτεξ. Ιστορικό: @χειρ πεταλεκτομή Ο4-Ο5 το 2019.',
        ];
        $token = zpms_nf_test_token($http, "/patient/$pid/edit");
        $res = $http->post("/patient/$pid/edit", $form + ['csrf_token' => $token, 'use_ajax' => '1']);
        assert_true(str_ends_with(trim($res['body']), 'OK'), 'autosave did not answer OK: ' . substr($res['body'], -200));
        $rows = zpms_nf_test_rows('patient_id = ?', [$pid]);
        assert_equal([['alg', 'λάτεξ'], ['proc', 'πεταλεκτομή Ο4-Ο5 το 2019'], ['lvl', 'Ο4-Ο5']], zpms_nf_test_pairs($rows), 'rows after the edit');
        $ids = $db->query("SELECT id, tag_key, origin FROM note_fields WHERE patient_id = $pid ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        assert_equal((int)$ids[1]['id'], (int)$ids[2]['origin'], 'the inner level does not point at its procedure row');
        assert_equal('L4-L5', $rows[2]['normalized'], 'inner level not normalised');

        // Same note, another field changed: the rows stay as they are (same ids).
        $form['patient-telephone'] = '2100000001';
        $res = $http->post("/patient/$pid/edit", $form + ['csrf_token' => $token, 'use_ajax' => '1']);
        assert_true(str_ends_with(trim($res['body']), 'OK'), 'second autosave failed');
        $again = $db->query("SELECT id FROM note_fields WHERE patient_id = $pid ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        assert_equal(array_column($ids, 'id'), $again, 'rows were rewritten although the note did not change');

        // The audit log carries field names only, never a value.
        $detail = (string)$db->query("SELECT GROUP_CONCAT(detail) FROM audit_log WHERE patient_id = $pid")->fetchColumn();
        assert_not_contains('λάτεξ', $detail, 'a tagged value reached the audit log');
    });

    $runner->add('a new appointment and editing its note: rows follow the note, prescriptions are split into parts', function () use ($http, $db, &$state) {
        TestSchema::assertSafeToMutate();
        $pid = $state['pid'];
        $token = zpms_nf_test_token($http, "/appointment/$pid/newappointment");
        $res = $http->post("/appointment/$pid/newappointment", [
            'csrf_token' => $token, 'submit' => '1', 'appointment-date' => '2026-10-01T10:00', 'appointment-place' => 'test-clinic',
            'appointment-notes' => 'Η μαγνητική δείχνει @δγ κήλη μεσοσπονδυλίου δίσκου, στο @επ Ο4-Ο5 με πίεση της ρίζας.',
        ]);
        assert_equal(302, $res['status'], 'new-appointment POST did not redirect');
        $aid = (int)$db->query("SELECT id FROM appointments WHERE pguid = " . $db->quote($state['guid']) . ' ORDER BY id DESC LIMIT 1')->fetchColumn();
        $state['aid'] = $aid;
        $rows = zpms_nf_test_rows("source = 'appointment' AND appointment_id = ?", [$aid]);
        assert_equal([['dx', 'κήλη μεσοσπονδυλίου δίσκου'], ['lvl', 'Ο4-Ο5']], zpms_nf_test_pairs($rows), 'appointment rows');
        assert_equal($pid, (int)$rows[0]['patient_id'], 'patient id');
        assert_equal(20, (int)$rows[0]['pos'], 'position of the tag');

        $token = zpms_nf_test_token($http, "/patient/$pid/edit");
        $res = $http->post("/appointment/$aid/edit", [
            'csrf_token' => $token, 'use_ajax' => '1', "appointment-date-$aid" => '2026-10-01', 'appointment-place' => 'test-clinic',
            'appointment-notes' => "@δγ ριζοπάθεια.\n@συν λυρικα 150 1χ2 για 30 ημέρες, μετά το φαγητό· @επ Ο6",
        ]);
        assert_true(str_ends_with(trim($res['body']), 'OK'), 'appointment autosave did not answer OK');
        $rows = zpms_nf_test_rows("source = 'appointment' AND appointment_id = ?", [$aid]);
        assert_equal(['dx', 'rx', 'lvl'], array_column($rows, 'tag_key'), 'rows after the edit');
        $rx = $rows[1];
        assert_equal('Lyrica', $rx['rx_drug'], 'drug (from its Greek spelling, seeded list)');
        assert_equal('150mg', $rx['rx_strength'], 'bare strength in the drug\'s unit');
        assert_equal('1χ2', $rx['rx_per_day_text'], 'per day as written');
        assert_equal(1.0, (float)$rx['rx_per_dose'], 'per dose');
        assert_equal(2, (int)$rx['rx_per_day'], 'per day');
        assert_equal(30, (int)$rx['rx_days'], 'days');
        assert_equal('μετά το φαγητό', $rx['rx_note'], 'note');
        assert_equal(60.0, (float)$rx['rx_total'], 'total tablets');
        assert_equal(0, (int)$rows[2]['valid'], 'an impossible level is stored as invalid');
        assert_equal('L6', $rows[2]['normalized'], 'impossible level normalised anyway');
        // The patient-note rows are untouched by an appointment save.
        assert_equal(3, count(zpms_nf_test_rows("source = 'patient' AND patient_id = ?", [$pid])), 'patient-note rows changed');
    });

    $runner->add('the patient page: note boxes carry the reading look; notes are escaped in the textarea (no script from a note)', function () use ($http, $db, &$state) {
        TestSchema::assertSafeToMutate();
        $pid = $state['pid'];
        $evil = '</textarea><script>alert(1)</script><b>x</b> @δγ κήλη & «στένωση»';
        $a = new appointmentsClass(['guid' => guid(), 'cuser' => 'test', 'cdate' => getDBtime(), 'adate' => '2027-01-01 10:00:00', 'aplace' => '',
            'anote' => $evil, 'atype' => 'appointment', 'pguid' => $state['guid']]);
        $a->insert();
        $page = $http->get("/patient/$pid/edit");
        assert_equal(200, $page['status'], 'patient page did not load');
        $body = $page['body'];
        assert_not_contains('<script>alert(1)</script>', $body, 'a note broke out of its textarea (stored XSS)');
        assert_contains('&lt;/textarea&gt;&lt;script&gt;alert(1)&lt;/script&gt;', $body, 'the note is not in the textarea, escaped');
        assert_equal(1, substr_count($body, 'id="zt-spec"'), 'the tag definitions must be on the page exactly once');
        assert_contains('.zt-c-rose{', $body, 'palette classes missing');
        assert_contains('js/note-box.js', $body, 'note-box.js not loaded');
        assert_contains('css/note-tags.css', $body, 'note-tags.css not loaded');
        assert_true(substr_count($body, 'class="zt-box"') >= 3, 'not every note (patient + appointments) is in a note box');
        // Reading look: tag word gone, value coloured, the rest escaped.
        assert_contains('<span class="zt-v zt-c-rose" data-tag="dx" data-o="', $body, 'reading look of a diagnosis missing');
        assert_contains('&lt;b&gt;x&lt;/b&gt; </span>', $body, 'text before the tag not escaped in the reading look');
        assert_contains('>κήλη &amp; «στένωση»</span>', $body, 'value not in the reading look');
        // The collapsed row and the header line show the note without tag words.
        assert_true((bool)preg_match('~<span class="ar-note">[^<]*κήλη~u', $body), 'row preview missing');
        assert_true(!preg_match('~<span class="ar-note">[^<]*@δγ~u', $body), 'row preview shows the tag word');
        assert_contains('<span id="pi-note">λάτεξ. Ιστορικό: πεταλεκτομή Ο4-Ο5 το 2019.</span>', $body, 'header note line not without tag words');
        $db->prepare('DELETE FROM appointments WHERE id = ?')->execute([$a->getid()]);

        $form = $http->get("/appointment/$pid/newappointment");
        assert_contains('class="zt-box"', $form['body'], 'the new-appointment form has no note box');
        assert_contains('id="zt-spec"', $form['body'], 'the new-appointment form has no tag definitions');
    });

    $runner->add('suggestions: lists and previous entries (most used first), never deleted or invalid rows; refused to accounts that write no notes', function () use ($http, $baseUrl, $db, &$state) {
        TestSchema::assertSafeToMutate();
        $get = function (TestHttpClient $c) {
            $r = $c->get('/notes/suggestions');
            $i = strpos($r['body'], '{"tags"');
            return [$r['status'], $i === false ? null : json_decode(substr($r['body'], $i), true)];
        };
        // Two more notes saying «ριζοπάθεια», one soft-deleted, one with an invalid level.
        $mk = function (string $note, ?string $deleted = null) use ($state) {
            $a = new appointmentsClass(['guid' => guid(), 'cuser' => 'test', 'cdate' => getDBtime(), 'adate' => '2026-12-01 10:00:00', 'aplace' => '',
                'anote' => $note, 'atype' => 'appointment', 'pguid' => $state['guid']]);
            $a->insert();
            zpms_note_fields_sync_appointment($a);
            if ($deleted) zpms_note_fields_hide_appointments(dbConnection::getConnection(), [(int)$a->getid()], $deleted);
            return (int)$a->getid();
        };
        $ids = [$mk('@δγ Ριζοπάθεια'), $mk('@δγ ριζοπάθεια @επ Ο9'), $mk('@δγ μυστική διάγνωση', '2026-01-01 00:00:00')];

        [$status, $data] = $get($http);
        assert_equal(200, $status, 'suggestions refused to a doctor');
        $dx = $data['tags']['dx'] ?? null;
        assert_not_null($dx, 'no dx suggestions');
        assert_equal('Κήλη μεσοσπονδυλίου δίσκου', $dx['list'][0]['text'], 'fixed list not in order');
        $prev = array_column($dx['previous'], 'count', 'text');
        $rizo = array_values(array_filter($dx['previous'], fn($p) => zpms_note_fold($p['text']) === 'ριζοπαθεια'));
        assert_equal(3, $rizo[0]['count'] ?? null, 'previous entries not counted across spellings');
        assert_equal('ριζοπαθεια', zpms_note_fold($dx['previous'][0]['text']), 'most used not first');
        assert_true(!isset($prev['μυστική διάγνωση']), 'a soft-deleted note leaked into the suggestions');
        assert_true(!in_array('Ο9', array_column($data['tags']['lvl']['previous'], 'text'), true), 'an invalid level is suggested');
        $rx = array_column($data['tags']['rx']['list'], null, 'text');
        assert_equal(['λυρικα'], $rx['Lyrica']['aliases'] ?? null, 'drug aliases missing');
        assert_equal(['25mg', '75mg', '150mg', '300mg'], $rx['Lyrica']['strengths'] ?? null, 'drug strengths missing');

        $sec = new TestHttpClient($baseUrl);
        if (!(int)$db->query("SELECT COUNT(*) FROM users WHERE uname = '" . TestFixtures::SECRETARY_USERNAME . "'")->fetchColumn()) TestFixtures::createSecretaryUser();
        TestFixtures::loginAsSecretary($sec);
        [$status, $data] = $get($sec);
        assert_equal(403, $status, 'an account that writes no notes got suggestions');
        assert_equal([], (array)($data['tags'] ?? []), 'a refused request still carried data');
        $anon = new TestHttpClient($baseUrl);
        $r = $anon->get('/notes/suggestions');
        assert_not_contains('Ριζοπάθεια', $r['body'], 'suggestions reachable without signing in');

        foreach ($ids as $id) {
            $db->prepare('DELETE FROM note_fields WHERE appointment_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM appointments WHERE id = ?')->execute([$id]);
        }
    });

    $runner->add('patient record: allergy line and «Κλινική εικόνα» (newest first, repeats counted, links, no deleted or invalid values)', function () use ($http, $db, &$state) {
        TestSchema::assertSafeToMutate();
        $pid = $state['pid'];
        $body = $http->get("/patient/$pid/edit")['body'];
        assert_true((bool)preg_match('~<div class="pi-allergies[^"]*"[^>]*>(.*?)</div>~su', $body, $m), 'no allergy line on the patient header');
        assert_contains('<a href="#patient-note">λάτεξ</a>', $m[1], 'allergy not linked to the patient note');
        assert_true((bool)preg_match('~<section class="clinical-picture"(.*?)</section>~su', $body, $cp), 'no «Κλινική εικόνα» panel');
        $panel = $cp[1];
        foreach (['data-tag="alg"', 'data-tag="dx"', 'data-tag="rx"', 'data-tag="proc"', 'data-tag="lvl"'] as $needle) {
            assert_contains($needle, $panel, "panel box missing: $needle");
        }
        assert_contains('href="#app-', $panel, 'appointment values not linked to their card');
        assert_true((bool)preg_match('~class="cp-total">[^<]*60~u', $panel), 'prescription total not shown');
        assert_not_contains('>Ο6<', $panel, 'an impossible level is in the summary');

        // A soft-deleted appointment's values do not show; a repeat is counted once.
        $mk = function (string $note) use ($state) {
            $a = new appointmentsClass(['guid' => guid(), 'cuser' => 'test', 'cdate' => getDBtime(), 'adate' => '2026-12-24 10:00:00', 'aplace' => '',
                'anote' => $note, 'atype' => 'appointment', 'pguid' => $state['guid']]);
            $a->insert();
            zpms_note_fields_sync_appointment($a);
            return $a;
        };
        $gone = $mk('@δγ κρυφή διάγνωση');
        $gone->setdeleted('2026-12-25 00:00:00');
        $gone->update();
        zpms_note_fields_hide_appointments($db, [(int)$gone->getid()], '2026-12-25 00:00:00');
        $again = $mk('@δγ Ριζοπάθεια');
        $pic = zpms_note_clinical_picture($db, $pid, true, []);
        $dx = array_column($pic['tags']['dx']['entries'], null, 'text');
        assert_true(!isset($dx['κρυφή διάγνωση']), 'a deleted appointment\'s value is in the summary');
        $rizo = array_values(array_filter($pic['tags']['dx']['entries'], fn($e) => zpms_note_fold($e['text']) === 'ριζοπαθεια'));
        assert_equal(1, count($rizo), 'a repeated value is listed twice');
        assert_equal(2, $rizo[0]['count'], 'repeat not counted');
        assert_equal('Ριζοπάθεια', $rizo[0]['text'], 'not the newest occurrence first');
        assert_equal(['dx', 'lvl', 'alg'], array_slice(array_keys($pic['tags']), 0, 3), 'boxes not in the order of the tag file');

        // A viewer who may not see appointment notes gets the patient note only.
        $own = zpms_note_clinical_picture($db, $pid, false, []);
        assert_true(!isset($own['tags']['dx']) && isset($own['tags']['alg']), 'appointment values reached a viewer without appointment access');
        foreach ([$gone, $again] as $a) {
            $db->prepare('DELETE FROM note_fields WHERE appointment_id = ?')->execute([$a->getid()]);
            $db->prepare('DELETE FROM appointments WHERE id = ?')->execute([$a->getid()]);
        }
    });

    $runner->add('patients list: clinical filters by diagnosis (accents ignored), level (Greek or English), procedure; combined; only readable notes', function () use ($http, $db, &$state) {
        TestSchema::assertSafeToMutate();
        $mkp = function (string $name, string $note) {
            $p = new patientsClass(['guid' => guid(), 'cuser' => 'test', 'cdate' => getDBtime(), 'pname' => $name, 'pdob' => '1970-01-01 00:00:00',
                'pamka' => '', 'ptel' => '', 'paddr' => '', 'pemail' => '', 'pnote' => $note]);
            $p->insert();
            zpms_note_fields_sync_patient($p);
            return $p;
        };
        $a = $mkp('Φίλτρο Άλφα', '@δγ Σπονδυλική στένωση @επ Ο3-Ο4');
        $b = $mkp('Φίλτρο Βήτα', '@δγ σπονδυλική στένωση. @χειρ πεταλεκτομή L4-L5');
        $c = $mkp('Φίλτρο Γάμμα', '@δγ στένωση χωρίς σημασία');
        $c->setdeleted(getDBtime()); $c->update();
        zpms_note_fields_hide_patients($db, [$c->getguid()], getDBtime());
        $names = function (string $query) use ($http) {
            $body = $http->get('/patients?' . $query)['body'];
            preg_match_all('~Φίλτρο (Άλφα|Βήτα|Γάμμα)~u', $body, $m);
            $found = array_values(array_unique($m[1]));
            sort($found);
            return $found;
        };
        assert_equal(['Άλφα', 'Βήτα'], $names('tag_dx=' . rawurlencode('ΣΤΕΝΩΣΗ')), 'diagnosis filter (accents/case ignored, deleted patient left out)');
        assert_equal(['Βήτα'], $names('tag_lvl=' . rawurlencode('ο4-5')), 'level filter, Greek short form, level inside a procedure');
        assert_equal(['Άλφα'], $names('tag_lvl=' . rawurlencode('L3-L4')), 'level filter, English');
        assert_equal([], $names('tag_lvl=' . rawurlencode('Ο3')), 'a single vertebra is not the disc level');
        assert_equal(['Βήτα'], $names('tag_proc=' . rawurlencode('πεταλ') . '&tag_dx=' . rawurlencode('στένωση')), 'two filters combined');
        assert_equal([], $names('tag_dx=' . rawurlencode('%')), 'a % in the filter must be literal');
        $page = $http->get('/patients?tag_dx=' . rawurlencode('στένωση'))['body'];
        assert_contains('name="tag_dx" value="στένωση"', $page, 'the filter value is not kept in the form');
        assert_contains('tag_dx=', $page, 'the pager/sort links lose the filter');

        // Appointment notes count only for viewers who may read them.
        $bind = [];
        $w = zpms_note_patient_filter_where(['dx' => 'κήλη'], true, false, 'p.id', $bind);
        assert_contains("'patient'", $w[0], 'filter not limited to patient notes');
        assert_not_contains("'appointment'", $w[0], 'appointment notes searched for a viewer who may not read them');
        foreach ([$a, $b, $c] as $p) {
            $db->prepare('DELETE FROM note_fields WHERE patient_id = ?')->execute([$p->getid()]);
            $db->prepare('DELETE FROM patients WHERE id = ?')->execute([$p->getid()]);
        }
    });

    $runner->add('Settings «Λίστες σημειώσεων»: add, edit, reorder, deactivate, promote; CSRF; audited without text; refused without settings-manage', function () use ($http, $baseUrl, $db) {
        TestSchema::assertSafeToMutate();
        $page = $http->get('/settings/note-lists?tag=exam');
        assert_equal(200, $page['status'], 'lists page did not load');
        assert_contains('Μαγνητική τομογραφία', $page['body'], 'the exam list is not shown');
        $token = TestHttpClient::extractCsrfToken($page['body']);
        $post = fn(array $f) => $http->post('/settings/note-lists', $f + ['csrf_token' => $token, 'tag' => 'exam']);

        $count = fn() => (int)$db->query("SELECT COUNT(*) FROM note_list_entries WHERE tag_key = 'exam'")->fetchColumn();
        $n = $count();
        $http->post('/settings/note-lists', ['tag' => 'exam', 'action' => 'add', 'text' => 'Χωρίς token']);
        assert_equal($n, $count(), 'an add without a CSRF token was accepted');

        $res = $post(['action' => 'add', 'text' => '  Υπερηχογράφημα   κοιλίας ', 'aliases' => 'υπερηχο, US', 'next_words' => 'άνω, κάτω, άνω']);
        assert_equal(302, $res['status'], 'add did not redirect');
        $row = $db->query("SELECT * FROM note_list_entries WHERE tag_key = 'exam' AND text = 'Υπερηχογράφημα κοιλίας'")->fetch(PDO::FETCH_ASSOC);
        assert_not_null($row ?: null, 'entry not added (spaces not tidied?)');
        assert_equal('υπερηχο,US', $row['aliases'], 'aliases');
        assert_equal(['άνω', 'κάτω'], json_decode($row['next_words'], true), 'next words (duplicates dropped)');
        $post(['action' => 'add', 'text' => 'Υπερηχογράφημα κοιλίας']);
        assert_equal($n + 1, $count(), 'a duplicate entry was added');

        // Move it to the top, then deactivate it.
        for ($i = 0; $i < $n; $i++) $post(['action' => 'move', 'id' => $row['id'], 'dir' => 'up']);
        $first = $db->query("SELECT id FROM note_list_entries WHERE tag_key = 'exam' ORDER BY sort, id LIMIT 1")->fetchColumn();
        assert_equal((int)$row['id'], (int)$first, 'move up did not reach the top');
        $post(['action' => 'update', 'id' => $row['id'], 'text' => 'Υπερηχογράφημα κοιλίας', 'aliases' => '', 'next_words' => 'άνω']);
        assert_equal(0, (int)$db->query("SELECT active FROM note_list_entries WHERE id = {$row['id']}")->fetchColumn(), 'unticked entry still active');
        $sugg = $http->get('/notes/suggestions')['body'];
        assert_not_contains('Υπερηχογράφημα κοιλίας', $sugg, 'an inactive entry is still suggested');
        // An entry of another tag cannot be changed through this tag.
        $other = (int)$db->query("SELECT id FROM note_list_entries WHERE tag_key = 'dx' LIMIT 1")->fetchColumn();
        $post(['action' => 'update', 'id' => $other, 'text' => 'Αλλαγμένο']);
        assert_equal(0, (int)$db->query("SELECT COUNT(*) FROM note_list_entries WHERE text = 'Αλλαγμένο'")->fetchColumn(), 'an entry of another tag was changed');

        // Promote a frequent free-text entry (shown on the page first).
        foreach (['Φ1', 'Φ2'] as $nm) {
            $p = new patientsClass(['guid' => guid(), 'cuser' => 'test', 'cdate' => getDBtime(), 'pname' => $nm, 'pdob' => '1970-01-01 00:00:00',
                'pamka' => '', 'ptel' => '', 'paddr' => '', 'pemail' => '', 'pnote' => '@εξ πυελογραφία']);
            $p->insert();
            zpms_note_fields_sync_patient($p);
            $tmp[] = $p;
        }
        $page = $http->get('/settings/note-lists?tag=exam')['body'];
        assert_true((bool)preg_match('~class="nl-ftext">πυελογραφία<~u', $page), 'a value used twice is not offered for the list');
        $post(['action' => 'promote', 'text' => 'πυελογραφία']);
        assert_equal(1, (int)$db->query("SELECT COUNT(*) FROM note_list_entries WHERE tag_key = 'exam' AND text = 'πυελογραφία'")->fetchColumn(), 'promote did not add the entry');

        $details = (string)$db->query("SELECT GROUP_CONCAT(detail SEPARATOR '|') FROM audit_log WHERE event_type = 'note_list_changed'")->fetchColumn();
        assert_contains('tag=exam action=add', $details, 'list change not audited');
        assert_not_contains('Υπερηχογράφημα', $details, 'entry text reached the audit log');
        assert_not_contains('πυελογραφία', $details, 'promoted text reached the audit log');

        $sec = new TestHttpClient($baseUrl);
        TestFixtures::loginAsSecretary($sec);
        $r = $sec->get('/settings/note-lists');
        assert_not_contains('nl-edit', $r['body'], 'a secretary (no settings-manage) can see the lists page');

        foreach ($tmp as $p) {
            $db->prepare('DELETE FROM note_fields WHERE patient_id = ?')->execute([$p->getid()]);
            $db->prepare('DELETE FROM patients WHERE id = ?')->execute([$p->getid()]);
        }
        $db->exec("DELETE FROM note_list_entries WHERE text IN ('Υπερηχογράφημα κοιλίας', 'πυελογραφία')");
    });

    $runner->add('a pending appointment turned into a patient record: its notes are parsed then', function () use ($http, $db, &$state) {
        TestSchema::assertSafeToMutate();
        $pending = new pendingAppointmentsClass([
            'id' => null, 'cuser' => TestFixtures::USERNAME, 'cdate' => getDBtime(), 'guid' => guid(),
            'patient_name' => 'Ασθενής Από Εκκρεμές', 'patient_phone' => '2100000777', 'patient_amka' => '', 'patient_email' => '',
            'appointment_datetime' => '2026-11-02 09:00:00', 'location' => 'test-clinic', 'notes' => 'Πονάει. @εξ ακτινογραφία ΟΜΣΣ',
        ]);
        $pending->insert();
        assert_equal(0, (int)$db->query('SELECT COUNT(*) FROM note_fields WHERE value_text = ' . $db->quote('ακτινογραφία ΟΜΣΣ'))->fetchColumn(), 'a pending note was parsed before conversion');
        $token = zpms_nf_test_token($http, '/consultation/pending/' . $pending->getid() . '/convert');
        $res = $http->post('/consultation/pending/' . $pending->getid() . '/convert', [
            'csrf_token' => $token, 'patient-name' => 'Ασθενής Από Εκκρεμές', 'patient-telephone' => '2100000777', 'patient-amka' => '', 'patient-email' => '',
        ]);
        assert_equal(302, $res['status'], 'convert POST did not redirect');
        $row = $db->query("SELECT n.tag_key, n.value_text FROM note_fields n JOIN appointments a ON a.id = n.appointment_id
                           WHERE a.guid = (SELECT ap.guid FROM appointments ap JOIN pending_appointments pa ON pa.converted_appointment_id = ap.id WHERE pa.id = " . (int)$pending->getid() . ')')->fetch(PDO::FETCH_ASSOC);
        assert_equal(['tag_key' => 'exam', 'value_text' => 'ακτινογραφία ΟΜΣΣ'], $row, 'converted appointment note not parsed');
    });

    $runner->add('deleting an appointment hides its rows; deleting the patient hides all of theirs', function () use ($http, $db, &$state) {
        TestSchema::assertSafeToMutate();
        $pid = $state['pid'];
        $aid = $state['aid'];
        $token = zpms_nf_test_token($http, "/patient/$pid/edit");
        $res = $http->post("/appointment/$aid/delete", ['csrf_token' => $token]);
        assert_equal(302, $res['status'], 'appointment delete did not redirect');
        $deleted = $db->query("SELECT deleted FROM appointments WHERE id = $aid")->fetchColumn();
        assert_not_null($deleted, 'appointment not deleted');
        $rows = zpms_nf_test_rows("source = 'appointment' AND appointment_id = ?", [$aid]);
        assert_equal(3, count($rows), 'a soft delete removed rows');
        foreach ($rows as $r) assert_equal($deleted, $r['deleted'], 'row not hidden with the appointment\'s time');
        assert_equal(0, count(zpms_nf_test_rows("source = 'patient' AND patient_id = ? AND deleted IS NOT NULL", [$pid])), 'patient-note rows hidden too early');

        $token = zpms_nf_test_token($http, "/patient/$pid/edit");
        $res = $http->post("/patient/$pid/delete", ['csrf_token' => $token]);
        assert_equal(302, $res['status'], 'patient delete did not redirect');
        $pDeleted = $db->query("SELECT deleted FROM patients WHERE id = $pid")->fetchColumn();
        assert_not_null($pDeleted, 'patient not deleted');
        foreach (zpms_nf_test_rows("source = 'patient' AND patient_id = ?", [$pid]) as $r) assert_equal($pDeleted, $r['deleted'], 'patient-note row not hidden');
        assert_equal(3, (int)$db->query("SELECT COUNT(*) FROM note_fields WHERE appointment_id = $aid AND deleted = " . $db->quote($deleted))->fetchColumn(),
            'the appointment rows lost their own delete time');
    });

    $runner->add('note-fields:rebuild reproduces exactly the rows the saves wrote (deleted ones stay hidden)', function () use ($db) {
        TestSchema::assertSafeToMutate();
        $before = zpms_nf_test_rows('1');
        assert_true(count($before) > 5, 'too few rows to compare');
        $dry = zpms_note_fields_rebuild($db, null, '2026-01-01 00:00:00', false);
        assert_equal(count($before), $dry['fields'], 'dry run counts a different number of rows');
        assert_equal($before, zpms_nf_test_rows('1'), 'a dry run changed rows');

        $db->exec('DELETE FROM note_fields');
        $done = zpms_note_fields_rebuild($db, null, '2026-01-01 00:00:00', true);
        assert_equal(count($before), $done['fields'], 'rebuild wrote a different number of rows');
        // origin ids change with the new row ids; compare which row they point at instead.
        $originTag = fn() => $db->query('SELECT n.id, o.tag_key, o.value_text FROM note_fields n LEFT JOIN note_fields o ON o.id = n.origin ORDER BY n.source, n.appointment_id, n.pos, n.id')->fetchAll(PDO::FETCH_ASSOC);
        $strip = fn(array $rows) => array_map(fn($r) => array_diff_key($r, ['origin' => 1]), $rows);
        assert_equal($strip($before), $strip(zpms_nf_test_rows('1')), 'rebuilt rows differ');
        foreach ($originTag() as $r) {
            if ($r['tag_key'] !== null) assert_true(in_array($r['tag_key'], ['proc', 'thx'], true), 'an origin points at a ' . $r['tag_key'] . ' row');
        }
    });

    $runner->add('one patient only: --patient rebuilds that patient and leaves the others', function () use ($db, &$state) {
        TestSchema::assertSafeToMutate();
        $pid = $state['pid'];
        $otherIds = $db->query("SELECT id FROM note_fields WHERE patient_id <> $pid ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        $mine = zpms_nf_test_rows('patient_id = ?', [$pid]);
        $report = zpms_note_fields_rebuild($db, $pid, '2026-01-01 00:00:00', true);
        assert_equal(1, $report['patients'], 'more than one patient rebuilt');
        assert_equal($otherIds, $db->query("SELECT id FROM note_fields WHERE patient_id <> $pid ORDER BY id")->fetchAll(PDO::FETCH_COLUMN), 'other patients\' rows touched');
        $strip = fn(array $rows) => array_map(fn($r) => array_diff_key($r, ['origin' => 1]), $rows);
        assert_equal($strip($mine), $strip(zpms_nf_test_rows('patient_id = ?', [$pid])), 'the patient\'s rows differ after rebuild');
    });

    $runner->add('purge removes the rows for good; cleanup:orphans removes rows whose note is gone', function () use ($db, &$state) {
        TestSchema::assertSafeToMutate();
        $pid = $state['pid'];
        $patients = zpmscli_find_patients($db, [(string)$pid]);
        $n = count(zpms_nf_test_rows('patient_id = ?', [$pid]));
        $dir = sys_get_temp_dir() . '/zpms_nf_' . getmypid();
        @mkdir($dir);
        $dry = zpmscli_purge_patients($db, $patients, $dir, false);
        assert_equal($n, $dry['note_fields'], 'purge dry run counts the wrong number of rows');
        assert_equal($n, count(zpms_nf_test_rows('patient_id = ?', [$pid])), 'a dry run removed rows');
        zpmscli_purge_patients($db, $patients, $dir, true);
        assert_equal(0, count(zpms_nf_test_rows('patient_id = ?', [$pid])), 'purge left rows behind');

        // An orphan: a row for a patient and an appointment that do not exist.
        $db->exec("INSERT INTO note_fields (patient_id, patient_guid, source, appointment_id, tag_key, value_text) VALUES (999999, 'x', 'appointment', 999999, 'dx', 'orphan')");
        $report = zpmscli_cleanup_orphans($db, $dir, true);
        assert_true($report['orphan_note_fields'] >= 1, 'orphan not reported');
        assert_equal(0, (int)$db->query("SELECT COUNT(*) FROM note_fields WHERE value_text = 'orphan'")->fetchColumn(), 'orphan row survived');
        @rmdir($dir);
    });

    $runner->add('appointments:purge (zpmscli) removes only that appointment\'s rows', function () use ($http, $db) {
        TestSchema::assertSafeToMutate();
        $p = new patientsClass(['guid' => guid(), 'cuser' => 'test', 'cdate' => getDBtime(), 'pname' => 'Ασθενής Purge', 'pdob' => '1980-01-01 00:00:00',
            'pamka' => '', 'ptel' => '', 'paddr' => '', 'pemail' => '', 'pnote' => '@δγ μένει']);
        $p->insert();
        zpms_note_fields_sync_patient($p);
        $a = new appointmentsClass(['guid' => guid(), 'cuser' => 'test', 'cdate' => getDBtime(), 'adate' => '2026-01-01 10:00:00', 'aplace' => '',
            'anote' => '@δγ φεύγει', 'atype' => 'appointment', 'pguid' => $p->getguid()]);
        $a->insert();
        zpms_note_fields_sync_appointment($a);
        assert_equal([['dx', 'φεύγει'], ['dx', 'μένει']], zpms_nf_test_pairs(zpms_nf_test_rows('patient_id = ?', [$p->getid()])), 'setup rows');

        $dir = sys_get_temp_dir() . '/zpms_nf2_' . getmypid();
        @mkdir($dir);
        zpmscli_soft_delete_appointments($db, zpmscli_find_appointments($db, [(string)$a->getid()]), '2026-02-02 02:02:02', true);
        assert_equal('2026-02-02 02:02:02', $db->query("SELECT deleted FROM note_fields WHERE appointment_id = {$a->getid()}")->fetchColumn(), 'CLI soft delete did not hide the row');
        zpmscli_purge_appointments($db, zpmscli_find_appointments($db, [(string)$a->getid()]), $dir, true);
        assert_equal([['dx', 'μένει']], zpms_nf_test_pairs(zpms_nf_test_rows('patient_id = ?', [$p->getid()])), 'wrong rows after purging the appointment');
        @rmdir($dir);
    });

    $runner->add('command line: note-fields:rebuild and note-lists:seed (dry run by default, --yes writes, audited)', function () use ($db) {
        TestSchema::assertSafeToMutate();
        $env = 'ZPMS_DB_CONFIG=' . escapeshellarg(ZPMS_TEST_APPDIR . '/config/db.test.php');
        $run = function (string $args) use ($env): array {
            $out = [];
            exec($env . ' php ' . escapeshellarg(ZPMS_TEST_APPDIR . '/bin/zpmscli.php') . ' ' . $args . ' 2>&1', $out, $code);
            return [$code, implode("\n", $out)];
        };
        $count = fn() => (int)$db->query('SELECT COUNT(*) FROM note_fields')->fetchColumn();
        $n = $count();
        $maxId = (int)$db->query('SELECT MAX(id) FROM note_fields')->fetchColumn();
        [$code, $out] = $run('note-fields:rebuild');
        assert_equal(0, $code, $out);
        assert_contains('DRY RUN', $out, 'no dry-run banner');
        assert_equal($maxId, (int)$db->query('SELECT MAX(id) FROM note_fields')->fetchColumn(), 'a dry run rewrote rows');
        [$code, $out] = $run('note-fields:rebuild --yes');
        assert_equal(0, $code, $out);
        assert_equal($n, $count(), 'rebuild changed the number of rows');
        assert_true((int)$db->query('SELECT MIN(id) FROM note_fields')->fetchColumn() > $maxId, 'rows were not rewritten');
        assert_equal(1, (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE event_type = 'note_fields_rebuilt' AND actor_type = 'cli'")->fetchColumn(), 'rebuild not audited');
        [$code, $out] = $run('note-fields:rebuild --patient=999999');
        assert_equal(1, $code, 'an unknown patient should fail');
        [$code, $out] = $run('note-lists:seed --yes');
        assert_equal(0, $code, $out);
        assert_contains('nothing to add', $out, 'a second seed claims to add entries');
    });
}
