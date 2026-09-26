<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
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

        // **لا يلمس اختبارٌ قرصَ التطوير.** كان `AdminResetDatabaseTest` ينادي «تصفير البيانات» على
        // القرص الحقيقيّ، فيحذف `ticket-docs` و`case-docs` و`recordings` كاملةً مع كلّ تشغيلٍ للحزمة —
        // وقيسَ أثره: مرفقات قضيّةٍ تجريبيّة اختفت من القرص فصار تنزيلها ٤٠٤. الاختبار الذي يحتاج
        // القرص يزيّفه هو أيضاً، والتزييف هنا شبكةُ أمانٍ لمن نسي.
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * **الصفحة المرفوضة تُعيد صاحبها ومعه السبب** — لا صفحة خطأ (`App\Support\ErrorResponse`).
     *
     * فتحُ رابطٍ مرفوض في المتصفّح (تنزيلٌ لغير مالكه، ملفٌّ لغير المُسنَد إليه) يُحوَّل بإشعارٍ
     * عربيّ بدل صفحة «Forbidden» الخام. فالحارس يُقاس هنا بالتحويل **وبالسبب**: التحويلُ وحده
     * لا يثبت الرفض (النجاح يحوّل أيضاً)، والسببُ العربيّ في `flash.error` هو ما يراه صاحبه.
     */
    protected function assertPageRefused(TestResponse $response, ?string $reason = null): TestResponse
    {
        $response->assertRedirect()->assertSessionHas('error');

        $message = (string) session('error');
        $this->assertMatchesRegularExpression('/\p{Arabic}/u', $message, 'سبب الرفض بالعربيّة لا نصّ الإطار');

        if ($reason !== null) {
            $this->assertStringContainsString($reason, $message);
        }

        return $response;
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
