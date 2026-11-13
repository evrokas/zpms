<?php
/* The clinical-tag parser (docs/CLINICAL_TAGS_PLAN.md): config/note_tags.yaml
 * is valid, and the PHP parser (web/note_tags.php) and the JS parser
 * (web/js/note-tags.js) both give exactly what tests/note_tags/cases.json
 * expects -- and exactly the same as each other, field for field. No
 * database involved. */

require_once ZPMS_TEST_APPDIR . '/web/note_tags.php';

/** Compares the keys an expectation names (rx: the keys it names). Floats compared as numbers. */
function zpms_note_tags_test_match(array $expect, array $actual, string $where): void {
    assert_equal(count($expect), count($actual),
        "$where: expected " . count($expect) . ' row(s), got ' . count($actual) . ': ' . json_encode($actual, JSON_UNESCAPED_UNICODE));
    foreach ($expect as $i => $row) {
        foreach ($row as $key => $want) {
            $got = $actual[$i][$key] ?? null;
            if ($key === 'rx') {
                assert_true(is_array($got), "$where row $i: no rx parts");
                foreach ($want as $rk => $rv) {
                    $gv = $got[$rk] ?? null;
                    $same = (is_int($rv) || is_float($rv)) && $gv !== null ? abs((float)$rv - (float)$gv) < 1e-9 : $rv === $gv;
                    assert_true($same, "$where row $i rx.$rk: expected " . json_encode($rv, JSON_UNESCAPED_UNICODE) . ', got ' . json_encode($gv, JSON_UNESCAPED_UNICODE));
                }
                continue;
            }
            assert_true($want === $got, "$where row $i $key: expected " . json_encode($want, JSON_UNESCAPED_UNICODE) . ', got ' . json_encode($got, JSON_UNESCAPED_UNICODE));
        }
    }
}

/** Numbers as floats so 60 (JS) and 60.0 (PHP) compare equal. */
function zpms_note_tags_test_canon($v) {
    if (is_array($v)) return array_map('zpms_note_tags_test_canon', $v);
    if (is_int($v) || is_float($v)) return (float)$v;
    return $v;
}

