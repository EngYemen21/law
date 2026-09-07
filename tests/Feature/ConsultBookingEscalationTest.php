<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Jobs\EscalateUnassignedTicketJob;
use App\Models\Consult;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **الحجز المتعذّر يُرفع إلى الإدارة — لا يُرفض في وجه من سدّد.**
 *
 * كان `ConsultController::schedule` يرمي خطأ تحقّقٍ حين لا يجد محامياً، وينتهي الأمر:
 * عميلٌ **دفع الفاتورة** ثمّ صُدّ عند اختيار الموعد، **ولا أحد في الإدارة يعلم** أنّ
 * أحداً حاول. لا إشعار، ولا قيد، ولا صفٌّ يُراجَع.
 *
 * ورسالتُه كاذبةٌ فوق ذلك: «لا يوجد مستشار **مختصّ** متاح» — بينما `rankedSpecialists`
 * يسقط إلى **كلّ** المحامين النشطين حين لا يطابق أحدٌ التخصّص
 * (`$pool = $matched->isNotEmpty() ? $matched : $lawyers`). فالتخصّص لا دخل له
 * إطلاقاً؛ السبب أنّ الجميع مشغولون في تلك الساعة — أو أنّه لا محاميَ نشطاً أصلاً،
 * وهي حال قاعدةٍ فيها **محامٍ نشطٌ واحد**.
 *
 * والعلاج يحتذي سابقة `EscalateUnassignedTicketJob` في التذاكر: يُحجز الموعد، ويُسنَد
 * الملفّ إلى أقدم إداريّ بالوسم نفسه، ويُشعَر ليوزّعه.
 */
class ConsultBookingEscalationTest extends TestCase
{
    use RefreshDatabase;

    /** استشارةٌ سُدِّدت وتنتظر اختيار الموعد — نقطةُ العطل بالضبط. */
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
     * @return array{0:string,1:string}
     */
    private function futureSlot(): array
    {
        $at = now()->addDay()->setTime(11, 0);

        return [$at->toDateString(), $at->format('H:i')];
    }

    /** **الحارس الأثمن:** لا محاميَ نشطاً ⇒ **الحجز ينجح** ويُرفع إلى الإدارة. */
    public function test_an_unassignable_booking_escalates_instead_of_failing(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        // محامٍ **معطَّل**: `rankedSpecialists` يقصر على `status = active`، فلا مرشّح
        User::factory()->create(['role' => Role::Lawyer, 'status' => 'inactive']);

        $consult = $this->paidConsult($client);
        [$date, $time] = $this->futureSlot();

        $this->actingAs($client)
            ->post("/consults/{$consult->id}/schedule", ['date' => $date, 'time' => $time])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $fresh = $consult->fresh();

        // الموعد وقع فعلاً — العميل سدّد، فلا يُردّ خاويَ اليدين
        $this->assertSame('جديدة', $fresh->status, 'الاستشارة دخلت الرحلة');
        $this->assertNotNull($fresh->starts_at);
        $this->assertNotNull($fresh->appointment_id);

        // والملفّ صار مملوكاً للإدارة لا معلَّقاً بلا صاحب
        $this->assertSame(EscalateUnassignedTicketJob::SENIOR_LABEL, $fresh->lawyer);
        $this->assertSame($admin->id, $fresh->assigned_lawyer_id);

        // وقُيّد السبب — فلا يبدو إسناداً عادياً بعد شهر
        $this->assertTrue(
            collect($fresh->audit ?? [])->contains(
                fn ($e) => str_contains((string) ($e['after'] ?? ''), 'رُفع إلى الإدارة للتوزيع')
            ),
            'سببُ الإسناد مقيَّد'
        );
    }

    /** **والإدارة تُشعَر** — وإلّا كان التصعيد صفّاً صامتاً في قاعدة البيانات. */
    public function test_every_admin_is_notified_to_distribute_it(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $first = User::factory()->create(['role' => Role::Admin]);
        $second = User::factory()->create(['role' => Role::Admin]);
        User::factory()->create(['role' => Role::Lawyer, 'status' => 'inactive']);

        $consult = $this->paidConsult($client);
        [$date, $time] = $this->futureSlot();

        $this->actingAs($client)->post("/consults/{$consult->id}/schedule", ['date' => $date, 'time' => $time]);

        foreach ([$first, $second] as $admin) {
            $this->assertSame(
                1,
                UserNotification::where('user_id', $admin->id)->where('body', 'like', '%وزّعها على مستشار%')->count(),
                'كلُّ إداريّ يُشعَر'
            );
        }

        // **والعميل لا يُوعَد بما لم يقع:** موعدُه مؤكَّد، ومستشارُه لم يُسنَد بعد
        $this->assertSame(
            1,
            UserNotification::where('user_id', $client->id)->where('body', 'like', '%ويُسنَد مستشارك قبل الجلسة%')->count(),
            'الموعد مؤكَّد والمستشار لا — والفرق يُقال'
        );
    }

    /**
     * **ولا إدارةَ ⇒ يبقى الرفض** — لا يُترك ملفٌّ بلا مالك.
     *
     * والرسالة لا تنسب التعذّر إلى التخصّص: لا مختصّ في القصّة أصلاً.
     */
    public function test_without_any_admin_the_booking_is_refused_with_an_honest_message(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        User::factory()->create(['role' => Role::Lawyer, 'status' => 'inactive']);

        $consult = $this->paidConsult($client);
        [$date, $time] = $this->futureSlot();

        $this->actingAs($client)
            ->post("/consults/{$consult->id}/schedule", ['date' => $date, 'time' => $time])
            ->assertSessionHasErrors('time');

        $message = (string) session('errors')->first('time');
        $this->assertStringNotContainsString('مختصّ', $message, 'التخصّص ليس السبب — والاحتياطيّ يسقط إلى كلّ المحامين');
        $this->assertStringContainsString('لم يعد متاحاً', $message);

        $this->assertSame('بانتظار تحديد الموعد', $consult->fresh()->status, 'ولا يُحجز نصف حجز');
    }

    /** **والإسناد العاديّ لم يتغيّر** — التصعيد استثناءٌ لا مسارٌ جديد. */
    public function test_a_normal_booking_still_assigns_a_real_lawyer(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        User::factory()->create(['role' => Role::Admin]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);

        $consult = $this->paidConsult($client);
        [$date, $time] = $this->futureSlot();

        $this->actingAs($client)
            ->post("/consults/{$consult->id}/schedule", ['date' => $date, 'time' => $time])
            ->assertRedirect();

        $fresh = $consult->fresh();
        $this->assertSame($lawyer->id, $fresh->assigned_lawyer_id);
        $this->assertNotSame(EscalateUnassignedTicketJob::SENIOR_LABEL, $fresh->lawyer);

        // ولا إشعارَ توزيعٍ للإدارة حين لا تصعيد
        $this->assertSame(0, UserNotification::where('body', 'like', '%وزّعها على مستشار%')->count());
    }

    /**
     * **والوصلُ بين الدفعتين:** الملفّ المصعَّد لا يحلّله أحدٌ حتى توزّعه الإدارة.
     *
     * فالموظّف فقد صلاحيّة التلخيص، والمحامي غير مسنَد. وهذا **مقصود**: يبقى في يد
     * الإدارة حتى تُسنِده، ثمّ يحلّله المحامي الذي أُسنِد إليه.
     */
    public function test_the_escalated_file_becomes_analyzable_once_distributed(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);

        $consult = $this->paidConsult($client);
        $consult->forceFill([
            'status' => 'قيد مراجعة الموظف',
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
