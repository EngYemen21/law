<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Jobs\EscalateUnassignedTicketJob;
use App\Models\Appointment;
use App\Models\Consult;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsConsultJourney;
use Tests\TestCase;

/**
 * **الحجز بيد الطاقم — فلا «تصعيدَ» يُسنِد الملفّ لإداريّ باسم مستشار.**
 *
 * كان العميل يختار موعده بعد السداد، فإن لم يجد الخادم محامياً متفرّغاً حُجز الموعد وأُسند
 * الملفّ إلى أقدم إداريّ بوسم «الإدارة العليا» (قرار 2026-09-05). ثمّ قرّر المالك (2026-09-14)
 * أنّ **الطاقم يحجز لا العميل**: الموظّف أو الإدارة يختار محامياً فعلياً ووقتاً، فلا ضحيّةَ
 * تُردّ خاويةَ اليدين ولا حاجةَ إلى مالكٍ مؤقّت.
 *
 * فالثوابت هنا: العميل لا يحجز، وتعذّرُ المحامي يُقال للطاقم بصدق فيختار غيره، ولا يُكتب
 * موعدٌ ولا إسنادٌ وهميّ.
 */
class ConsultBookingEscalationTest extends TestCase
{
    use BuildsConsultJourney;
    use RefreshDatabase;

    /** استشارةٌ سُدِّدت وتنتظر أن يحدّد الطاقم موعدها. */
    private function paidConsult(User $client): Consult
    {
        return Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-ESC-'.uniqid(), 'subject' => 'نزاع تجاري',
            'type' => 'تجاري', 'channel' => 'هاتفية', 'status' => 'بانتظار تحديد الموعد',
            'session' => 'بانتظار الجلسة', 'tone' => 'b-amber', 'lawyer' => 'المستشار القانوني',
            'price' => 500, 'vat' => 75, 'total' => 575,
            'priced_at' => now(), 'paid_at' => now(),
        ]);
    }

    /**
     * تاريخٌ ووقتٌ صالحان في المستقبل.
     *
     * @return array{date:string,time:string}
     */
    private function futureSlot(): array
    {
        $at = now()->addDay()->setTime(11, 0);

        return ['date' => $at->toDateString(), 'time' => $at->format('H:i')];
    }

    /** **العميل لا يختار موعده** ولو سدّد — يصله ردٌّ مفهوم، ولا يُكتب شيء. */
    public function test_the_client_cannot_pick_a_slot_even_after_paying(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        $consult = $this->paidConsult($client);

        $this->actingAs($client)
            ->post("/consults/{$consult->id}/schedule", $this->futureSlot())
            ->assertStatus(422);

        $this->assertSame('بانتظار تحديد الموعد', $consult->fresh()->status);
        $this->assertNull($consult->fresh()->starts_at);
        $this->assertSame(0, Appointment::count());
    }

    /** **لا محاميَ متفرّغاً ⇒ رفضٌ صادق للطاقم** — لا موعدٌ ولا إسنادٌ لإداريّ. */
    public function test_staff_booking_without_a_free_lawyer_is_refused_honestly(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        // محامٍ **معطَّل**: لا مرشّح نشط
        User::factory()->create(['role' => Role::Lawyer, 'status' => 'inactive']);
        $consult = $this->paidConsult($client);

        $response = $this->adminPublishes($consult, $this->futureSlot())->assertStatus(422);

        $message = (string) $response->json('errors.lawyer_id.0');
        $this->assertStringContainsString('لا يتوفّر محامٍ', $message);
        $this->assertStringNotContainsString('مختصّ', $message, 'التخصّص ليس السبب');

        $fresh = $consult->fresh();
        $this->assertSame('بانتظار تحديد الموعد', $fresh->status, 'ولا يُحجز نصف حجز');
        $this->assertNull($fresh->assigned_lawyer_id);
        $this->assertNotSame(EscalateUnassignedTicketJob::SENIOR_LABEL, $fresh->lawyer);
        $this->assertSame(0, Appointment::count());
    }

    /** **ولا يصل العميلَ ولا الإدارةَ إشعارُ تصعيدٍ لم يقع.** */
    public function test_a_refused_booking_notifies_nobody(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        User::factory()->create(['role' => Role::Lawyer, 'status' => 'inactive']);
        $consult = $this->paidConsult($client);

        $this->adminPublishes($consult, $this->futureSlot())->assertStatus(422);

        $this->assertSame(0, UserNotification::where('body', 'like', '%وزّعها على مستشار%')->count());
        $this->assertSame(0, UserNotification::where('user_id', $client->id)->count());
    }

    /** **والإسناد العاديّ لم يتغيّر** — محامٍ فعليّ، وموعدٌ منشور للعميل. */
    public function test_a_normal_booking_still_assigns_a_real_lawyer(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        $consult = $this->paidConsult($client);

        $this->adminPublishes($consult, $this->futureSlot())->assertOk();

        $fresh = $consult->fresh();
        $this->assertSame('جديدة', $fresh->status);
        $this->assertSame($lawyer->id, $fresh->assigned_lawyer_id);
        $this->assertNotSame(EscalateUnassignedTicketJob::SENIOR_LABEL, $fresh->lawyer);
        $this->assertSame(0, UserNotification::where('body', 'like', '%وزّعها على مستشار%')->count());
    }

    /**
     * **الصفوف القديمة المصعَّدة** (قبل 2026-09-14) لا يحلّلها أحدٌ حتى توزّعها الإدارة.
     *
     * فالموظّف فقد صلاحيّة التلخيص، والمحامي غير مسنَد. يبقى في يد الإدارة حتى تُسنِده،
     * ثمّ يحلّله المحامي الذي أُسنِد إليه.
     */
    public function test_the_escalated_file_becomes_analyzable_once_distributed(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);

        $consult = $this->paidConsult($client);
        $consult->forceFill([
            'status' => 'جديدة',
            'lawyer' => EscalateUnassignedTicketJob::SENIOR_LABEL,
            'assigned_lawyer_id' => $admin->id,
        ])->save();

        // قبل التوزيع: المحامي لا يبلغه
        $this->actingAs($lawyer)
            ->post(route('lawyer.consults.analyze', $consult))
            ->assertStatus(403);

        // الإدارة توزّعه
        $this->actingAs($admin)
            ->post("/admin/consults/{$consult->id}/refer", ['lawyer_id' => $lawyer->id])
            ->assertRedirect();

        $this->assertSame($lawyer->id, $consult->fresh()->assigned_lawyer_id);
    }
}
