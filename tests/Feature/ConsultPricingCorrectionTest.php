<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\Invoice;
use App\Models\JourneyTransition;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\ConsultBooking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * **طلبٌ مسعَّرٌ لا يصير مأزقاً.**
 *
 * كان في دورة المال بابان مسدودان:
 *
 * **الأوّل — التسعير بصفر.** مودال الجدول يفحص `isProcessing` وحده، والحارس المشترك
 * يقبل `0`، والخادم `min:0`. فتُنشأ فاتورة **٠ ر.س «مستحقّة»**، وتنتقل الاستشارة إلى
 * «بانتظار السداد» فلا تعود قابلةً للتسعير، ولا يستطيع العميل سداد صفر — **وتعرضها
 * الشاشة نفسها «لم تُسعر بعد»** لأن `total` صفرٌ falsy. لا مخرج إلّا إلغاء الطلب.
 *
 * **الثاني — لا تصحيح.** `setPrice` يشترط «بانتظار التسعير»، وأوّلُ تسعيرٍ يقفلها.
 * فرقمٌ خاطئ أُرسل إلى عميلٍ في فاتورة لا سبيل إلى تصحيحه، وشاشة الطلبات تعرض زرّ
 * «تعديل السعر» مصيرُه ٤٢٢ دائماً.
 */
class ConsultPricingCorrectionTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:Consult,1:User,2:User} */
    private function pendingRequest(): array
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $admin = User::factory()->create(['role' => Role::Admin]);

        $consult = Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-PRC-'.uniqid(), 'subject' => 'نزاع تجاري',
            'type' => 'استشارة', 'channel' => 'مرئية', 'status' => 'بانتظار التسعير',
            'session' => 'بانتظار الجلسة', 'tone' => 'b-amber', 'lawyer' => 'مستشار',
        ]);

        return [$consult, $admin, $client];
    }

    /** **الحارس الأثمن:** الدورة كاملةً — رفضُ الصفر، ثمّ تسعير، ثمّ تصحيح. */
    public function test_a_priced_request_never_becomes_a_dead_end(): void
    {
        [$consult, $admin, $client] = $this->pendingRequest();

        // (١) الصفر يُرفض — ولا فاتورةَ ميتة
        $this->actingAs($admin)
            ->post("/admin/consults/{$consult->id}/price", ['price' => 0])
            ->assertSessionHasErrors('price');

        $this->assertSame('بانتظار التسعير', $consult->fresh()->status, 'ولا تُقفل الحالة');
        $this->assertSame(0, Invoice::where('consult_id', $consult->id)->count(), 'ولا تُصدَر فاتورة');

        // (٢) تسعيرٌ صحيح
        $this->actingAs($admin)
            ->post("/admin/consults/{$consult->id}/price", ['price' => 500])
            ->assertRedirect();

        $fresh = $consult->fresh();
        $this->assertSame('بانتظار السداد', $fresh->status);
        $this->assertNotNull($fresh->priced_at);
        $this->assertSame(1, Invoice::where('consult_id', $consult->id)->where('paid', false)->where('status', 'مستحقة')->count());

        // (٣) **التصحيح** — الباب الذي لم يكن موجوداً
        $this->actingAs($admin)
            ->post("/admin/consults/{$consult->id}/reprice")
            ->assertRedirect();

        $fresh = $consult->fresh();
        $this->assertSame('بانتظار التسعير', $fresh->status, 'يعود إلى الطابور');
        $this->assertNull($fresh->priced_at);
        $this->assertSame(500, (int) $fresh->price, 'ويبقى الرقم السابق ليُصحَّح لا ليُبدأ من فراغ');

        // الفاتورة تُلغى ولا تُحذف — صفٌّ صدر باسم عميلٍ وأُشعر به
        $this->assertSame(0, Invoice::where('consult_id', $consult->id)->where('status', 'مستحقة')->count());
        $this->assertSame(1, Invoice::where('consult_id', $consult->id)->where('status', 'ملغاة')->count());

        // وإلغاء التسعير خطوةٌ في رحلة الاستشارة: من «بانتظار السداد»، بالفاعل والمبلغ السابق
        $row = JourneyTransition::where('transition', 'consult.reprice')->where('entity_id', $consult->id)->sole();
        $this->assertSame('بانتظار السداد', $row->from_state);
        $this->assertSame('بانتظار التسعير', $row->to_state);
        $this->assertSame($admin->id, $row->actor_id);
        $this->assertSame((string) $fresh->total, (string) $row->payload['previous_total']);

        // (٤) وتسعيرٌ ثانٍ يُقبل — وفاتورةٌ فعّالةٌ واحدة
        $this->actingAs($admin)
            ->post("/admin/consults/{$consult->id}/price", ['price' => 700])
            ->assertRedirect();

        $this->assertSame(
            1,
            Invoice::where('consult_id', $consult->id)->where('status', 'مستحقة')->count(),
            'فاتورةٌ فعّالةٌ واحدة لا اثنتان'
        );
        $this->assertSame(805, (int) $consult->fresh()->total, '700 + 15٪');

        // والعميل أُشعر بالإلغاء — كان في طريقه إلى السداد
        $this->assertSame(
            1,
            UserNotification::where('user_id', $client->id)->where('body', 'like', '%أُلغيت فاتورة%')->count()
        );
    }

    /** والتصحيح **قبل السداد وحده** — بعده استردادٌ ماليّ لا تسعير. */
    public function test_a_paid_request_cannot_be_repriced(): void
    {
        [$consult, $admin] = $this->pendingRequest();

        $this->actingAs($admin)->post("/admin/consults/{$consult->id}/price", ['price' => 500]);
        ConsultBooking::markPaid($consult->fresh());

        $this->actingAs($admin)
            ->post("/admin/consults/{$consult->id}/reprice")
            ->assertStatus(422);

        $this->assertNotNull($consult->fresh()->paid_at, 'ولا يُمسّ السداد');
    }

    /** ولا يُصحَّح ما لم يُسعَّر. */
    public function test_an_unpriced_request_cannot_be_repriced(): void
    {
        [$consult, $admin] = $this->pendingRequest();

        $this->actingAs($admin)
            ->post("/admin/consults/{$consult->id}/reprice")
            ->assertStatus(422);

        $this->assertSame('بانتظار التسعير', $consult->fresh()->status);
    }

    /** **والحدّ عند الكاتب لا عند نداءٍ واحد** — `setPrice` مدخلٌ عامّ. */
    public function test_the_floor_is_enforced_at_the_writer_not_only_the_request(): void
    {
        [$consult, $admin] = $this->pendingRequest();

        $this->expectException(HttpException::class);
        ConsultBooking::setPrice($consult, 0, $admin);
    }
}
