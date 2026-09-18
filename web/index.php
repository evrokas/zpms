<?php

include_once(__DIR__ . "/../config/db.php");       // load database parameters
// require_once(__DIR__ . '/../../config/db.php');

// Optional -- only present once an admin has set up the DocArc patient
// lookup integration (see config/docarc_api.php.in). Guarded with
// is_file() (unlike db.php above) since its absence is a normal,
// supported state, not a fatal misconfiguration.
if (is_file(__DIR__ . '/../config/docarc_api.php')) {
    include_once(__DIR__ . '/../config/docarc_api.php');
}

// Optional -- only present once an admin has set up the APYweb
// financial-info lookup integration (see config/apyweb_api.php.in).
// Same is_file() guard as docarc_api.php above.
if (is_file(__DIR__ . '/../config/apyweb_api.php')) {
    include_once(__DIR__ . '/../config/apyweb_api.php');
}

require_once(__FWDIR__ . '/bootstrap.php');
require_once(__DIR__ . '/api_patients.php');
require_once(__DIR__ . '/appointment_files.php');
require_once(__DIR__ . '/apyweb_client.php');
require_once(__DIR__ . '/google_calendar_client.php');
require_once(__DIR__ . '/rbac.php');


    ini_set('session.gc_maxlifetime', 3600);
    // cookie_lifetime is deliberately no longer overridden here -- the PHP
    // session cookie itself now always dies on browser close (lifetime 0,
    // set explicitly in zeusfw_session_start(), called from Kernel::boot()
    // below); persistence across browser restarts is delegated entirely to
    // the separate zeusfwrememberme cookie/user_tokens mechanism, same as
    // DocArc.

    // Project Zeus - Patient Registration System

    // zpms's own one-off setup that has to run at boot() 's app-hook point
    // (after the session/language/output-buffer are ready, before modules
    // are registered and the route is dispatched) -- see Kernel::boot()'s
    // own docblock (zeusfw core/kernel/Kernel.php) for the function_exists()
    // convention this follows.
    function zeusfw_app_boot() {
        locationsClassEx::setDefaultLocation();
    }

    $kernel = new Kernel($_SERVER, "../config");

    // Runs the whole request bootstrap + dispatch sequence that used to be
    // hand-written here: Request/Router/Renderer construction, session
    // start, the CSRF/lockout/login-redirect opt-ins below, remember-me
    // cookie resolution, language selection, module registration, route
    // matching + dispatch, and the final render/flush. See Kernel::boot()'s
    // own docblock for exactly what each step does and why.
    $kernel->boot([
        // Opt in to CSRF enforcement on the shared login_post() -- see
        // csrfClass::$enforceLogin's docblock (zeusfw core/lib/Csrf.php).
        // Safe to do unconditionally: web/templates/content/login.zetem
        // (zpms's own override of the core login form) always renders
        // csrf_field() now, so every real login submission already carries
        // a valid token.
        'csrf_login' => true,

        // Same reasoning for the shared webforms dispatcher -- see
        // csrfClass::$enforceWebforms's docblock. generateHTMLForm() already
        // renders csrf_field() into every DB-defined webform unconditionally
        // (framework-wide, harmless for apps that don't check it); this
        // just tells processform() to actually verify it for zpms's own
        // webforms.
        'csrf_webforms' => true,

        // Opt in to the brute-force lockout on the shared login_post() --
        // see LoginSecurityClass's docblock (zeusfw core/lib/UserLogin.php).
        // Safe to enable unconditionally: every account starts at
        // wrongpasscount=0, so this can't retroactively lock anyone out, it
        // only starts counting failed attempts from here on. Deliberately
        // NOT also calling enableAccountStatusEnforcement() here -- that
        // one requires first confirming every real account in this server's
        // users table actually has active=1 and expired=0 set (see the same
        // docblock for why an account can be working today without that
        // being true), which needs a human with production DB access to
        // check, not something this change can safely assume.
        'login_lockout' => true,

        // Redirect straight to /login instead of a bare 401 page for any
        // route with `access:` that an unauthenticated/unpermitted request
        // hits -- see SecurityClass::$loginRedirectUrl's own docblock
        // (zeusfw core/lib/Security.php). homepage() below applies the same
        // "go straight to /login" treatment to '/' itself, which has no
        // `access:` of its own (it renders different content per login
        // state rather than being gated) and so never reaches this
        // codepath.
        'login_redirect' => '/login',

        'default_language' => 'gr',
    ]);