function zpms_static_note_tags(TestRunner $runner): void {
    $root = ZPMS_TEST_APPDIR;
    $cases = json_decode((string)file_get_contents($root . '/tests/note_tags/cases.json'), true)['cases'] ?? null;

    $runner->add('config/note_tags.yaml: nine tags, valid value types, colours from the palette, names unique', function () {
        $cfg = zpms_note_tags_config();
        assert_equal(['dx', 'lvl', 'alg', 'exam', 'proc', 'thx', 'pt', 'med', 'rx'], array_keys($cfg['tags']), 'tag keys');
        foreach ($cfg['tags'] as $key => $tag) {
            foreach (['tint', 'mark', 'ink'] as $part) {
                assert_true((bool)preg_match('/^#[0-9a-f]{6}$/', $cfg['palette'][$tag['colour']][$part] ?? ''), "$key: palette $part");
            }
        }
        assert_true(!empty($cfg['tags']['proc']['inner_levels']) && !empty($cfg['tags']['thx']['inner_levels']), 'inner_levels on proc and thx');
        assert_equal(6, count(zpms_note_rx_drugs_from_config()), 'starter drug list');
    });

    $runner->add('reading look: tag words left out, values coloured, everything escaped, offsets kept', function () {
        $html = zpms_note_reading_html('Βλ. <b>@δγ κήλη & "στένωση"</b> τέλος');
        assert_equal('<span data-o="0">Βλ. &lt;b&gt;</span><span class="zt-v zt-c-rose" data-tag="dx" data-o="11" title="Διάγνωση">κήλη &amp; &quot;στένωση&quot;&lt;/b&gt; τέλος</span>', $html);
        assert_equal('', zpms_note_reading_html(''), 'an empty note must give an empty box (the placeholder shows)');
        assert_equal('<span data-o="0">χωρίς ετικέτες</span>', zpms_note_reading_html('χωρίς ετικέτες'));
        $assets = zpms_note_tags_assets();
        assert_contains('.zt-c-rose{--zt-tint:#fbebea;--zt-mark:#e6b2ac;--zt-ink:#8c3b34}', $assets, 'palette classes');
        assert_contains('id="zt-spec"', $assets, 'tag definitions for the browser');
        assert_not_contains('</script><', substr($assets, strpos($assets, 'zt-spec'), -10), 'spec JSON not safe inside <script>');
        assert_equal('', zpms_note_tags_assets(), 'assets emitted twice on one page');
    });

    $runner->add('JS editing helpers: the field under the caret, prescription blank spans (tests/note_tags/editing_cases.js)', function () {
        exec('node ' . escapeshellarg(ZPMS_TEST_APPDIR . '/tests/note_tags/editing_cases.js') . ' 2>&1', $out, $code);
        assert_equal(0, $code, implode("\n", $out));
    });

    $runner->add('a malformed tag file is refused, not half-read', function () {
        $tmp = tempnam(sys_get_temp_dir(), 'zpmstags') . '.yaml';
        file_put_contents($tmp, "tags:\n  dx: { names: [δγ], colour: nope, value: text }\npalette:\n  rose: { tint: '#ffffff', mark: '#ffffff', ink: '#ffffff' }\n");
        try {
            zpms_note_tags_config($tmp);
            assert_true(false, 'an unknown colour was accepted');
        } catch (RuntimeException $e) {
            assert_contains('colour', $e->getMessage());
        } finally {
            @unlink($tmp);
        }
    });

    $runner->add('tests/note_tags/cases.json is readable', function () use ($cases) {
        assert_true(is_array($cases) && count($cases) > 20, 'no test cases');
    });
    if (!is_array($cases)) return;

    $spec = zpms_note_tags_spec();
    $drugs = zpms_note_rx_drugs_from_config();

    foreach ($cases as $case) {
        $runner->add('PHP parser: ' . $case['name'], function () use ($case, $spec, $drugs) {
            zpms_note_tags_test_match($case['expect'], zpms_note_parse($case['text'], $spec, $drugs), 'PHP');
            if (isset($case['plain'])) assert_equal($case['plain'], zpms_note_strip($case['text'], $spec), 'PHP: note without the tag words');
        });
    }

    // One node run for all cases.
    $js = null;
    $runner->add('JS parser runs under node', function () use ($root, $spec, $drugs, $cases, &$js) {
        $dir = sys_get_temp_dir() . '/zpms-note-tags-' . getmypid();
        @mkdir($dir);
        file_put_contents("$dir/spec.json", json_encode($spec, JSON_UNESCAPED_UNICODE));
        file_put_contents("$dir/drugs.json", json_encode($drugs, JSON_UNESCAPED_UNICODE));
        $cmd = 'node ' . escapeshellarg($root . '/tests/note_tags/run_cases.js') . ' ' . escapeshellarg("$dir/spec.json") . ' '
             . escapeshellarg("$dir/drugs.json") . ' ' . escapeshellarg($root . '/tests/note_tags/cases.json') . ' 2>&1';
        exec($cmd, $out, $code);
        @unlink("$dir/spec.json"); @unlink("$dir/drugs.json"); @rmdir($dir);
        assert_equal(0, $code, implode("\n", $out));
        $js = json_decode(implode("\n", $out), true);
        assert_true(is_array($js) && count($js) === count($cases), 'unexpected node output: ' . substr(implode("\n", $out), 0, 300));
    });
    foreach ($cases as $i => $case) {
        $runner->add('JS parser: ' . $case['name'] . ' (and same as PHP)', function () use ($case, $i, $spec, $drugs, &$js) {
            assert_true(is_array($js), 'the JS parser did not run');
            zpms_note_tags_test_match($case['expect'], $js[$i]['rows'], 'JS');
            assert_equal(zpms_note_tags_test_canon(zpms_note_parse($case['text'], $spec, $drugs)), zpms_note_tags_test_canon($js[$i]['rows']), 'PHP and JS differ');
            $phpRuns = array_map(fn($r) => [$r[0], $r[1], $r[2]], zpms_note_runs($case['text'], $spec));
            assert_equal($phpRuns, $js[$i]['runs'], 'PHP and JS cut the note into different runs');
            if (isset($case['plain'])) assert_equal($case['plain'], $js[$i]['plain'], 'JS: note without the tag words');
        });
    }
}
