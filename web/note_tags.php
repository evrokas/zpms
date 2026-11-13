<?php
/**
 * Clinical tags in the note boxes -- phase 1: the rules and the storage
 * (docs/CLINICAL_TAGS_PLAN.md).
 *
 * While writing an appointment note (appointments.anote) or the patient note
 * (patients.pnote) the doctor marks facts inline -- «@δγ κήλη μεσοσπονδυλίου
 * δίσκου, στο @επ Ο4-Ο5 με πίεση» -- and keeps writing. The note is saved
 * exactly as typed and stays the single source of truth; every tagged value is
 * also extracted into note_fields (web/classes/yaml/note_fields.yaml), rebuilt
 * for a note on every save of that note.
 *
 * The tags themselves are defined only in config/note_tags.yaml. The parser
 * exists twice, here and in web/js/note-tags.js (the note box needs it while
 * typing); both run the same test cases, tests/note_tags/cases.json, so they
 * cannot drift apart. Keep the two written the same way, step by step.
 *
 * Plain PDO, no framework, so bin/zpmscli.php (note-fields:rebuild,
 * note-lists:seed, the purge commands) runs exactly this code. Only the
 * zpms_note_fields_*_for() wrappers at the end use the web app's entity
 * classes; they are what the request handlers in web/index.php call, and they
 * never let a failure here break the save of the note itself (the note is
 * already stored; `note-fields:rebuild` repairs the rows).
 *
 * Values are patient data: never put one into the audit log or error log.
 */

const ZPMS_NOTE_VALUE_TYPES = ['text', 'levels', 'prescription'];
const ZPMS_NOTE_SOURCES = ['appointment', 'patient'];
// A closing bracket or quote with no opener inside the value ends it
// («(@εξ ακτινογραφία)»); openers left at the end of a value are dropped.
const ZPMS_NOTE_CLOSERS = [')' => '(', ']' => '[', '»' => '«'];
const ZPMS_NOTE_OPENERS = ['(' => true, '[' => true, '«' => true];

// --------------------------------------------------------------------------
// tag definitions
// --------------------------------------------------------------------------

/** The app folder (one up from web/). */
function zpms_note_tags_appdir(): string {
    return dirname(__DIR__);
}

/**
 * config/note_tags.yaml, checked. Throws RuntimeException naming the problem
 * when the file is missing or malformed -- a wrong tag definition must not
 * quietly produce wrong rows.
 */
function zpms_note_tags_config(?string $file = null): array {
    static $cache = [];
    $file = $file ?? zpms_note_tags_appdir() . '/config/note_tags.yaml';
    if (isset($cache[$file])) return $cache[$file];

    if (!function_exists('yaml_parse_file') && is_file(__DIR__ . '/core/lib/yaml_compat.php')) {
        require_once __DIR__ . '/core/lib/yaml_compat.php';
    }
    if (!is_file($file)) throw new RuntimeException("Tag definitions not found: $file");
    $cfg = yaml_parse_file($file);
    if (!is_array($cfg) || !is_array($cfg['tags'] ?? null) || !is_array($cfg['palette'] ?? null)) {
        throw new RuntimeException("$file: `tags:` and `palette:` are required");
    }
    $seen = [];
    foreach ($cfg['tags'] as $key => $tag) {
        if (!preg_match('/^[a-z][a-z0-9_]{0,15}$/', (string)$key)) throw new RuntimeException("$file: bad tag key '$key'");
        if (!is_array($tag) || !is_array($tag['names'] ?? null) || !$tag['names']) throw new RuntimeException("$file: tag $key has no names");
        if (!in_array($tag['value'] ?? null, ZPMS_NOTE_VALUE_TYPES, true)) throw new RuntimeException("$file: tag $key has an unknown value type");
        if (!isset($cfg['palette'][$tag['colour'] ?? ''])) throw new RuntimeException("$file: tag $key names a colour that is not in the palette");
        foreach ($tag['names'] as $name) {
            $folded = zpms_note_fold((string)$name);
            if (!preg_match('/^[\p{L}\p{N}_]+$/u', $folded)) throw new RuntimeException("$file: tag $key: name '$name' must be letters/digits only");
            if (isset($seen[$folded]) && $seen[$folded] !== $key) throw new RuntimeException("$file: name '$name' is used by both $seen[$folded] and $key");
            $seen[$folded] = $key;
        }
    }
    return $cache[$file] = $cfg;
}

/**
 * What the parser needs (both versions): every tag with its settings, and the
 * palette. Sent to the browser as JSON by later phases; the test suite feeds
 * it to web/js/note-tags.js.
 */
function zpms_note_tags_spec(?array $cfg = null): array {
    $cfg = $cfg ?? zpms_note_tags_config();
    $tags = [];
    foreach ($cfg['tags'] as $key => $tag) {
        $tags[$key] = $tag + ['inner_levels' => false];
        $tags[$key]['names'] = array_values(array_map('strval', $tag['names']));
    }
    return ['tags' => $tags, 'palette' => $cfg['palette']];
}

/** The drug list from the starter list in the config file (used when the table is not there yet). */
function zpms_note_rx_drugs_from_config(?array $cfg = null): array {
    $cfg = $cfg ?? zpms_note_tags_config();
    $out = [];
    foreach (($cfg['starter_lists']['rx']['entries'] ?? []) as $e) {
        $out[] = zpms_note_rx_drug((string)$e['text'], (array)($e['aliases'] ?? []), (array)($e['strengths'] ?? []));
    }
    return $out;
}

/** One drug for the parser: {text, aliases[], unit}. The unit is that of its first strength (default mg). */
function zpms_note_rx_drug(string $text, array $aliases, array $strengths): array {
    $unit = 'mg';
    if ($strengths && preg_match('/[\d.,]+\s*([^\d\s.,]+)\s*$/u', (string)reset($strengths), $m)) $unit = mb_strtolower($m[1]);
    return ['text' => $text, 'aliases' => array_values(array_filter(array_map(fn($a) => trim((string)$a), $aliases), 'strlen')), 'unit' => $unit];
}

// --------------------------------------------------------------------------
// the parser (keep in step with web/js/note-tags.js)
// --------------------------------------------------------------------------

