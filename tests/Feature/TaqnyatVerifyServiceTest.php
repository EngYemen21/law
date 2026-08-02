<?php

namespace Tests\Feature;

use App\Services\TaqnyatVerifyService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TaqnyatVerifyServiceTest extends TestCase
{
    private function configure(): void
    {
        config(['services.taqnyat.api_key' => 'tok_test', 'services.taqnyat.sender' => 'Salasel']);
    }

    private function svc(): TaqnyatVerifyService
    {
        return app(TaqnyatVerifyService::class);
    }

    public function test_not_configured_returns_false_without_http(): void
    {
        Http::fake();

        $this->assertFalse($this->svc()->isConfigured());
        $this->assertFalse($this->svc()->generate('0555555555', 'req-1'));
        $this->assertFalse($this->svc()->check('0555555555', 'req-1', '1234'));

        Http::assertNothingSent();
    }

    public function test_generate_true_on_code_5(): void
    {
        $this->configure();
        Http::fake(['api.taqnyat.sa/verify.php' => Http::response(['code' => 5], 200)]);

        $this->assertTrue($this->svc()->generate('0555555555', 'req-1'));

        Http::assertSent(function ($req) {
            $p = $req->data()[0] ?? [];

            return str_contains($req->url(), '/verify.php')
                && $p['numbers'] === ['966555555555']
                && $p['method'] === 'sms'
                && $p['requestId'] === 'req-1'
                && ! isset($p['activeKey'])
                && $req->hasHeader('Authorization', 'Bearer tok_test');
        });
    }

    public function test_generate_false_on_error_code(): void
    {
        $this->configure();
        Http::fake(['api.taqnyat.sa/verify.php' => Http::response(['code' => 4], 200)]); // رصيد غير كافٍ

        $this->assertFalse($this->svc()->generate('0555555555', 'req-1'));
    }

    public function test_check_true_on_code_10(): void
    {
        $this->configure();
        Http::fake(['api.taqnyat.sa/verify.php' => Http::response(['code' => 10], 200)]);

        $this->assertTrue($this->svc()->check('0555555555', 'req-1', '1234'));

        Http::assertSent(fn ($req) => (($req->data()[0] ?? [])['activeKey'] ?? null) === '1234');
    }

    public function test_check_false_on_incorrect_code_11(): void
    {
        $this->configure();
        Http::fake(['api.taqnyat.sa/verify.php' => Http::response(['code' => 11], 200)]);

        $this->assertFalse($this->svc()->check('0555555555', 'req-1', '0000'));
    }

    public function test_check_false_on_unparsable_response(): void
    {
        $this->configure();
        Http::fake(['api.taqnyat.sa/verify.php' => Http::response('gateway error', 502)]);

        $this->assertFalse($this->svc()->check('0555555555', 'req-1', '1234'));
    }

    public function test_parses_plain_text_numeric_response(): void
    {
        $this->configure();

        // verify.php قد يعيد الكود كنصّ خام (لا JSON) — 5 للتوليد و10 للتحقّق
        Http::fake(['api.taqnyat.sa/verify.php' => function ($req) {
            $isCheck = isset(($req->data()[0] ?? [])['activeKey']);

            return Http::response($isCheck ? '10' : '5', 200, ['Content-Type' => 'text/html']);
        }]);

        $this->assertTrue($this->svc()->generate('0555555555', 'req-1'));
        $this->assertTrue($this->svc()->check('0555555555', 'req-1', '1234'));
    }

    public function test_plain_text_error_code_is_false(): void
    {
        $this->configure();
        // كود «2» (رفض/اعتماد غير صالح) نصّاً → false
        Http::fake(['api.taqnyat.sa/verify.php' => Http::response('2', 200, ['Content-Type' => 'text/html'])]);

        $this->assertFalse($this->svc()->generate('0555555555', 'req-1'));
        $this->assertFalse($this->svc()->check('0555555555', 'req-1', '1234'));
    }
}
