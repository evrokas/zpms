<?php
/* Minimal, dependency-free assertion + test-runner library -- matching
 * this app's existing "no framework unless truly impractical to avoid"
 * convention (see docarc's CLAUDE.md for the same rule applied there; no
 * PHPUnit/Composer dependency exists anywhere in this project family). */

class TestFailure extends Exception {}

function assert_true($condition, string $message = 'expected true, got false'): void {
    if (!$condition) throw new TestFailure($message);
}

function assert_false($condition, string $message = 'expected false, got true'): void {
    if ($condition) throw new TestFailure($message);
}

function assert_equal($expected, $actual, string $message = ''): void {
    if ($expected !== $actual) {
        $exp = var_export($expected, true);
        $act = var_export($actual, true);
        throw new TestFailure($message !== '' ? $message : "expected $exp, got $act");
    }
}

function assert_not_equal($expected, $actual, string $message = ''): void {
    if ($expected === $actual) {
        $val = var_export($actual, true);
        throw new TestFailure($message !== '' ? $message : "expected value to differ from $val");
    }
}

function assert_contains(string $needle, ?string $haystack, string $message = ''): void {
    if ($haystack === null || !str_contains($haystack, $needle)) {
        $msg = $message !== '' ? $message : "expected to find " . var_export($needle, true) . " in string";
        throw new TestFailure($msg);
    }
}

function assert_not_contains(string $needle, ?string $haystack, string $message = ''): void {
    if ($haystack !== null && str_contains($haystack, $needle)) {
        $msg = $message !== '' ? $message : "expected NOT to find " . var_export($needle, true) . " in string";
        throw new TestFailure($msg);
    }
}

function assert_null($value, string $message = 'expected null'): void {
    if ($value !== null) throw new TestFailure($message);
}

function assert_not_null($value, string $message = 'expected non-null value'): void {
    if ($value === null) throw new TestFailure($message);
}

class TestRunner {
    /** @var array<string, callable> */
    private array $tests = [];
    private string $suiteName;

    function __construct(string $suiteName) {
        $this->suiteName = $suiteName;
    }

    function add(string $name, callable $fn): void {
        $this->tests[$name] = $fn;
    }

    /** Runs every registered test, printing PASS/FAIL as it goes. Returns true iff everything passed. */
    function run(): bool {
        echo "-- {$this->suiteName} --\n";
        $passed = 0;
        $failed = 0;
        foreach ($this->tests as $name => $fn) {
            try {
                $fn();
                echo "  PASS  $name\n";
                $passed++;
            } catch (TestFailure $e) {
                echo "  FAIL  $name\n        " . $e->getMessage() . "\n";
                $failed++;
            } catch (Throwable $e) {
                echo "  ERROR $name\n        " . get_class($e) . ': ' . $e->getMessage() . "\n";
                echo "        " . $e->getFile() . ':' . $e->getLine() . "\n";
                $failed++;
            }
        }
        $total = $passed + $failed;
        echo "   {$this->suiteName}: $passed/$total passed\n\n";
        return $failed === 0;
    }
}
