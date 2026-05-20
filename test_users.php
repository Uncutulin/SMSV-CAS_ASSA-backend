<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

// Test what the BI /users endpoint returns
$token = '876|OnNf7Q4bcFp6K5lkZJIp1Y2Tm2LZwwqdCgPSMN7qd93ba6fe';
$response = \Illuminate\Support\Facades\Http::withoutVerifying()->withToken($token)->get("https://api.smsvsegurosbi.com.ar/api/users");
echo "Status: " . $response->status() . "\n";
$body = $response->json();
echo "Type: " . gettype($body) . "\n";
if (is_array($body)) {
    echo "Keys: " . implode(', ', array_keys($body)) . "\n";
    if (isset($body['data'])) {
        echo "First item in data: " . json_encode($body['data'][0] ?? 'empty') . "\n";
    } else {
        echo "First element: " . json_encode($body[0] ?? 'empty') . "\n";
    }
} else {
    echo "Body: " . $response->body() . "\n";
}
