<?php

// functions specific for operations menu

function operations_list($params) {
    global $kernel;

    if($errmsg=SecurityClass::require('operations-view-list'))return $errmsg;

    if(array_key_exists("key", $params)) {
        $sort_key = $params['key'];

        if(array_key_exists("order", $params)) {
            $order_key = $params['order'];
        }
    }

    // if(isset($sort_key)) {
    //     if(!in_array($sort_key, ['name', 'lastapp']))$sort_key = 'lastapp';
    // } else $sort_key = "lastapp";
    
    // if(isset($order_key)) {
    //     if(!in_array($order_key, ['1', '0']))$order_key = "1";
    // } else if($sort_key == "lastapp")$order_key = "0"; else $order_key = '1';

    // if(isset($sort_key))echopre("SORT set: " . $sort_key);
    // if(isset($order_key))echopre("ORDER set: " . $order_key);

    // $sort_key = "date";

    $opers = operationsClass::sgetAll();

    // switch($sort_key) {
    //     case 'name':
    //         $pat = patientsClassEx::getPatientsByName($order_key);
    //         break;
    //     case 'lastapp':
    //         $pat = patientsClassEx::getPatientsByLastAppointment($order_key);
    //         break;
    // }


    // echopre("operations: " . print_r( $opers, 1));
    if(!count($opers)) {
        return "No operations found!";
    }
    // $fieldNames = $opers[0]->getFields();
    // echopre("fieldNames: " . print_r($fieldNames, 1));

    $form = formsClass::getFormRenderArray("operations");
    // echopre("form: " . print_r($form['inputs'], 1)); 

    $fields = ['opdate', 'pname', 'opdiagnosis', 'opprocedure', 'clinic'];

    $headerFields = array_filter($form['inputs'], function ($v) use ($fields) {
        return (in_array($v['name'], $fields));
    });


    // echopre("header fields: " . print_r($headerFields, 1));
    $headers = [];
    foreach($headerFields as $hd) {
        $headers[] = ['label' => $hd['label'], 'name' => $hd['name'] ];
    }
    // echopre("headers: " . print_r($headers, 1));

    $data = [];
    foreach($opers as $op) {
        $row = [];
        // echopre("op: " . print_r($op, 1));
        $rr = $op->getAllFields();
        foreach($fields as $fld) {
            $r = $op->getFieldValue("get".$fld);

            switch($fld) {
                case 'opdate': 
                    $r = formatDate($r);
                    break;
            }
            
            $row[] = $r;
        }   
        // echopre("row: " . print_r($row, 1));
        // echopre("row2: " . print_r($rr, 1));
        // $data[] = $row;
        $data[] = $rr;
    }
    // echopre("data: " . print_r($data, 1));

    return Renderer::render('operations_list.zetem', 
            ['headers' => $headers,
                'rowdata' => $data
            ]
        );

/* 
    $opersTable = formsClass::renderFormResults('operations', ['pname', 'pguid']);

    return $opersTable;

    $pp = array();
    foreach($pat as $p) {
        if($p['p']->getdeleted() == null) {

            if($p['a'])$dt = date_format(new DateTime($p['a']), "d-m-Y H:i");
            else $dt = null;

            $pp[] = ['id' => $p['p']->getid(),
                    'pname' => $p['p']->getpname(),
                    'pamka' => $p['p']->getpamka(),
                    'lastapp' => $dt
                ];
        }
    }

    return Renderer::render("patients_list.zetem",
        ['pat_list' => $pp,
            // 'notice' => $kernel->ifelseStatus('patient_edit', '', true)
        ]);
 */
}

function operations_search_post($params) {
    SecurityClass::require('patients-view-list');

    if(strlen($_POST['search-term'])>0) {
        header('location: '.rel_url('/patients/search/'.urlencode($_POST['search-term'])));
    } else {
        header('location: '.rel_url('/patients'));
    }

    exit();
}

