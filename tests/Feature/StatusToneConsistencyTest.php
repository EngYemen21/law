<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\CaseStatus;
use App\Support\CaseJourney;
use App\Support\ExecFlow;
use App\Support\TicketJourney;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * تناسق نغمات الحالة عبر اللوحات الأربع.
 *
 * كل نغمة كانت حرفية عند موضع الكتابة، فتُلوَّن الحالة نفسها لونين باختلاف كاتبها.
 * هذه الاختبارات تثبّت أن لكل حالة نغمة واحدة مصدرها صنف الرحلة، وأن أي حالة
 * يكتبها الخادم لها نغمة معرّفة صراحةً (لا تسقط على القيمة الافتراضية).
 */
class StatusToneConsistencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_ticket_status_has_exactly_one_declared_tone(): void
    {
        foreach (TicketJourney::statuses() as $status) {
            $tone = TicketJourney::toneFor($status);
            $this->assertNotEmpty($tone);
            $this->assertStringStartsWith('b-', $tone, "نغمة «{$status}» يجب أن تكون من مفردات الشارات");
        }

        // مراحل الرحلة تُصدِّر نغمتها المعلنة نفسها عبر toneFor
        foreach (TicketJourney::STAGES as $stage) {
            $this->assertSame($stage['tone'], TicketJourney::toneFor($stage['status']));
        }
    }

    public function test_every_case_status_the_server_writes_has_a_declared_tone(): void
    {
        // الحالات التي تكتبها المتحكّمات فعلياً — لا تسقط أي منها على الافتراضي
        $written = ['بانتظار اعتماد الأتعاب', 'بانتظار سداد الأتعاب', 'قيد التحضير', 'منظورة', 'صدر الحكم', 'مغلقة'];

        foreach ($written as $status) {
            $this->assertArrayHasKey($status, CaseJourney::STATUSES, "حالة «{$status}» غير معرّفة في CaseJourney");
            $this->assertStringStartsWith('b-', CaseJourney::toneFor($status));
        }
    }

    /**
     * حالات التنفيذ التي يكتبها الخادم فعلاً هي أسماء مراحل `ExecFlow` وحدها: `ExecService::sync`
     * يكتب `label($stage)` و`tone($stage)` معاً من الفهرس نفسه، فلا قائمتان تتباعدان أصلاً.
     *
     * وكان هذا الاختبار يقيس كتالوج `ExecJourney` — حالاتٌ («جارٍ»، «تجهيز السند التنفيذي»،
     * «مكتمل»…) **لا يكتبها أيّ مسار في المنظومة**، فكان يحرس قائمةً ميتة ويُعطي ثقةً كاذبة
     * بأن حالات التنفيذ محروسة. حُذف الكتالوج، وصار القياس على ما يُكتب حقاً.
     */
    public function test_every_exec_status_the_server_writes_has_a_declared_tone(): void
    {
        foreach (ExecFlow::FLOW as $stage => $status) {
            $this->assertNotEmpty($status);
            $this->assertSame($status, ExecFlow::label($stage), "المرحلة {$stage} تُسمّى «{$status}»");
            $this->assertStringStartsWith('b-', ExecFlow::tone($stage), "نغمة «{$status}» يجب أن تكون من مفردات الشارات");
        }
    }

    public function test_stage_and_tone_come_from_one_map_so_they_cannot_drift(): void
    {
        // المرحلة من خريطة المسار، والنغمة من الـEnum وحده — لا نسختان تتباعدان
        foreach (CaseJourney::STATUSES as $status => $meta) {
            $this->assertSame($meta['at'], CaseJourney::stage($status));
            $this->assertSame(CaseStatus::from($status)->tone(), CaseJourney::toneFor($status));
            $this->assertArrayNotHasKey('tone', $meta, 'عادت نسخة الألوان الثانية إلى CaseJourney');
        }
    }

    public function test_journey_stages_stay_within_their_flowline_bounds(): void
    {
        // فهرس المرحلة لا يتجاوز عدد خطوات المسار المعروض، وإلا ظهر مسار بلا مرحلة حالية
        foreach (CaseJourney::STATUSES as $status => $meta) {
            $this->assertLessThan(count(CaseJourney::LIFE), $meta['at'], "مرحلة «{$status}» خارج CASE_LIFE");
        }

        // ومسار التنفيذ: «مغلق» آخر خطوةٍ معروضة، والفهرس الخارج يسقط على الأولى لا على فراغ
        $this->assertSame('مغلق', ExecFlow::FLOW[count(ExecFlow::FLOW) - 1]);
        $this->assertSame(ExecFlow::FLOW[0], ExecFlow::label(count(ExecFlow::FLOW)));

        foreach (TicketJourney::statuses() as $status) {
            $this->assertLessThan(count(TicketJourney::STAGES), TicketJourney::indexOf($status));
        }
    }
}
