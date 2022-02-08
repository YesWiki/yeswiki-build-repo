<?php

namespace YesWikiRepo;

$loader = require __DIR__ . '/vendor/autoload.php';

set_exception_handler(function ($e) {
    if (!isset($argv)) {
        header('HTTP/1.1 500 Internal Server Error');
    }
    echo htmlSpecialChars($e->getMessage());
    die();
});

openlog('[YesWikiRepo] ', LOG_CONS|LOG_PERROR, LOG_SYSLOG);

if (!\file_exists('config.php')) {
    exit('No config.php file found, copy the config.php.example to config.php and adapt to your configuration.');
} else {
    include_once('config.php');
}
$repo = new Repository($config);

// WebHook
$request = new HttpRequest($_SERVER, $_POST);
if ($request->isHook()) {
    (new WebhookController($repo))->run($request->getContent());
    exit;
}

if (isset($argv)) { // Command line
    $params = array();
    parse_str(implode('&', array_slice($argv, 1)), $params);
    (new ScriptController($repo))->run($params);
    exit;
}
if (!empty($_GET['action']) && in_array($_GET['action'], ['init','update', 'purge'])) { // HTTP Request
    $header = getallheaders();

    if (!empty($config['repo-key']) && isset($header['Repository-Key']) && $header['Repository-Key'] == $config['repo-key']) {
        (new ScriptController($repo))->run($_GET);
        exit;
    } else {
        exit('No Repository-Key set in header or wrong value for Repository-Key.');
    }
} else {
    exit('No action parameter set. Accepted values "init", "update", "purge"');
}

// Oups...
throw new \Exception("Bad request.", 1);
