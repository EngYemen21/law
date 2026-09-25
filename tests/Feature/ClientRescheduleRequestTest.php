<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\Meeting;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * قناة العميل لطلب إعادة الجدولة (فحص الأزرار — النواقص):
 * استشارته الفائتة كانت تعرض نصاً ميتاً «تواصل مع المكتب لإعادة الجدولة» بلا أي زرّ
 * ولا نقطة خادمية، واجتماعه القادم بلا أي قناة لطلب تغيير الموعد.
 */
class ClientRescheduleRequestTest extends TestCase
{
    use RefreshDatabase;

    private function missedConsult(User $client, User $lawyer): Consult
    {
        return Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-RR-1', 'subject' => 'نزاع تجاري',
            'channel' => 'مرئية', 'lawyer' => $lawyer->name, 'assigned_lawyer_id' => $lawyer->id,
            'day' => now()->subDay()->format('Y-m-d'), 'time' => '10:00',
            'starts_at' => now()->subDay()->setTime(10, 0),
            'status' => 'مؤكد', 'session' => 'بانتظار الجلسة', 'paid_at' => now()->subDays(2),
        ]);
    }

    // ————— الاستشارة الفائتة —————

    public function test_client_can_request_consult_reschedule(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $consult = $this->missedConsult($client, $lawyer);

        $this->actingAs($client)->post(route('consults.reschedule-request', $consult))
            ->assertRedirect()->assertSessionHas('flash');

        // وُثّق بسجل التدقيق وأُشعر المحامي المسند والإدارة
        $audit = collect($consult->fresh()->audit ?? []);
        $this->assertTrue($audit->contains(fn ($a) => str_contains(json_encode($a, JSON_UNESCAPED_UNICODE), 'طلب تغيير الموعد')), 'طلب العميل موثَّق');
        $this->assertGreaterThan(0, UserNotification::where('user_id', $lawyer->id)->count(), 'المحامي أُشعر');
        $this->assertGreaterThan(0, UserNotification::where('user_id', $admin->id)->count(), 'الإدارة أُشعرت');
    }

    /**
     * **الموعد القادم يُطلب تغييره — قبل ٢٤ ساعة.** (قرار المالك 2026-09-25)
     *
     * كانت القناة للفائتة وحدها: من لا يستطيع الحضور غداً لم يكن له طريقٌ إلّا أن يفوته الموعد.
     */
    public function test_an_upcoming_consult_can_be_asked_to_move_a_day_ahead(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $consult = $this->missedConsult($client, $lawyer);
        $consult->update(['starts_at' => now()->addDays(2), 'day' => now()->addDays(2)->format('Y-m-d')]);

        $this->assertTrue($consult->fresh()->toClientCard()['rescheduleRequest']['canRequest'], 'الزرّ لا يظهر لموعدٍ بعد يومين.');

        $this->actingAs($client)->post(route('consults.reschedule-request', $consult), ['note' => 'لديّ سفر'])->assertRedirect();

        $consult->refresh();
        $this->assertNotNull($consult->reschedule_requested_at);
        $this->assertSame('لديّ سفر', $consult->reschedule_request_note);
    }

    /** وخلال ٢٤ ساعة يُتّصل بالمكتب — والزرّ لا يظهر أصلاً، فلا يُعرض ما يرفضه الخادم. */
    public function test_a_consult_starting_within_a_day_cannot_be_asked_to_move(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $consult = $this->missedConsult($client, $lawyer);
        $consult->update(['starts_at' => now()->addHours(5), 'day' => now()->format('Y-m-d')]);

        $this->assertFalse($consult->fresh()->toClientCard()['rescheduleRequest']['canRequest']);
        $this->actingAs($client)->post(route('consults.reschedule-request', $consult))->assertStatus(422);
        $this->assertNull($consult->fresh()->reschedule_requested_at);
    }

    /** **مرّةً حتى يُقضى** — كان يُرسَل مرّاتٍ بلا حدّ، وكلُّ مرّةٍ تُنبّه الإدارة كلّها. */
    public function test_a_pending_request_is_not_sent_twice(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $consult = $this->missedConsult($client, $lawyer);

        $this->actingAs($client)->post(route('consults.reschedule-request', $consult))->assertRedirect();
        $once = UserNotification::where('user_id', $admin->id)->count();

        $this->actingAs($client)->post(route('consults.reschedule-request', $consult))->assertStatus(422);

        $this->assertSame($once, UserNotification::where('user_id', $admin->id)->count(), 'الطلب الثاني نبّه الإدارة من جديد.');
        $this->assertTrue($consult->fresh()->toClientCard()['rescheduleRequest']['pending'], 'العميل لا يرى أنّ طلبه قيد المعالجة.');
    }

    /** والرفض بسببٍ يصل العميل — ويمحو الطلب فيستطيع غيره لاحقاً. */
    public function test_staff_decline_the_request_with_a_reason_the_client_reads(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $consult = $this->missedConsult($client, $lawyer);
        $this->actingAs($client)->post(route('consults.reschedule-request', $consult))->assertRedirect();

        $this->actingAs($admin)->post(route('admin.consults.reschedule-request.dismiss', $consult), [])->assertSessionHasErrors('reason');
        $this->actingAs($admin)
            ->post(route('admin.consults.reschedule-request.dismiss', $consult), ['reason' => 'لا يتوفّر موعدٌ آخر هذا الأسبوع'])
            ->assertRedirect();

        $this->assertNull($consult->fresh()->reschedule_requested_at);
        $this->assertStringContainsString(
            'لا يتوفّر موعدٌ آخر هذا الأسبوع',
            (string) UserNotification::where('user_id', $client->id)->latest('id')->value('body')
        );

        // ولا رفضَ لطلبٍ غير موجود
        $this->actingAs($admin)->post(route('admin.consults.reschedule-request.dismiss', $consult), ['reason' => 'x'])->assertStatus(422);
    }

    /** وإعادةُ الجدولة تقضيه — لا يبقى «قيد المعالجة» بعد أن عولج. */
    public function test_rescheduling_fulfils_the_pending_request(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $consult = $this->missedConsult($client, $lawyer);
        $this->actingAs($client)->post(route('consults.reschedule-request', $consult))->assertRedirect();

        $this->actingAs($admin)->post(route('admin.consults.reschedule', $consult), ['reason' => 'client_request'])->assertRedirect();

        $this->assertNull($consult->fresh()->reschedule_requested_at);
    }

    /** غير المالك يُصدّ. */
    public function test_foreign_client_cannot_request_consult_reschedule(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $intruder = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $consult = $this->missedConsult($client, $lawyer);

        $this->actingAs($intruder)->post(route('consults.reschedule-request', $consult))->assertStatus(403);
    }

    // ————— الاجتماع القادم —————

    public function test_client_can_request_meeting_time_change(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $meeting = Meeting::create([
            'user_id' => $client->id, 'ref' => 'M-RR-1', 'title' => 'اجتماع متابعة',
            'when_label' => 'غداً · 10:00', 'starts_at' => now()->addDay()->setTime(10, 0),
            'status' => 'قادم', 'assigned_lawyer_id' => $lawyer->id, 'created_by' => 'الموظف',
        ]);

        $this->actingAs($client)->post(route('meetings.change-request', $meeting))
            ->assertRedirect()->assertSessionHas('flash');

        $this->assertGreaterThan(0, UserNotification::where('user_id', $lawyer->id)->count(), 'المحامي المسند أُشعر');
        $this->assertGreaterThan(0, UserNotification::where('user_id', $admin->id)->count(), 'الإدارة أُشعرت');
    }

    /** اجتماع منتهٍ لا يقبل طلب تغيير الموعد. */
    public function test_ended_meeting_rejects_change_request(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $meeting = Meeting::create([
            'user_id' => $client->id, 'ref' => 'M-RR-2', 'title' => 'منتهٍ',
            'when_label' => 'أمس', 'status' => 'منتهٍ',
        ]);

        $this->actingAs($client)->post(route('meetings.change-request', $meeting))->assertStatus(422);
    }
}
