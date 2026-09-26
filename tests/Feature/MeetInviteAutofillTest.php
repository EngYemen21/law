<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\LegalCase;
use App\Models\Meeting;
use App\Models\MeetRequest;
use App\Models\Ticket;
use App\Models\User;
use App\Support\ClientDirectory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * نافذة دعوة الاجتماع: الموضوع يُملأ من عنوان الملفّ في القاعدة، والموعد يقبل دقيقةً
 * مخصّصة خارج الفترات الجاهزة، والدعوة تمرّ ببوّابة موافقة الإدارة قبل أن يعلم بها العميل.
 *
 * كان حقل «الموضوع/الخدمة» يُكتب يدوياً وإن كان عنوان الملفّ مسجّلاً في القاعدة، وكان
 * منتقي الوقت مقصوراً على فتراتٍ كلَّ نصف ساعة (09:00–20:30) رغم أنّ الخادم يقبل أيّ
 * وقتٍ صالح ويحكم عليه بحارسَيه: لا موعد ماضٍ، ولا تعارض مع حجوزات المحامي.
 */
class MeetInviteAutofillTest extends TestCase
{
    use RefreshDatabase;

    // ————— ١ · دليل العملاء يحمل موضوع كلّ ملفّ —————

    public function test_client_directory_carries_each_file_subject(): void
    {
        $client = User::factory()->create(['role' => Role::Client, 'name' => 'شركة الأفق']);

        $ticket = Ticket::create([
            'user_id' => $client->id, 'number' => 'TK-2026-001',
            'type' => 'استشارة', 'subject' => 'نزاع على بند التسليم في عقد التوريد',
            'status' => 'جديدة',
        ]);
        LegalCase::create([
            'user_id' => $client->id, 'ticket_id' => $ticket->id,
            'number' => 'CS-2026-001', 'type' => 'تجارية', 'status' => 'جارية',
        ]);
        Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-2026-001',
            'subject' => 'مراجعة عقد شراكة', 'type' => 'استشارة',
            'channel' => 'مرئية', 'status' => 'بانتظار التسعير',
            'session' => 'بانتظار الجلسة', 'tone' => 'b-amber', 'lawyer' => 'مستشار',
        ]);

        $items = collect(ClientDirectory::list())->firstWhere('id', $client->id)['items'];
        $byRef = collect($items)->keyBy('ref');

        $this->assertSame('نزاع على بند التسليم في عقد التوريد', $byRef['TK-2026-001']['subject']);
        // القضية بلا عمود موضوع — موضوعها موضوع تذكرتها
        $this->assertSame('نزاع على بند التسليم في عقد التوريد', $byRef['CS-2026-001']['subject']);
        $this->assertSame('مراجعة عقد شراكة', $byRef['CN-2026-001']['subject']);

        // النصّ المعروض يبقى كما كان: «المرجع — النوع»
        $this->assertSame('CS-2026-001 — قضية', $byRef['CS-2026-001']['label']);
    }

    public function test_subject_is_null_when_the_file_has_none(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        // عمود موضوع التذكرة يقبل الفراغ فعلاً (بخلاف الاستشارة والقضية)
        Ticket::create([
            'user_id' => $client->id, 'number' => 'TK-2026-777',
            'type' => 'استشارة', 'subject' => null, 'status' => 'جديدة',
        ]);

        $items = collect(ClientDirectory::list())->firstWhere('id', $client->id)['items'];

        // null لا نصّاً مختلقاً: الحقل يبقى فارغاً للكتابة بدل أن يُملأ بما لا مصدر له
        $this->assertNull(collect($items)->firstWhere('ref', 'TK-2026-777')['subject']);
    }

    public function test_the_modal_fills_the_service_without_overwriting_typing(): void
    {
        $ui = file_get_contents(resource_path('js/lib/meeting-ui.tsx'));

        // التعبئة من موضوع الخيار المختار
        $this->assertStringContainsString('caseOptions.find((o) => o.label === label)?.subject', $ui);
        // ولا تطمس ما كتبه المستخدم بيده
        $this->assertStringContainsString('if (subject && !serviceTyped) {', $ui);
        // والوقت المخصّص مفعَّل في نافذة الدعوة (سِمةٌ بلا قيمة = true) بعد أن كان allowCustom={false}
        $lines = array_map('trim', explode(chr(10), str_replace(chr(13), '', $ui)));
        $this->assertContains('allowCustom', $lines, 'الوقت المخصّص ما زال معطّلاً في نافذة الدعوة');
    }

    // ————— ٢ · الرحلة: دعوةٌ بوقتٍ مخصّص ثمّ موافقة الإدارة —————

    public function test_invite_with_a_custom_minute_is_published_after_admin_approval(): void
    {
        $client = User::factory()->create(['role' => Role::Client, 'name' => 'شركة الأفق']);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'name' => 'سارة القحطاني']);
        $employee = User::factory()->create(['role' => Role::Employee, 'name' => 'خالد العتيبي']);
        $admin = User::factory()->create(['role' => Role::Admin]);

        $day = now()->addWeek()->format('Y-m-d');

        // 11:07 ليست من الفترات الجاهزة (كلّ نصف ساعة من 09:00) — وقتٌ مخصّص بدقّة الدقيقة
        $this->actingAs($employee)->post(route('employee.meetreqs.store'), [
            'client_id' => $client->id, 'lawyer_id' => $lawyer->id,
            'type' => 'استشارة مرئية', 'service' => 'نزاع على بند التسليم',
            'case_ref' => 'CS-2026-001 — قضية',
            'day' => $day, 'time' => '11:07', 'duration' => 45,
        ])->assertRedirect();

        $req = MeetRequest::firstOrFail();
        $this->assertSame('11:07', $req->time);
        $this->assertSame($lawyer->id, $req->assigned_lawyer_id);

        // بوّابة النشر: لا اجتماع ولا شيء يبلغ العميل قبل موافقة الإدارة
        $this->assertSame(MeetRequest::STAGE_SENT, $req->stage);
        $this->assertSame(0, Meeting::count());
        $this->actingAs($client)->get('/meetings')
            ->assertInertia(fn ($p) => $p->component('meetings')->has('meetings', 0));

        // موافقة الإدارة تُنشئ الاجتماع وتنشره
        $this->actingAs($admin)->post(route('admin.meetreqs.approve', $req))->assertRedirect();

        $meeting = Meeting::firstOrFail();
        $this->assertSame($client->id, $meeting->user_id);
        $this->assertSame($lawyer->id, $meeting->assigned_lawyer_id);
        // لا مدّة تُكتب للاجتماع — ينتهي حين يُنهى (قرار المالك 2026-09-26)
        $this->assertNull($meeting->dur);
        $this->assertStringContainsString('11:07', (string) $meeting->when_label);
        $this->assertSame('11:07', $meeting->starts_at?->format('H:i'));
        $this->assertSame('قادم', $meeting->status);

        // ويصل العميل الآن — لا قبل ذلك
        $this->actingAs($client)->get('/meetings')
            ->assertInertia(fn ($p) => $p->component('meetings')->has('meetings', 1));

        // والمُرسِل يُشعَر باعتماد دعوته
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $employee->id,
            'body' => "وافقت الإدارة على دعوة الاجتماع ({$req->ref}) ونُشرت للعميل.",
        ]);
    }

    public function test_a_custom_minute_that_collides_with_a_booking_is_refused(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $day = now()->addWeek()->format('Y-m-d');

        $payload = [
            'client_id' => $client->id, 'lawyer_id' => $lawyer->id,
            'type' => 'استشارة مرئية', 'service' => 'أولى',
            'day' => $day, 'time' => '11:00', 'duration' => 60,
        ];
        $this->actingAs($employee)->post(route('employee.meetreqs.store'), $payload)->assertRedirect();

        // 11:07 تقع داخل الحجز السابق (11:00–12:00) — الوقت المخصّص لا يلتفّ على الحارس
        $this->actingAs($employee)->post(route('employee.meetreqs.store'), array_merge($payload, [
            'time' => '11:07', 'service' => 'ثانية',
        ]))->assertSessionHasErrors('time');

        $this->assertSame(1, MeetRequest::count());
    }

    // ————— ٣ · الشاشة تعترف بالموافقة —————

    public function test_an_approved_invitation_leaves_the_pending_stage(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $admin = User::factory()->create(['role' => Role::Admin]);

        $this->actingAs($employee)->post(route('employee.meetreqs.store'), [
            'client_id' => $client->id, 'lawyer_id' => $lawyer->id,
            'type' => 'استشارة مرئية', 'service' => 'تحقّق الموافقة',
            'day' => now()->addWeek()->format('Y-m-d'), 'time' => '13:00', 'duration' => 60,
        ])->assertRedirect();

        $req = MeetRequest::firstOrFail();
        $this->assertSame(MeetRequest::STAGE_SENT, $req->stage);

        $this->actingAs($admin)->post(route('admin.meetreqs.approve', $req))->assertRedirect();

        // **المرحلة تغادر «المعلّقة»** — والشاشة كانت تبتلعها بشرط `stage < 2`
        $req->refresh();
        $this->assertSame(MeetRequest::STAGE_CONFIRMED, $req->stage);
        $this->assertNotSame(MeetRequest::STAGE_SENT, $req->stage);

        // والموافقة الثانية مرفوضة — فالزرّ لا يجوز أن يبقى معروضاً
        $this->actingAs($admin)->post(route('admin.meetreqs.approve', $req))->assertStatus(422);
    }

    public function test_the_screen_shows_a_published_invitation_as_published(): void
    {
        $ui = file_get_contents(resource_path('js/lib/meeting-ui.tsx'));

        // المرحلة ١ لها فرعُها: نجاحٌ لا انتظار
        $this->assertStringNotContainsString(') : r.stage < 2 ? (', $ui, 'المرحلة ١ ما زالت تُعرض «بانتظار موافقة الإدارة»');
        $this->assertStringContainsString(') : r.stage === 1 ? (', $ui);
        $this->assertStringContainsString('<Badge text={MR_FLOW[1]} tone="b-green" />', $ui);

        // والرفض يُسمَع: كان يسقط صامتاً فتُنقر الموافقة مرّتين بلا أثر
        $this->assertStringContainsString("onError: (e) => toast(Object.values(e)[0] ?? 'الموافقة متاحة للدعوات المعلّقة فقط')", $ui);
    }
}
