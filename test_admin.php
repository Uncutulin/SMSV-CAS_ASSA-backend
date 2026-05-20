<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$controller = app()->make(\App\Http\Controllers\AdminController::class);
$request = \Illuminate\Http\Request::create('/api/admin/users', 'GET');
$request->headers->set('Authorization', 'Bearer test');
$response = $controller->getUsers($request);
echo "Status: " . $response->getStatusCode() . "\n";
$data = json_decode($response->getContent(), true);
if (isset($data[0])) {
    echo "First user: " . json_encode(array_intersect_key($data[0], array_flip(['name','email','status','local_role']))) . "\n";
    echo "Total users: " . count($data) . "\n";
} else {
    echo "Response: " . $response->getContent() . "\n";
}
