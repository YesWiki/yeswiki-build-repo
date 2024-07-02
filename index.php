<?php

namespace YesWikiRepo;

use Exception;

$loader = require __DIR__ . '/vendor/autoload.php';
$output = '';

function log($content)
{
    file_put_contents('webhook.log', $content . "\n---------\n", FILE_APPEND | LOCK_EX);
}
openlog('[YesWikiRepo] ', LOG_CONS | LOG_PERROR, LOG_SYSLOG);

// Load config
if (!\file_exists('config.php')) {
    exit('No config.php file found, copy the config.php.example to config.php and adapt to your configuration.');
} else {
    include_once('config.php');
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
        // trig only for push
        if ($_SERVER['HTTP_X_GITHUB_EVENT'] == "push") {
            $controller = new WebhookController($repo);
            if ($controller->isAuthorizedHook()) {
                $output .= $controller->run($request->getContent());
                header("HTTP/1.0 200 OK", true, 200);
                echo json_encode(['content' => $content]);
            } else {
                header("HTTP/1.0 401 Unauthorized", true, 401);
                exit("Unauthorized Hook");
            }
        }
        header("HTTP/1.0 200 OK", true, 200);
        exit;
    } catch (Exception $ex) {
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
$header = getallheaders();
if (!empty($config['repo-key']) && isset($header['Repository-Key']) && $header['Repository-Key'] == $config['repo-key']) {
    if (!empty($_GET['action']) && in_array($_GET['action'], ['init', 'update', 'purge'])) { // HTTP Request
        (new ScriptController($repo))->run($_GET);
        exit;
    } else {
        exit('No action parameter set. Accepted values "init", "update", "purge"');
    }
} else {
    exit('No Repository-Key set in header or wrong value for Repository-Key.');
}