function operations_list_search($params) {
    SecurityClass::require('patients-view-list');

    $pat = patientsClassEx::search(urldecode($params['term']));

    
    // $s = '';
    // foreach($pat as $p) {
    //     $s .= "<pre>name: " . $p->getpname() . "</pre>";
    // }   
    // return ($s);

    $pp = array();
    foreach($pat as $p) {
        $ppp = array();
        $ppp['id'] = $p->getid();
        $ppp['pname'] = $p->getpname();
        $ppp['pamka'] = $p->getpamka();
        $ppp['tel'] = $p->getptel();

        $pp[] = $ppp;
    }

    return Renderer::render("patients_list.zetem",
        [   'search_term' => $params['term'],
            'pat_list' => $pp,
            // 'notice' => $kernel->ifelseStatus('patient_edit', '', true)
        ]);
}

function operations_list_search_ajax($params) {
    SecurityClass::require('patients-view-list');

    $arg = $_POST['sterm'];
    // error_log("\nAjax request: ". print_r($arg, 1) . "\n");;
    // error_log("\nurldecode: " . urldecode($arg));
    $pat = patientsClassEx::search($arg);   //, ['pname', 'pamka', 'ptel']);

    error_log("\nSearch response: " . print_r($pat, 1));

    $list = array();
    foreach($pat as $p) {
        // error_log('dob ->' . $p->getpdob());
        $list[] = [
                'id' => $p->getid(),
                'name' => $p->getpname(),
                'amka' => $p->getpamka(),
                // 'age' => DateTime::createFromFormat('Y-m-d h:m:s', $p->getpdob())->diff(new DateTime('now'))->y,
                'tel' => $p->getptel(),
                'link' => rel_url('/patient/' . $p->getid() . '/edit')
                // date('Y', date_diff(date(), time($p->getpdob)))
            ];
    }

    $base = explode('index.php', $_SERVER['PHP_SELF'])[0];
    $response = [
        'base' => $base,
        'referer' => explode($base, $_SERVER['HTTP_REFERER'])[0],
        'list' => $list
    ];
    // error_log("\nSearch response: " . print_r($list, 1));

    // $json = json_encode($list);
    $json = json_encode($response);
    // echopre("ajax search: " . $json);
    echo $json;
    exit();

}
function operation_edit($params) {
    global $kernel;

    if($errmsg=SecurityClass::require('operations-edit-operation'))return $errmsg;

    if(!isset($params['id'])) {
        $kernel->addStatus('error', 'Ο φάκελος του ασθενή είναι λάθος!');
        return ("operations doesn't exist");
    }

    $op = new operationsClass([
        'opdate' => getDBtime(),
        'cuser' => $kernel->getUserName(),
        'cdate' => getDBtime(),
        'pname' => '',
        'pdob' => '',
        'opdiagnosis' => '',
        'oprocedure' => '',
        'clinic' => '',
        'category' => '',
        'surgeon1' => '',
        'surgeon2' => '',
        'anesthesiology' => '',
        'invoice_status' => 'pending'
    ]);

    $opForm = formsClass::getFormRenderArray('operations');
    $subBtn = generateFormButton('submit', 'Προσθήκη', 'new');
    $opForm = formArrayAddButton($opForm, $subBtn);

    // $subBtn = generateFormButton('submit', 'Αναζήτηση ασθενή', 'patientsearch');
    // $opForm = formArrayAddButton($opForm, $subBtn);

    $subBtn = generateFormButton('reset', 'Καθαρισμός', 'reset');
    $opForm = formArrayAddButton($opForm, $subBtn);

    // foreach($opForm['inputs'] as $key => $inp) {
        // if($inp['label'] === 'Name' && $inp['name'] === 'pname') {
            // echopre("found : " . print_r($inp, 1));
            // $opForm['inputs'][$key]['attributes'] = ['class' => 'select2'];
        // }
    // }
    $opForm['inputs'][0]['default'] = formatBrowserDate( time() );

    setupFormActionHandler($opForm, 'operations_handler');

    // $opForm = setupFormActionHandler($opForm, 'operations_handler');

    // echopre("op form: " . print_r($opForm, 1));
    $opForm = formsClass::renderFormArray( $opForm );
    return $opForm;
}

