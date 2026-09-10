<?php

// Include the class definition file
require_file_or_autoload: require_once __DIR__ . '/tauronAwarie.php';

// Standard cron execution (completely silent)
$cron = new TauronOutageCron(silent: false, debug: false);

// Browser testing mode (displays JSON inside <pre>, no debug logs)
// $cron = new TauronOutageCron(silent: false, debug: false);

$cron->checkOutages(
    cityName: 'Wrocław', 
    street: 'Energetyczna', 
    houseNumber: '1'
);