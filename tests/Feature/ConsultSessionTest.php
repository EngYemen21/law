<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * تحقّق من رحلة جلسة الاستشارة: استشاراتي → استقبال الاستشارات → بدء → إنهاء + ملخص.
 */
class ConsultSessionTest extends TestCase
{
    use RefreshDatabase;

    private function makeConsult(User $client, array $extra = []): Consult
    {
        return Consult::create(array_merge([
            'user_id' => $client->id,
            'ref' => 'CN-2026-5001',
            'subject' => 'نزاع تجاري مع مورّد',
            'channel' => 'مرئية',
            'lawyer' => 'أ. سارة القحطاني',
            'day' => 'الاثنين 29 يونيو',
            'time' => '11:30 ص',
            'when_label' => 'الاثنين 29 يونيو · 11:30 ص',
            'price' => 450, 'vat' => 68, 'total' => 518,
        ], $extra));
    }

    public function test_client_sees_own_consults_in_myconsults(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $other = User::factory()->create(['role' => Role::Client]);
        $this->makeConsult($client);
        $this->makeConsult($other, ['ref' => 'CN-2026-5002']);

        $this->actingAs($client)->get(route('myconsults'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('myconsults')
                ->has('consults', 1)
                ->where('consults.0.ref', 'CN-2026-5001')
                ->where('consults.0.slink', url('/consults/room?ref=CN-2026-5001')));
    }

    public function test_recv_scoping_isolates_by_role(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        // استشارة بلا فرع (مجمّع الاستقبال) مُسندة لهذا المحامي
        $this->makeConsult($client, ['assigned_lawyer_id' => $lawyer->id]);

        // الموظف يرى استشارة بلا فرع (المجمّع المشترك)
        $employee = User::factory()->create(['role' => Role::Employee]);
        $this->actingAs($employee)->get('/employee/consultrecv')
            ->assertOk()->assertInertia(fn ($p) => $p->has('consults', 1));

        // الإدارة ترى الكل
        $admin = User::factory()->create(['role' => Role::Admin]);
        $this->actingAs($admin)->get('/admin/consultrecv')
            ->assertOk()->assertInertia(fn ($p) => $p->has('consults', 1));

        // المحامي المسند يراها؛ محامٍ آخر لا يرى شيئاً (عزل الإسناد)
        $this->actingAs($lawyer)->get('/lawyer/consultrecv')
            ->assertOk()->assertInertia(fn ($p) => $p->has('consults', 1));
        $other = User::factory()->create(['role' => Role::Lawyer]);
        $this->actingAs($other)->get('/lawyer/consultrecv')
            ->assertOk()->assertInertia(fn ($p) => $p->has('consults', 0));
    }

    public function test_employee_starts_session_and_client_is_notified(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $consult = $this->makeConsult($client);

        $this->actingAs($employee)->post(route('employee.consults.start', $consult))
            ->assertRedirect();

        $consult->refresh();
        $this->assertSame('جلسة جارية', $consult->session);
        $this->assertSame('قيد الاستشارة', $consult->status);
        $this->assertSame(1, UserNotification::where('user_id', $client->id)->count());
    }

    public function test_lawyer_ends_session_generates_summary_and_notifies(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $consult = $this->makeConsult($client, ['session' => 'جلسة جارية', 'status' => 'قيد الاستشارة', 'assigned_lawyer_id' => $lawyer->id]);

        $this->actingAs($lawyer)->post(route('lawyer.consults.end', $consult), [
            'notes' => 'العميل زوّدنا بعقد التوريد والمراسلات.',
            'duration' => '05:32',
        ])->assertRedirect();

        $consult->refresh();
        $this->assertSame('منتهية', $consult->session);
        $this->assertSame('منتهية', $consult->status);
        $this->assertSame('05:32', $consult->duration_label);
        $this->assertSame('العميل زوّدنا بعقد التوريد والمراسلات.', $consult->session_notes);
        // الملخص القالبي (بلا مفاتيح AI في الاختبارات) يتضمن الموضوع والملاحظات
        $this->assertStringContainsString('ملخص استشارة — CN-2026-5001', $consult->summary);
        $this->assertStringContainsString('نزاع تجاري مع مورّد', $consult->summary);
        $this->assertStringContainsString('عقد التوريد', $consult->summary);
        $this->assertSame(1, UserNotification::where('user_id', $client->id)->count());
    }

    public function test_ending_twice_does_not_duplicate_notifications(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $consult = $this->makeConsult($client, ['session' => 'جلسة جارية']);

        // إشعار الختم يقع مرّةً واحدة ولا يتبع نجاح التوليد — انتهاء الجلسة
        // واقعةٌ تخصّ العميل سواء كُتب الملخّص أم انتظر تدوين المستشار.
        $this->actingAs($employee)->post(route('employee.consults.end', $consult))->assertRedirect();
        $this->actingAs($employee)->post(route('employee.consults.end', $consult))->assertRedirect();

        $this->assertSame(1, UserNotification::where('user_id', $client->id)->count());
    }

    public function test_client_cannot_start_or_end_sessions(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $consult = $this->makeConsult($client);

        // حاجز الدور يعيد توجيه غير المخوّل بعيداً دون تنفيذ الإجراء
        $this->actingAs($client)->post("/employee/consults/{$consult->id}/start")->assertRedirect();
        $this->actingAs($client)->post("/lawyer/consults/{$consult->id}/end")->assertRedirect();
        $this->assertSame('بانتظار الجلسة', $consult->fresh()->session);
        $this->assertNull($consult->fresh()->summary);
    }

    // بلا مفاتيح Zoom في الاختبارات: تُعيد البطاقة الرابط الداخلي الاحتياطي
    public function test_card_falls_back_to_internal_link_without_zoom(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $consult = $this->makeConsult($client);

        $card = $consult->toCard();
        // التوجيه الداخلي الإلزامي: العميل يدخل عبر غرفة المنصة لا عبر رابط خارجي
        $this->assertSame(url('/consults/room?ref=CN-2026-5001'), $card['slink']);
        $this->assertArrayNotHasKey('hostLink', $card);

        // وحتى مع رابط Zoom محفوظ: دخول العميل يبقى عبر غرفة المنصة حصراً (لا روابط خارجية تخرج عن المنصة)
        $consult->update([
            'meet_link' => 'https://zoom.us/j/123456789',
            'host_link' => 'https://zoom.us/s/123456789?zak=abc',
        ]);
        $card = $consult->fresh()->toCard();
        $this->assertSame(url('/consults/room?ref=CN-2026-5001'), $card['slink']);
        // ولا رابط المضيف الخارجيّ للطاقم أيضاً — الدخول من غرفة المنصّة وحدها (قرار المالك 2026-09-29)
        $this->assertArrayNotHasKey('hostLink', $card);
    }

    public function test_staff_video_room_receives_consult_by_ref(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $consult = $this->makeConsult($client);

        $this->actingAs($employee)->get('/employee/videoroom?ref='.$consult->ref)
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('employee/videoroom')
                ->where('consult.ref', $consult->ref)
                ->where('selfName', $employee->name));
    }
}
