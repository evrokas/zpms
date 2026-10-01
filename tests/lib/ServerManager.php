<?php
/*
 * Spawns/stops the `php -S` dev server the HTTP-level functional tests
 * (auth/CSRF, patient CRUD, appointment CRUD, file uploads) drive via
 * TestHttpClient -- same "php -S + curl" manual-testing convention this
 * project family's own README/CLAUDE.md files already describe, just
 * automated.
 */
class TestServer {
    private $process = null;
    private string $host;
    private int $port;
    private ?string $stdoutLog = null;
    private ?string $stderrLog = null;

    function __construct(string $host = '127.0.0.1', int $port = 8781) {
        $this->host = $host;
        $this->port = $port;
    }

    function baseUrl(): string {
        return "http://{$this->host}:{$this->port}";
    }

    function start(): void {
        $webRoot = ZPMS_TEST_APPDIR . '/web';
        $router = __DIR__ . '/router.php';
        $prepend = __DIR__ . '/prepend_test_db.php';

        // `exec` so the shell replaces itself with the php process instead
        // of forking a child of it -- proc_get_status()'s pid then IS the
        // actual php -S server, so stop() can signal it directly without
        // needing process-group tricks to reach through an intermediate sh.
        $cmd = sprintf(
            'exec php -d display_errors=1 -d auto_prepend_file=%s -S %s:%d -t %s %s',
            escapeshellarg($prepend),
            escapeshellarg($this->host),
            $this->port,
            escapeshellarg($webRoot),
            escapeshellarg($router)
        );

        // stdout/stderr go to files, not pipes. A pipe's OS buffer is small
        // (64KB on Linux) and nothing here ever reads from one -- php -S
        // logs one line per request to stdout by default, plus any PHP
        // warning/notice the app under test emits (display_errors=1, set
        // above, only affects what's echoed into the *response body*; PHP
        // separately writes the same diagnostics to its error_log target,
        // which defaults to stderr here). Across a long functional run
        // that's enough lines to fill an unread 64KB pipe outright -- once
        // full, the *child's* next write() call blocks, so the php -S
        // process itself hangs mid-request, indistinguishable from the
        // outside from a genuinely slow/stuck request (confirmed directly:
        // a form-heavy page hit late in a long run reproducibly hung for
        // the full 15s curl timeout with the server never sending a single
        // byte back, and stopped reproducing the instant stdout/stderr were
        // pointed at files instead of pipes). Using files instead of
        // stream_set_blocking()'s false on a pipe actually fixes this --
        // non-blocking only changes how the *reader* (this process, which
        // never reads anyway) behaves, not whether the *writer* (the php -S
        // child) blocks on a full buffer.
        $this->stdoutLog = tempnam(sys_get_temp_dir(), 'zpms_test_server_stdout_');
        $this->stderrLog = tempnam(sys_get_temp_dir(), 'zpms_test_server_stderr_');
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $this->stdoutLog, 'w'],
            2 => ['file', $this->stderrLog, 'w'],
        ];
        $this->process = proc_open($cmd, $descriptors, $pipes, $webRoot);
        if (!is_resource($this->process)) {
            throw new RuntimeException("Could not start test server: $cmd");
        }

        $this->waitUntilReady();
    }

    private function waitUntilReady(): void {
        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline) {
            $status = proc_get_status($this->process);
            if (!$status['running']) {
                throw new RuntimeException('Test server exited immediately after starting -- see ' . $this->stderrLog);
            }
            $conn = @fsockopen($this->host, $this->port, $errno, $errstr, 0.2);
            if ($conn) {
                fclose($conn);
                return;
            }
            usleep(50000);
        }
        throw new RuntimeException("Test server did not become reachable within 5s at {$this->baseUrl()}.");
    }

    /** Path to the server's own stderr (PHP warnings/errors, one request's worth interleaved with the rest) -- for a human debugging a failure after the fact. */
    function stderrLogPath(): ?string {
        return $this->stderrLog;
    }

    function stop(): void {
        if ($this->process === null) {
            return;
        }
        $status = proc_get_status($this->process);
        if ($status['running']) {
            posix_kill($status['pid'], SIGTERM);
            $deadline = microtime(true) + 3.0;
            while (microtime(true) < $deadline) {
                $status = proc_get_status($this->process);
                if (!$status['running']) {
                    break;
                }
                usleep(50000);
            }
            if ($status['running']) {
                posix_kill($status['pid'], SIGKILL);
            }
        }
        proc_close($this->process);
        $this->process = null;

        // Deliberately removed only on a clean stop(), not left for
        // __destruct() to race against -- a test harness that crashes
        // ($server->stop() never called) leaves these on disk, which is
        // the one case a human most wants them still readable.
        if ($this->stdoutLog !== null && is_file($this->stdoutLog)) {
            @unlink($this->stdoutLog);
        }
        if ($this->stderrLog !== null && is_file($this->stderrLog)) {
            @unlink($this->stderrLog);
        }
    }

    function __destruct() {
        $this->stop();
    }
}
