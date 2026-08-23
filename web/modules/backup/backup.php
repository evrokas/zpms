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
        return $this->renderTemplate(['status' => $this->readStatus()]);
    }

    function run($params = array()) {
        if(($ret = rbacClass::require(ZPMS_PERM_BACKUP_ACCESS)))return $ret;
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
}

function register_backup_module() {
    global $kernel;

    $kernel->registerModule( new backupModule(__DIR__, 'backup', 'backup.zetem'));
}
