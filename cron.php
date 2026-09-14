<?php

// Include the class definition file
require_once __DIR__ . '/tauronAwarie.php';

// Standard cron execution (completely silent)
$cron = new TauronOutageCron(silent: true, debug: false);

// Browser testing mode (displays JSON inside <pre>, outputs debug logs)
// $cron = new TauronOutageCron(silent: false, debug: true);

// --- EXAMPLE 1: Single location check (Active by default) ---
$cron->checkOutages(
    cityName: 'Wrocław', 
    street: 'Energetyczna', 
    houseNumber: '1'
);

// --- Multiple locations batch check ---
/*
$cron->checkMultipleLocations([
    [
        'cityName' => 'Wrocław', 
        'street' => 'Energetyczna', 
        'houseNumber' => '1'
    ],
    [
        'cityName' => 'Legnickie Pole', 
        'street' => 'Książąt Śląskich', 
        'houseNumber' => '1'
    ]
]);
*/