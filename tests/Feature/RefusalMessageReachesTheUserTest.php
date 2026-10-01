<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\User;
use App\Support\SessionWindow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

/**
 * **رسالةُ الرفض تصل صاحبها.**
 *
 * كان `abort(422, '…')` في زيارة Inertia يعيد **صفحة HTML بلا ترويسة `X-Inertia`
 * وبلا تحويل** — فـ`onError` لا يُنادى، ومعالجاتُ الخطأ المكتوبة في الشاشات لا
 * تُنفَّذ قطّ. ثلاثة عشر حارساً في متحكّم الاستشارات وحده تمنع الضرر ولا تشرح:
 * يرى الموظّف نافذة خطأ خام بدل «فات موعد هذه الجلسة — سجّل لم يحضر أو أعد جدولتها».
 *
 * والحارس يؤكّد **النصّ** لا الرمز: تحويلٌ بلا رسالةٍ هو العطل نفسه بوجهٍ ألطف.
 */
class RefusalMessageReachesTheUserTest extends TestCase
{
    use RefreshDatabase;

    /**
     * نصّ الرسالة من الجلسة — بأيّ شكلٍ جاءت.
     *
     * `session('errors')` قد تكون `ViewErrorBag` وقد تكون مصفوفةً خاماً بحسب ما إذا
     * كان الوسيط قد هيّأها؛ ومطابقةُ شكلٍ واحد تُسقط الاختبار على تفصيلٍ لا يخصّه.
     */
    private function refusalMessage(): string
    {
        $errors = session('errors');

        if ($errors instanceof ViewErrorBag) {
            return (string) $errors->getBag('default')->first('message');
        }

        if (is_array($errors)) {
            $first = $errors['message'] ?? reset($errors);

            return (string) (is_array($first) ? reset($first) : $first);
        }

        return '';
    }

    /** @return array{0:Consult,1:User} */
    private function futureConsult(): array
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);

        $consult = Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-MSG-'.uniqid(), 'subject' => 'نزاع تجاري',
            'type' => 'استشارة', 'channel' => 'مرئية', 'status' => 'مؤكد',
            'session' => 'بانتظار الجلسة', 'tone' => 'b-blue',
            'lawyer' => $lawyer->name, 'assigned_lawyer_id' => $lawyer->id,
            'starts_at' => now()->addWeeks(3),
        ]);

        return [$consult, $lawyer];
    }

    /** **الحارس الأثمن:** الرفض يصل الشاشة رسالةً تُعرض، لا صفحةَ خطأ. */
    public function test_a_refused_action_returns_a_message_the_screen_can_show(): void
    {
        [$consult, $lawyer] = $this->futureConsult();

        $response = $this->actingAs($lawyer)
            ->withHeader('X-Inertia', 'true')
            ->post("/lawyer/consults/{$consult->id}/start");

        $response->assertRedirect();
        $response->assertSessionHasErrors('message');

        $this->assertStringContainsString(
            SessionWindow::staffStartLabel(),
            $this->refusalMessage(),
            'النصّ هو نصّ `abort` نفسه — لا رسالةً عامّة تُخفي السبب'
        );

        $this->assertSame('بانتظار الجلسة', $consult->fresh()->session, 'والحارس ما زال يمنع');
    }

    /** والسببان يتمايزان — «فات الموعد» غير «لم يحن بعد». */
    public function test_each_refusal_carries_its_own_reason(): void
    {
        [$consult, $lawyer] = $this->futureConsult();
        $consult->update(['starts_at' => now()->subMonth()]);

        // `assertSessionHasErrors` تُهيّئ حقيبة الأخطاء قبل قراءتها — وبدونها تعود
        // القيمة الخام مفتاحاً لا نصّاً.
        $this->actingAs($lawyer)
            ->withHeader('X-Inertia', 'true')
            ->post("/lawyer/consults/{$consult->id}/start")
            ->assertRedirect()
            ->assertSessionHasErrors('message');

        $this->assertStringContainsString('فات موعد', $this->refusalMessage());
    }

    /**
     * **ولا يُبتلَع الرفض في مسار axios.**
     *
     * نداءات axios تحتاج جسم خطأ حقيقيّاً؛ التحويل يجعلها تقرأ ٢٠٠ فتظنّ الإجراء
     * نجح — وهو عطلٌ موثَّقٌ في `bootstrap/app.php` سبق إصلاحُه. فالتحويل مشروطٌ
     * بـ`X-Inertia` وحدها.
     */
    public function test_an_axios_call_still_gets_a_real_error_body(): void
    {
        [$consult, $lawyer] = $this->futureConsult();

        $this->actingAs($lawyer)
            ->withHeader('Accept', 'application/json')
            ->post("/lawyer/consults/{$consult->id}/start")
            ->assertStatus(422);
    }

    /** وزيارةٌ عاديّة (بلا Inertia ولا axios) تبقى على حالها. */
    public function test_a_plain_request_is_untouched(): void
    {
        [$consult, $lawyer] = $this->futureConsult();

        $this->actingAs($lawyer)
            ->post("/lawyer/consults/{$consult->id}/start")
            ->assertStatus(422);
    }
}
