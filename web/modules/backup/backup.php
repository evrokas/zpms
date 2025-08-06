<?php

class backupModule extends moduleClass {
    static $dirPath;

    public function __construct($adir, $amodule, $atemplate) {
        parent::__construct($adir, $amodule, $atemplate);

        $rt = yaml_parse_file(__DIR__ . '/backup.yaml');
        global $kernel;
        $srt = $kernel->resolveModuleDir($rt, $adir, $amodule);
        $kernel->addConfig( $srt );

        global $router;
        $router->initRouteTable($kernel->getConfig('routes'));

        if(self::$dirPath = core_get_dir_in_lib('backups')) {
            // echopre("dir created successfully " . self::$dirPath);
        }
    }


    function render($params = array()) {
        return $this->renderTemplate([]);
    }

    function run($params = array()) {

        if(($ret=SecurityClass::require('backup-access')))return $ret;
        // echopre(">".__APPDIR__ . "< params: " . print_r($params, 1));

        // $t = core_get_file_in_lib('test.txt', 'backup');
        // echopre("test file: $t");

        // $pdflist = backupFilesClass::sgetAll();
        
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::$dirPath,RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY);
            
        // echopre("Files: " . print_r($files, 1));
        $file_list = [];
        foreach($files as $f) {
            $file_list[] = ['name' => $f, 'path' => $f->getPathName() ];
            // echopre(print_r($f, 1));
        }
        // $listhtml = [];
        // $ind = 1;
        // foreach($pdflist as $el) {
        //     // echopre("pdf element: " . print_r($el, 1));
        //     $elinfo = unserialize($el->getdata());
        //     $listhtml[] = $l = Renderer::render('pdflist_element.zetem', ['el' => $el, 'index' => $ind, 'info' => $elinfo]);
        //     $ind++;
        //     // echopre("element : " . print_r($l, 1));
        // }

        return $this->renderTemplate(['files' => $file_list]);
    }
}

function register_backup_module() {
    global $kernel;

    $kernel->registerModule(new backupModule(__DIR__, 'backup', 'backup.zetem'));
}


function backup_handler($params) {    
    return Renderer::render('backup.zetem', []);
}


function backup_process($params) {

    echopre("backup_process: " . print_r($params, 1));
    exit;
    SecurityClass::require('backup-access');

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['pdfFile'])) {

        $fileName = basename(basename($_FILES['pdfFile']['name']));
        $filePath = core_get_file_in_lib($fileName, 'backup');
        echopre("Filename to create: $fileName @ $filePath");
        
        if(file_exists($filePath)) {
            $results = "File name already exists. Please rename and try again (File: $fileName)";
        } else {
            if (move_uploaded_file($_FILES['pdfFile']['tmp_name'], $filePath)) {
                
                // Extract text from PDF
                $pdfText = pdf2text($filePath);
                // echopre("Extracted text: $pdfText");

                $results = scanPDF($pdfText);
                
                // Save data to the database, if record found
                if(count($results)>0) {
                    $dbentry = new backupFilesClass([
                        'guid' => guid(),
                        'cdate' => getDBtime(),
                        'cuser' => $_SESSION['user'],
                        'file_name' => $fileName,
                        'file_path' => $filePath,
                        'file_hash' => hashFile($filePath),
                        'data' => serialize( $results ),
                    ]);

                    $dbentry->insert();
                    $results = 'Record imported in database';

                } else {
                    $results = "Error parsing uploaded file";
                }
            } else {
                $results = 'File upload failed';

                // echo '<p>File upload failed.</p>';
            }
        }
    } else {
        $results = 'No file uploaded';
    }

    global $kernel;
    $kernel->addStatus('notice', $results);
    $output = json_encode($results);
    echo $output;
    exit();
}


function backup_action_delete($params) {
    global $kernel;


    SecurityClass::require('backup-access');

    if(isset($params['id'])) {
        $rec = backupFilesClass::sgetById($params['id']);
        if($rec) {
            $rec->delete();
            if(file_exists($rec->getfile_path())) {
                $kernel->addStatus('notice', 'file ' . $rec->getfile_name() . ' was deleted succesfully');
                unlink($rec->getfile_path());
            } else {
                $kernel->addStatus('error', 'file `' . $rec->getfile_name() . '` was not found in the library');
            }
        } else {
            $kernel->addStatus('error', "backup_action_delete: entry with id = " . $params['id'] . "is not valid");
        }

    } else {
        $kernel->addStatus('error', 'backup_action_delete: `id` argument is missing');
    }

    // return to page
    header('location: ' . rel_url('/apps/backup'));
    exit;
}


function backup_action_download($params) {
    global $kernel;

    $ret = SecurityClass::require('backup-access');
    if(!empty($ret)) {
        echo $ret;
        exit;
    }
    //  $ret;

    if(isset($params['id'])) {
        $rec = backupFilesClass::sgetById($params['id']);
        if($rec) {
            if(file_exists($rec->getfile_path())) {
                header('refresh:3;url=' . rel_url('/apps/backup'));
                header('Content-Description: File Transfer');
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename=' . $rec->getfile_name());
                header('Expires: 0');
                header('Cache-Control: must-revalidate');
                header('Pragma: public');         
                header('Content-Length: ' . filesize( $rec->getfile_path() ) );
                readfile($rec->getfile_path());          
    
                $kernel->addStatus('notice', 'file `'.$rec->getfile_name() . '` was downloaded succesfully');

            } else $kernel->addStatus('error', "file `' . $rec->getfile_name() . '` was not found in the library");

        } else $kernel->addStatus('error', "backup_action_download: entry with id = " . $params['id'] . " is not valid");

    } else
        $kernel->addStatus('error', 'backup_action_download: `id` argument is missing');

    // return to page
    exit;
    
}