<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\HearingStatus;
use App\Enums\Role;
use App\Mail\HearingEventMail;
use App\Models\CaseHearing;
use App\Models\LegalCase;
use App\Models\User;
use App\Models\UserNotification;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * **تأجيل جلسة المحكمة سجلٌّ يبقى، والتالية جلسةٌ جديدة.**
 *
 * كان التعديل يكتب فوق الصفّ نفسه: يضيع الموعد القديم، وتُمحى نتيجة جلسةٍ سُجّلت «مؤجلة»،
 * وتصحيحُ عنوانٍ وحده يبلّغ العميل «أُعيدت جدولة جلستك».
 */
class HearingPostponementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    /** @return array{0:User,1:User,2:LegalCase} */
    private function lawyerAndCase(): array
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        $lawyer->syncPermissions(Permission::whereIn('name', ['إدارة القضايا والأتعاب'])->get());
        $case = LegalCase::create([
            'user_id' => $client->id, 'assigned_lawyer_id' => $lawyer->id,
            'number' => 'CASE-2026-6300', 'type' => 'نزاع تجاري', 'status' => 'منظورة', 'tone' => 'b-blue', 'update_text' => '—',
        ]);

        return [$client, $lawyer, $case];
    }

    private function hearing(LegalCase $case, array $attrs = []): CaseHearing
    {
        $at = now()->addDays(3)->setTime(10, 0);

        return $case->hearings()->create($attrs + [
            'title' => 'جلسة المرافعة', 'day' => $at->toDateString(), 'time' => '10:00', 'court' => 'الدائرة التجارية الأولى',
            'status' => HearingStatus::Scheduled->value, 'starts_at' => $at,
            'reminder_24h_sent_at' => now(), 'reminder_1h_sent_at' => now(),
        ]);
    }

    private function update(User $actor, LegalCase $case, CaseHearing $hearing, array $payload)
    {
        return $this->actingAs($actor)->post(route('lawyer.cases.hearings.update', [$case, $hearing]), $payload);
    }

    public function test_moving_the_date_creates_a_linked_hearing_and_keeps_the_old_one_postponed(): void
    {
        Mail::fake();
        [$client, $lawyer, $case] = $this->lawyerAndCase();
        $old = $this->hearing($case);
        $day = now()->addDays(20)->toDateString();

        $this->update($lawyer, $case, $old, [
            'title' => 'جلسة المرافعة', 'day' => $day, 'time' => '09:30', 'court' => 'الدائرة التجارية الأولى',
            'reason' => 'court_decision', 'note' => 'لتقديم المذكرة الجوابية',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $old->refresh();
        $this->assertSame(HearingStatus::Postponed->value, $old->status, 'الجلسة القديمة تبقى محضراً مؤجّلاً');
        $this->assertSame('قرار المحكمة — لتقديم المذكرة الجوابية', $old->outcome, 'ونتيجتها سبب التأجيل');
        $this->assertSame(now()->addDays(3)->toDateString(), $old->starts_at->toDateString(), 'وموعدها القديم لم يُمحَ');

        $next = CaseHearing::where('postponed_from_id', $old->id)->firstOrFail();
        $this->assertSame(HearingStatus::Scheduled->value, $next->status);
        $this->assertSame($day.' 09:30', $next->starts_at->format('Y-m-d H:i'));
        $this->assertSame(2, $case->hearings()->count());
        $this->assertStringContainsString('قرار المحكمة', (string) $case->fresh()->update_text);
        $this->assertTrue(UserNotification::where('user_id', $client->id)->where('body', 'like', '%قرار المحكمة%')->exists(), 'العميل يُبلَّغ بالتأجيل وسببه');
        Mail::assertQueued(HearingEventMail::class, fn ($m) => $m->event === 'rescheduled' && $m->hearing->is($next) && $m->hasTo($client->email));
    }

    public function test_the_new_hearing_starts_with_fresh_reminders(): void
    {
        Mail::fake();
        [, $lawyer, $case] = $this->lawyerAndCase();
        $old = $this->hearing($case);

        $this->update($lawyer, $case, $old, [
            'title' => 'جلسة المرافعة', 'day' => now()->addDays(10)->toDateString(), 'time' => '11:00', 'reason' => 'lawyer_unavailable',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $next = CaseHearing::where('postponed_from_id', $old->id)->firstOrFail();
        $this->assertNull($next->reminder_24h_sent_at);
        $this->assertNull($next->reminder_1h_sent_at);
        $this->assertSame('الدائرة التجارية الأولى', $next->court, 'الدائرة تنتقل إن لم تُرسَل');
    }

    public function test_an_already_recorded_postponement_keeps_its_outcome(): void
    {
        Mail::fake();
        [, $lawyer, $case] = $this->lawyerAndCase();
        $old = $this->hearing($case, ['status' => HearingStatus::Postponed->value, 'outcome' => 'أجّلت الدائرة لحضور الشاهد']);

        $this->update($lawyer, $case, $old, [
            'title' => 'جلسة سماع الشاهد', 'day' => now()->addDays(30)->toDateString(), 'time' => '10:00', 'reason' => 'court_decision',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $old->refresh();
        $this->assertSame(HearingStatus::Postponed->value, $old->status);
        $this->assertSame('أجّلت الدائرة لحضور الشاهد', $old->outcome, 'النتيجة المسجّلة لا تُكتب فوقها');
        $this->assertSame('جلسة المرافعة', $old->title);
        $this->assertTrue(CaseHearing::where('postponed_from_id', $old->id)->where('title', 'جلسة سماع الشاهد')->exists());

        // وموعدها حُسم بتاليتها: لا تُؤجَّل ثانيةً فتتفرّع السلسلة
        $this->update($lawyer, $case, $old, [
            'title' => 'جلسة سماع الشاهد', 'day' => now()->addDays(40)->toDateString(), 'time' => '10:00', 'reason' => 'court_decision',
        ])->assertStatus(422);
        $this->assertSame(2, $case->hearings()->count());
    }

    public function test_editing_only_title_or_court_changes_in_place_without_telling_the_client(): void
    {
        Mail::fake();
        [$client, $lawyer, $case] = $this->lawyerAndCase();
        $hearing = $this->hearing($case);
        $notified = UserNotification::where('user_id', $client->id)->count();

        $this->update($lawyer, $case, $hearing, [
            'title' => 'جلسة المرافعة الختامية', 'day' => $hearing->starts_at->toDateString(), 'time' => '10:00', 'court' => 'الدائرة التجارية الثانية',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $hearing->refresh();
        $this->assertSame('جلسة المرافعة الختامية', $hearing->title);
        $this->assertSame('الدائرة التجارية الثانية', $hearing->court);
        $this->assertSame(HearingStatus::Scheduled->value, $hearing->status);
        $this->assertNotNull($hearing->reminder_24h_sent_at, 'الموعد لم يتحرّك فلا تُعاد التذكيرات');
        $this->assertSame(1, $case->hearings()->count(), 'لا صفّ جديد');
        $this->assertSame($notified, UserNotification::where('user_id', $client->id)->count(), 'لا «أُعيدت جدولة جلستك» وموعده لم يتغيّر');
        Mail::assertNothingQueued();
    }

    public function test_a_reason_is_required_when_the_date_moves(): void
    {
        Mail::fake();
        [, $lawyer, $case] = $this->lawyerAndCase();
        $hearing = $this->hearing($case);
        $payload = ['title' => 'جلسة المرافعة', 'day' => now()->addDays(15)->toDateString(), 'time' => '10:00'];

        $this->update($lawyer, $case, $hearing, $payload)->assertSessionHasErrors('reason');
        $this->update($lawyer, $case, $hearing, $payload + ['reason' => 'other'])->assertSessionHasErrors('note');
        $this->update($lawyer, $case, $hearing, $payload + ['reason' => 'technical'])->assertSessionHasErrors('reason'); // ليس من أسباب الجلسات

        $this->assertSame(HearingStatus::Scheduled->value, $hearing->fresh()->status);
        $this->assertSame(1, $case->hearings()->count());
    }

    public function test_a_past_date_is_rejected(): void
    {
        Mail::fake();
        [, $lawyer, $case] = $this->lawyerAndCase();
        $hearing = $this->hearing($case);

        $this->update($lawyer, $case, $hearing, [
            'title' => 'جلسة المرافعة', 'day' => now()->subDays(2)->toDateString(), 'time' => '10:00', 'reason' => 'court_decision',
        ])->assertStatus(422);

        $this->assertSame(HearingStatus::Scheduled->value, $hearing->fresh()->status);
        $this->assertSame(1, $case->hearings()->count());
    }

    public function test_the_case_page_exposes_the_chain_and_the_allowed_actions(): void
    {
        Mail::fake();
        [, $lawyer, $case] = $this->lawyerAndCase();
        $old = $this->hearing($case);
        $this->update($lawyer, $case, $old, [
            'title' => 'جلسة المرافعة', 'day' => now()->addDays(20)->toDateString(), 'time' => '10:00', 'reason' => 'court_decision',
        ])->assertRedirect();

        $rows = $case->fresh()->hearings->map->toData()->keyBy('id');
        $next = CaseHearing::where('postponed_from_id', $old->id)->firstOrFail();

        $this->assertSame($old->id, $rows[$next->id]['postponedFromId']);
        $this->assertFalse($rows[$old->id]['canRecord'], 'المؤجّلة سُجّلت نتيجتها');
        $this->assertFalse($rows[$old->id]['canCancel'], 'والمؤجّلة سجلٌّ لا يُلغى');
        $this->assertTrue($rows[$next->id]['canRecord']);
        $this->assertTrue($rows[$next->id]['canCancel']);
        $this->assertTrue($rows[$next->id]['canEdit']);

        // وإلغاء المؤجّلة يمحو تأجيلها — مرفوض من الخادم لا من الواجهة وحدها
        $this->actingAs($lawyer)->post(route('lawyer.cases.hearings.cancel', [$case, $old]))->assertStatus(422);
        $this->assertSame(HearingStatus::Postponed->value, $old->fresh()->status);
    }
}
