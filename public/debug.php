<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

$request = Illuminate\Http\Request::capture();
$response = $kernel->handle($request);

echo "STATUS: " . $response->getStatusCode() . "\n";
echo "APP_DEBUG: " . (config('app.debug') ? 'true' : 'false') . "\n";
echo "APP_KEY set: " . (config('app.key') ? 'yes' : 'no') . "\n";
echo "CONTENT LENGTH: " . strlen($response->getContent()) . "\n";
echo "CONTENT:\n" . $response->getContent();
echo "\n\nHEADERS:\n";
foreach ($response->headers->all() as $key => $val) {
    echo "$key: " . implode(', ', $val) . "\n";
}