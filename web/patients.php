<?php

function patients_list($params) {
    global $kernel;

    SecurityClass::require('patients-view-list');

    // echopre(print_r($params,1));
    // $pc = new patientsClass();
    // $pat = $pc->getAll();

    // $pat = patientsClass::sgetAll();
    if(array_key_exists("key", $params)) {
        $sort_key = $params['key'];

        if(array_key_exists("order", $params)) {
            $order_key = $params['order'];
        }
    }

    if(isset($sort_key)) {
        if(!in_array($sort_key, ['name', 'lastapp']))$sort_key = 'lastapp';
    } else $sort_key = "lastapp";
    
    if(isset($order_key)) {
        if(!in_array($order_key, ['1', '0']))$order_key = "1";
    } else if($sort_key == "lastapp")$order_key = "0"; else $order_key = '1';

    // if(isset($sort_key))echopre("SORT set: " . $sort_key);
    // if(isset($order_key))echopre("ORDER set: " . $order_key);


    switch($sort_key) {
        case 'name':
            $pat = patientsClassEx::getPatientsByName($order_key);
            break;
        case 'lastapp':
            $pat = patientsClassEx::getPatientsByLastAppointment($order_key);
            break;
    }

    // echo "<pre>";
    // print_r( $pat );
    // echo "</pre>";
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
}

function patients_search_post($params) {
    SecurityClass::require('patients-view-list');

    if(strlen($_POST['search-term'])>0) {
        header('location: '.rel_url('/patients/search/'.urlencode($_POST['search-term'])));
    } else {
        header('location: '.rel_url('/patients'));
    }

    exit();
}

function patients_list_search($params) {
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

function patients_list_search_ajax($params) {
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
                'guid' => $p->getguid(),
                'name' => $p->getpname(),
                'amka' => $p->getpamka(),
                'dob' => formatBrowserDate($p->getpdob()),
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
function patient_edit($params) {
    global $kernel;

    if($errmsg=SecurityClass::require('patients-edit-patient'))return $errmsg;

    if(!isset($params['id'])) {
        $kernel->addStatus('error', 'Ο φάκελος του ασθενή είναι λάθος!');
        return ("patient id doesn't exist");
    }

    // $pc = new patientsClass();
    // $pat = $pc->getById($params['id']);
    // error_log("\n$params: " .print_r($params, 1)."\n");
    $pat = patientsClass::sgetById( $params['id'] );

    // echo "<pre>patient: " . print_r($pat, 1) . "</pre>";
    $app_list = appointmentsClassEx::getAppointmentsForPatient($pat->getguid(), 'DESC');
    // $loc = locationsClass::sgetAll();
    $loc = locationsClassEx::sgetAll( $kernel->getCurrentLanguage() );

    $apprender = array();
    $appdates = array();
    foreach($app_list as $ap) {
        
        if($ap->getdeleted() == null) {
            $apprender[] = [
                'index' => count($apprender),
                'markup' => Renderer::render('view_appointment.zetem', [
                                                'action' => rel_url('/appointment/' . $ap->getid() . '/edit'),
                                                'index' => count($apprender)+1,
                                                'checked' => "checked",/*(!count($apprender)?"checked":""),*/
                                                'id' => $params['id'], 
                                                'patient' => $pat,
                                                'appointment' => $ap,
                                                'locations' => $loc
                                            ]),
                'attributes' => new Attributes()
            ];
            $appdates[] = [ 'index' => count($apprender), 'date' => $ap->getadate() ];
        }
    }

    return (Renderer::render("edit_patient.zetem", [
        'action' => 'edit', 
        'id' => $params['id'], 
        'patient' => $pat,
        'appdates' => $appdates,
        'appointments' => $apprender
    ]));
}
function patient_edit_post($params) {
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


function patient_new($params) {
    global $kernel;

    SecurityClass::require('patients-new-patient');

    $pc = new patientsClass([
        'pname' => '', //randomAlpha(12, 2),
        'pdob' => getDBtime(),  //'1977-07-29 00:00:00',
        'pamka' => '', //randomNumber(11),
        'ptel' => '', //randomNumber(10),
        'pemail' => '', //randomEmail(),
        'paddr' => '', //randomAlnum(12, 3),
        'pnote' => '', //randomALnum(20,10)
    ]);
    
    // $pat = $pc->getById($params['id']);
    return (Renderer::render("edit_patient.zetem", ['action' => 'new', 'id' => null, 'patient' => $pc]));
}

function patient_delete($params) {
    global $kernel;

    SecurityClass::require('patients-delete-patient');

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


function patient_new_post($params) {
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

function patient_appointment_new($params) {
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


function patient_appointment_new_post($params) {
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
function ajax_update_patient_info($params) {
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
