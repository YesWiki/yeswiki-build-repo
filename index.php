<?php

namespace YesWikiRepo;

use Exception;

require __DIR__ . '/vendor/autoload.php';
error_reporting(E_ALL ^ E_DEPRECATED);

set_exception_handler(function ($e) {
    if (!isset($argv)) {
        header('HTTP/1.1 500 Internal Server Error');
    }
    echo htmlspecialchars($e->getMessage());
    die();
});

openlog('[YesWikiRepo] ', LOG_CONS | LOG_PERROR, LOG_SYSLOG);

if (!\file_exists('config.php')) {
    exit('No config.php file found, copy the config.php.example to config.php and adapt to your configuration.');
} else {
    $config = require_once('config.php');
}
$repo = new Repository($config);

// WebHook
$request = new HttpRequest($_SERVER, $_POST);
if ($request->isHook()) {
    // headers

    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Headers: X-Requested-With, Location, Link, Slug, Accept, Content-Type');
    header('Access-Control-Expose-Headers: Location, Slug, Accept, Content-Type');
    header('Access-Control-Allow-Methods: POST, GET, OPTIONS, DELETE, PUT, PATCH');
    header('Access-Control-Max-Age: 86400');
    header("Content-Security-Policy: frame-ancestors 'self' *;");

    try {
        ob_start();
        // trig only for push
        if ($_SERVER['HTTP_X_GITHUB_EVENT'] == "push") {
            $controller = new WebhookController($repo);
            if ($controller->isAuthorizedHook()) {
                trigger_error(json_encode($request->getContent()));
                $controller->run($request->getContent());
            } else {
                throw new Exception("Unauthorized");
            }
        }
        header("HTTP/1.0 200 OK", true, 200);
        $content = ob_get_contents();
        ob_end_clean();
        echo $content;
        exit;
    } catch (Exception $ex) {
        if (ob_get_level() > 0) {
            $content = ob_get_contents();
            ob_end_clean();
        }
        if ($ex->getMessage() == "Bad hook format.") {
            header("HTTP/1.0 400 Bad Request", true, 400);
            header("Content-Type: application/json;");
            echo json_encode(['errorMessage' => "Bad hook format."]
                + (empty($content) ? [] : ['content' => $content]));
        } elseif ($ex->getMessage() == "Unauthorized") {
            header("HTTP/1.0 401 Unauthorized", true, 401);
            header("Content-Type: application/json;");
            echo json_encode(['errorMessage' => "Unauthorized"]
                + (empty($content) ? [] : ['content' => $content]));
        } else {
            header("HTTP/1.0 500 Internal Server Error", true, 500);
            header("Content-Type: application/json;");
            echo json_encode(['errorMessage' => $ex->getMessage()]
                + (empty($content) ? [] : ['content' => $content]));
        }
    }
    exit;
}

if (isset($argv)) { // Command line
    $params = array();
    parse_str(implode('&', array_slice($argv, 1)), $params);
    (new ScriptController($repo))->run($params);
    exit;
}
if (!empty($_GET['action']) && in_array($_GET['action'], ['build', 'purge'])) { // HTTP Request
    $header = getallheaders();

    if (!empty($config['repo-key']) && isset($header['Repository-Key']) && $header['Repository-Key'] == $config['repo-key']) {
        (new ScriptController($repo))->run($_GET);
        exit;
    } else {
        exit('No Repository-Key set in header or wrong value for Repository-Key.');
    }
} else {
    exit('No action parameter set. Accepted values "build", "purge"');
}

// Oups...
throw new \Exception("Bad request.", 1);
