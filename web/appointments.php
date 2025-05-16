<?php

function appointments_list($params) {
    global $kernel;

    // SecurityClass::require('appointments-view-list');
    if(($errmsg=SecurityClass::require('appointments-view-list')))return $errmsg;

    $app = new appointmentsClass();
    $ap = $app->getAll();
    // $apnt = $ap->getAll();
    // echo "<pre>";
    // print_r( $pat );
    // echo "</pre>";
    $pp = array();
    foreach($ap as $appoint) {
        $pat = patientsClassEx::sgetByGuid( $appoint->getpguid());

        if(($appoint->getdeleted() == null) &&
            ($pat->getdeleted() == null)) 
            {


            $pp[] = ['id' => $appoint->getid(),
                    'adate' => $appoint->getadate(),
                    'pname' => $pat->getpname(),
                    'aplace' => $appoint->getaplace()
                ];
        }
    }

    return Renderer::render("appointments_list.zetem",
        ['app_list' => $pp,
            // 'notice' => $kernel->ifelseStatus('patient_edit', '', true)
        ]);
}


function appointment_edit($params) {
    global $kernel;

    if(!isset($params['id'])) {
        $kernel->addStatus('error', 'Το ραντεβού δεν βρέθηκε!');
        return ("patients doesn't exist");
    }

    $loc = locationsClassEx::sgetAll( $kernel->getCurrentLanguage() );

    $ap = new appointmentsClass();
    $app = $ap->getById($params['id']);
    return (Renderer::render("edit_appointment.zetem", ['action' => 'edit', 'id' => $params['id'], 'appointment' => $app, 'locations' => $loc]));
    
}
function appointment_edit_post($params) {
    global $kernel;

    SecurityClass::require('appointment-edit');

    if(isset($_POST['delete'])) {
        // $kernel->addStatus('warning', 'Η επεξεργασία του φακέλου ακυρώθηκε.');
        $kernel->pushRouteHistory($_SERVER['HTTP_REFERER']);
        // $kernel->addStatus('warning', 'Ο αποστολέας της εντολής είναι: '.$_SERVER['HTTP_REFERER']);
        header('location: '.rel_url('/appointment/'.$params['id'].'/delete'));
        exit();
    }            

    if(!isset($params['id'])) {
        return ("patients doesn't exist");
    }

    $ap = appointmentsClass::sgetById($params['id']);
    // error_log("\bFound appointment: " . print_r($ap, 1) . "\n");
    // $ap->setaplace($_POST['appointment-place']);
    $ap->setaplace((locationsClassEx::getbyMachineName($_POST['appointment-place'], $kernel->getCurrentLanguage()))->getname());

    // we cannot search for appointment-date, because of special handling of
    // date fields in the view page, we must search for appointmenet-date-?
    // where ? is the appointment index in the view page, we do not care for
    // the index itself, but for the value of that key
    // ON THE OTHER HAND
    // in the single page new appointment, the post field
    // is named 'appointment-date' 
    foreach($_POST as $postkey => $postval) {
        if(strstr($postkey, "appointment-date")) {
            // error_log($postkey . ' ==> ' . print_r($postkey, 1));
            $ap->setadate(getDBformattime($postval));    //$_POST['appointment-date']));
        }
    }

    $ap->setanote($_POST['appointment-notes']);

    $ap->update();

    // error_log('patient appointment saved');
    if(key_exists('use_ajax', $_POST)) {
        // error_log("\nused AJAX\n");
        echo "OK";
        exit();
    }
    // error_log('\nREFERER: '. $_SERVER['HTTP_REFERER']."\n");
    header('location: '. $_SERVER['HTTP_REFERER']);
}


function appointment_new($params) {
    global $kernel;


    // $loc = locationsClass::sgetAll();
    $loc = locationsClassEx::sgetAll( $kernel->getCurrentLanguage() );

    $ap = new appointmentsClass([
        'adate' => getDBtime(),
        'aplace' => ''
    ]);
    
    // error_log('\nREFERER: '. $_SERVER['HTTP_REFERER']."\n");

    // $pat = $pc->getById($params['id']);
    return (Renderer::render("edit_appointment.zetem", ['action' => 'new', 'id' => null, 'appointment' => $ap, 'locations' => $loc]));
}

function appointment_new_post($params) {
    global $kernel;


    $kernel->addStatus('warning', 'Η επεξεργασία του ραντεβού ακυρώθηκε.');
    header('location: '.rel_url($_SERVER['HTTP_REFERER']));
}

function appointment_delete($params) {
    global $kernel;

    $ap = new appointmentsClass();
    $app = $ap->getById($params['id']);
    $app->setdeleted( getDBtime() );
    $app->update();

    // $app->delete();

    $kernel->addStatus('warning', 'Το ραντεβού διαγράφθηκε με επιτυχία.');
    $kernel->addStatus('warning', 'Εάν θέλετε να ακυρώσετε την διαγραφή, κάντε κλικ ' .
                        '<a href="'.rel_url('/appointment/'.$params['id'].'/recover').'">εδώ</a>.'
                            );


    $s = $kernel->popRouteHistory();
    // $kernel->addStatus('warning', 'History route: ' . print_r($s, 1));

    if(!$s)$s = rel_url('/appointments');
    header('location: '.$s);
    exit();
}

function appointment_recover($params) {
    return 'recover appointment ' . $params['id'];
}

