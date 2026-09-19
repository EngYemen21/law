<?php

namespace Tests\Feature;

use App\Enums\AiSource;
use App\Enums\Role;
use App\Models\AiRun;
use App\Models\Consult;
use App\Models\JourneyTransition;
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Ai\AiReviewOutcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsConsultJourney;
use Tests\TestCase;

/**
 * **نشر نتيجة الجلسة كلّه أو لا شيء.**
 *
 * 🔴 كان الاعتماد النهائيّ لملخّص الجلسة يحفظ الاعتماد، ويُشعر العميل، ويضيف رسالتين في
 * محادثة التذكرة ويعتمد نتيجتها — ثمّ ينادي المحرّك لنقلها «بانتظار قرار المآل». فإن رفض
 * (تذكرةٌ حُوّلت قضيّةً) بقي كلّ ذلك محفوظاً والتذكرة عالقة، وإعادة المحاولة تُرفض لأنّ
 * الملخّص «معتمد». قِيس قبل الإصلاح: رسالتان باقيتان + إشعارٌ للعميل + تذكرةٌ عالقة.
 */
class ConsultOutcomePublishAtomicTest extends TestCase
{
    use BuildsConsultJourney, RefreshDatabase;

    /** استشارةٌ منتهية بملخّص، وتذكرتها «بانتظار ملخّص الجلسة». @return array{0:Consult,1:Ticket} */
    private function endedConsult(bool $ticketAlreadyACase = false): array
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $ticket = $this->ticketWithApprovedOpinion($client);
        $consult = $this->requestPricedAndPaid($client, $ticket->fresh(), 'video');
        $consult->refresh()->forceFill(['session' => 'منتهية', 'status' => 'منتهية', 'summary' => 'ملخّص الجلسة: إنذارٌ ثمّ دعوى.'])->save();
        $ticket->refresh()->forceFill(['status' => 'بانتظار ملخّص الجلسة'])->save();

        if ($ticketAlreadyACase) {
            // حارس `ReadyForOutcome`: تذكرةٌ لها قضيّة لا تُنقل ⇒ يرفض المحرّك
            LegalCase::create([
                'user_id' => $client->id, 'ticket_id' => $ticket->id, 'number' => 'CAS-AT-'.uniqid(),
                'type' => 'نزاع تجاري', 'department' => 'القسم التجاري', 'status' => 'قيد الدراسة',
            ]);
        }

        return [$consult->fresh(), $ticket->fresh()];
    }

    /** لا أثر لاعتمادٍ رُفض: لا رسائل، ولا نتيجة معتمدة، ولا اعتماد، ولا إشعار، والحالة كما هي. */
    private function assertNothingLeftBehind(Consult $consult, Ticket $ticket, int $messagesBefore): void
    {
        $consult->refresh();
        $ticket->refresh();

        $this->assertSame($messagesBefore, $ticket->messages()->count(), 'لا رسائل ناقصة في المحادثة');
        $this->assertNotSame('approved', $ticket->summary?->result_status, 'النتيجة لم تُعتمد');
        $this->assertNull($consult->summary_approved_at, 'الملخّص لم يُعتمد — فإعادة المحاولة ممكنة');
        $this->assertSame('بانتظار ملخّص الجلسة', $ticket->status);
        $this->assertSame(0, UserNotification::where('user_id', $consult->user_id)->where('body', 'like', '%اعتُمد ملخّص استشارتك%')->count(), 'لا إشعار بما لم يقع');
        $this->assertSame(0, JourneyTransition::where('transition', 'ticket.ready_for_outcome')->count());
    }

    public function test_a_refused_publish_from_the_consult_screen_leaves_nothing_behind(): void
    {
        [$consult, $ticket] = $this->endedConsult(ticketAlreadyACase: true);
        $before = $ticket->messages()->count();

        $this->actingAs($this->journeyAdmin())
            ->post("/admin/consults/{$consult->id}/summary/approve")
            ->assertStatus(422);

        $this->assertNothingLeftBehind($consult, $ticket, $before);
    }

    /** ومن صندوق المراجعة: القيد نفسه لا يُسجَّل «مقبولاً» فيغيب والملفّ لم يُطلَق. */
    public function test_a_refused_publish_from_the_review_inbox_keeps_the_item_pending(): void
    {
        [$consult, $ticket] = $this->endedConsult(ticketAlreadyACase: true);
        $before = $ticket->messages()->count();
        $run = AiRun::create([
            'task_type' => 'consult.summary', 'source' => AiSource::AiSuccess->value,
            'entity_ref' => $consult->ref, 'status' => AiRun::STATUS_NEEDS_REVIEW,
        ]);

        $this->actingAs($this->journeyAdmin())
            ->post(route('admin.ai-review.decide', $run), ['action' => 'accept'])
            ->assertStatus(422);

        $this->assertNull($run->fresh()->review_action, 'القرار لم يُسجَّل — يبقى في الصندوق');
        $this->assertNothingLeftBehind($consult, $ticket, $before);
    }

    /** المسار السليم لم يتغيّر: رسالتان، ونتيجةٌ معتمدة، وإشعارٌ واحد، و«بانتظار قرار المآل». */
    public function test_a_normal_approval_publishes_once(): void
    {
        [$consult, $ticket] = $this->endedConsult();
        $before = $ticket->messages()->count();
        $admin = $this->journeyAdmin();

        $this->actingAs($admin)->post("/admin/consults/{$consult->id}/summary/approve")->assertRedirect();

        $ticket->refresh();
        $this->assertSame('بانتظار قرار المآل', $ticket->status);
        $this->assertSame($before + 2, $ticket->messages()->count());
        $this->assertSame('approved', $ticket->summary?->result_status);
        $this->assertNotNull($consult->fresh()->summary_approved_at);
        $this->assertSame(1, UserNotification::where('user_id', $consult->user_id)->where('body', 'like', '%اعتُمد ملخّص استشارتك%')->count());
        $this->assertSame(1, UserNotification::where('user_id', $consult->user_id)->where('body', 'like', '%اكتملت معالجة تذكرتك%')->count());

        // اعتمادٌ ثانٍ بنسخةٍ قديمة من الاستشارة (كاعتمادين متزامنين) لا يكرّر شيئاً
        $this->assertFalse(AiReviewOutcome::approveConsultSummary($consult, $admin));
        $this->assertSame($before + 2, $ticket->messages()->count());
        $this->assertSame(1, UserNotification::where('user_id', $consult->user_id)->where('body', 'like', '%اعتُمد ملخّص استشارتك%')->count());
    }
}
