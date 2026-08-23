<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$request = Illuminate\Http\Request::capture();
$response = $kernel->handle($request);

echo "DB_CONNECTION env: [" . env('DB_CONNECTION') . "]\n";
echo "config database.default: [" . config('database.default') . "]\n";
echo "SESSION_DRIVER env: [" . env('SESSION_DRIVER') . "]\n";
echo "config session.driver: [" . config('session.driver') . "]\n";
echo "config session.connection: [" . config('session.connection') . "]\n";
echo "APP_ENV: [" . env('APP_ENV') . "]\n";