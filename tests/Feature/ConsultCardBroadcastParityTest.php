<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Events\ConsultStatusBroadcast;
use App\Models\Consult;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **البطاقة والبثّ لا يختلفان على استشارةٍ واحدة.**
 *
 * الشاشة تُحمَّل ببطاقة `toCard()` ثمّ تنسخ حمولة البثّ فوقها. فكلّ مفتاحٍ يحمله
 * المساران بقيمتين مختلفتين ينقلب **تبدّلاً في الشاشة بلا سبب**: `when` كان يُشتقّ
 * في البطاقة (`whenLabel()`) ويُرسَل خاماً في البثّ (`when_label`)، فيتغيّر عمود
 * الموعد صيغةً عند أوّل بثّ. ومفاتيح كانت البطاقة تحملها والبثّ لا (`missed`،
 * `startable`) تبقى بائتةً حتى إعادة التحميل: موعدٌ فات يبقى «قابلاً للبدء».
 *
 * ومقارنةُ المسارين — لا التأكيد على كلٍّ وحده — هي ما يمنع افتراقهما ثانيةً.
 */
class ConsultCardBroadcastParityTest extends TestCase
{
    use RefreshDatabase;

    private function consult(array $overrides = []): Consult
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);

        // `$overrides` أوّلاً: عامل `+` يُبقي مفاتيح **اليسار**، فوضعُ الافتراضيّات
        // أوّلاً كان يبتلع كلّ تخصيص صامتاً.
        return Consult::create($overrides + [
            'user_id' => $client->id, 'ref' => 'CN-PAR-'.uniqid(), 'subject' => 'نزاع تجاري',
            'type' => 'استشارة', 'channel' => 'مرئية', 'status' => 'مؤكد', 'session' => 'بانتظار الجلسة',
            'tone' => 'b-blue', 'lawyer' => $lawyer->name, 'assigned_lawyer_id' => $lawyer->id,
            'starts_at' => now()->addDays(2)->setTime(11, 0),
        ]);
    }

    /** @return array<string,mixed> */
    private function payload(Consult $consult): array
    {
        return (new ConsultStatusBroadcast($consult))->broadcastWith();
    }

    /** كلّ مفتاحٍ مشتركٍ بينهما يحمل القيمة نفسها. */
    public function test_the_card_and_the_broadcast_never_disagree_about_a_consult(): void
    {
        $consult = $this->consult();
        $card = $consult->toCard();
        $wire = $this->payload($consult);

        $shared = array_intersect_key($wire, $card);
        $this->assertNotEmpty($shared, 'المساران يتقاطعان فعلاً — وإلّا فالحارس فارغ');

        foreach ($shared as $key => $value) {
            // الملخّص وحده يختلف بقصد: البثّ يحمل المعتمَد فقط لأن قناته مشتركة مع
            // العميل، والبطاقة تحمل النصّ الخام للطاقم ليراجعه.
            if ($key === 'summary') {
                continue;
            }

            $this->assertSame($card[$key], $value, "المفتاح «{$key}» يختلف بين البطاقة والبثّ");
        }
    }

    /** والموعد بالصيغة نفسها — لا يتبدّل عمودُه عند أوّل بثّ. */
    public function test_the_broadcast_sends_the_same_date_format_as_the_card(): void
    {
        $consult = $this->consult();

        $this->assertSame($consult->toCard()['when'], $this->payload($consult)['when']);
        $this->assertNotSame('', (string) $this->payload($consult)['when']);
    }

    /** ومشتقّات النافذة تُبثّ — فلا تبقى بائتةً بعد فوات الموعد. */
    public function test_the_broadcast_carries_the_derived_session_window(): void
    {
        $missed = $this->consult(['starts_at' => now()->subDays(2)]);
        $wire = $this->payload($missed);

        $this->assertArrayHasKey('missed', $wire);
        $this->assertArrayHasKey('startable', $wire);
        $this->assertTrue($wire['missed'], 'فات موعدها');
        $this->assertFalse($wire['startable'], 'فلا تُبدأ');
    }

    /** **ومصدر الملخّص لا يتلوّث بمصدر التحليل** — عمودان لأنهما حدثان مختلفان. */
    public function test_the_summary_source_is_reported_apart_from_the_analysis_source(): void
    {
        $consult = $this->consult(['ai_source' => 'ai_success', 'summary_ai_source' => 'fallback']);
        $card = $consult->toCard();

        $this->assertSame('ai_success', $card['aiSource'], 'تحليلٌ نجح يبقى ناجحاً');
        $this->assertSame('fallback', $card['summaryAiSource'], 'وتعثّرُ الملخّص مرئيّ');
    }

    /** و`null` تعني «لم يُقَس» — لا «نجح» ولا «فشل». */
    public function test_an_unmeasured_summary_source_stays_null(): void
    {
        $this->assertNull($this->consult()->toCard()['summaryAiSource']);
    }
}