function operation_edit_post($params) {
    global $kernel;

    SecurityClass::require('patients-edit-patient');

    if(isset($_POST['delete'])) {
        $kernel->addStatus('warning', 'Η επεξεργασία του φακέλου ακυρώθηκε.');
        header('location: '.rel_url('/patients'));
        exit();
    }            

    if(!isset($params['id'])) {
        return ("patient doesn't exist");
    }

    // error_log("Get patient: " . print_r($params, 1 ));
    // echo "this is the post version<br/>";
    $pat = patientsClass::sgetById($params['id']);
    // error_log("Patient: " . print_r($pat, 1)."\n");

    // if(isset($_POST['submit'])) {
    
    
    $pat->loadFields([
        'cuser' => $kernel->getUserName(),
        'cdate' => getDBtime(),
        'pname' => $_POST['patient-name'],
        'pdob' => getDBformattime($_POST['patient-dob']),
        'pamka' => $_POST['patient-amka'],
        'ptel' => $_POST['patient-telephone'],
        'paddr' => $_POST['patient-address'],
        'pemail' => $_POST['patient-email'],
        'pnote' => $_POST['patient-note']
    ]);
    
    $pat->update();

    // error_log("patient record saved\n");
    
    if(isset($_POST['use_ajax'])) {
        echo "OK";
        exit();
    }

    $kernel->addStatus('notice', 'Ο φάκελος του ασθενή <b>' . $pat->getpname() . '</b> έχει αποθηκευτεί.');
    header('location: '.rel_url('/patient/'.$pat->getid().'/edit'));
    exit();
    // return '';
    // patients_list(['notice' => 'Ο φάκελος του ασθενή ' + $pat->getpname() . ' έχει αποθηκευτεί.']);

    // return (Renderer::render("edit_patients.zetem", ['notice' => 'data were saved', 'id' => $params['id'], 'patient' => $pat]));
}


function operation_new($params) {
    global $kernel;

    if($errmsg=SecurityClass::require('operations-new-patient'))return $errmsg;

    $op = new operationsClass([
        'opdate' => getDBtime(),
        'cuser' => $kernel->getUserName(),
        'cdate' => getDBtime(),
        'pname' => '',
        'pdob' => '',
        'opdiagnosis' => '',
        'opprocedure' => '',
        'clinic' => '',
        'category' => '',
        'surgeon1' => '',
        'surgeon2' => '',
        'anesthesiology' => '',
        'invoice_status' => 'pending'
    ]);

    $opForm = formsClass::getFormRenderArray('operations');
    $subBtn = generateFormButton('submit', 'Προσθήκη', 'new');
    $opForm = formArrayAddButton($opForm, $subBtn);

    // $subBtn = generateFormButton('submit', 'Αναζήτηση ασθενή', 'patientsearch');
    // $opForm = formArrayAddButton($opForm, $subBtn);

    $subBtn = generateFormButton('reset', 'Καθαρισμός', 'reset');
    $opForm = formArrayAddButton($opForm, $subBtn);

    // foreach($opForm['inputs'] as $key => $inp) {
        // if($inp['label'] === 'Name' && $inp['name'] === 'pname') {
            // echopre("found : " . print_r($inp, 1));
            // $opForm['inputs'][$key]['attributes'] = ['class' => 'select2'];
        // }
    // }
    $opForm['inputs'][0]['default'] = formatBrowserDate( time() );

    setupFormActionHandler($opForm, 'operations_handler');

    // $opForm = setupFormActionHandler($opForm, 'operations_handler');

    // echopre("op form: " . print_r($opForm, 1));
    $opForm = formsClass::renderFormArray( $opForm );
    return $opForm;
}

function operation_delete($params) {
    global $kernel;

    if($errmsg=SecurityClass::require('operations-delete-patient'))return $errmsg;

    $pat = patientsClass::sgetById($params['id']);

    $dtime = getDBtime();    // make delete time same for patient and appointments
                        // so to be able to recover them later

    // also delete all records of appointments for this patient
    $app_list = appointmentsClassEx::getAppointmentsForPatient($pat->getguid());
    foreach($app_list as $ap) {
        $ap->setdeleted( $dtime );
        $ap->update();
        // $ap->delete();
    }

    $pat->setdeleted( $dtime );
    $pat->update();

    // $pat->delete();

    $kernel->addStatus('warning', 'Ο φάκελος του ασθενή <b>'.$pat->getpname() . '</b> διαγράφθηκε με επιτυχία.');
    header('location: '.rel_url('/patients'));
    exit();
}