/** Lowercase, Greek accents removed, final sigma -> σ. One character in, one out. */
function zpms_note_fold_char(string $c): string {
    static $map = ['ά' => 'α', 'έ' => 'ε', 'ή' => 'η', 'ί' => 'ι', 'ό' => 'ο', 'ύ' => 'υ', 'ώ' => 'ω',
        'ϊ' => 'ι', 'ϋ' => 'υ', 'ΐ' => 'ι', 'ΰ' => 'υ', 'ς' => 'σ'];
    $l = mb_strtolower($c, 'UTF-8');
    if (mb_strlen($l, 'UTF-8') !== 1) $l = $c;
    return $map[$l] ?? $l;
}

function zpms_note_fold(string $s): string {
    $out = '';
    foreach (zpms_note_chars($s) as $c) $out .= zpms_note_fold_char($c);
    return $out;
}

/** @return string[] the characters (code points) of $s */
function zpms_note_chars(string $s): array {
    return $s === '' ? [] : preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY);
}

function zpms_note_is_word(?string $c): bool {
    return $c !== null && preg_match('/^[\p{L}\p{N}_]$/u', $c) === 1;
}

/** Whitespace, spelled out (PCRE's \s and JavaScript's \s differ outside ASCII). */
const ZPMS_NOTE_SPACE_CLASS = '[ \t\n\r\f\v\x{00A0}\x{2028}\x{2029}]';

function zpms_note_is_space(?string $c): bool {
    return $c !== null && preg_match('/^' . ZPMS_NOTE_SPACE_CLASS . '$/u', $c) === 1;
}

/** trim() with the same whitespace on both sides of the parser. */
function zpms_note_trim(string $s): string {
    return preg_replace('/^' . ZPMS_NOTE_SPACE_CLASS . '+|' . ZPMS_NOTE_SPACE_CLASS . '+$/u', '', $s);
}

function zpms_note_is_digit(?string $c): bool {
    return $c !== null && strlen($c) === 1 && $c >= '0' && $c <= '9';
}

function zpms_note_is_inline_space(?string $c): bool {
    return $c === ' ' || $c === "\t" || $c === "\u{00A0}";
}

function zpms_note_is_newline(?string $c): bool {
    return $c === "\n" || $c === "\r" || $c === "\u{2028}" || $c === "\u{2029}";
}

/** Semicolon marks: Greek ano teleia (U+0387, U+00B7), ';', Greek question mark (U+037E). */
function zpms_note_is_semicolon(?string $c): bool {
    return $c === ';' || $c === "\u{0387}" || $c === "\u{00B7}" || $c === "\u{037E}";
}

/** A comma between two digits (0,5) is a decimal comma: it ends nothing. */
function zpms_note_decimal_comma(array $chars, int $k): bool {
    return $k > 0 && zpms_note_is_digit($chars[$k - 1] ?? null) && zpms_note_is_digit($chars[$k + 1] ?? null);
}

/** Normalised form of a text value: folded, whitespace collapsed. */
function zpms_note_normalize_text(string $s): string {
    return zpms_note_trim(preg_replace('/' . ZPMS_NOTE_SPACE_CLASS . '+/u', ' ', zpms_note_fold($s)));
}

/**
 * Parses a note. Returns one entry per tagged value, in order:
 *   tag        internal key (dx, lvl, ...)
 *   value      the value as typed
 *   normalized levels: English form ("L4-L5, L5-S1"); text: folded; null if none
 *   valid      false for an impossible level (kept, but left out of the summary)
 *   pos        character offset of the '@' (of the level itself for an inner level)
 *   start/end  character range of the value
 *   origin     for a level found inside another value: the index of that entry
 *   rx         prescriptions: {drug, strength, per_day_text, per_dose, per_day, days, note, total}
 *
 * @param array $spec  zpms_note_tags_spec()
 * @param array $drugs list of {text, aliases[], unit} (zpms_note_rx_drugs())
 */
function zpms_note_parse(string $text, array $spec, array $drugs = []): array {
    $p = new ZpmsNoteParser($text, $spec, $drugs);
    return $p->parse();
}

final class ZpmsNoteParser {
    private array $c;      // characters
    private array $f;      // folded characters
    private int $n;
    private array $names;  // [[folded name chars, key], ...] longest first
    private array $tags;
    private array $drugs;

    public function __construct(string $text, array $spec, array $drugs) {
        $this->c = zpms_note_chars($text);
        $this->f = array_map('zpms_note_fold_char', $this->c);
        $this->n = count($this->c);
        $this->tags = $spec['tags'];
        $this->drugs = $drugs;
        $names = [];
        foreach ($this->tags as $key => $tag) {
            foreach ($tag['names'] as $name) $names[] = [zpms_note_chars(zpms_note_fold($name)), $key];
        }
        usort($names, fn($a, $b) => count($b[0]) <=> count($a[0]));
        $this->names = $names;
    }

    private function at(int $i): ?string { return $i >= 0 && $i < $this->n ? $this->c[$i] : null; }

    private function slice(int $s, int $e): string { return implode('', array_slice($this->c, $s, $e - $s)); }

    /** [key, end of the name] when a tag starts at $i, else null. */
    private function tagAt(int $i): ?array {
        if ($this->at($i) !== '@' || zpms_note_is_word($this->at($i - 1))) return null;
        foreach ($this->names as [$chars, $key]) {
            $l = count($chars);
            if ($i + 1 + $l > $this->n) continue;
            $ok = true;
            for ($k = 0; $k < $l; $k++) {
                if ($this->f[$i + 1 + $k] !== $chars[$k]) { $ok = false; break; }
            }
            if ($ok && !zpms_note_is_word($this->at($i + 1 + $l))) return [$key, $i + 1 + $l];
        }
        return null;
    }

    /** End of a value that starts at $j (before trailing spaces). [end, stop] */
    private function valueEnd(int $j, bool $commaAllowed): array {
        $k = $j;
        $open = ['(' => 0, '[' => 0, '«' => 0];
        while ($k < $this->n) {
            $ch = $this->c[$k];
            if (zpms_note_is_newline($ch) || zpms_note_is_semicolon($ch)) break;
            if (isset($open[$ch])) $open[$ch]++;
            if (isset(ZPMS_NOTE_CLOSERS[$ch])) {
                if ($open[ZPMS_NOTE_CLOSERS[$ch]] === 0) break;   // closes a bracket opened before the tag
                $open[ZPMS_NOTE_CLOSERS[$ch]]--;
            }
            if ($ch === ',' && !$commaAllowed && !zpms_note_decimal_comma($this->c, $k)) break;
            if ($ch === '.' && ($k + 1 >= $this->n || zpms_note_is_space($this->c[$k + 1]))) break;
            if ($ch === '@' && $this->tagAt($k) !== null) break;
            $k++;
        }
        $e = $k;
        while ($e > $j && (zpms_note_is_space($this->c[$e - 1]) || isset(ZPMS_NOTE_OPENERS[$this->c[$e - 1]]))) $e--;
        return [$e, $k];
    }

