<?php
/* How the framework loads an app's configuration files (Kernel::loadConfigFiles()):
 * the list comes from zeusfw's own config/zeusfw.info.yaml (`config_files:`), a
 * loaded file may add more, each is loaded once, in order, and a name can never
 * leave the config directory. Runs without the real config: a Kernel built without
 * its constructor reads throw-away files from a temp folder. */

function zpms_config_files_tests(TestRunner $runner): void {
    $kernelWith = function (array $configFiles): Kernel {
        $k = (new ReflectionClass('Kernel'))->newInstanceWithoutConstructor();
        $p = new ReflectionProperty('Kernel', 'config');
        $p->setAccessible(true);
        $p->setValue($k, $configFiles ? ['config_files' => $configFiles] : []);
        return $k;
    };
    $dir = function (array $files): string {
        $d = sys_get_temp_dir() . '/zpms-cfg-' . getmypid() . '-' . mt_rand();
        mkdir($d . '/more', 0700, true);
        foreach ($files as $name => $yaml) file_put_contents("$d/$name", $yaml);
        return $d;
    };

    $runner->add('the framework file lists the app files: site, then settings (required)', function () {
        $conf = yaml_parse_file(ZPMS_TEST_APPDIR . '/web/core/config/zeusfw.info.yaml');
        assert_equal('site.info.yaml', $conf['config_files'][0]);
        assert_equal(['file' => 'settings.info.yaml', 'required' => true], $conf['config_files'][1]);
    });

    $runner->add('files are merged in order; a missing optional file is skipped', function () use ($kernelWith, $dir) {
        $d = $dir(['site.info.yaml' => "name: site\nlist: [a]\n", 'settings.info.yaml' => "name2: settings\nlist: [b]\n"]);
        $k = $kernelWith(['site.info.yaml', 'absent.info.yaml', ['file' => 'settings.info.yaml', 'required' => true]]);
        $k->loadConfigFiles($d);
        assert_equal('site', $k->getConfig('name'));
        assert_equal('settings', $k->getConfig('name2'));
        assert_equal(['a', 'b'], $k->getConfig('list'), 'later files must add to earlier ones in order');
    });

    $runner->add('a loaded file can name more files; each is loaded once, after the ones already queued', function () use ($kernelWith, $dir) {
        $d = $dir([
            'site.info.yaml' => "order: [site]\nconfig_files:\n  - more/extra.info.yaml\n  - settings.info.yaml\n",
            'settings.info.yaml' => "order: [settings]\n",
            'more/extra.info.yaml' => "order: [extra]\nconfig_files:\n  - more/third.yaml\n",
            'more/third.yaml' => "order: [third]\n",
        ]);
        $k = $kernelWith(['site.info.yaml', 'settings.info.yaml']);
        $k->loadConfigFiles($d);
        assert_equal(['site', 'settings', 'extra', 'third'], $k->getConfig('order'), 'wrong order, or a file loaded twice');
    });

    $runner->add('with no list (an older framework file) site.info.yaml then settings.info.yaml are loaded', function () use ($kernelWith, $dir) {
        $d = $dir(['site.info.yaml' => "order: [site]\n", 'settings.info.yaml' => "order: [settings]\n"]);
        $k = $kernelWith([]);
        $k->loadConfigFiles($d);
        assert_equal(['site', 'settings'], $k->getConfig('order'));
    });

    $runner->add('file names that could leave the config directory are refused', function () {
        foreach (['site.info.yaml', 'more/x.yml', 'a-b_c.1.yaml'] as $ok) assert_true(Kernel::validConfigFileName($ok), "$ok should be valid");
        foreach (['', '/etc/passwd.yaml', '../x.yaml', 'a/../../x.yaml', 'x.php', 'x.yaml.php', 'a b.yaml', "x\0.yaml"] as $bad) {
            assert_false(Kernel::validConfigFileName($bad), 'accepted: ' . json_encode($bad));
        }
    });
}
