<?php
require('vendor/autoload.php');

$ini = parse_ini_file("variables.ini.php");

use Illuminate\Database\Capsule\Manager as Capsule;

$capsule = new Capsule;

$capsule->addConnection(
    [
    'driver'    => 'mysql',
    'host'      => $ini['host'],
    'database'  => $ini['database'],
    'username'  => $ini['username'],
    'password'  => $ini['password'],
    'charset'   => 'utf8',
    'collation' => 'utf8_unicode_ci',
    ]
);

$capsule->setAsGlobal();

$capsule->bootEloquent();

session_start();

/*
 * This route handles authentication via oauth
 */
Flight::route(
    '/login',
    function () {
        global $ini;
        $server = new sabas\OAuth1\Client\Server\Openstreetmap(array(
            'identifier' => $ini['identifier'],
            'secret' => $ini['secret'],
            'callback_uri' => 'http://'.$_SERVER['HTTP_HOST'].Flight::request()->base.'/login',
        ));

        if (isset($_SESSION['token_credentials']) && !isset($_GET['oauth_token'])) {
            if (!isset($_SESSION['token_credentials'])) {
                echo 'No token credentials.';
                exit(1);
            }
            $tokenCredentials = unserialize($_SESSION['token_credentials']);

            $user = $server->getUserDetails($tokenCredentials);
            $_SESSION['display_name'] = (string) $user->user['display_name'];
            $_SESSION['user_id'] = (string) $user->user['id'];
            $_SESSION['user_picture'] = (string) $user->user->img['href'];
            Flight::redirect('/');
        } elseif (isset($_GET['oauth_token']) && isset($_GET['oauth_verifier'])) {
            $temporaryCredentials = unserialize($_SESSION['temporary_credentials']);
            $tokenCredentials = $server->getTokenCredentials($temporaryCredentials, $_GET['oauth_token'], $_GET['oauth_verifier']);
            unset($_SESSION['temporary_credentials']);
            $_SESSION['token_credentials'] = serialize($tokenCredentials);
            session_write_close();

            Flight::redirect('/login');
        } elseif (!isset($_SESSION['token_credentials'])) {
            $temporaryCredentials = $server->getTemporaryCredentials();
            $_SESSION['temporary_credentials'] = serialize($temporaryCredentials);
            session_write_close();
            $server->authorize($temporaryCredentials);
            exit;
        } else {

        }
    }
);

/*
 * Logout route, mainly for testing
 */
Flight::route(
    '/logout',
    function () {
        session_destroy();
        Flight::redirect('/');
    }
);

Flight::route(
    '/',
    function () {
        $users = Capsule::table('new_user')
            ->leftJoin('welcome_user', 'new_user.user_id', '=', 'welcome_user.uid')
            ->leftJoin(Capsule::raw("
                            (SELECT uid, MAX(timestamp) AS timestamp FROM notes GROUP BY uid) maxnotes
                        "), 'maxnotes.uid', '=', 'new_user.user_id')
            ->leftJoin('notes', 'maxnotes.timestamp', '=', 'notes.timestamp')
            ->where('registration_date', '>', strtotime('today midnight'))
            ->where('registration_date', '<', strtotime('tomorrow midnight'))
            ->get();

        Flight::render('user_table.php', [ 'results' => $users, 'day' => date('Ymd')], 'content');
        Flight::render('template.php', [ 'pTitle' => "Users who registered today" ]);
    }
);

Flight::route(
    '/day(/@day)',
    function ($day) {
        if ($day === null) {
            $day = date('Ymd');
        }

        $date = DateTime::createFromFormat('YmdHi', $day.'0000');
        $start = $date->getTimestamp();
        $date->add(new DateInterval('P1D'));
        $end = $date->getTimestamp();

        $users = Capsule::table('new_user')
            ->leftJoin('welcome_user', 'new_user.user_id', '=', 'welcome_user.uid')
            ->leftJoin(Capsule::raw("
                            (SELECT uid, MAX(timestamp) AS timestamp FROM notes GROUP BY uid) maxnotes
                        "), 'maxnotes.uid', '=', 'new_user.user_id')
            ->leftJoin('notes', 'maxnotes.timestamp', '=', 'notes.timestamp')
            ->where('registration_date', '>', $start)
            ->where('registration_date', '<', $end)
            ->get();
        Flight::render('user_table.php', [ 'results' => $users, 'day' => $day ], 'content');
        Flight::render('template.php', [ 'pTitle' => "Users who registered on ".$day ]);
    }
);

/*
 * Ajax functions to update welcomed and answered flags
 */
Flight::route(
    'POST /user/@id:[0-9]+/welcomed',
    function ($id) {
        if (!isset($_SESSION['display_name'])) {
            Flight::redirect('/');
        }
        $welcomed_by = $_SESSION['display_name'];
        if ($_POST['isWelcomed'] == 0) {
            $welcomed_by = '';
        }
        Capsule::table('welcome_user')
        ->where('uid', $id)
        ->update(
            [
                'welcomed' => $_POST['isWelcomed'],
                'welcomed_by' => $welcomed_by
            ]
        );
    }
);

Flight::route(
    'POST /user/@id:[0-9]+/answered',
    function ($id) {
        if (!isset($_SESSION['display_name'])) {
            Flight::redirect('/');
        }
        Capsule::table('welcome_user')
        ->where('uid', $id)
        ->update(
            [
                'answered' => $_POST['hasAnswered']
            ]
        );
    }
);

Flight::route(
    '/user/@id',
    function ($id) {
        if (!isset($_SESSION['display_name'])) {
            Flight::redirect('/');
        }
        $user = Capsule::table('new_user')
            ->leftJoin('welcome_user', 'new_user.user_id', '=', 'welcome_user.uid')
            ->where('new_user.user_id', $id)
            ->first();
        $notes = Capsule::table('notes')
            ->where('notes.uid', $id)
            ->orderBy('timestamp', 'desc')
            ->get();
        Flight::render('user.php', [ 'user' => $user, 'notes' => $notes ], 'content');
        Flight::render('template.php', [ 'pTitle' => "User ".$id ]);
    }
);

/*
 * Add a note
 */
Flight::route(
    'GET /note/@id',
    function ($id) {
        if (!isset($_SESSION['display_name'])) {
            Flight::redirect('/');
        }
        Flight::render('note.php', [ 'id' => $id ], 'content');
        Flight::render('template.php', [ 'pTitle' => "Add a note on user ".$id ]);
    }
);

Flight::route(
    'POST /note/@id',
    function ($id) {
        if (!isset($_SESSION['display_name'])) {
            Flight::redirect('/');
        }
        Capsule::table('notes')->insert(
            [
                'uid' => $id,
                'timestamp' => time(),
                'author' => $_SESSION['display_name'],
                'type' => 'note',
                'note' => $_POST['note']
            ]
        );
        Flight::redirect('/user/'.$id);
    }
);

/*
 * Welcome message creation
 */
Flight::route(
    'GET /welcome/@id',
    function ($id) {
        if (!isset($_SESSION['display_name'])) {
            Flight::redirect('/');
        }
        //TODO
        //pagina con textbox a sx, a dx bottoni che corrispondono alle parti di testo disponibili per ciascuna lingua
        //cliccando sul bottone viene appeso al testo nella textbox

        Flight::render('note.php', [ 'id' => $id ], 'content');
        Flight::render('template.php', [ 'pTitle' => "Create welcome message for user ".$id ]);
    }
);

Flight::start();