function operation_new_post($params) {
    global $kernel;


    SecurityClass::require('patients-new-patient');

    error_log('\nAjax request: '. $kernel->isAjaxRequest()?"true":"false" . "\n");
    
    if(isset($_POST['use_ajax'])) {
        echo "REJECTED";
        exit();
    }

    if(!isset($_POST['submit']) && !isset($_POST['submitedit']))exit();

    // echo "this is the post version<br/>";
    $pc = new patientsClass([
        // 'guid' => 
        'id' => null,
        'cuser' => $kernel->getUserName(),
        'cdate' => getDBtime(),
        'pname' => $_POST['patient-name'],
        'pdob' => getDBformattime($_POST['patient-dob']),
        'pamka' => $_POST['patient-amka'],
        'ptel' => $_POST['patient-telephone'],
        'paddr' => $_POST['patient-address'],
        'pemail' => $_POST['patient-email'],
        'pnote' => $_POST['patient-note'],
        'guid' => guid()
    ]);
    // print_r( $pc );

    $pc->insert();

    $kernel->addStatus('notice', 'Δημιουργήθηκε νέος φάκελος για τον ασθενή <b>' . $pc->getpname() . '</b>');

    if(isset($_POST['submitedit']))
        header('location: '.rel_url('/patient/'.$pc->getid().'/edit'));
    else
        header('location: '.rel_url('/patients'));

    exit();
}

function operation_appointment_new($params) {
    global $kernel;

    if(!isset($params['id'])) {
        return ("User could not be found");
    }

    // error_log('\nSERVER: ' . print_r($_SERVER, 1));

    if(isset($_POST['use_ajax'])) {
        // if this is an AJAX request, then do nothing, because the record doesn't exist yet!
        echo "REJECTED";
        exit();
    }
    
    // error_log("\npatient_appointment_new: ".print_r($params, 1)."\n");
    $p = patientsClass::sgetById( $params['id'] );
    // echo "<pre>" . print_r( $p ) . "</pre>";

    $loc = locationsClassEx::sgetAll( $kernel->getCurrentLanguage() );
    // $loc = locationsClass::sgetAll();
    $ap = new appointmentsClass([
        'adate' => getDBtime(),
        'aplace' => locationsClassEx::getCurrentLocation(),
        'anote' => '', //print_r( $_SERVER, 1) /*'notes'*/
    ]);
    // $kernel->setS
    // $pat = $pc->getById($params['id']);
    return (Renderer::render("edit_appointment.zetem", ['action' => 'newappointment', 'id' => null, 'appointment' => $ap, 'patient' => $p, 'locations' => $loc]));
}


function operation_appointment_new_post($params) {
    global $kernel;

    // error_log("<pre>patient_appointment_new_post: ".print_r($params, 1)."</pre>");
    // error_log("\nPatient id: " );
    if(isset($_POST['cancel'])) {
        $kernel->addStatus('warning', 'Η επεξεργασία του ραντεβού ακυρώθηκε.');
        header('location: '.rel_url('/patient/'.$params['id'].'/edit'));
        exit();
    }            

    if(!isset($_POST['submit'])) {

    }
         
    $pat = patientsClass::sgetById( $params['id'] );
    if(!$pat) {
        echo "User " . $params['id'] . "cannot be found!\n";
        exit();
    }

    $loc = locationsClassEx::getbyMachineName($_POST['appointment-place'], $kernel->getCurrentLanguage());


    // echo "this is the post version<br/>";
    $app = new appointmentsClass([
        // 'guid' => 
        'id' => null,
        'cuser' => 'admin',
        'cdate' => getDBtime(),
        'adate' => getDBformattime($_POST['appointment-date']),
        'aplace' => ($loc)?$loc->getname():'',   //$_POST['appointment-place'],
        'anote' => $_POST['appointment-notes'],
        'guid' => guid(),
        'pguid' => $pat->getguid()
    ]);
    // print_r( $pc );

    $app->insert();

    // error_log('\nREFERER: '. $_SERVER['HTTP_REFERER']."\n");

    $kernel->addStatus('notice', 'Δημιουργήθηκε νέο ραντεβού.');
    header('location: '.rel_url('/patient/'.$pat->getid().'/edit'));
}

