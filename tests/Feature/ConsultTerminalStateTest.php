<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * **الاستشارة المنتهية لا تُبعث.**
 *
 * كان كلّ حارسٍ في المتحكّم يمنع **دورة الحجز** وحدها ولا يمنع النهايات. فمن درج
 * الموظّف تُحال استشارةٌ «ملغاة» إلى محامٍ ويصل صاحبَها «أُحيلت استشارتك وسنوافيك
 * بموعد الجلسة» — لطلبٍ ألغاه المكتب وأشعره بإلغائه قبل قليل. وتُعاد جدولتها،
 * ويُعاد تحليلها، ويُطلَب لها مستند.
 *
 * وأسوأها أن **الملغاة كانت قابلةً للبدء**: `cancelRequest` يكتب «ملغاة» ولا يمسّ
 * `session`، والإلغاء لا يقع إلّا قبل الجلسة فـ`starts_at` فارغ — والفرع الذي يسمح
 * بالبدء بلا موعد كان يجعل الزرّ مفعّلاً والخادم يقبله.
 *
 * **ومصفوفةٌ لا حالةٌ واحدة:** ثلاث نهايات × أربعة أفعال. سطحٌ خامس يُضاف بلا حارس
 * يظهر غيابُه هنا.
 */
class ConsultTerminalStateTest extends TestCase
{
    use RefreshDatabase;

    /**
     * **المغلقة وحدها** — و«لم يحضر» ليست منها.
     *
     * وقعتُ في هذا: أدرجتُها أوّلاً فسقط `ConsultNoShowRescheduleTest`. العميل دفع
     * ولم يحضر، ومسارُ إنقاذه **إعادةُ الجدولة** — ومنعُها يُعيد العطل الذي كُتبت
     * تلك الحزمة لإصلاحه: الفائتة تعلق «بانتظار الجلسة» للأبد.
     *
     * @return array<string, array{0:string}>
     */
    public static function terminalStatuses(): array
    {
        return [
            'ملغاة' => ['ملغاة'],
            'منتهية' => ['منتهية'],
        ];
    }

