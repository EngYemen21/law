<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\Invoice;
use App\Models\User;
use App\Support\ConsultBooking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **الدورة كاملةً في مسارٍ واحد** — طلبٌ ← تسعيرٌ بصفرٍ يُرفض ← تسعيرٌ صحيح ← **تصحيح**
 * ← سدادٌ ← موعدٌ ← جلسةٌ ← نهاية.
 *
 * الحراسُ السابقة تفحص كلَّ باب على حدة؛ وهذا يفحص أن **الأبواب تفتح على بعضها**:
 * أنّ حالةً واحدةً تنتقل عبر الدورة دون أن تعلق في مأزق، وأنّ الفاتورة الفعّالة تبقى
 * واحدةً، وأنّ ما يُعرض إيراداً محصَّلاً هو ما سُدِّد لا ما انتهى.
 */
class ConsultMoneyCycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_full_money_cycle_has_no_dead_end(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $admin = User::factory()->create(['role' => Role::Admin]);

        $consult = Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-CYC-'.uniqid(), 'subject' => 'نزاع تجاري',
            'type' => 'استشارة', 'channel' => 'مرئية', 'status' => 'بانتظار التسعير',
            'session' => 'بانتظار الجلسة', 'tone' => 'b-amber', 'lawyer' => 'مستشار',
        ]);

        // (١) الصفر يُرفض — ولا فاتورةَ ميتة، ولا حالةٌ تُقفل
        $this->actingAs($admin)
            ->post("/admin/consults/{$consult->id}/price", ['price' => 0])
            ->assertSessionHasErrors('price');
        $this->assertSame('بانتظار التسعير', $consult->fresh()->status);
        $this->assertSame(0, Invoice::where('consult_id', $consult->id)->count());

        // (٢) تسعيرٌ صحيح ⇒ فاتورةٌ مستحقّة واحدة
        $this->actingAs($admin)->post("/admin/consults/{$consult->id}/price", ['price' => 500])->assertRedirect();
        $this->assertSame('بانتظار السداد', $consult->fresh()->status);
        $this->assertSame(1, Invoice::where('consult_id', $consult->id)->where('status', 'مستحقة')->count());

        // (٣) **التصحيح** — الباب الذي لم يكن موجوداً: تُلغى الفاتورة ولا تُحذف
        $this->actingAs($admin)->post("/admin/consults/{$consult->id}/reprice")->assertRedirect();
        $this->assertSame('بانتظار التسعير', $consult->fresh()->status);
        $this->assertSame(1, Invoice::where('consult_id', $consult->id)->where('status', 'ملغاة')->count());

        // (٤) تسعيرٌ ثانٍ ⇒ فاتورةٌ فعّالةٌ واحدة لا اثنتان
        $this->actingAs($admin)->post("/admin/consults/{$consult->id}/price", ['price' => 700])->assertRedirect();
        $this->assertSame(1, Invoice::where('consult_id', $consult->id)->where('status', 'مستحقة')->count());
        $this->assertSame(805, (int) $consult->fresh()->total, '700 + 15٪');

        // **وقبل السداد: معلَّقٌ لا محصَّل** — التعريفان متعامدان
        $card = $consult->fresh()->toCard();
        $this->assertFalse($card['paid'], 'لا يُحتسب محصَّلاً');
        $this->assertTrue($card['priced'], 'ويُحتسب معلَّقاً — سعرٌ مقرَّرٌ لم يُسدَّد');

        // (٥) سداد
        ConsultBooking::markPaid($consult->fresh());
        $consult->refresh();
        $this->assertNotNull($consult->paid_at);
        $this->assertSame('بانتظار تحديد الموعد', $consult->status);

        $card = $consult->toCard();
        $this->assertTrue($card['paid'], 'الآن محصَّلٌ — وبعد السداد وحده');

        // ولا تصحيحَ بعد السداد: استردادٌ ماليّ لا تسعير
        $this->actingAs($admin)->post("/admin/consults/{$consult->id}/reprice")->assertStatus(422);
        $this->assertNotNull($consult->fresh()->paid_at, 'ولا يُمسّ السداد');

        // (٦) موعدٌ ثمّ جلسة
        $consult->forceFill([
            'status' => 'قيد الاستشارة',
            'session' => 'جلسة جارية',
            'starts_at' => now()->subMinutes(5),
        ])->save();

        // (٧) والنهايةُ لا تُبطل ما سُدِّد
        $consult->forceFill(['status' => 'منتهية', 'session' => 'منتهية'])->save();

        $final = $consult->fresh()->toCard();
        $this->assertTrue($final['paid'], 'المحصَّل يبقى محصَّلاً');
        $this->assertNotNull($final['ageMins'], 'والعمر مقيسٌ لا صفرٌ مفترَض');
        // فاتورتان في السجلّ: الأولى ملغاةٌ بالتصحيح، والثانية مسدَّدة. ولا مستحقٌّ باقٍ.
        $this->assertSame(1, Invoice::where('consult_id', $consult->id)->where('paid', true)->count());
        $this->assertSame(0, Invoice::where('consult_id', $consult->id)->where('status', 'مستحقة')->count());
        $this->assertSame(1, Invoice::where('consult_id', $consult->id)->where('status', 'ملغاة')->count());
    }

    /** **وحالةٌ واحدةٌ بنصٍّ ولونٍ واحدين** — لا كتالوجَ ثانٍ في أيّ شاشة. */
    public function test_one_status_reads_the_same_everywhere(): void
    {
        // الكتالوجات الأربعة على الخادم، ونظائرها المصدَّرة للواجهة
        $lib = (string) file_get_contents(resource_path('js/lib/employee-data.ts'));

        foreach ([
            'CONSULT_TERMINAL_STATUSES' => Consult::TERMINAL_STATUSES,
            'CONSULT_CLOSED_STATUSES' => Consult::CLOSED_STATUSES,
            'CONSULT_BOOKING_STATUSES' => Consult::PRE_SESSION_STATUSES,
            'CONSULT_SESSIONS' => Consult::SESSIONS,
            'CONSULT_PRIORITIES' => Consult::PRIORITIES,
        ] as $name => $server) {
            preg_match("/export const {$name} = \[([^\]]*)\]/u", $lib, $m);
            $this->assertNotEmpty($m, "«{$name}» غير مصدَّرة للواجهة");

            preg_match_all("/'([^']+)'/u", $m[1], $vals);
            $this->assertSame(
                $server,
                $vals[1],
                "«{$name}» تنحرف عن نظيرها الخادميّ — وهو أصلُ كلّ حالةٍ تُعرض بلونين"
            );
        }
    }
}