// AJAX callback for updating patient info data
function ajax_update_operation_info($params) {
    global $kernel;

    SecurityClass::require('patients-edit-patient');

    $pat = patientsClass::sgetById($params['id']);

    $pat->loadFields([
        'cuser' => $kernel->getUserName(),
        'cdate' => getDBtime(),
        'pname' => $_POST['patient-name'],
        'pdob' => getDBformattime($_POST['patient-dob']),
        'pamka' => $_POST['patient-amka'],
        'ptel' => $_POST['patient-telephone'],
        'paddr' => $_POST['patient-address'],
        'pemail' => $_POST['patient-email'],
        'pnote' => $_POST['patient-note']
    ]);

    // save this final request for after debugging
    // $pat->update();

    error_log('\najax update_patient_info: note: ' . $_POST['patients-note']);
    return ("OK");
}

function operations_handler($params = array()) {
  global $kernel;

  echopre( "operations handler " . print_r($params, 1) . " POST: " . print_r($_POST, 1) );

    $action = $_POST['submit'] ?? null;
    switch( $action ) {
        case 'new': 

            $op = new operationsClass([
                'opdate' => getDBtime(),
                'guid' => $_POST['guid'] ?? guid(),
                'pguid' => $_POST['guid'] ?? guid(),
                'cuser' => $kernel->getUserName(),
                'cdate' => getDBtime(),
                'pname' => $_POST['pname'] ?? '<pname>',
                'pdob' => getDBformattime( $_POST['pdob'] ?? null ),
                'opdiagnosis' => $_POST['opdiagnosis'] ?? '<opdiagnosis>',
                'opprocedure' => $_POST['opprocedure'] ?? '<oprocedure>',
                'clinic' => suppliersClass::sgetAllFilter('suppliers', ['guid' => $_POST['clinic']])[0]->getname(),
                'category' => $_POST['category'] ??  '<category>',
                'surgeon1' => doctorsClass::sgetAllFilter('doctors', ['guid' => $_POST['surgeon1']])[0]->getdoctor_name(),
                'surgeon2' => doctorsClass::sgetAllFilter('doctors', ['guid' => $_POST['surgeon2']])[0]->getdoctor_name(),
                'anesthesiology' => doctorsClass::sgetAllFilter('doctors', ['guid' => $_POST['anesthesiology']])[0]->getdoctor_name(),
                'invoice_status' => invoiceStatusClass::sgetAllFilter('invoicestatus', [ 'guid' => $_POST['invoice_status'] ])[0]->getstatus() ?? '<invoice_status>'
            ]);
            echopre("new operation: " . print_r($op, 1));
            $op->insert();
            $kernel->addStatus('notice', "Η επέμβαση του ασθενή <b>" . $_POST['pname'] . "</b> καταχωρήθηκε επιτυχώς.");
            header('location: '.rel_url('/operations'));
            exit;
            break;

        // case 'newedit': return 'new & edit operation'; break;
    }

    return null;
    // echopre("operations_handler", print_r($params, 1));
    return "operations handler for dispatcher " . print_r($params, 1) . " POST: " . print_r($_POST, 1);
}



// handle all invoices and payment invoices actions
function invoices_handler($params = []) {
  global $kernel;

    echopre( "payments handler " . print_r($params, 1) . " POST: " . print_r($_POST, 1) );

    $action = $_POST['submit'] ?? null;
    switch( $action ) {
        case 'new': 
            $pay = new invoicesClass([
                'guid' => guid(),
                'cdate' => getDBtime(),
                'cuser' => $kernel->getUserName(),
                'patient_id' => $_POST['patient_id'] ?? '<patient_id>',
                'inv_date' => getDBformattime( $_POST['inv_date'] ?? null ),
                'total_amount' => $_POST['total_amount'] ?? 0,
                'tax_amount' => $_POST['tax_amount'] ?? 0,
                'inv_status' => $_POST['inv_status'] ?? '<inv_status>',
                'payment_method' => $_POST['payment_method'] ?? '<payment_method>',
                'supplier_id' => suppliersClass::sgetAllFilter('suppliers', ['guid' => $_POST['clinic']])[0]->getname()
            ]);

            echopre("new invoice: " . print_r($pay, 1));

            // $pay->insert();
            
            break;
        }

}

