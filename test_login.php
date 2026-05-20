<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$request = \Illuminate\Http\Request::create('/api/login', 'POST', [
    'email' => 'jorgecutuli@gmail.com',
    'password' => 'Ejercito@2026'
]);

$controller = app()->make(\App\Http\Controllers\AuthController::class);
$response = $controller->login($request);
echo "Status: " . $response->getStatusCode() . "\n";
echo "Content: " . $response->getContent() . "\n";