    public function parse(): array {
        $out = [];
        $i = 0;
        while ($i < $this->n) {
            $t = $this->tagAt($i);
            if ($t === null) { $i++; continue; }
            [$key, $nameEnd] = $t;
            $j = $nameEnd;
            while (zpms_note_is_inline_space($this->at($j))) $j++;
            if ($this->at($j) === ':') {
                $j++;
                while (zpms_note_is_inline_space($this->at($j))) $j++;
            }
            $type = $this->tags[$key]['value'];
            $next = $nameEnd;
            if ($type === 'levels') {
                [$limit] = $this->valueEnd($j, true);
                $seq = $this->levelSequence($j, $limit);
                if ($seq !== null) {
                    $out[] = ['tag' => $key, 'value' => $this->slice($j, $seq['end']), 'normalized' => $seq['normalized'],
                        'valid' => $seq['valid'], 'pos' => $i, 'start' => $j, 'end' => $seq['end'], 'origin' => null, 'rx' => null];
                    $next = $seq['end'];
                } else {
                    [$e, $stop] = $this->valueEnd($j, false);
                    if ($e > $j) {
                        $out[] = ['tag' => $key, 'value' => $this->slice($j, $e), 'normalized' => null,
                            'valid' => false, 'pos' => $i, 'start' => $j, 'end' => $e, 'origin' => null, 'rx' => null];
                    }
                    $next = max($nameEnd, $e);
                }
            } else {
                [$e] = $this->valueEnd($j, $type === 'prescription');
                if ($e > $j) {
                    $value = $this->slice($j, $e);
                    $row = ['tag' => $key, 'value' => $value, 'normalized' => zpms_note_normalize_text($value),
                        'valid' => true, 'pos' => $i, 'start' => $j, 'end' => $e, 'origin' => null, 'rx' => null];
                    if ($type === 'prescription') $row['rx'] = zpms_note_parse_rx($value, $this->drugs);
                    $outer = count($out);
                    $out[] = $row;
                    if (!empty($this->tags[$key]['inner_levels'])) {
                        $p = $j;
                        while ($p < $e) {
                            $seq = $this->levelSequence($p, $e);
                            if ($seq === null) { $p++; continue; }
                            $out[] = ['tag' => 'lvl', 'value' => $this->slice($p, $seq['end']), 'normalized' => $seq['normalized'],
                                'valid' => $seq['valid'], 'pos' => $p, 'start' => $p, 'end' => $seq['end'], 'origin' => $outer, 'rx' => null];
                            $p = $seq['end'];
                        }
                    }
                }
                $next = max($nameEnd, $e);
            }
            $i = $next;
        }
        return $out;
    }

    // ---- levels ----

    private const REGION = ['α' => 'C', 'a' => 'C', 'c' => 'C', 'θ' => 'T', 't' => 'T',
        'ο' => 'L', 'o' => 'L', 'l' => 'L', 'ι' => 'S', 'i' => 'S', 's' => 'S'];
    private const REGION_MAX = ['C' => 7, 'T' => 12, 'L' => 5, 'S' => 1];
    private const REGION_BASE = ['C' => 0, 'T' => 7, 'L' => 19, 'S' => 24];

    /** Digits at $p (1-2 of them, not followed by another digit): [number, end] or null. */
    private function number(int $p, int $lim): ?array {
        $k = $p;
        while ($k < $lim && $k - $p < 2 && zpms_note_is_digit($this->c[$k])) $k++;
        if ($k === $p || ($k < $lim && zpms_note_is_digit($this->c[$k]))) return null;
        return [(int)$this->slice($p, $k), $k];
    }

    /** One level (Ο4, Ο4-Ο5, ο4-5, L5/S1, Θ12-Ο1) starting at $p, ending before $lim. */
    private function levelToken(int $p, int $lim): ?array {
        if ($p >= $lim || zpms_note_is_word($this->at($p - 1))) return null;
        $r1 = self::REGION[$this->f[$p]] ?? null;
        if ($r1 === null) return null;
        $n1 = $this->number($p + 1, $lim);
        if ($n1 === null) return null;
        $tok = ['r1' => $r1, 'n1' => $n1[0], 'r2' => null, 'n2' => null, 'end' => $n1[1]];
        $q = $n1[1];
        if ($q < $lim && in_array($this->c[$q], ['-', '/', "\u{2013}", "\u{2014}"], true)) {
            $r = $q + 1;
            $r2 = ($r < $lim) ? (self::REGION[$this->f[$r]] ?? null) : null;
            $n2 = $this->number($r2 !== null ? $r + 1 : $r, $lim);
            if ($n2 !== null) $tok = ['r1' => $r1, 'n1' => $n1[0], 'r2' => $r2 ?? $r1, 'n2' => $n2[0], 'end' => $n2[1]];
        }
        if (zpms_note_is_word($this->at($tok['end']))) return null;
        return $tok;
    }

    private static function levelValid(string $r, int $n): bool {
        return $n >= 1 && $n <= self::REGION_MAX[$r];
    }

    /** Levels joined by ',', '&' or 'και' from $p: {end, normalized, valid} or null. */
    private function levelSequence(int $p, int $lim): ?array {
        $tok = $this->levelToken($p, $lim);
        if ($tok === null) return null;
        $parts = [];
        $valid = true;
        $end = $p;
        while ($tok !== null) {
            $end = $tok['end'];
            if ($tok['r2'] === null) {
                $parts[] = $tok['r1'] . $tok['n1'];
                $valid = $valid && self::levelValid($tok['r1'], $tok['n1']);
            } else {
                $parts[] = $tok['r1'] . $tok['n1'] . '-' . $tok['r2'] . $tok['n2'];
                $ok = self::levelValid($tok['r1'], $tok['n1']) && self::levelValid($tok['r2'], $tok['n2'])
                    && self::REGION_BASE[$tok['r2']] + $tok['n2'] > self::REGION_BASE[$tok['r1']] + $tok['n1'];
                $valid = $valid && $ok;
            }
            $q = $end;
            while ($q < $lim && zpms_note_is_inline_space($this->c[$q])) $q++;
            if ($q < $lim && ($this->c[$q] === ',' || $this->c[$q] === '&')) {
                $q++;
            } elseif ($q + 3 <= $lim && $this->f[$q] === 'κ' && $this->f[$q + 1] === 'α' && $this->f[$q + 2] === 'ι'
                      && !zpms_note_is_word($this->at($q + 3))) {
                $q += 3;
            } else {
                break;
            }
            while ($q < $lim && zpms_note_is_inline_space($this->c[$q])) $q++;
            $tok = $this->levelToken($q, $lim);
        }
        return ['end' => $end, 'normalized' => implode(', ', $parts), 'valid' => $valid];
    }
}