function paymentinvoices_list($params = array()) {
    return "Payment Invoices List";
}

function paymentinvoices_new($params = array()) {
    return "Payment Invoices Add";
}

function invoices_new($params = []) {
  global $kernel;

    if($errmsg=SecurityClass::require('finance-new-invoice'))return $errmsg;

    // $op = new invoicesClass([
    //     'opdate' => getDBtime(),
    //     'cuser' => $kernel->getUserName(),
    //     'cdate' => getDBtime(),
    //     'pname' => '',
    //     'pdob' => '',
    //     'opdiagnosis' => '',
    //     'oprocedure' => '',
    //     'clinic' => '',
    //     'category' => '',
    //     'surgeon1' => '',
    //     'surgeon2' => '',
    //     'anesthesiology' => '',
    //     'invoice_status' => 'pending'
    // ]);

    $invForm = formsClass::getFormRenderArray('invoices');
    $subBtn = generateFormButton('submit', 'Προσθήκη', 'new');
    $invForm = formArrayAddButton($invForm, $subBtn);

    // $subBtn = generateFormButton('submit', 'Αναζήτηση ασθενή', 'patientsearch');
    // $opForm = formArrayAddButton($opForm, $subBtn);

    $subBtn = generateFormButton('reset', 'Καθαρισμός', 'reset');
    $invForm = formArrayAddButton($invForm, $subBtn);

    // foreach($invForm['inputs'] as $key => $inp) {
        // if($inp['label'] === 'Name' && $inp['name'] === 'pname') {
            // echopre("found : " . print_r($inp, 1));
            // $invForm['inputs'][$key]['attributes'] = ['class' => 'select2'];
        // }
    // }
    foreach($invForm['inputs'] as $index => $inp) {
        if($inp['name'] === 'inv_date') $invForm['inputs'][$index]['default'] = formatBrowserDate( time() );
    }

    setupFormActionHandler($invForm, 'invoices_handler');

    // $invForm = setupFormActionHandler($invForm, 'operations_handler');

    // echopre("op form: " . print_r($invForm, 1));
    $invForm = formsClass::renderFormArray( $invForm );
    return $invForm;
}

function invoices_list($params = []) {
    return "Invoices list";
}

/* 
function pdf2text($filename) {
    // Simple implementation to extract text from PDF
    $content = shell_exec('pdftotext ' . escapeshellarg($filename) . ' -');
    return $content ? $content : 'Error processing PDF';
}
 */


function invoices_upload_new($params = []) {
    global $kernel;
    
    $pdfscanner_path = __APPDIR__ . '/web/test/pr/pdfparse.php';
    $tempFilePath = $_FILES['pdfFile']['tmp_name'];
    error_log("temporary file name: " . $tempFilePath);
    
    $txtFilePath = tempnam('/tmp/', 'text');
    shell_exec("pdftotext -layout $tempFilePath $txtFilePath");
    
    $scan = shell_exec("php $pdfscanner_path $txtFilePath");
    $results = json_decode($scan, true);

    $res =[
        'document_type' => $results['document_type'],
        'document_name' => $results['results']['document_type'],
        'series' => $results['results']['series'],
        'number' => $results['results']['invoice_number'],
        'mark' => $results['results']['mark_number'],
        'payment_method' => $results['results']['payment_method'],
    ];
    // $kernel->addStatus('notice', "pdf $tempFilePath uploaded, results: " . print_r($results, 1));
    // $content = 
    echo(json_encode($res, JSON_UNESCAPED_UNICODE));
    // echo "file uploaded";
    exit;
    return "new file uploaded";

}