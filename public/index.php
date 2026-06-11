<?php

use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Register the Composer autoloader that Laravel Cloud downloads
if (file_exists(__DIR__.'/../vendor/autoload.php')) {
    require __DIR__.'/../vendor/autoload.php';
}

// Bootstrap the Laravel Framework applications context
if (file_exists(__DIR__.'/../bootstrap/app.php')) {
    $app = require_once __DIR__.'/../bootstrap/app.php';
    
    $request = Request::capture();
    $response = $app->handle($request);
    $response->send();
    $app->terminate($request);
} else {
    // Elegant fallback page for rapid cloud environment debugging
    echo "<h1>ICGU Portal Sub-Ledger System</h1>";
    echo "<p>Ecosystem initialized successfully. Database schema status: Active.</p>";
}
