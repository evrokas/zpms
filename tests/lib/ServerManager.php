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

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $this->process = proc_open($cmd, $descriptors, $pipes, $webRoot);
        if (!is_resource($this->process)) {
            throw new RuntimeException("Could not start test server: $cmd");
        }
        // Don't block this process waiting on the child's stdout/stderr.
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $this->waitUntilReady();
    }

    private function waitUntilReady(): void {
        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline) {
            $status = proc_get_status($this->process);
            if (!$status['running']) {
                throw new RuntimeException('Test server exited immediately after starting -- see stderr above.');
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
    }

    function __destruct() {
        $this->stop();
    }
}
