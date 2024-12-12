<?php

class pdflibModule extends moduleClass {
    public function __construct($adir, $amodule, $atemplate) {
        parent::__construct($adir, $amodule, $atemplate);

        $rt = yaml_parse_file(__DIR__ . '/pdflib.yaml');
        
        global $kernel;
        $srt = $kernel->resolveModuleDir($rt, $adir, $amodule);
        $kernel->addConfig( $srt );

        global $router;
        $router->initRouteTable($kernel->getConfig('routes'));
    }


    function render($params = array()) {
        return $this->renderTemplate([]);
    }

    function run($params = array()) {

        if(($ret=SecurityClass::require('pdflib-access')))return $ret;


        // $t = core_get_file_in_lib('test.txt', 'pdflib');
        // echopre("test file: $t");

        $pdflist = pdflibFilesClass::sgetAll();
        
        $listhtml = [];
        $ind = 1;
        foreach($pdflist as $el) {
            // echopre("pdf element: " . print_r($el, 1));
            $elinfo = unserialize($el->getdata());
            $listhtml[] = $l = Renderer::render('pdflist_element.zetem', ['el' => $el, 'index' => $ind, 'info' => $elinfo]);
            $ind++;
            // echopre("element : " . print_r($l, 1));
        }
        return $this->renderTemplate(['pdflist' => $listhtml]);
    }
}

function register_pdflib_module() {
    global $kernel;

    $kernel->registerModule(new pdflibModule(__DIR__, 'pdflib', 'pdflib.zetem'));
}


function pdflib_handler($params) {    
    return Renderer::render('pdflib.zetem', []);
}



// Include pdf2text library
// require 'pdf2text.php';

function pdf2text($filename) {
    // Simple implementation to extract text from PDF
    $content = shell_exec('pdftotext ' . escapeshellarg($filename) . ' -');
    return $content ? $content : 'Error processing PDF';
}

function hashFile($filename) {
    $hash = hash_file('sha256', $filename);
    return $hash;
}

function scanPDF($text) {
    $patterns = [
        [
            ['series','number','date','mark'], 
                "/Τρόπος Πληρωμής\s*\n\s*([Α-Ω])\s*\n\s*(\d+)\s*\n\s*(\d{2}\/\d{2}\/\d{4})\s*\n\s*(\d+)/u"
            ],
        ['type', "/^(.*)\nΣειρά/m"],
        ['name',  "/Στοιχεία Πελάτη\nΑ\.Φ\.Μ.*?Επωνυμία:\s*(.*?)(\s)*Διεύθυνση/s"],
        ['address', '/Στοιχεία Πελάτη\s*.*\n\s*Διεύθυνση\s*:\s*(.+)/u'],
        ['code', '/Ποσότητα\\s*\\n\\s*(\\d+)/u'],
        ['reason', '/Ποσότητα.*?\n\d+\n(.*?)(?=\n\d+)/s'],
        ['amount', '/Πληρωτέο\s*\(€\)\s*:\s*(\d+(?:\.\d{1,2})?)/u'],
        ['notes', '/Παρατηρήσεις\\s*:\\s*(.+)/u']
    ];
    $pattern = "/Στοιχεία Πελάτη\nΑ\.Φ\.Μ.*?Επωνυμία(.*?)Διεύθυνση/s";

    $results = [];
    // echopre("patterns " . print_r($patterns));
    foreach($patterns as $pat) {
        if(is_array($pat[0])) {
            // echopre("Searching for pattern: [] => " . $pat[1]);
            if(preg_match($pat[1], $text, $matches)) {
                $ind = 1;
                foreach($pat[0] as $patname) {
                    // echopre("  name: $patname");
                    $results[$patname] = str_replace("\n", " " , $matches[$ind]);
                    // echopre("$patname => " . print_r($matches, 1));
                    $ind++;
                }
            }
        } else {
            // echopre("Searching for pattern: " . $pat[0] . " => " . $pat[1]);
            if(preg_match($pat[1], $text, $matches)) {
                $results[$pat[0]] = str_replace("\n", " ", $matches[1]);
                // echopre("$pat[0] => $matches[1]");
            }
        }
            
    }

    // echopre("Results: " . print_r($results,1));
    return ($results);
}
function pdflib_process($params) {

    SecurityClass::require('pdflib-access');

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['pdfFile'])) {

        $fileName = basename(basename($_FILES['pdfFile']['name']));
        $filePath = core_get_file_in_lib($fileName, 'pdflib');
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
                    $dbentry = new pdflibFilesClass([
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


function pdflib_action_delete($params) {
    global $kernel;


    SecurityClass::require('pdflib-access');

    if(isset($params['id'])) {
        $rec = pdflibFilesClass::sgetById($params['id']);
        if($rec) {
            $rec->delete();
            if(file_exists($rec->getfile_path())) {
                $kernel->addStatus('notice', 'file ' . $rec->getfile_name() . ' was deleted succesfully');
                unlink($rec->getfile_path());
            } else {
                $kernel->addStatus('error', 'file `' . $rec->getfile_name() . '` was not found in the library');
            }
        } else {
            $kernel->addStatus('error', "pdflib_action_delete: entry with id = " . $params['id'] . "is not valid");
        }

    } else {
        $kernel->addStatus('error', 'pdflib_action_delete: `id` argument is missing');
    }

    // return to page
    header('location: ' . rel_url('/apps/pdflib'));
    exit;
}

function pdflib_action_download($params) {
    global $kernel;

    $ret = SecurityClass::require('pdflib-access');
    if(!empty($ret)) {
        echo $ret;
        exit;
    }
    //  $ret;

    if(isset($params['id'])) {
        $rec = pdflibFilesClass::sgetById($params['id']);
        if($rec) {
            if(file_exists($rec->getfile_path())) {
                header('refresh:3;url=' . rel_url('/apps/pdflib'));
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

        } else $kernel->addStatus('error', "pdflib_action_download: entry with id = " . $params['id'] . " is not valid");

    } else
        $kernel->addStatus('error', 'pdflib_action_download: `id` argument is missing');

    // return to page
    exit;
    
}