/* ----- website handlers ----- */

    function homepage($params) {
        global $kernel;

        // Straight to /login rather than a "please login" landing page --
        // same "get an unauthenticated visitor to the login form with no
        // extra click" treatment SecurityClass::$loginRedirectUrl gives
        // every `access:`-gated route (see its own docblock); '/' has no
        // `access:` of its own since it renders different content per
        // login state, so it needs this one explicit check instead.
        if(!SecurityClass::userLoggedIn()) {
            header('location: ' . rel_url('/login'));
            exit();
        }

        // user is logged, so show a different page
        if(!isset($_SESSION['location'])) {
            $locname = null;
        } else $locname = $_SESSION['location'];

        $uname = $kernel->getUserName();
        $account = UsersClassEx::getUserAccount($uname);

        return Renderer::render("homepage.zetem", [
            'location' => $locname,
            'display_name' => $account ? $account->getname() : $uname,
            // Only Patients/New Patient/QR generation live on the homepage
            // now -- everything else (profile, settings, backups) is
            // reachable from the nav menu, same access decision it already
            // makes for the same destinations (config/settings.info.yaml's
            // menu.main).
            'can_view_patients' => rbacClass::isPermitted(ZPMS_PERM_PATIENTS_VIEW_LIST),
            'can_create_patients' => rbacClass::isPermitted(ZPMS_PERM_PATIENTS_NEW_PATIENT),
        ]);
    }


    function patients_list($params) {
        global $kernel;

        if(($errmsg = rbacClass::require(ZPMS_PERM_PATIENTS_VIEW_LIST)))return $errmsg;

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
                'can_create_patient' => rbacClass::isPermitted(ZPMS_PERM_PATIENTS_NEW_PATIENT),
                'can_delete_patient' => rbacClass::isPermitted(ZPMS_PERM_PATIENTS_DELETE_PATIENT)
            ]);
    }

    function patients_search_post($params) {
        if(($errmsg = rbacClass::require(ZPMS_PERM_PATIENTS_VIEW_LIST)))return $errmsg;

        if(strlen($_POST['search-term'])>0) {
            header('location: '.rel_url('/patients/search/'.urlencode($_POST['search-term'])));
        } else {
            header('location: '.rel_url('/patients'));
        }

        exit();
    }

    function patients_list_search($params) {
        if(($errmsg = rbacClass::require(ZPMS_PERM_PATIENTS_VIEW_LIST)))return $errmsg;

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
                'can_create_patient' => rbacClass::isPermitted(ZPMS_PERM_PATIENTS_NEW_PATIENT),
                'can_delete_patient' => rbacClass::isPermitted(ZPMS_PERM_PATIENTS_DELETE_PATIENT)
            ]);
    }

    function patients_list_search_ajax($params) {
        if(($errmsg = rbacClass::require(ZPMS_PERM_PATIENTS_VIEW_LIST)))return $errmsg;

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
    function patient_edit($params) {
        global $kernel;

        // Viewing a patient's page and actually saving changes to it are
        // gated separately -- see ZPMS_PERM_PATIENTS_VIEW_LIST's own
        // docblock in web/rbac.php. A holder of only patients-view-list
        // (e.g. the secretary role) can open this page and read
        // everything on it; patient_edit_post() below still requires
        // patients-edit-patient regardless of what a tampered request
        // submits, and $canEditPatient/$canEditAppointment (computed
        // below) drive edit_patient.zetem/view_appointment.zetem
        // rendering the page fully read-only (disabled fields, no
        // save/appointment/attachment controls) when the viewer lacks
        // the corresponding edit permission.
        if(($errmsg = rbacClass::require(ZPMS_PERM_PATIENTS_VIEW_LIST)))return $errmsg;

        $canEditPatient = rbacClass::isPermitted(ZPMS_PERM_PATIENTS_EDIT_PATIENT);
        $canEditAppointment = rbacClass::isPermitted(ZPMS_PERM_APPOINTMENT_EDIT);

        if(!isset($params['id'])) {
            $kernel->addStatus('error', 'Ο φάκελος του ασθενή δεν βρέθηκε!');
            return ("patients doesn't exist");
        }

        // $pc = new patientsClass();
        // $pat = $pc->getById($params['id']);
        // error_log("\n$params: " .print_r($params, 1)."\n");
        $pat = patientsClass::sgetById( $params['id'] );

        // Read-only financial-info block (APYweb integration) -- silent
        // no-op (null) when unconfigured/unreachable/no match, see
        // apyweb_client.php's own docblock for why this has no
        // user-facing error unlike an interactive lookup.
        $financials = zpms_apyweb_fetch_financials($pat->getpname());
        $hasFinancials = $financials !== null && (
            count($financials['invoices']) > 0
            || count($financials['operations']) > 0
            || count($financials['fee_reports']) > 0
        );

        // echo "<pre>patient: " . print_r($pat, 1) . "</pre>";
        $app_list = appointmentsClassEx::getAppointmentsForPatient($pat->getguid(), 'DESC');
        // $loc = locationsClass::sgetAll();
        $loc = locationsClassEx::sgetAll( $kernel->getCurrentLanguage() );

        // A viewer without appointment-edit (e.g. the secretary role)
        // never sees the full editable appointment/operation cards
        // (view_appointment.zetem -- notes, save/delete, file uploads) --
        // instead gets a compact, read-only summary listing just the date
        // and location of each, per request. Only one of these two arrays
        // is ever built, since edit_patient.zetem renders one or the
        // other, never both.
        $apprender = array();
        $appdates = array();
        $appointmentSummary = array();
        foreach($app_list as $ap) {

            if($ap->getdeleted() == null) {
                if ($canEditAppointment) {
                    $apprender[] = [
                        'index' => count($apprender),
                        'markup' => Renderer::render('view_appointment.zetem', [
                                                        'action' => rel_url('/appointment/' . $ap->getid() . '/edit'),
                                                        'index' => count($apprender)+1,
                                                        'checked' => "checked",/*(!count($apprender)?"checked":""),*/
                                                        'id' => $params['id'],
                                                        'patient' => $pat,
                                                        'appointment' => $ap,
                                                        'locations' => $loc,
                                                        'files' => appointmentFilesClassEx::getFilesForAppointment($ap->getid())
                                                    ]),
                        'attributes' => new Attributes()
                    ];
                    $appdates[] = [ 'index' => count($apprender), 'date' => $ap->getadate() ];
                } else {
                    $appointmentSummary[] = [
                        'date' => $ap->getadate(),
                        'location' => $ap->getaplace(),
                    ];
                }
            }
        }

        return (Renderer::render("edit_patient.zetem", [
            'action' => 'edit',
            'id' => $params['id'],
            'patient' => $pat,
            'appdates' => $appdates,
            'appointments' => $apprender,
            'appointment_summary' => $appointmentSummary,
            'financials' => $financials,
            'has_financials' => $hasFinancials,
            'can_edit_patient' => $canEditPatient,
            'can_edit_appointment' => $canEditAppointment
        ]));
    }
    function patient_edit_post($params) {
        global $kernel;

        if(($errmsg = rbacClass::require(ZPMS_PERM_PATIENTS_EDIT_PATIENT)))return $errmsg;

        if(!csrfClass::verifyRequest()) {
            if(isset($_POST['use_ajax'])) {
                echo "FAILED";
                exit();
            }
            $kernel->addStatus('error', 'Μη έγκυρο token ασφαλείας (CSRF). Παρακαλώ προσπαθήστε ξανά.');
            header('location: '.rel_url('/patients'));
            exit();
        }

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
        
        $res = $pat->update();

        if(isset($_POST['use_ajax'])) {
            if($res) {
                echo "OK";
            } else {
                echo "FAILED";
            }
            exit();
        }

        if($res) {
            $kernel->addStatus('notice', 'Ο φάκελος του ασθενή <b>' . htmlspecialchars($pat->getpname(), ENT_QUOTES, 'UTF-8') . '</b> έχει αποθηκευτεί.');
        } else {
            $kernel->addStatus('error', 'Αδυναμία αποθήκευσης φακέλου.');
        }

        header('location: '.rel_url('/patient/'.$pat->getid().'/edit'));
        exit();
        // return '';
        // patients_list(['notice' => 'Ο φάκελος του ασθενή ' + $pat->getpname() . ' έχει αποθηκευτεί.']);
    
        // return (Renderer::render("edit_patients.zetem", ['notice' => 'data were saved', 'id' => $params['id'], 'patient' => $pat]));
    }


    function patient_new($params) {
        global $kernel;

        if(($errmsg = rbacClass::require(ZPMS_PERM_PATIENTS_NEW_PATIENT)))return $errmsg;

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
        // A brand-new, not-yet-saved patient form is always fully
        // editable -- reaching this handler already required
        // patients-new-patient above, so there's no read-only case here.
        return (Renderer::render("edit_patient.zetem", [
            'action' => 'new',
            'id' => null,
            'patient' => $pc,
            'can_edit_patient' => true,
            'can_edit_appointment' => true
        ]));
    }

    function patient_delete($params) {
        global $kernel;

        if(($errmsg = rbacClass::require(ZPMS_PERM_PATIENTS_DELETE_PATIENT)))return $errmsg;

        // Was a plain GET link (a bare <a href>, no server-side check at
        // all beyond the role) -- deleting a patient record (and cascading
        // their appointments below) from a mere GET request is a classic
        // CSRF hole: a crafted link, <img> tag, or malicious page could
        // silently delete a record for any logged-in staff member who just
        // loads it. Now POST-only (see config/settings.info.yaml) with a
        // real CSRF token, submitted via the small form patients_list.zetem
        // now renders instead of a bare link.
        if(!csrfClass::verifyRequest()) {
            $kernel->addStatus('error', 'Μη έγκυρο token ασφαλείας (CSRF). Παρακαλώ προσπαθήστε ξανά.');
            header('location: '.rel_url('/patients'));
            exit();
        }

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

        $kernel->addStatus('warning', 'Ο φάκελος του ασθενή <b>'.htmlspecialchars($pat->getpname(), ENT_QUOTES, 'UTF-8') . '</b> διαγράφθηκε με επιτυχία.');
        header('location: '.rel_url('/patients'));
        exit();
    }


    function patient_new_post($params) {
        global $kernel;


        if(($errmsg = rbacClass::require(ZPMS_PERM_PATIENTS_NEW_PATIENT)))return $errmsg;

        error_log('\nAjax request: '. $kernel->isAjaxRequest()?"true":"false" . "\n");

        if(isset($_POST['use_ajax'])) {
            echo "REJECTED";
            exit();
        }

        if(!isset($_POST['submit']) && !isset($_POST['submitedit']))exit();

        if(!csrfClass::verifyRequest()) {
            $kernel->addStatus('error', 'Μη έγκυρο token ασφαλείας (CSRF). Παρακαλώ προσπαθήστε ξανά.');
            header('location: '.rel_url('/patient/new'));
            exit();
        }

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

        $kernel->addStatus('notice', 'Δημιουργήθηκε νέος φάκελος για τον ασθενή <b>' . htmlspecialchars($pc->getpname(), ENT_QUOTES, 'UTF-8') . '</b>');

        if(isset($_POST['submitedit']))
            header('location: '.rel_url('/patient/'.$pc->getid().'/edit'));
        else
            header('location: '.rel_url('/patients'));

        exit();
    }

    /**
     * Live duplicate-name check for the "new patient" form
     * (edit_patient.zetem, action=='new'). Called on every keystroke
     * (debounced client-side, see [data-check-duplicate] in scripts.js)
     * so staff creating a patient record are warned before they save a
     * second record for someone already on file, and can jump straight
     * to the existing one instead. Name-only scope (unlike the general
     * patients_list_search_ajax, which also matches ptel/pamka) since a
     * phone/AMKA coincidence isn't a "same patient name" duplicate.
     */
    function patient_new_check_name($params) {
        header('Content-Type: application/json');

        if (rbacClass::require(ZPMS_PERM_PATIENTS_NEW_PATIENT)) {
            http_response_code(401);
            echo json_encode(['matches' => []]);
            exit();
        }

        $term = trim((string)($_POST['pname'] ?? ''));

        if (mb_strlen($term) < 2) {
            echo json_encode(['matches' => []]);
            exit();
        }

        $found = patientsClassEx::search($term, ['pname'], true, 8);

        $matches = [];
        foreach ((array)$found as $p) {
            $matches[] = [
                'id' => $p->getid(),
                'name' => $p->getpname(),
                'amka' => $p->getpamka(),
                'link' => rel_url('/patient/' . $p->getid() . '/edit'),
            ];
        }

        echo json_encode(['matches' => $matches]);
        exit();
    }

    function appointment_edit_post($params) {
        global $kernel;

        if(($errmsg = rbacClass::require(ZPMS_PERM_APPOINTMENT_EDIT)))return $errmsg;

        if(!csrfClass::verifyRequest()) {
            if(key_exists('use_ajax', $_POST)) {
                echo "FAILED";
                exit();
            }
            $kernel->addStatus('error', 'Μη έγκυρο token ασφαλείας (CSRF). Παρακαλώ προσπαθήστε ξανά.');
            header('location: '. $_SERVER['HTTP_REFERER']);
            exit();
        }

        if(isset($_POST['delete'])) {
            // CSRF was already verified above, in this same request -- delete
            // directly instead of redirecting through a second GET hop to
            // /appointment/{id}/delete, which used to have no CSRF check of
            // its own (that route now requires POST + a token too, so a
            // plain redirect here wouldn't even satisfy it anymore -- see
            // appointment_perform_delete()).
            appointment_perform_delete($params['id']);
        }

        if(!isset($params['id'])) {
            return ("patients doesn't exist");
        }

        $ap = appointmentsClass::sgetById($params['id']);
        // error_log("\bFound appointment: " . print_r($ap, 1) . "\n");
        // $ap->setaplace($_POST['appointment-place']);
        // Null-safe -- same pattern already used by patient_appointment_new_post()
        // for this identical lookup. A machine name with no matching row (empty
        // locations table, or one deleted between page load and submit) used to
        // be an uncaught fatal error (getname() on null) instead of just saving
        // an empty place, a real crash on a plain edit-and-save.
        $loc = locationsClassEx::getbyMachineName($_POST['appointment-place'], $kernel->getCurrentLanguage());
        $ap->setaplace($loc ? $loc->getname() : '');

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

        // atype is deliberately never read/set here -- it's fixed at
        // creation (see patient_appointment_new()'s own comment for where
        // it's actually decided) and this form carries no editable control
        // for it at all, so there's nothing in $_POST to trust or ignore.

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


    // Shared by appointment_delete() (the direct route -- CSRF-checked
    // there) and appointment_edit_post()'s delete branch above (CSRF
    // already checked earlier in that same request) -- actually performs
    // the soft-delete and redirects, so a delete triggered from the inline
    // appointment card no longer needs a second GET hop through a
    // separate route to do the real work.
    function appointment_perform_delete($id) {
        global $kernel;

        $ap = new appointmentsClass();
        $app = $ap->getById($id);
        $app->setdeleted( getDBtime() );
        $app->update();

        // $app->delete();

        $kernel->addStatus('warning', 'Το ραντεβού διαγράφθηκε με επιτυχία.');

        // Appointments are always viewed from inside their patient's own
        // record, so that's always the right place to land back on --
        // resolved directly from the just-deleted appointment's own
        // pguid, rather than trusting a Referer/route-history value that
        // could be missing, spoofed, or simply wrong.
        $pat = patientsClassEx::sgetByGuid($app->getpguid());
        $s = $pat ? rel_url('/patient/'.$pat->getid().'/edit') : rel_url('/patients');
        header('location: '.$s);
        exit();
    }

    function appointment_delete($params) {
        global $kernel;

        if(($errmsg = rbacClass::require(ZPMS_PERM_APPOINTMENT_EDIT)))return $errmsg;

        // Was a plain GET link with no CSRF check of its own -- a crafted
        // link/<img>/malicious page could silently delete an appointment
        // for any logged-in staff member who just loaded it. Now POST-only
        // (see config/settings.info.yaml) with a real CSRF token.
        if(!csrfClass::verifyRequest()) {
            $kernel->addStatus('error', 'Μη έγκυρο token ασφαλείας (CSRF). Παρακαλώ προσπαθήστε ξανά.');
            header('location: '.rel_url('/patients'));
            exit();
        }

        appointment_perform_delete($params['id']);
    }

    // Renders the "new appointment"/"new operation" form -- type is
    // decided once, right here, entirely by which of the two functions
    // below called this ("Νέο Ραντεβού" vs "Νέο Χειρουργείο" on
    // edit_patient.zetem, two separate GET routes/links, see
    // patient_operation_new()'s own comment for why a query string
    // couldn't be used for this instead). There's no dropdown anywhere in
    // this flow; edit_appointment.zetem just displays whichever type this
    // resolved to as a fixed label and carries it through to
    // patient_appointment_new_post() via a hidden field (always POSTing
    // back to the plain /newappointment route regardless of which page
    // rendered the form), and once saved it's permanent --
    // appointment_edit_post() never touches atype at all.
    function patient_appointment_new_render($params, string $atype) {
        global $kernel;

        // Was missing entirely -- neither this function nor either of its
        // two GET routes (config/settings.info.yaml) had any access check,
        // so a specific patient's name/DOB/AMKA/etc. rendered into this
        // form (below) was reachable by anyone who could guess/enumerate a
        // patient id, logged in or not. Same permission appointment_edit_post()/
        // appointment_delete()/every appointment_file_* handler already
        // requires for this exact resource -- there is no separate
        // "create" permission for appointments.
        if(($errmsg = rbacClass::require(ZPMS_PERM_APPOINTMENT_EDIT)))return $errmsg;

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
            'atype' => $atype,
        ]);
        // $kernel->setS
        // $pat = $pc->getById($params['id']);
        return (Renderer::render("edit_appointment.zetem", [
            'action' => rel_url('/appointment/' . $params['id'] . '/newappointment'),
            'id' => null,
            'appointment' => $ap,
            'patient' => $p,
            'locations' => $loc
        ]));
    }

    function patient_appointment_new($params) {
        return patient_appointment_new_render($params, 'appointment');
    }

    function patient_operation_new($params) {
        return patient_appointment_new_render($params, 'operation');
    }

    function patient_appointment_new_post($params) {
        global $kernel;

        // Was missing entirely -- CSRF alone doesn't imply the requester
        // is logged in (a session/token exists for anonymous visitors
        // too), so this endpoint would create a real appointment record
        // for any patient id from an unauthenticated request. Same
        // permission every other appointment-mutating handler requires.
        if(($errmsg = rbacClass::require(ZPMS_PERM_APPOINTMENT_EDIT)))return $errmsg;

        if(!csrfClass::verifyRequest()) {
            $kernel->addStatus('error', 'Μη έγκυρο token ασφαλείας (CSRF). Παρακαλώ προσπαθήστε ξανά.');
            header('location: '.rel_url('/patient/'.$params['id'].'/edit'));
            exit();
        }

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

        // Carried through as a hidden field from patient_appointment_new()
        // above (which is what actually decided it, from which "Add ..."
        // button was clicked) -- not user-editable on this form, no
        // dropdown, and never touched again after this insert.
        $atype = ($_POST['appointment-type'] ?? '') === 'operation' ? 'operation' : 'appointment';

        // echo "this is the post version<br/>";
        $app = new appointmentsClass([
            // 'guid' =>
            'id' => null,
            // Was hardcoded to the literal string 'admin' regardless of
            // who was actually logged in -- every appointment created via
            // this handler misattributed itself in the audit trail. Every
            // other creation handler in this file (patient_new_post(),
            // appointment_files.php's upload) already uses this.
            'cuser' => $kernel->getUserName(),
            'cdate' => getDBtime(),
            'adate' => getDBformattime($_POST['appointment-date']),
            'aplace' => ($loc)?$loc->getname():'',   //$_POST['appointment-place'],
            'anote' => $_POST['appointment-notes'],
            'atype' => $atype,
            'guid' => guid(),
            'pguid' => $pat->getguid()
        ]);
        // print_r( $pc );

        $app->insert();

        // error_log('\nREFERER: '. $_SERVER['HTTP_REFERER']."\n");

        $kernel->addStatus('notice', 'Δημιουργήθηκε νέο ραντεβού.');
        header('location: '.rel_url('/patient/'.$pat->getid().'/edit'));
    }

    // Default length of a plain phone-booked consultation, for the
    // Calendar event's end time only -- neither pending_appointments nor
    // appointments has a duration column, and nothing here needs one
    // beyond sizing the block Calendar shows.
    const ZPMS_CONSULTATION_DEFAULT_DURATION_MINUTES = 30;

    /**
     * Every currently-defined location's display name, for the <select>
     * on the booking/edit/convert forms -- same locationsClassEx source
     * view_appointment.zetem's own location field already reads,
     * returned as plain strings (not objects) since pending_appointments.
     * location is a plain varchar, same convention appointments.aplace
     * already uses.
     */
    function zpms_pending_appointment_location_options(): array {
        global $kernel;
        $names = [];
        foreach (locationsClassEx::sgetAll($kernel->getCurrentLanguage()) as $loc) {
            $names[] = $loc->getname();
        }
        return $names;
    }

    /**
     * Pushes a pending_appointments row's current fields to its Calendar
     * event -- create if it has no google_event_id yet, otherwise patch
     * the existing one in place. Shared by consultation_new_post() and
     * pending_appointment_edit_post(); entirely a no-op, silently, when
     * googleCalendarClass::isEnabled() is false (not configured yet) --
     * never blocks or fails the pending-row save itself, matching every
     * other optional integration in this app family's fail-soft
     * convention.
     */
    function zpms_pending_appointment_sync_to_calendar(pendingAppointmentsClass $pending): void {
        if (!googleCalendarClass::isEnabled()) {
            return;
        }

        if ($pending->getgoogle_event_id() === null) {
            $eventId = googleCalendarClass::createEvent(
                $pending->getid(),
                $pending->getpatient_name(),
                $pending->getappointment_datetime(),
                ZPMS_CONSULTATION_DEFAULT_DURATION_MINUTES,
                $pending->getpatient_phone() ?? '',
                $pending->getpatient_amka() ?? '',
                $pending->getpatient_email() ?? '',
                $pending->getlocation() ?? '',
                $pending->getnotes() ?? ''
            );
            if ($eventId === null) {
                return;
            }
            $pending->setgoogle_event_id($eventId);
        } else {
            googleCalendarClass::updateEvent(
                $pending->getgoogle_event_id(),
                $pending->getpatient_name(),
                $pending->getappointment_datetime(),
                ZPMS_CONSULTATION_DEFAULT_DURATION_MINUTES,
                $pending->getpatient_phone() ?? '',
                $pending->getpatient_amka() ?? '',
                $pending->getpatient_email() ?? '',
                $pending->getlocation() ?? '',
                $pending->getnotes() ?? ''
            );
        }
        $pending->setgoogle_synced_at(getDBtime());
        $pending->update();
    }

    function consultation_new($params) {
        global $kernel;

        if(($errmsg = rbacClass::require(ZPMS_PERM_PENDING_APPOINTMENTS_MANAGE)))return $errmsg;

        return (Renderer::render("new_consultation.zetem", [
            'calendar_enabled' => googleCalendarClass::isEnabled(),
            'locations' => zpms_pending_appointment_location_options(),
        ]));
    }

    function consultation_new_post($params) {
        global $kernel;

        if(($errmsg = rbacClass::require(ZPMS_PERM_PENDING_APPOINTMENTS_MANAGE)))return $errmsg;

        if(!csrfClass::verifyRequest()) {
            $kernel->addStatus('error', 'Μη έγκυρο token ασφαλείας (CSRF). Παρακαλώ προσπαθήστε ξανά.');
            header('location: '.rel_url('/consultation/new'));
            exit();
        }

        // No patient/appointment row yet -- see pending_appointments.yaml's
        // own docblock for why this table exists at all: a patient record
        // is only ever created once the patient actually shows up and the
        // doctor asks for one (pending_appointment_convert_post() below).
        $pending = new pendingAppointmentsClass([
            'id' => null,
            'cuser' => $kernel->getUserName(),
            'cdate' => getDBtime(),
            'guid' => guid(),
            'patient_name' => $_POST['patient-name'],
            'patient_phone' => $_POST['patient-telephone'] ?? '',
            'patient_amka' => $_POST['patient-amka'] ?? '',
            'patient_email' => $_POST['patient-email'] ?? '',
            'appointment_datetime' => getDBformattime($_POST['appointment-date']),
            'location' => $_POST['appointment-location'] ?? '',
            'notes' => $_POST['appointment-notes'] ?? '',
        ]);
        $pending->insert();

        zpms_pending_appointment_sync_to_calendar($pending);

        $kernel->addStatus('notice', 'Καταχωρήθηκε εκκρεμές ραντεβού για τον/την <b>'
            . htmlspecialchars($pending->getpatient_name(), ENT_QUOTES, 'UTF-8') . '</b>'
            . ($pending->getgoogle_event_id() ? ' (συγχρονίστηκε με το Google Calendar).' : '.'));

        header('location: '.rel_url('/consultation/pending'));
        exit();
    }

    /**
     * "Εκκρεμή Ραντεβού" -- every not-yet-converted, not-yet-cancelled
     * pending_appointments row, regardless of whether it was booked on
     * /consultation/new or pulled in from a Calendar-native event by
     * bin/sync_google_calendar.php. Secretary-level: viewing/editing/
     * cancelling a phone booking never needs real patient-record access.
     */
    function pending_appointments_list($params) {
        global $kernel;

        if(($errmsg = rbacClass::require(ZPMS_PERM_PENDING_APPOINTMENTS_MANAGE)))return $errmsg;

        $pending = pendingAppointmentsClass::sgetAll(
            'converted_at IS NULL AND cancelled_at IS NULL',
            null
        );
        usort($pending, fn($a, $b) => strcmp($a->getappointment_datetime(), $b->getappointment_datetime()));

        return (Renderer::render("pending_appointments_list.zetem", [
            'pending' => $pending,
            // Gates the per-row "Δημιουργία Φακέλου" button -- a
            // secretary-only account sees the list but not that action,
            // same permission split pending_appointment_convert()
            // enforces server-side (this is display-only, not the real
            // access check).
            'can_convert' => rbacClass::isPermitted(ZPMS_PERM_PATIENTS_NEW_PATIENT)
                && rbacClass::isPermitted(ZPMS_PERM_APPOINTMENT_EDIT),
        ]));
    }

    function pending_appointment_edit($params) {
        global $kernel;

        if(($errmsg = rbacClass::require(ZPMS_PERM_PENDING_APPOINTMENTS_MANAGE)))return $errmsg;

        $pending = pendingAppointmentsClass::sgetById((int)$params['id']);
        if (!$pending || $pending->getconverted_at() !== null || $pending->getcancelled_at() !== null) {
            $kernel->addStatus('error', 'Η καταχώρηση δεν βρέθηκε ή έχει ήδη επεξεργαστεί.');
            header('location: '.rel_url('/consultation/pending'));
            exit();
        }

        return (Renderer::render("pending_appointment_edit.zetem", [
            'pending' => $pending,
            'locations' => zpms_pending_appointment_location_options(),
        ]));
    }

    function pending_appointment_edit_post($params) {
        global $kernel;

        if(($errmsg = rbacClass::require(ZPMS_PERM_PENDING_APPOINTMENTS_MANAGE)))return $errmsg;

        if(!csrfClass::verifyRequest()) {
            $kernel->addStatus('error', 'Μη έγκυρο token ασφαλείας (CSRF). Παρακαλώ προσπαθήστε ξανά.');
            header('location: '.rel_url('/consultation/pending'));
            exit();
        }

        $pending = pendingAppointmentsClass::sgetById((int)$params['id']);
        if (!$pending || $pending->getconverted_at() !== null || $pending->getcancelled_at() !== null) {
            $kernel->addStatus('error', 'Η καταχώρηση δεν βρέθηκε ή έχει ήδη επεξεργαστεί.');
            header('location: '.rel_url('/consultation/pending'));
            exit();
        }

        $pending->setpatient_name($_POST['patient-name']);
        $pending->setpatient_phone($_POST['patient-telephone'] ?? '');
        $pending->setpatient_amka($_POST['patient-amka'] ?? '');
        $pending->setpatient_email($_POST['patient-email'] ?? '');
        $pending->setappointment_datetime(getDBformattime($_POST['appointment-date']));
        $pending->setlocation($_POST['appointment-location'] ?? '');
        $pending->setnotes($_POST['appointment-notes'] ?? '');
        $pending->update();

        zpms_pending_appointment_sync_to_calendar($pending);

        $kernel->addStatus('notice', 'Ενημερώθηκε το εκκρεμές ραντεβού.');
        header('location: '.rel_url('/consultation/pending'));
        exit();
    }

    /**
     * Cancels a pending appointment with no patient record ever created
     * -- the caller cancelled, or (for a Calendar-native row) it was a
     * personal event that landed on the shared calendar by mistake.
     * Deletes the linked Calendar event too, if there is one, so
     * cancelling here doesn't leave a booking Calendar still shows.
     */
    function pending_appointment_delete($params) {
        global $kernel;

        if(($errmsg = rbacClass::require(ZPMS_PERM_PENDING_APPOINTMENTS_MANAGE)))return $errmsg;

        if(!csrfClass::verifyRequest()) {
            $kernel->addStatus('error', 'Μη έγκυρο token ασφαλείας (CSRF). Παρακαλώ προσπαθήστε ξανά.');
            header('location: '.rel_url('/consultation/pending'));
            exit();
        }

        $pending = pendingAppointmentsClass::sgetById((int)$params['id']);
        if ($pending && $pending->getconverted_at() === null && $pending->getcancelled_at() === null) {
            if ($pending->getgoogle_event_id() !== null) {
                googleCalendarClass::deleteEvent($pending->getgoogle_event_id());
            }
            $pending->setcancelled_at(getDBtime());
            $pending->update();
            $kernel->addStatus('notice', 'Το ραντεβού ακυρώθηκε.');
        }

        header('location: '.rel_url('/consultation/pending'));
        exit();
    }

    /**
     * The patient showed up (or called to confirm) and the doctor wants
     * a real record -- pre-fills the same duplicate-name check
     * patient_new()/patient_new_check_name() already provide, so an
     * existing patient can be picked instead of creating a second record
     * for someone already on file.
     */
    function pending_appointment_convert($params) {
        global $kernel;

        if(($errmsg = rbacClass::require(ZPMS_PERM_PATIENTS_NEW_PATIENT)))return $errmsg;
        if(($errmsg = rbacClass::require(ZPMS_PERM_APPOINTMENT_EDIT)))return $errmsg;

        $pending = pendingAppointmentsClass::sgetById((int)$params['id']);
        if (!$pending || $pending->getconverted_at() !== null || $pending->getcancelled_at() !== null) {
            $kernel->addStatus('error', 'Η καταχώρηση δεν βρέθηκε ή έχει ήδη επεξεργαστεί.');
            header('location: '.rel_url('/consultation/pending'));
            exit();
        }

        return (Renderer::render("pending_appointment_convert.zetem", ['pending' => $pending]));
    }

    function pending_appointment_convert_post($params) {
        global $kernel;

        if(($errmsg = rbacClass::require(ZPMS_PERM_PATIENTS_NEW_PATIENT)))return $errmsg;
        if(($errmsg = rbacClass::require(ZPMS_PERM_APPOINTMENT_EDIT)))return $errmsg;

        if(!csrfClass::verifyRequest()) {
            $kernel->addStatus('error', 'Μη έγκυρο token ασφαλείας (CSRF). Παρακαλώ προσπαθήστε ξανά.');
            header('location: '.rel_url('/consultation/pending'));
            exit();
        }

        $pending = pendingAppointmentsClass::sgetById((int)$params['id']);
        if (!$pending || $pending->getconverted_at() !== null || $pending->getcancelled_at() !== null) {
            $kernel->addStatus('error', 'Η καταχώρηση δεν βρέθηκε ή έχει ήδη επεξεργαστεί.');
            header('location: '.rel_url('/consultation/pending'));
            exit();
        }

        $existingPatientId = trim((string)($_POST['existing-patient-id'] ?? ''));

        if ($existingPatientId !== '') {
            $pat = patientsClass::sgetById((int)$existingPatientId);
            if (!$pat) {
                $kernel->addStatus('error', 'Ο επιλεγμένος ασθενής δεν βρέθηκε.');
                header('location: '.rel_url('/consultation/pending/'.$pending->getid().'/convert'));
                exit();
            }
        } else {
            $pat = new patientsClass([
                'id' => null,
                'cuser' => $kernel->getUserName(),
                'cdate' => getDBtime(),
                'pname' => $_POST['patient-name'],
                'pdob' => getDBtime(),
                'pamka' => $_POST['patient-amka'] ?? '',
                'ptel' => $_POST['patient-telephone'] ?? '',
                'paddr' => '',
                'pemail' => $_POST['patient-email'] ?? '',
                'pnote' => '',
                'guid' => guid(),
            ]);
            $pat->insert();
        }

        $app = new appointmentsClass([
            'id' => null,
            'cuser' => $kernel->getUserName(),
            'cdate' => getDBtime(),
            'adate' => $pending->getappointment_datetime(),
            'aplace' => $pending->getlocation() ?? '',
            'anote' => $pending->getnotes() ?? '',
            'atype' => 'appointment',
            'guid' => guid(),
            'pguid' => $pat->getguid(),
            'google_event_id' => $pending->getgoogle_event_id(),
            'google_synced_at' => $pending->getgoogle_event_id() !== null ? getDBtime() : null,
        ]);
        $app->insert();

        $pending->setconverted_at(getDBtime());
        $pending->setconverted_patient_id($pat->getid());
        $pending->setconverted_appointment_id($app->getid());
        $pending->update();

        $kernel->addStatus('notice', 'Δημιουργήθηκε φάκελος και ραντεβού για τον ασθενή <b>'
            . htmlspecialchars($pat->getpname(), ENT_QUOTES, 'UTF-8') . '</b>.');

        header('location: '.rel_url('/patient/'.$pat->getid().'/edit'));
        exit();
    }


    function settings($params) {
        global $kernel;

        // Clinics/Doctors reference-data management (below) is a distinct
        // capability from the day-to-day patients-new-patient permission --
        // it used to reuse that permission, which meant any staff who could
        // register a new patient could also edit clinic/doctor reference
        // data. Gated on its own settings-manage permission now (see
        // bin/migrate_roles.php's role seed data).
        if(($errmsg = rbacClass::require(ZPMS_PERM_SETTINGS_MANAGE)))return $errmsg;

        // $dbclinics = formsClass::getForm('clinics');
        // $dbdoctors = formsClass::getForm('doctors');

        return Renderer::render('settings.zetem', [
            'clinics_table' => formsClass::renderFormResults('clinics'),
            'clinics' => formsClass::renderForm('clinics'),

            // 'table_short' -- doctors.yaml's named view showing just
            // Doctor name, proving the multi-view mechanism actually
            // works; the yaml's own 'table_extended' (both columns) stays
            // available as that form's `default` for any caller (e.g. the
            // /webform/viewform/doctors route) that doesn't request a view.
            'doctors_table' => formsClass::renderFormResults('doctors', [], 'table_short'),
            'doctors' => formsClass::renderForm('doctors'),

            // A settings-manage holder doesn't necessarily also have
            // users-manage (deliberately not granted to doctor or
            // maintenance by default -- see ZEUSFW_PERM_MANAGE_USERS's own
            // comment in zeusfw's core/lib/Rbac.php), so this link is only
            // shown when the current user actually has it, rather than to
            // everyone who can reach this page at all.
            'show_user_management' => rbacClass::isPermitted(ZEUSFW_PERM_MANAGE_USERS),
        ]);

    }


    // AJAX callback for updating patient info data
    function ajax_update_patient_info($params) {
        global $kernel;

        if(($errmsg = rbacClass::require(ZPMS_PERM_PATIENTS_EDIT_PATIENT)))return $errmsg;

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

    function app_generate_qr($params) {
        // echopre(print_r($_SERVER, 1));

        if(($_SERVER['REQUEST_METHOD'] === "GET") || (!strlen($_POST['qrtext']))) {
            return Renderer::render('genqr.zetem', []);

        } else {
            if(($err = csrfClass::requireValid()))return $err;

            // echopre("qrtext: " . $_POST['qrtext']);
            // POST method

            // Previously built as a raw shell string with $_POST values
            // interpolated directly into it -- classic command injection
            // (e.g. a crafted imagetype value could run arbitrary shell
            // commands). Fixed two ways: every option is validated
            // against the exact choices genqr.zetem's <select> elements
            // offer, falling back to that field's own default on
            // anything else, and the command itself is invoked via
            // proc_open() with an argument array -- matching docarc's
            // docarc_generate_qr_png() -- so argv reaches qrencode
            // directly with no shell ever parsing these values,
            // regardless of what's in them.
            $allowedImageTypes = ['png', 'svg'];
            $allowedDpi = ['75', '100', '150', '200', '300', '600'];
            $allowedMargins = ['1', '2', '3', '4', '5', '6'];
            $allowedErrorCorrection = ['L', 'M', 'Q', 'H'];

            $imagetype = in_array($_POST['imagetype'] ?? '', $allowedImageTypes, true) ? $_POST['imagetype'] : 'png';
            $imagedpi = in_array($_POST['imagedpi'] ?? '', $allowedDpi, true) ? $_POST['imagedpi'] : '150';
            $margins = in_array($_POST['margins'] ?? '', $allowedMargins, true) ? $_POST['margins'] : '3';
            $errorcorrection = in_array($_POST['errorcorrection'] ?? '', $allowedErrorCorrection, true) ? $_POST['errorcorrection'] : 'Q';

            // Fixed string, no user input involved -- but shelling out for
            // a plain glob+delete is unnecessary, so this no longer does
            // either. array_map('unlink', ...) over glob()'s own matches
            // already only touches files that exist, same as -f's
            // "don't error on missing" behavior.
            array_map('unlink', glob('cache/qr-*') ?: []);
            $file = tempnam('cache', 'qr-image-');
            $str = tempnam('cache', 'qr-text-');
            $filename = array_reverse( explode('/', $file) )[0];
            // echopre("file: $file <br>filename: $filename <br> str: $str");
            // $file = 'cache/qr-image';
            // $str = 'cache/qr-text';

            file_put_contents($str, $_POST['qrtext']);

            $command = ['qrencode', '--type=' . $imagetype, '--level=' . $errorcorrection, '--dpi=' . $imagedpi, '--margin=' . $margins, '-r', $str, '-o', $file];
            echopre(print_r($command, 1));

            $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $process = proc_open($command, $descriptors, $pipes);
            if(is_resource($process)) {
                fclose($pipes[0]);
                stream_get_contents($pipes[1]);
                fclose($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[2]);
                $exitCode = proc_close($process);
                // echopre("qrencode exit code: $exitCode, stderr: $stderr");
            }

            return Renderer::render('genqr.zetem', ['qrimage' => $filename,'qrtext' => $_POST['qrtext']]);
        }
    }


function clinics_edit($params) {
    // echopre("Edit clinics");

    // This route previously had no permission check at all -- anyone who
    // could reach /apps/edit_clinics (no menu link, but reachable directly)
    // got the clinics management form with zero auth. Same settings-manage
    // permission as settings() above, which renders the same form.
    if(($errmsg = rbacClass::require(ZPMS_PERM_SETTINGS_MANAGE)))return $errmsg;

    $dbForm = formsClass::renderForm('clinics');
    // $dbForm = formsClass::renderForm('operations');

    return $dbForm;
}


// Row-action button `handler:` resolvers (table_view -> buttons: ->
// handler:, doctors.yaml/clinics.yaml) -- each is called per row at
// render time with that row's guid/fields (core/lib/FormElement.php's
// generateHTMLTableRowButton(), zeusfw) and returns the URL to bake into
// that row's rendered button. Their only job is picking the URL; the
// routes they point at (webforms_delete/clinics_row_edit,
// config/settings.info.yaml) do the actual work.
function doctors_delete_url($guid, $fields) {
    return rel_url('/webform/delete/doctors/' . $guid);
}

function clinics_delete_url($guid, $fields) {
    return rel_url('/webform/delete/clinics/' . $guid);
}

function clinics_edit_url($guid, $fields) {
    return rel_url('/clinics/' . $guid . '/edit');
}

// GET /clinics/{guid}/edit -- loads the clinic by guid and renders the
// same 'clinics' webform clinics_edit()/settings() already use, prefilled
// with that row's real values via renderForm()'s $default_values param.
// 'row_guid' (clinics.yaml's form.inputs/form_view -- a hidden field,
// never shown) carries the row's own guid back on submit, which is what
// tells formsClass::storeFormResults() (zeusfw) to update this existing
// row instead of inserting a new one.
function clinics_row_edit($params) {
    if(($errmsg = rbacClass::require(ZPMS_PERM_SETTINGS_MANAGE)))return $errmsg;

    $rows = clinicsClass::sgetAllFilter('clinics', ['guid' => $params['guid']]);
    $clinic = $rows[0] ?? null;
    if(!$clinic) return error_404();

    return formsClass::renderForm('clinics', null, [
        'clinic_name' => $clinic->getclinic_name(),
        'row_guid' => $clinic->getguid(),
    ]);
}



function totp_handler($params) {

    global $kernel;

    $tfile = core_get_temp_filename('temp_qrcode.png');
    $str ="QR TEST CODE";

    // Previously a single string run through escapeshellcmd() after $str
    // alone had been escapeshellarg()'d -- every value here is currently
    // hardcoded/internally generated so it isn't exploitable today, but
    // that string-escaping combination is exactly the fragile pattern that
    // broke in app_generate_qr() before it was fixed (see that function's
    // own comment). Same fix here: proc_open() with an argument array, so
    // no shell ever parses these values regardless of what's in them.
    $command = ['qrencode', '-o', $tfile, '--size=10', $str];
    error_log("qrencode command: " . print_r($command, 1));

    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($command, $descriptors, $pipes);
    if(is_resource($process)) {
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        proc_close($process);
    }
    if(!file_exists($tfile)) {
        $output = ['result' => 'failed',
                    'error' => 'failed to create qr image',
                    'qrdata' => ''
                ];
    }else {
        $qrdata = base64_encode(file_get_contents($tfile));
        // error_log("qr data: " . $qrdata);
        $output = [
            'result' => 'success',
            'qrdata' => $qrdata
        ];
        unlink( $tfile );
    }
    echo json_encode( $output );
    exit();
}