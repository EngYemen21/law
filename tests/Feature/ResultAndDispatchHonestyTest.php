<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Correspondence;
use App\Models\Ticket;
use App\Models\TicketSummary;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\ExternalSystemService;
use App\Support\CorrespondenceFlow;
use App\Support\TicketResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * صدق بطاقة النتيجة، وصدق إثبات الإرسال.
 *
 * الأولى: بطاقة النتيجة المعتمدة كانت تحمل بندين ثابتين دائماً — «تنفيذ التوصيات
 * أعلاه — المسؤول: [اسم المحامي]» و«متابعة المهلة النظامية ثم التصعيد» — يُسندان
 * مهمّةً إلى محامٍ بالاسم بلا سجلّ، ويَعِدان بمتابعة مهلةٍ لم تُحسب.
 *
 * والثانية: `ExternalSystemService::send` كانت تُعيد مرجعاً مُختلَقاً عند فشل النداء
 * الحقيقيّ، فيُخزَّن على المخاطبة ويُشعَر العميل بأنها أُرسلت إلى الجهة — ولم تُرسل.
 */
class ResultAndDispatchHonestyTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:Ticket, 1:?TicketSummary} */
    private function file(array $summaryFields = []): array
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'name' => 'أ. المستشار']);

        $ticket = Ticket::create([
            'user_id' => $client->id, 'number' => 'SB-RES-'.uniqid(), 'type' => 'نزاع تجاري',
            'subject' => 'مطالبة', 'status' => 'مكتملة', 'tone' => 'b-green',
            'assigned_lawyer_id' => $lawyer->id, 'assigned_lawyer' => $lawyer->name,
        ]);

        $summary = $summaryFields === [] ? null : TicketSummary::create(array_merge([
            'ticket_id' => $ticket->id, 'case_summary' => 'ملخّص.',
            'status' => 'approved', 'result_status' => 'approved', 'ai_generated' => true,
        ], $summaryFields));

        return [$ticket, $summary];
    }

    // ── أ) بطاقة النتيجة ──

    /** لا مهمّة تُسند إلى محامٍ بالاسم بلا سجلّ لها. */
    public function test_the_result_card_never_assigns_a_task_to_a_named_lawyer(): void
    {
        [$ticket, $summary] = $this->file(['facts' => 'وقائع.', 'key_points' => 'توصية حقيقيّة.']);

        $card = TicketResult::card($ticket, $summary);

        $this->assertStringNotContainsString('تنفيذ التوصيات أعلاه', $card);
        $this->assertStringNotContainsString('المسؤول:', $card);
        $this->assertStringNotContainsString('أ. المستشار', $card, 'لا تُنسب مهمّة إلى محامٍ لم يُسندها');
    }

    /** ولا وعدَ بمتابعة مهلة لم تُحسب ولا بتصعيدٍ لم يُقرَّر. */
    public function test_the_result_card_makes_no_procedural_promise(): void
    {
        [$ticket, $summary] = $this->file(['facts' => 'وقائع.', 'key_points' => 'توصية.']);

        $this->assertStringNotContainsString(
            'متابعة المهلة النظامية ثم التصعيد',
            TicketResult::card($ticket, $summary)
        );
    }

    /** وحقلٌ فارغ يُعلن غيابه لا يُملأ بتوصيةٍ قالبيّة. */
    public function test_an_empty_field_announces_its_absence(): void
    {
        [$ticket, $summary] = $this->file(['facts' => '', 'key_points' => '']);

        $card = TicketResult::card($ticket, $summary);
        $text = TicketResult::compose($ticket, $summary);

        foreach ([$card, $text] as $out) {
            $this->assertStringNotContainsString('اتخاذ الإجراء النظامي الأنسب بعد الدراسة', $out);
            $this->assertStringContainsString(TicketResult::NO_RECOMMENDATIONS, $out);
        }
    }

    /** وما كتبه المحامي فعلاً يُعرض كما هو. */
    public function test_real_recommendations_are_shown_verbatim(): void
    {
        [$ticket, $summary] = $this->file([
            'facts' => 'تأخّر المورّد عن التسليم.',
            'key_points' => 'يُوصى بالمطالبة القضائيّة بالفسخ والتعويض.',
        ]);

        $card = TicketResult::card($ticket, $summary);

        $this->assertStringContainsString('يُوصى بالمطالبة القضائيّة بالفسخ والتعويض.', $card);
        $this->assertStringNotContainsString(TicketResult::NO_RECOMMENDATIONS, $card);
    }

    // ── ب) إثبات الإرسال ──

    /** @return Correspondence مخاطبة عند المرحلة التي تسبق الإرسال */
    private function correspondence(): Correspondence
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);

        return Correspondence::create([
            'user_id' => $client->id, 'number' => 'MKH-'.uniqid(),
            'subject' => 'طلب تنفيذ', 'entity' => 'محكمة التنفيذ',
            'body' => 'نصّ المخاطبة.', 'stage' => 2, 'status' => 'قيد الإعداد',
            'tone' => 'b-blue', 'lawyer_id' => $lawyer->id,
        ]);
    }

    /** فشل النداء الحقيقيّ لا يُنتج مرجعاً. */
    public function test_a_failed_dispatch_never_fabricates_a_reference(): void
    {
        config(['services.external_corr.base_url' => 'https://corr.test', 'services.external_corr.api_key' => 'k']);
        Http::fake(['corr.test/*' => Http::response([], 500)]);

        $this->assertNull(app(ExternalSystemService::class)->send($this->correspondence()));
    }

    /** ولا يُخزَّن مرجعٌ ولا يُشعَر العميل بإرسالٍ لم يقع. */
    public function test_a_failed_dispatch_does_not_tell_the_client_it_was_sent(): void
    {
        config(['services.external_corr.base_url' => 'https://corr.test', 'services.external_corr.api_key' => 'k']);
        Http::fake(['corr.test/*' => Http::response([], 500)]);

        $corr = $this->correspondence();
        CorrespondenceFlow::advance($corr, 'الموظّف');
        $corr->refresh();

        $this->assertNull($corr->ext_ref, 'لا مرجع مُختلَق');
        $this->assertNull($corr->ext_synced_at);

        $notices = UserNotification::where('user_id', $corr->user_id)->pluck('body')->implode(' | ');
        $this->assertStringNotContainsString('تم إرسال مخاطبة', $notices, 'لا إشعار إرسالٍ بلا إرسال');
    }

    /** والنجاح الحقيقيّ يُخزَّن مرجعه ويُشعَر به — الإصلاح لا يكسر المسار السليم. */
    public function test_a_successful_dispatch_stores_the_real_reference(): void
    {
        config(['services.external_corr.base_url' => 'https://corr.test', 'services.external_corr.api_key' => 'k']);
        Http::fake(['corr.test/*' => Http::response(['referenceNumber' => 'MOJ-2026-55501'], 200)]);

        $corr = $this->correspondence();
        CorrespondenceFlow::advance($corr, 'الموظّف');
        $corr->refresh();

        $this->assertSame('MOJ-2026-55501', $corr->ext_ref);
        $notices = UserNotification::where('user_id', $corr->user_id)->pluck('body')->implode(' | ');
        $this->assertStringContainsString('تم إرسال مخاطبة', $notices);
    }

    /** ومرجع المحاكاة (بلا مفاتيح) يُعرّف نفسه فلا يُقرأ رقماً رسمياً. */
    public function test_the_simulated_reference_announces_itself(): void
    {
        config(['services.external_corr.base_url' => '', 'services.external_corr.api_key' => '']);

        $ext = app(ExternalSystemService::class)->send($this->correspondence());

        $this->assertNotNull($ext);
        $this->assertStringStartsWith(ExternalSystemService::SIMULATED_PREFIX, $ext['ref']);
    }
}
