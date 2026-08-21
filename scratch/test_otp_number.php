<?php

use App\Support\Phone;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$phone = '+966537434000';
$intlPhone = Phone::intl($phone);
$sender = 'SALASELBABL';
$baseUrl = 'https://api.taqnyat.sa';
$endpoint = $baseUrl.'/verify.php';

$keys = [
    'key_1' => '2f561884a57b80430e2f2e2650ead097',
    'key_2' => '53561fdbc06039c0a912c152ef42c0b9',
];

echo "Target phone: {$phone} (Intl: {$intlPhone})\n\n";

foreach ($keys as $name => $key) {
    echo "========================================\n";
    echo "Testing {$name}: {$key}\n";

    // First, check account balance / info if possible, or verify.php
    $balanceRes = Http::withToken($key)
        ->acceptJson()
        ->get('https://api.taqnyat.sa/account/balance');
    echo 'Balance check status: '.$balanceRes->status().' Body: '.$balanceRes->body()."\n";

    // Test send OTP via verify.php
    $requestId = 'test-'.uniqid();
    $payload = [
        'apiKey' => $key,
        'numbers' => [$intlPhone],
        'sender' => $sender,
        'method' => 'sms',
        'lang' => 'ar',
        'requestId' => $requestId,
        'returnJson' => 1,
    ];

    $response = Http::withToken($key)
        ->acceptJson()
        ->connectTimeout(10)
        ->timeout(30)
        ->post($endpoint, [$payload]);

    echo 'Verify.php status: '.$response->status()."\n";
    echo 'Verify.php body: '.$response->body()."\n";
}
