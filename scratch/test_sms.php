<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$phone = '967779475324';
$requestId = 'test-'.uniqid();

echo "Testing Taqnyat OTP send to {$phone}...\n";

$svc = app(App\Services\TaqnyatVerifyService::class);
$isConfigured = $svc->isConfigured();
echo "Is Configured: ".($isConfigured ? "YES\n" : "NO\n");

$apiKey = config('services.taqnyat.api_key');
$sender = config('services.taqnyat.sender');
echo "API Key: ".substr($apiKey, 0, 8)."...\n";
echo "Sender: {$sender}\n";

$payload = [
    'apiKey' => $apiKey,
    'numbers' => [\App\Support\Phone::intl($phone)],
    'sender' => $sender,
    'method' => 'sms',
    'lang' => 'ar',
    'requestId' => $requestId,
    'returnJson' => 1,
];

echo "Formatted Number: ".PhoneIntlTest($phone)."\n";

$response = Illuminate\Support\Facades\Http::withToken($apiKey)
    ->acceptJson()
    ->connectTimeout(10)
    ->timeout(30)
    ->post('https://api.taqnyat.sa/verify.php', [$payload]);

echo "HTTP Status: ".$response->status()."\n";
echo "Response Body: ".$response->body()."\n";

function PhoneIntlTest($p) {
    return App\Support\Phone::intl($p);
}
