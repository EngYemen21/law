<?php

namespace Tests\Feature;

use App\Support\CaseJourney;
use App\Support\ExecJourney;
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

    public function test_every_exec_status_the_server_writes_has_a_declared_tone(): void
    {
        $written = ['جديد', 'تجهيز السند التنفيذي', 'مقيّد لدى محكمة التنفيذ', 'جارٍ', 'مكتمل', 'مغلق'];

        foreach ($written as $status) {
            $this->assertArrayHasKey($status, ExecJourney::STATUSES, "حالة «{$status}» غير معرّفة في ExecJourney");
            $this->assertStringStartsWith('b-', ExecJourney::toneFor($status));
        }
    }

    public function test_stage_and_tone_come_from_one_map_so_they_cannot_drift(): void
    {
        // المرحلة والنغمة تُقرآن من الخريطة نفسها — لا قائمتان تتباعدان
        foreach (CaseJourney::STATUSES as $status => $meta) {
            $this->assertSame($meta['at'], CaseJourney::stage($status));
            $this->assertSame($meta['tone'], CaseJourney::toneFor($status));
        }

        foreach (ExecJourney::STATUSES as $status => $meta) {
            $this->assertSame($meta['at'], ExecJourney::stage($status));
            $this->assertSame($meta['tone'], ExecJourney::toneFor($status));
        }
    }

    public function test_journey_stages_stay_within_their_flowline_bounds(): void
    {
        // فهرس المرحلة لا يتجاوز عدد خطوات المسار المعروض، وإلا ظهر مسار بلا مرحلة حالية
        foreach (CaseJourney::STATUSES as $status => $meta) {
            $this->assertLessThan(count(CaseJourney::LIFE), $meta['at'], "مرحلة «{$status}» خارج CASE_LIFE");
        }

        foreach (ExecJourney::STATUSES as $status => $meta) {
            $this->assertLessThan(count(ExecJourney::LIFE), $meta['at'], "مرحلة «{$status}» خارج EXEC_LIFE");
        }

        foreach (TicketJourney::statuses() as $status) {
            $this->assertLessThan(count(TicketJourney::STAGES), TicketJourney::indexOf($status));
        }
    }
}