    /** @return array{0:Consult,1:User,2:User} */
    private function terminal(string $status): array
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);

        $consult = Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-TRM-'.uniqid(), 'subject' => 'نزاع تجاري',
            'type' => 'استشارة', 'channel' => 'مرئية', 'status' => $status,
            // الملغاة تحتفظ بـ«بانتظار الجلسة» فعلاً — `cancelRequest` لا يمسّ الجلسة
            'session' => $status === 'منتهية' ? 'منتهية' : 'بانتظار الجلسة',
            'tone' => 'b-red', 'lawyer' => $lawyer->name, 'assigned_lawyer_id' => $lawyer->id,
        ]);

        return [$consult, $lawyer, $client];
    }

    #[DataProvider('terminalStatuses')]
    public function test_a_terminal_consult_cannot_be_started(string $status): void
    {
        [$consult, $lawyer, $client] = $this->terminal($status);

        $this->assertFalse($consult->toCard()['startable'], 'ولا تعرضه البطاقة');

        $this->actingAs($lawyer)
            ->post("/lawyer/consults/{$consult->id}/start")
            ->assertStatus(422);

        $this->assertSame($status, $consult->fresh()->status);
        $this->assertSame(0, UserNotification::where('user_id', $client->id)->count(), 'ولا يُشعَر العميل');
    }

    #[DataProvider('terminalStatuses')]
    public function test_a_terminal_consult_cannot_be_referred(string $status): void
    {
        [$consult, $lawyer, $client] = $this->terminal($status);
        $other = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);

        $this->actingAs($lawyer)
            ->post("/lawyer/consults/{$consult->id}/refer", ['lawyer_id' => $other->id])
            ->assertStatus(422);

        $this->assertSame($status, $consult->fresh()->status);
        $this->assertSame($lawyer->id, $consult->fresh()->assigned_lawyer_id, 'ولا يُبدَّل المحامي');
        $this->assertSame(0, UserNotification::where('user_id', $client->id)->count());
    }

    #[DataProvider('terminalStatuses')]
    public function test_a_terminal_consult_cannot_be_asked_for_documents(string $status): void
    {
        [$consult, $lawyer, $client] = $this->terminal($status);

        $this->actingAs($lawyer)
            ->post("/lawyer/consults/{$consult->id}/reqdocs", ['docs' => 'صورة السجل التجاري'])
            ->assertStatus(422);

        $this->assertSame($status, $consult->fresh()->status);
        $this->assertSame(0, UserNotification::where('user_id', $client->id)->count());
    }

    #[DataProvider('terminalStatuses')]
    public function test_a_terminal_consult_cannot_be_rescheduled(string $status): void
    {
        [$consult, $lawyer, $client] = $this->terminal($status);

        $this->actingAs($lawyer)
            ->post("/lawyer/consults/{$consult->id}/reschedule", ['reason' => 'client_absent'])
            ->assertStatus(422);

        $this->assertSame($status, $consult->fresh()->status);
        $this->assertSame(0, UserNotification::where('user_id', $client->id)->count());
    }

    /**
     * **و«لم يحضر» تتعافى — وهذا نصف العقد.**
     *
     * حارسٌ يمنع الجميع ليس حمايةً بل شللاً. والفائتة لها مسارُ إنقاذٍ مقصود.
     */
    public function test_a_no_show_can_still_be_rescheduled_and_worked_on(): void
    {
        [$consult, $lawyer] = $this->terminal('لم يحضر');
        $consult->update(['session' => 'لم تُعقد']);

        $this->actingAs($lawyer)
            ->post("/lawyer/consults/{$consult->id}/reqdocs", ['docs' => 'صورة الهوية'])
            ->assertRedirect();

        $this->actingAs($lawyer)
            ->post("/lawyer/consults/{$consult->id}/reschedule", ['reason' => 'client_absent'])
            ->assertRedirect();

        $this->assertSame('بانتظار تحديد الموعد', $consult->fresh()->status, 'تعود لاختيار موعد');
    }

    /** لكنّها **لا تُبدأ** — جلستها «لم تُعقد» فلا تُفتح بضغطة. */
    public function test_a_no_show_still_cannot_be_started(): void
    {
        [$consult, $lawyer] = $this->terminal('لم يحضر');
        $consult->update(['session' => 'لم تُعقد']);

        $this->assertFalse($consult->fresh()->toCard()['startable']);
        $this->actingAs($lawyer)->post("/lawyer/consults/{$consult->id}/start")->assertStatus(422);
    }

    /**
     * **ولا تُحال جلسةٌ منعقدة.**
     *
     * كان الحارس يمنع النهايات ودورة الحجز ولا يمنع «قيد الاستشارة» — فزرّ «إعادة
     * إسناد المستشار» في لوحة الإدارة يُرجع الحالة أثناء الجلسة، ويصل صاحبَها إشعارٌ
     * يقول «سنوافيك بموعد الجلسة» وهو فيها الآن.
     */
    public function test_a_running_session_cannot_be_referred_away(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $other = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);

        $consult = Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-RUN-'.uniqid(), 'subject' => 'نزاع تجاري',
            'type' => 'استشارة', 'channel' => 'مرئية', 'status' => 'قيد الاستشارة',
            'session' => 'جلسة جارية', 'tone' => 'b-amber',
            'lawyer' => $lawyer->name, 'assigned_lawyer_id' => $lawyer->id,
        ]);

        $this->actingAs($lawyer)
            ->post("/lawyer/consults/{$consult->id}/refer", ['lawyer_id' => $other->id])
            ->assertStatus(422);

        $fresh = $consult->fresh();
        $this->assertSame('قيد الاستشارة', $fresh->status, 'ولا ترتدّ الحالة للخلف');
        $this->assertSame($lawyer->id, $fresh->assigned_lawyer_id);
        $this->assertSame(0, UserNotification::where('user_id', $client->id)->count(), 'ولا يُشعَر من هو في الجلسة');
    }

    /** **ولا يتحوّل المنع إلى شلل:** ملفٌّ حيّ ما زال يقبل الأفعال. */
    public function test_a_live_consult_still_accepts_the_same_actions(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);

        $consult = Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-LIVE-'.uniqid(), 'subject' => 'نزاع تجاري',
            'type' => 'استشارة', 'channel' => 'مرئية', 'status' => 'جديدة',
            'session' => 'بانتظار الجلسة', 'tone' => 'b-blue',
            'lawyer' => $lawyer->name, 'assigned_lawyer_id' => $lawyer->id,
        ]);

        $this->actingAs($lawyer)
            ->post("/lawyer/consults/{$consult->id}/reqdocs", ['docs' => 'صورة السجل التجاري'])
            ->assertRedirect();

        $this->assertSame('بانتظار استكمال البيانات', $consult->fresh()->status);
    }

    /** **وطلبٌ في دورة الحجز لا يخرج من طابور التسعير** بطلب مستند. */
    public function test_asking_for_documents_never_drops_a_request_out_of_pricing(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);

        $consult = Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-PRE-'.uniqid(), 'subject' => 'نزاع تجاري',
            'type' => 'استشارة', 'channel' => 'مرئية', 'status' => 'بانتظار السداد',
            'session' => 'بانتظار الجلسة', 'tone' => 'b-amber',
            'lawyer' => $lawyer->name, 'assigned_lawyer_id' => $lawyer->id,
        ]);

        $this->actingAs($lawyer)
            ->post("/lawyer/consults/{$consult->id}/reqdocs", ['docs' => 'صورة السجل التجاري'])
            ->assertStatus(422);

        $this->assertContains(
            $consult->fresh()->status,
            Consult::PRE_SESSION_STATUSES,
            'يبقى في الطابور حتى يُسعَّر ويُدفع'
        );
    }
}
