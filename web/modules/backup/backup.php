<?php

class backupModule extends moduleClass {

    public function __construct($adir, $amodule, $atemplate) {
        parent::__construct($adir, $amodule, $atemplate);

        $rt = yaml_parse_file(__DIR__ . '/backup.yaml');
        global $kernel;
        $srt = $kernel->resolveModuleDir($rt, $adir, $amodule);
        $kernel->addConfig( $srt );

        global $router;
        $router->initRouteTable($kernel->getConfig('routes'));
    }

    function render($params = array()) {
        return $this->renderTemplate([
            'status' => $this->readStatus(),
            'generations' => $this->readGenerations(),
        ]);
    }

    function run($params = array()) {
        if(($ret = SecurityClass::require('backup-access')))return $ret;
        return $this->render($params);
    }

    // Real backups are produced by bin/backup.sh, run nightly via cron/
    // systemd (see deploy/zpms-backup.cron/.timer/.service) -- there is no
    // "create backup" action on this page. This just surfaces the status
    // file that script writes on every run (web/files/logs/backup_status.json:
    // last_run_ts, status), so staff can tell at a glance whether last
    // night's backup succeeded without needing shell/log access. Returns
    // null if backups haven't been configured/run yet on this install.
    private function readStatus(): ?array {
        $statusFile = __APPDIR__ . '/web/files/logs/backup_status.json';
        if (!is_file($statusFile)) {
            return null;
        }
        $data = json_decode((string)file_get_contents($statusFile), true);
        return is_array($data) ? $data : null;
    }

    // Companion to readStatus() above: bin/backup.sh also writes
    // web/files/logs/backup_generations.json on every successful run --
    // {generated_at, tiers: {daily: [...], weekly: [...], monthly: [...]}},
    // each tier a sorted list of generation names actually present on the
    // backup destination (oldest first, matching bin/restore.sh --list's
    // own sort order). This page never talks SSH itself -- it only reads
    // this file, which bin/backup.sh already has the access to produce
    // once a night. Returns null under the same conditions readStatus()
    // does (not configured/run yet).
    private function readGenerations(): ?array {
        $generationsFile = __APPDIR__ . '/web/files/logs/backup_generations.json';
        if (!is_file($generationsFile)) {
            return null;
        }
        $data = json_decode((string)file_get_contents($generationsFile), true);
        return is_array($data) && isset($data['tiers']) && is_array($data['tiers']) ? $data : null;
    }
}

function register_backup_module() {
    global $kernel;

    $kernel->registerModule( new backupModule(__DIR__, 'backup', 'backup.zetem'));
}
