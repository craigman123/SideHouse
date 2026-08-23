<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

try {
    $request = Illuminate\Http\Request::capture();
    $response = $kernel->handle($request);

    echo "STATUS: " . $response->getStatusCode() . "\n";
    echo "CONTENT:\n" . $response->getContent();

} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "FILE: " . $e->getFile() . " LINE: " . $e->getLine() . "\n";
    echo "TRACE:\n" . $e->getTraceAsString();
}