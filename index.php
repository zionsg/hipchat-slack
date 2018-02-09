<?php
require 'vendor/autoload.php';

error_reporting(E_ALL & ~E_NOTICE);
set_time_limit(0);

$configFile = __DIR__ . '/config/config.php'; // must use __DIR__ to provide absolute path for it to work in crontab
$config = file_exists($configFile) ? include $configFile : []; // file to return array like App::config
$app = new \App\Application($config);
$app->run();
