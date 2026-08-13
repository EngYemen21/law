<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    /**
     * تثبيت وقت اليوم (لا التاريخ) على ساعة مبكرة (٠٠:٣٠) لكل اختبار — يمنع تذبذب الاختبارات
     * التي تحجز بساعات ثابتة (مثل ١١:٠٠/١٣:٠٠) اعتماداً على وقت التشغيل الفعلي، بعد إضافة
     * حارس «لا حجز في الماضي» الحقيقي. التاريخ يبقى اليوم الفعلي — لا يتأثر أي منطق تقويمي آخر.
     */
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::today()->setTime(0, 30));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * تهيئة مفاتيح تقنيات + تزييف واجهة Verify (generate=5، check=10 للرمز الصحيح و11 لغيره).
     * تُستدعى في الاختبارات التي تمرّ بمسار المصادقة الفعليّ.
     */
    protected function fakeTaqnyatVerify(string $correct = '1234'): void
    {
        config(['services.taqnyat.api_key' => 'tok_test', 'services.taqnyat.sender' => 'Salasel']);

        Http::fake(['api.taqnyat.sa/verify.php' => function ($request) use ($correct) {
            $payload = ($request->data()[0] ?? $request->data());
            $isCheck = isset($payload['activeKey']);

            if (! $isCheck) {
                return Http::response(['code' => 5], 200); // أُرسل بنجاح
            }

            return Http::response(['code' => $payload['activeKey'] === $correct ? 10 : 11], 200);
        }]);
    }

    /**
     * تسجيل الدخول عبر تدفّق الهوية + رمز SMS (OTP) الحقيقي — للاختبارات التي تختبر مسار الدخول.
     */
    protected function loginViaOtp(User $user, string $code = '1234'): TestResponse
    {
        $this->fakeTaqnyatVerify($code);
        $this->post('/auth/otp/request', ['national_id' => $user->national_id]);

        return $this->post('/auth/otp/verify', ['code' => $code]);
    }
}
