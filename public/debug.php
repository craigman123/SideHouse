<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

try {
    require __DIR__ . '/../vendor/autoload.php';
    echo "Autoload OK\n";

    $app = require_once __DIR__ . '/../bootstrap/app.php';
    echo "Bootstrap OK\n";

    $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
    echo "Kernel OK\n";

} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "FILE: " . $e->getFile() . " LINE: " . $e->getLine() . "\n";
    echo "TRACE:\n" . $e->getTraceAsString();
}