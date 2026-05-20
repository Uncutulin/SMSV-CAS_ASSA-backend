<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

try {
    $response = \Illuminate\Support\Facades\Http::withoutVerifying()->post("https://api.smsvsegurosbi.com.ar/api/login", [
        'email' => 'jorgecutuli@gmail.com',
        'password' => 'Ejercito@2026'
    ]);
    echo "Status: " . $response->status() . "\n";
    echo "Body: " . $response->body() . "\n";
} catch (\Exception $e) {
    echo "Exception: " . $e->getMessage() . "\n";
}