/**
 * The parts of a prescription value: «Lyrica 75mg 1x2 για 30 ημέρες, μετά το φαγητό».
 * Every pattern is matched on the folded text (same length as the typed one)
 * and the parts are cut from the typed text. Anything not placed elsewhere
 * goes into the note.
 */
function zpms_note_parse_rx(string $value, array $drugs): array {
    $rx = ['drug' => null, 'strength' => null, 'per_day_text' => null, 'per_dose' => null,
        'per_day' => null, 'days' => null, 'note' => null, 'total' => null];
    $chars = zpms_note_chars($value);
    $comma = false;
    foreach ($chars as $k => $ch) {
        if ($ch === ',' && !zpms_note_decimal_comma($chars, $k)) { $comma = $k; break; }
    }
    $mainChars = $comma === false ? $chars : array_slice($chars, 0, $comma);
    $tail = $comma === false ? '' : zpms_note_trim(implode('', array_slice($chars, $comma + 1)));
    $main = implode('', $mainChars);
    $folded = zpms_note_fold($main);
    $n = count($mainChars);
    $used = array_fill(0, $n, false);

    // First match of $re in the folded main part that does not touch an
    // already used character: [start, end, groups] in characters, or null.
    $find = function (string $re) use ($folded, &$used): ?array {
        $offset = 0;
        while (preg_match($re, $folded, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $s = mb_strlen(substr($folded, 0, $m[0][1]), 'UTF-8');
            $e = $s + mb_strlen($m[0][0], 'UTF-8');
            $free = true;
            for ($k = $s; $k < $e; $k++) if ($used[$k]) { $free = false; break; }
            if ($free && $e > $s) {
                for ($k = $s; $k < $e; $k++) $used[$k] = true;
                $groups = [];
                foreach ($m as $gi => $g) {
                    if ($gi === 0) continue;
                    $groups[$gi] = ($g[1] < 0) ? null : [mb_strlen(substr($folded, 0, $g[1]), 'UTF-8'), mb_strlen($g[0], 'UTF-8')];
                }
                return [$s, $e, $groups];
            }
            $offset = $m[0][1] + max(1, strlen($m[0][0]));
            if ($offset > strlen($folded)) break;
        }
        return null;
    };
    $cut = fn(int $s, int $e) => implode('', array_slice($mainChars, $s, $e - $s));
    $group = fn(array $m, int $gi) => $m[2][$gi] === null ? null : $cut($m[2][$gi][0], $m[2][$gi][0] + $m[2][$gi][1]);
    $num = fn(string $s) => $s === '½' ? 0.5 : ($s === '¼' ? 0.25 : (float)str_replace(',', '.', $s));
    $sp = ZPMS_NOTE_SPACE_CLASS;
    $nb = '(?<![\p{L}\p{N}])';
    $na = '(?![\p{L}\p{N}])';

    if ($m = $find("/{$nb}([0-9]+(?:[.,][0-9]+)?|½|¼){$sp}*[xχ×]{$sp}*([0-9]+){$na}/u")) {
        $rx['per_day_text'] = preg_replace('/' . ZPMS_NOTE_SPACE_CLASS . '+/u', '', $cut($m[0], $m[1]));
        $rx['per_dose'] = $num($group($m, 1));
        $rx['per_day'] = (int)$group($m, 2);
    } elseif ($m = $find("/{$nb}([0-9]+){$sp}*φορ(?:εσ|α){$na}/u")) {
        $rx['per_day_text'] = $cut($m[0], $m[1]);
        $rx['per_dose'] = 1.0;
        $rx['per_day'] = (int)$group($m, 1);
    } elseif ($m = $find("/{$nb}επι{$sp}+πονου{$na}/u")) {
        $rx['per_day_text'] = $cut($m[0], $m[1]);
    }
    if ($m = $find("/{$nb}(?:για{$sp}+)?([0-9]+){$sp}*(?:ημερεσ|ημερα|ημ|μερεσ|days|day|d){$na}/u")) {
        $rx['days'] = (int)$group($m, 1);
    } elseif ($m = $find("/(?<![\\p{L}\\p{N}½¼])[xχ×]{$sp}*([0-9]+){$na}/u")) {
        $rx['days'] = (int)$group($m, 1);
    } else {
        $find("/{$nb}συνεχωσ{$na}/u");   // continuously: no number of days, nothing to store
    }
    if ($m = $find("/{$nb}([0-9]+(?:[.,][0-9]+)?){$sp}*(mg|mcg|μg|μγ|gr|g|ml|iu){$na}/u")) {
        $rx['strength'] = $group($m, 1) . $group($m, 2);
    }

    // The drug: a known name or Greek spelling at the start (longest wins),
    // else the words before the first recognised part.
    $drugEnd = 0;
    $unit = 'mg';
    $best = null;
    foreach ($drugs as $d) {
        foreach (array_merge([$d['text']], $d['aliases']) as $name) {
            $fn = zpms_note_fold(zpms_note_trim($name));
            $l = mb_strlen($fn, 'UTF-8');
            if ($l === 0 || mb_substr($folded, 0, $l, 'UTF-8') !== $fn || zpms_note_is_word($mainChars[$l] ?? null)) continue;
            if ($best === null || $l > $best[1]) $best = [$d, $l];
        }
    }
    if ($best !== null) {
        $rx['drug'] = $best[0]['text'];
        $unit = $best[0]['unit'] ?: 'mg';
        $drugEnd = $best[1];
    } else {
        $k = 0;
        while ($k < $n && !$used[$k]) $k++;
        $drug = zpms_note_trim($cut(0, $k));
        $rx['drug'] = $drug !== '' ? $drug : null;
        $drugEnd = $k;
    }
    for ($k = 0; $k < $drugEnd; $k++) $used[$k] = true;

    // A bare number right after the drug is its strength, in the drug's unit.
    if ($rx['strength'] === null) {
        $k = $drugEnd;
        while ($k < $n && zpms_note_is_space($mainChars[$k])) $k++;
        $e = $k;
        while ($e < $n && !zpms_note_is_space($mainChars[$e]) && !$used[$e]) $e++;
        $tok = $cut($k, $e);
        if ($e > $k && preg_match('/^[0-9]+(?:[.,][0-9]+)?$/', $tok) && ($e >= $n || zpms_note_is_space($mainChars[$e]))) {
            $rx['strength'] = $tok . $unit;
            for ($q = $k; $q < $e; $q++) $used[$q] = true;
        }
    }

    $left = '';
    for ($k = 0; $k < $n; $k++) $left .= $used[$k] ? ' ' : $mainChars[$k];
    $left = zpms_note_trim(preg_replace('/' . ZPMS_NOTE_SPACE_CLASS . '+/u', ' ', $left));
    $notes = array_values(array_filter([$left, $tail], fn($s) => $s !== ''));
    $rx['note'] = $notes ? implode(', ', $notes) : null;

    if ($rx['per_dose'] !== null && $rx['per_day'] !== null && $rx['days'] !== null) {
        $rx['total'] = round($rx['per_dose'] * $rx['per_day'] * $rx['days'], 2);
    }
    return $rx;
}

// --------------------------------------------------------------------------
// display (keep in step with runs()/strip() in web/js/note-tags.js)
// --------------------------------------------------------------------------

/**
 * The note cut into runs for display: [text, offset, kind, row] where kind is
 * 'text', 'tag' (the @name and the spaces after it) or 'value' (row = the
 * parsed entry; a level inside another value is its own run).
 */
function zpms_note_runs(string $text, array $spec): array {
    $chars = zpms_note_chars($text);
    $rows = zpms_note_parse($text, $spec);
    $kind = array_fill(0, count($chars), null);   // null = text, -1 = tag, i = value of row i
    foreach ($rows as $i => $r) {
        if ($r['origin'] === null) {
            for ($k = $r['pos']; $k < $r['start']; $k++) $kind[$k] = -1;
        }
        for ($k = $r['start']; $k < $r['end']; $k++) $kind[$k] = $i;
    }
    $runs = [];
    $n = count($chars);
    for ($k = 0; $k < $n; ) {
        $e = $k;
        while ($e < $n && $kind[$e] === $kind[$k]) $e++;
        $runs[] = [implode('', array_slice($chars, $k, $e - $k)), $k,
            $kind[$k] === null ? 'text' : ($kind[$k] === -1 ? 'tag' : 'value'), is_int($kind[$k]) && $kind[$k] >= 0 ? $rows[$kind[$k]] : null];
        $k = $e;
    }
    return $runs;
}

/** The note as read: the tag words left out (for previews like the first line of a note). */
function zpms_note_strip(string $text, ?array $spec = null): string {
    try {
        $out = '';
        foreach (zpms_note_runs($text, $spec ?? zpms_note_tags_spec()) as [$t, , $kind]) {
            if ($kind !== 'tag') $out .= $t;
        }
        return $out;
    } catch (Throwable $e) {
        return $text;
    }
}

/**
 * The reading look of a note (docs/CLINICAL_TAGS_PLAN.md section 9): tag
 * words left out, each value in its ink colour. Every run is escaped and
 * carries data-o, its character offset in the note, so a click can put the
 * caret on the same word (web/js/note-box.js). Empty for an empty note.
 */
function zpms_note_reading_html(?string $text): string {
    $text = (string)$text;
    $h = fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    try {
        $spec = zpms_note_tags_spec();
        $out = '';
        foreach (zpms_note_runs($text, $spec) as [$t, $o, $kind, $row]) {
            if ($kind === 'tag') continue;
            if ($kind === 'value') {
                $tag = $spec['tags'][$row['tag']];
                $out .= '<span class="zt-v zt-c-' . $h($tag['colour']) . '" data-tag="' . $h($row['tag']) . '" data-o="' . $o . '" title="' . $h((string)$tag['label']) . '">' . $h($t) . '</span>';
            } else {
                $out .= '<span data-o="' . $o . '">' . $h($t) . '</span>';
            }
        }
        return $out;
    } catch (Throwable $e) {
        return $text === '' ? '' : '<span data-o="0">' . $h($text) . '</span>';
    }
}

/**
 * Once per page, before the first note box: the colour classes (from the
 * palette in config/note_tags.yaml) and the tag definitions for
 * web/js/note-box.js. Empty if the tag file cannot be read -- the boxes then
 * stay plain textareas.
 */
function zpms_note_tags_assets(): string {
    static $done = false;
    if ($done) return '';
    try {
        $spec = zpms_note_tags_spec();
    } catch (Throwable $e) {
        error_log('note tags: ' . $e->getMessage());
        return '';
    }
    $done = true;
    $css = '';
    foreach ($spec['palette'] as $name => $c) {
        if (!preg_match('/^[a-z][a-z0-9_-]*$/', (string)$name)) continue;
        $v = [];
        foreach (['tint', 'mark', 'ink'] as $part) {
            if (preg_match('/^#[0-9a-fA-F]{3,8}$/', (string)($c[$part] ?? ''))) $v[] = "--zt-$part:{$c[$part]}";
        }
        $css .= ".zt-c-$name{" . implode(';', $v) . "}\n";
    }
    $json = json_encode(['tags' => $spec['tags'], 'suggestUrl' => (function_exists('rel_url') && !empty($GLOBALS['kernel'])) ? rel_url('/notes/suggestions') : '/notes/suggestions'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    return "<style>\n$css</style>\n<script type=\"application/json\" id=\"zt-spec\">$json</script>\n";
}

// --------------------------------------------------------------------------
// storage (plain PDO: shared with bin/zpmscli.php)
// --------------------------------------------------------------------------

function zpms_note_table_exists(PDO $db, string $table): bool {
    $st = $db->prepare('SHOW TABLES LIKE ?');
    $st->execute([$table]);
    return (bool)$st->fetchColumn();
}

/** The active prescription drugs from note_list_entries (the config's starter list if the table is not there yet). */
function zpms_note_rx_drugs(PDO $db): array {
    if (!zpms_note_table_exists($db, 'note_list_entries')) return zpms_note_rx_drugs_from_config();
    $out = [];
    foreach ($db->query("SELECT text, aliases, rx_strengths FROM note_list_entries WHERE tag_key = 'rx' AND active = 1 ORDER BY sort, id") as $r) {
        $strengths = json_decode((string)$r['rx_strengths'], true);
        $out[] = zpms_note_rx_drug((string)$r['text'], explode(',', (string)$r['aliases']), is_array($strengths) ? $strengths : []);
    }
    return $out;
}

/**
 * Replaces the note_fields rows of one note with what the note says now
 * (one transaction). $source 'appointment' needs $appointmentId; 'patient' is
 * the patient note. $deleted copies the soft-delete time of the record the
 * note belongs to (null when it is live). Returns the number of rows written.
 */
function zpms_note_fields_sync(PDO $db, string $source, ?int $appointmentId, int $patientId, string $patientGuid,
                               ?string $text, ?string $deleted, string $now, ?array $spec = null, ?array $drugs = null): int {
    if (!in_array($source, ZPMS_NOTE_SOURCES, true)) throw new InvalidArgumentException("bad source $source");
    if ($source === 'appointment' && $appointmentId === null) throw new InvalidArgumentException('appointment id missing');
    $rows = zpms_note_parse((string)$text, $spec ?? zpms_note_tags_spec(), $drugs ?? zpms_note_rx_drugs($db));

    $own = !$db->inTransaction();
    if ($own) $db->beginTransaction();
    try {
        if ($source === 'appointment') {
            $db->prepare("DELETE FROM note_fields WHERE source = 'appointment' AND appointment_id = ?")->execute([$appointmentId]);
        } else {
            $db->prepare("DELETE FROM note_fields WHERE source = 'patient' AND patient_id = ?")->execute([$patientId]);
        }
        $ins = $db->prepare('INSERT INTO note_fields (patient_id, patient_guid, source, appointment_id, tag_key, value_text, normalized, valid, pos, origin,
                rx_drug, rx_strength, rx_per_day_text, rx_per_dose, rx_per_day, rx_days, rx_note, rx_total, deleted, cdate)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $ids = [];
        foreach ($rows as $idx => $r) {
            $rx = $r['rx'] ?? [];
            $ins->execute([$patientId, $patientGuid, $source, $source === 'appointment' ? $appointmentId : null,
                $r['tag'], $r['value'], $r['normalized'], $r['valid'] ? 1 : 0, $r['pos'],
                $r['origin'] !== null ? ($ids[$r['origin']] ?? null) : null,
                $rx['drug'] ?? null, $rx['strength'] ?? null, $rx['per_day_text'] ?? null, $rx['per_dose'] ?? null,
                $rx['per_day'] ?? null, $rx['days'] ?? null, $rx['note'] ?? null, $rx['total'] ?? null,
                $deleted, $now]);
            $ids[$idx] = (int)$db->lastInsertId();
        }
        if ($own) $db->commit();
    } catch (Throwable $e) {
        if ($own) $db->rollBack();
        throw $e;
    }
    return count($rows);
}

/** Soft delete: hides the rows of these appointments' notes. */
function zpms_note_fields_hide_appointments(PDO $db, array $appointmentIds, string $time): void {
    $appointmentIds = array_values(array_map('intval', $appointmentIds));
    if (!$appointmentIds || !zpms_note_table_exists($db, 'note_fields')) return;
    $in = implode(',', array_fill(0, count($appointmentIds), '?'));
    $db->prepare("UPDATE note_fields SET deleted = ? WHERE deleted IS NULL AND source = 'appointment' AND appointment_id IN ($in)")
        ->execute(array_merge([$time], $appointmentIds));
}

/** Soft delete of patients: hides every row of theirs (patient note and appointment notes). */
function zpms_note_fields_hide_patients(PDO $db, array $patientGuids, string $time): void {
    $patientGuids = array_values(array_map('strval', $patientGuids));
    if (!$patientGuids || !zpms_note_table_exists($db, 'note_fields')) return;
    $in = implode(',', array_fill(0, count($patientGuids), '?'));
    $db->prepare("UPDATE note_fields SET deleted = ? WHERE deleted IS NULL AND patient_guid IN ($in)")
        ->execute(array_merge([$time], $patientGuids));
}

/** Purge: removes for good the rows of these appointments and of these patients. */
function zpms_note_fields_purge(PDO $db, array $appointmentIds, array $patientIds): int {
    if (!zpms_note_table_exists($db, 'note_fields')) return 0;
    $n = 0;
    $appointmentIds = array_values(array_map('intval', $appointmentIds));
    $patientIds = array_values(array_map('intval', $patientIds));
    if ($appointmentIds) {
        $st = $db->prepare("DELETE FROM note_fields WHERE source = 'appointment' AND appointment_id IN (" . implode(',', array_fill(0, count($appointmentIds), '?')) . ')');
        $st->execute($appointmentIds);
        $n += $st->rowCount();
    }
    if ($patientIds) {
        $st = $db->prepare('DELETE FROM note_fields WHERE patient_id IN (' . implode(',', array_fill(0, count($patientIds), '?')) . ')');
        $st->execute($patientIds);
        $n += $st->rowCount();
    }
    return $n;
}

/**
 * Re-parses every note (or one patient's) from scratch: patient notes and
 * appointment notes, soft-deleted ones included (their rows stay hidden).
 * Rows left behind by notes that no longer exist go too.
 *
 * @return array{patients:int, appointments:int, fields:int, removed:int}
 */
function zpms_note_fields_rebuild(PDO $db, ?int $patientId, string $now, bool $apply): array {
    $spec = zpms_note_tags_spec();
    $drugs = zpms_note_rx_drugs($db);
    $report = ['patients' => 0, 'appointments' => 0, 'fields' => 0, 'removed' => 0];

    $sql = 'SELECT id, guid, pnote, deleted FROM patients' . ($patientId !== null ? ' WHERE id = ?' : '') . ' ORDER BY id';
    $st = $db->prepare($sql);
    $st->execute($patientId !== null ? [$patientId] : []);
    $patients = $st->fetchAll(PDO::FETCH_ASSOC);

    $count = $db->prepare($patientId !== null ? 'SELECT COUNT(*) FROM note_fields WHERE patient_id = ?' : 'SELECT COUNT(*) FROM note_fields');
    $count->execute($patientId !== null ? [$patientId] : []);
    $before = (int)$count->fetchColumn();

    if ($apply) $db->beginTransaction();
    try {
        if ($apply) {
            if ($patientId !== null) {
                $db->prepare('DELETE FROM note_fields WHERE patient_id = ?')->execute([$patientId]);
            } else {
                $db->exec('DELETE FROM note_fields');
            }
        }
        $apSt = $db->prepare('SELECT id, anote, deleted FROM appointments WHERE pguid = ? ORDER BY id');
        foreach ($patients as $p) {
            $report['patients']++;
            $notes = [['patient', null, $p['pnote'], $p['deleted']]];
            $apSt->execute([$p['guid']]);
            foreach ($apSt->fetchAll(PDO::FETCH_ASSOC) as $a) {
                $report['appointments']++;
                $notes[] = ['appointment', (int)$a['id'], $a['anote'], $a['deleted'] ?? $p['deleted']];
            }
            foreach ($notes as [$source, $apId, $text, $deleted]) {
                if ($apply) {
                    $report['fields'] += zpms_note_fields_sync($db, $source, $apId, (int)$p['id'], (string)$p['guid'], $text, $deleted, $now, $spec, $drugs);
                } else {
                    $report['fields'] += count(zpms_note_parse((string)$text, $spec, $drugs));
                }
            }
        }
        if ($apply) $db->commit();
    } catch (Throwable $e) {
        if ($apply) $db->rollBack();
        throw $e;
    }
    $report['removed'] = $before;
    return $report;
}

/**
 * Fills note_list_entries from `starter_lists:` in config/note_tags.yaml. A
 * tag's list is seeded only while it has no rows at all, so later edits and
 * deactivations are never undone. @return array<tag, entries added>
 */
function zpms_note_lists_seed(PDO $db, string $cuser, string $now, bool $apply): array {
    $cfg = zpms_note_tags_config();
    $report = [];
    $has = $db->prepare('SELECT COUNT(*) FROM note_list_entries WHERE tag_key = ?');
    $ins = $db->prepare('INSERT INTO note_list_entries (tag_key, text, aliases, next_words, rx_strengths, rx_defaults, sort, active, cdate, cuser)
        VALUES (?, ?, ?, ?, ?, NULL, ?, 1, ?, ?)');
    foreach (($cfg['starter_lists'] ?? []) as $key => $list) {
        if (!isset($cfg['tags'][$key])) continue;
        $has->execute([$key]);
        if ((int)$has->fetchColumn() > 0) continue;
        $nextWords = (array)($list['next_words'] ?? []);
        $sort = 0;
        foreach ((array)($list['entries'] ?? []) as $e) {
            if (!is_array($e)) $e = ['text' => $e];
            $sort += 10;
            if ($apply) {
                $ins->execute([$key, (string)$e['text'],
                    !empty($e['aliases']) ? implode(',', (array)$e['aliases']) : null,
                    $nextWords ? json_encode(array_values($nextWords), JSON_UNESCAPED_UNICODE) : null,
                    !empty($e['strengths']) ? json_encode(array_values(array_map('strval', (array)$e['strengths'])), JSON_UNESCAPED_UNICODE) : null,
                    $sort, $now, $cuser]);
            }
            $report[$key] = ($report[$key] ?? 0) + 1;
        }
    }
    return $report;
}

// --------------------------------------------------------------------------
// web app wrappers (called from web/index.php and bin/sync_google_calendar.php)
// --------------------------------------------------------------------------

/** Re-reads one appointment's note into note_fields. Never throws. */
function zpms_note_fields_sync_appointment($ap): void {
    try {
        if (!$ap || $ap->getid() === null) return;
        $db = dbConnection::getConnection();
        if (!zpms_note_table_exists($db, 'note_fields')) return;
        $pat = patientsClassEx::sgetByGuid($ap->getpguid());
        if (!$pat) return;
        zpms_note_fields_sync($db, 'appointment', (int)$ap->getid(), (int)$pat->getid(), (string)$pat->getguid(),
            (string)$ap->getanote(), $ap->getdeleted() ?? $pat->getdeleted(), getDBtime());
    } catch (Throwable $e) {
        error_log('note_fields: appointment #' . ($ap ? (int)$ap->getid() : 0) . ' not synced: ' . get_class($e));
    }
}

/** Re-reads the patient note into note_fields. Never throws. */
function zpms_note_fields_sync_patient($pat): void {
    try {
        if (!$pat || $pat->getid() === null) return;
        $db = dbConnection::getConnection();
        if (!zpms_note_table_exists($db, 'note_fields')) return;
        zpms_note_fields_sync($db, 'patient', null, (int)$pat->getid(), (string)$pat->getguid(),
            (string)$pat->getpnote(), $pat->getdeleted(), getDBtime());
    } catch (Throwable $e) {
        error_log('note_fields: patient #' . ($pat ? (int)$pat->getid() : 0) . ' not synced: ' . get_class($e));
    }
}

/**
 * The suggestion data for the note boxes (docs/CLINICAL_TAGS_PLAN.md section 6),
 * per tag: the fixed list (active entries, in order) and the previous entries
 * (from note_fields, most used first, with a count; soft-deleted and invalid
 * rows left out). Previous entries come only from notes the account may read:
 * patient notes need patients-view-list, appointment notes appointment-view
 * or appointment-edit. They are aggregated values with no patient attached.
 */
function zpms_note_suggestions(PDO $db, bool $patientNotes, bool $appointmentNotes, int $perTag = 40): array {
    $spec = zpms_note_tags_spec();
    $out = [];
    foreach ($spec['tags'] as $key => $tag) $out[$key] = ['list' => [], 'previous' => []];
    if (zpms_note_table_exists($db, 'note_list_entries')) {
        foreach ($db->query('SELECT tag_key, text, aliases, next_words, rx_strengths, rx_defaults FROM note_list_entries WHERE active = 1 ORDER BY tag_key, sort, id') as $r) {
            if (!isset($out[$r['tag_key']])) continue;
            $nw = json_decode((string)$r['next_words'], true);
            $st = json_decode((string)$r['rx_strengths'], true);
            $df = json_decode((string)$r['rx_defaults'], true);
            $out[$r['tag_key']]['list'][] = [
                'text' => (string)$r['text'],
                'aliases' => array_values(array_filter(array_map('trim', explode(',', (string)$r['aliases'])), 'strlen')),
                'next_words' => is_array($nw) ? array_values(array_map('strval', $nw)) : [],
                'strengths' => is_array($st) ? array_values(array_map('strval', $st)) : [],
                'defaults' => is_array($df) ? $df : null,
            ];
        }
    }
    $sources = [];
    if ($patientNotes) $sources[] = "'patient'";
    if ($appointmentNotes) $sources[] = "'appointment'";
    if ($sources && zpms_note_table_exists($db, 'note_fields')) {
        $st = $db->prepare('SELECT normalized, COUNT(*) AS c, MAX(value_text) AS t, MAX(id) AS last FROM note_fields
            WHERE tag_key = ? AND deleted IS NULL AND valid = 1 AND origin IS NULL AND normalized IS NOT NULL AND normalized <> \'\'
              AND source IN (' . implode(',', $sources) . ')
            GROUP BY normalized ORDER BY c DESC, last DESC LIMIT ' . (int)$perTag);
        foreach (array_keys($out) as $key) {
            $st->execute([$key]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[$key]['previous'][] = ['text' => (string)$r['t'], 'count' => (int)$r['c']];
            }
        }
    }
    return $out;
}

/**
 * The patient record's clinical summary (section 9, phase 4): for every tag with
 * `summary: true`, the values found in this patient's notes -- the patient note
 * first (it is the standing record), then appointment notes newest first. A
 * value repeated in several notes is shown once (its newest occurrence) with a
 * count. Soft-deleted rows and appointments, and impossible levels, are left
 * out. Appointment notes count only for a viewer who may see the appointment
 * cards ($withAppointments); $anchors maps an appointment id to its card's
 * anchor on the page (#app-N). Never throws: an empty summary on any failure.
 *
 * @return array{tags: array<string, array{label:string, colour:string, entries:array}>, allergies: array}
 */
function zpms_note_clinical_picture(PDO $db, int $patientId, bool $withAppointments, array $anchors = []): array {
    $out = ['tags' => [], 'allergies' => []];
    try {
        if (!zpms_note_table_exists($db, 'note_fields')) return $out;
        $spec = zpms_note_tags_spec();
        $sql = "SELECT n.tag_key, n.value_text, n.normalized, n.source, n.appointment_id, n.rx_total, a.adate
                FROM note_fields n LEFT JOIN appointments a ON a.id = n.appointment_id
                WHERE n.patient_id = ? AND n.deleted IS NULL AND n.valid = 1
                  AND (n.source = 'patient' OR (a.id IS NOT NULL AND a.deleted IS NULL))"
             . ($withAppointments ? '' : " AND n.source = 'patient'")
             . " ORDER BY (n.source = 'patient') DESC, a.adate DESC, n.appointment_id DESC, n.pos, n.id";
        $st = $db->prepare($sql);
        $st->execute([$patientId]);
        $seen = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $tag = $spec['tags'][$r['tag_key']] ?? null;
            if (!$tag || empty($tag['summary'])) continue;
            $key = $r['tag_key'] . '|' . ($r['normalized'] !== null && $r['normalized'] !== '' ? $r['normalized'] : zpms_note_normalize_text((string)$r['value_text']));
            if (isset($seen[$key])) {
                [$t, $i] = $seen[$key];
                $out['tags'][$t]['entries'][$i]['count']++;
                continue;
            }
            $isPatient = $r['source'] === 'patient';
            $entry = [
                'text' => (string)$r['value_text'],
                'date' => $isPatient ? null : (string)$r['adate'],
                'source' => $r['source'],
                'link' => $isPatient ? '#patient-note' : ($anchors[(int)$r['appointment_id']] ?? null),
                'count' => 1,
                'total' => $r['rx_total'] !== null ? (float)$r['rx_total'] : null,
                'tag' => $r['tag_key'],
                'colour' => (string)$tag['colour'],
            ];
            if (!isset($out['tags'][$r['tag_key']])) {
                $out['tags'][$r['tag_key']] = ['label' => (string)$tag['label'], 'colour' => (string)$tag['colour'], 'entries' => []];
            }
            $out['tags'][$r['tag_key']]['entries'][] = $entry;
            $seen[$key] = [$r['tag_key'], count($out['tags'][$r['tag_key']]['entries']) - 1];
            if (!empty($tag['header_alert'])) $out['allergies'][] = $entry;
        }
        // Panel boxes in the order of the tag file.
        $ordered = [];
        foreach (array_keys($spec['tags']) as $k) if (isset($out['tags'][$k])) $ordered[$k] = $out['tags'][$k];
        $out['tags'] = $ordered;
    } catch (Throwable $e) {
        error_log('note summary: ' . get_class($e));
        return ['tags' => [], 'allergies' => []];
    }
    return $out;
}

/** GET /notes/suggestions -- JSON for web/js/note-box.js. Only for accounts that write notes. */
function note_tags_suggestions($params) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $writes = rbacClass::isPermitted(ZPMS_PERM_PATIENTS_EDIT_PATIENT) || rbacClass::isPermitted(ZPMS_PERM_APPOINTMENT_EDIT);
    if (!$writes) {
        http_response_code(403);
        echo json_encode(['tags' => (object)[]]);
        exit();
    }
    try {
        $data = zpms_note_suggestions(dbConnection::getConnection(),
            rbacClass::isPermitted(ZPMS_PERM_PATIENTS_VIEW_LIST),
            rbacClass::isPermitted(ZPMS_PERM_APPOINTMENT_VIEW) || rbacClass::isPermitted(ZPMS_PERM_APPOINTMENT_EDIT));
    } catch (Throwable $e) {
        error_log('note suggestions: ' . get_class($e));
        $data = [];
    }
    echo json_encode(['tags' => (object)$data], JSON_UNESCAPED_UNICODE);
    exit();
}

/** Soft delete in the web app: hides the rows. Never throws. */
function zpms_note_fields_hide_for(?int $appointmentId, ?string $patientGuid, string $time): void {
    try {
        $db = dbConnection::getConnection();
        if ($appointmentId !== null) zpms_note_fields_hide_appointments($db, [$appointmentId], $time);
        if ($patientGuid !== null) zpms_note_fields_hide_patients($db, [$patientGuid], $time);
    } catch (Throwable $e) {
        error_log('note_fields: rows not hidden: ' . get_class($e));
    }
}
