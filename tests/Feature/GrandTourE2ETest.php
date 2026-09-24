<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\TicketOutcomeTrack;
use App\Enums\Role;
use App\Jobs\GenerateTicketReplyJob;
use App\Models\Execution;
use App\Models\Invoice;
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Models\User;
use App\Support\CaseFee;
use App\Support\ExecFee;
use App\Support\TicketJourney;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * الجولة الكبرى E2E — سيناريو واحد متصل عبر الأدوار الأربعة (عميل/موظف/محامٍ/إدارة):
 * تذكرة (المراحل + كل أزرار المحادثة) ← قضية (أتعاب/سداد/لائحة/جلسات/حكم) ← تنفيذ الحكم،
 * ثم تدفّق التنفيذ العشري كاملاً. تكمّل الحزم التفصيلية القائمة برحلة واحدة غير مقطوعة.
 */
class GrandTourE2ETest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:User,1:User,2:User,3:User} client, employee, lawyer, admin */
    private function roles(): array
    {
        $this->seed(PermissionSeeder::class);
        $client = User::factory()->create(['role' => Role::Client]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $employee->syncPermissions(Permission::all());
        // القسم مطابق لقسم التذكرة: الإسناد الأوّل يشترط التخصّص الآن، وبلا متخصّص يُصعَّد
        // الأمر عبر وظيفة مطابورة — وQueue::fake() في هذا المسار يمنعها. موضوع هذا الملفّ
        // الرحلة الكاملة لا سياسة الإسناد (LawyerAssignmentPolicyTest يغطّيها).
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'department' => 'القسم التجاري']);
        $lawyer->syncPermissions(Permission::all());
        $admin = User::factory()->create(['role' => Role::Admin]);

        return [$client, $employee, $lawyer, $admin];
    }

    public function test_ticket_stages_and_chat_buttons_then_case_to_execution(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        Queue::fake(); // مهام AI الخلفية خارج نطاق هذا المسار (يغطّيها AiResilienceTest وتحقق حي سابق)
        [$client, $employee, $lawyer, $admin] = $this->roles();

        // ── المرحلة 1: العميل يفتح تذكرة بمرفق (زر الإنشاء) ──
        $this->actingAs($client)->post(route('tickets.store'), [
            'type' => 'نزاع تجاري', 'department' => 'القسم التجاري',
            'details' => 'نزاع توريد بضاعة بقيمة 150000 ريال.',
            'opponent_name' => 'شركة الخليج', 'claim_amount' => 150000,
            'court_name' => 'المحكمة التجارية',
            'files' => [UploadedFile::fake()->create('contract.pdf', 300, 'application/pdf')],
        ])->assertRedirect();
        $ticket = Ticket::where('user_id', $client->id)->firstOrFail();
        // الفتح لا يُسنِد ولا يُحيل (قرار المالك 2026-09-20): التذكرة تنتظر قرار الطاقم، ويقع
        // إسنادها في المرحلة 3 أدناه بزرّ التحويل عند الموظّف — يغطّي السياسةَ LawyerAssignmentPolicyTest
        $this->assertSame('قيد التحليل', $ticket->status);
        $this->assertNull($ticket->assigned_lawyer_id);
        $this->assertLessThan(TicketJourney::indexOf('مكتملة'), TicketJourney::indexOf($ticket->status));

        // العميل يفتح المحادثة ويرسل رسالة (زر الإرسال) — وردّ AI مُسلَّم للطابور
        $this->actingAs($client)->get(route('tickets.show', $ticket))
            ->assertInertia(fn ($p) => $p->component('ticketchat')->where('ticket.no', $ticket->number));
        $this->actingAs($client)->post(route('tickets.messages.store', $ticket), ['body' => 'أرجو الإفادة بالرأي.'])->assertSuccessful();
        Queue::assertPushed(GenerateTicketReplyJob::class);

        // ── المرحلة 2: أزرار الموظف — ردّ / ملاحظة داخلية / طلب مستندات ──
        $this->actingAs($employee)->post(route('employee.tickets.reply', $ticket), ['body' => 'استلمنا طلبكم.'])->assertSuccessful();
        $this->actingAs($employee)->post(route('employee.tickets.note', $ticket), ['body' => 'العقد يحتاج تدقيقاً.'])->assertSuccessful();
        $this->actingAs($employee)->post(route('employee.tickets.reqdocs', $ticket), ['docs' => ['السجل التجاري']])->assertSuccessful();
        $this->assertSame('بانتظار مستندات', $ticket->fresh()->status);

        // العميل يرفع الناقص (زر الإرفاق) — والملاحظة الداخلية لا تظهر له
        $this->actingAs($client)->post(route('tickets.attach', $ticket), [
            'file' => UploadedFile::fake()->create('cr.pdf', 100, 'application/pdf'),
        ])->assertSuccessful();
        $this->actingAs($client)->get(route('tickets.show', $ticket))
            ->assertInertia(fn ($p) => $p->where('messages', fn ($msgs) => collect($msgs)->every(fn ($m) => $m['who'] !== 'note')));

        // ── المرحلة 3: تحويل الموظف للمستشار (زر التحويل) ثم أزرار المحامي ──
        $this->actingAs($employee)->post(route('employee.transfer.do', $ticket), [
            'department' => 'القسم التجاري', 'lawyer_id' => $lawyer->id,
        ])->assertRedirect();
        $ticket->refresh();
        $this->assertSame($lawyer->id, $ticket->assigned_lawyer_id);

        $this->actingAs($lawyer)->post(route('lawyer.tickets.reply', $ticket), ['body' => 'درست ملفكم وسنوافيكم.'])->assertSuccessful();
        $this->actingAs($lawyer)->post(route('lawyer.tickets.note', $ticket), ['body' => 'الشرط الجزائي قابل للطعن.'])->assertSuccessful();
        foreach (['client' => 'استلمنا طلبكم.', 'staff' => 'درست ملفكم وسنوافيكم.', 'note' => 'الشرط الجزائي قابل للطعن.'] as $who => $body) {
            $this->assertTrue($ticket->messages()->where('body', 'like', "%{$body}%")->exists(), "رسالة {$who} مفقودة");
        }

        // اكتمال الدراسة ثم اعتماد الإدارة العليا لمسار القضية عبر حوكمة المسارات
        $ticket->update(['status' => 'مكتملة', 'tone' => 'b-green']);
        $this->actingAs($admin)->post(route('admin.tickets.track.approve', $ticket), [
            'track' => TicketOutcomeTrack::Case->value,
            'reason' => 'اعتماد الإدارة العليا لتحويل التذكرة إلى قضية رسمية مباشرة.',
        ])->assertRedirect();
        $case = LegalCase::where('ticket_id', $ticket->id)->firstOrFail();
        $this->assertSame('بانتظار اعتماد الأتعاب', $case->status);

        // ── المرحلة 4: القضية — أتعاب الإدارة ← سداد العميل ← اللائحة ← منظورة ──
        $this->actingAs($client)->get(route('cases'))->assertInertia(fn ($p) => $p->has('cases', 1));

        $this->actingAs($admin)->post(route('admin.cases.fee', $case), ['fee' => 10000, 'lawyer_pct' => 20])->assertRedirect();
        $case->refresh();
        $this->assertSame('بانتظار سداد الأتعاب', $case->status);
        $this->assertTrue(Invoice::where('case_id', $case->id)->exists()); // فاتورة الأتعاب صدرت للعميل

        CaseFee::markPaid($case->fresh()); // سداد العميل (تسوية البوّابة تحاكى كما في CaseConversionTest)
        $case->refresh();
        $this->assertSame('قيد التحضير', $case->status);
        $this->assertTrue((bool) Invoice::where('case_id', $case->id)->first()->paid);

        // اللائحة جاهزة لاعتماد المحامي (توليدها الخلفي خارج النطاق مع Queue::fake) → اعتماد → منظورة
        $case->update(['pleading_status' => 'pending_lawyer']);
        // الاعتماد يُطلق المسودّة فيشترط وجودها (توليدها الخلفيّ مُعطَّل بـQueue::fake)
        $case->messages()->create(['who' => 'ai', 'name' => 'المساعد القانوني', 'role' => 'مسودة اللائحة', 'body' => 'نصّ المسودّة', 'withheld_at' => now()]);
        $this->actingAs($lawyer)->post(route('lawyer.cases.pleading', $case))->assertRedirect();
        $this->assertSame('قيد التحضير', $case->fresh()->status, 'الاعتماد يقفل النصّ ولا يرفع الدعوى');

        // رفع الصحيفة في ناجز ⇐ «بانتظار القيد»، ثمّ القيد ⇐ «منظورة» وتُجدوَل الجلسة الأولى
        $this->actingAs($lawyer)->post(route('lawyer.cases.najiz.file', $case), ['request_no' => 'NJ-REQ-0001', 'filed_at' => now()->toDateString()])->assertRedirect();
        $this->assertSame('بانتظار القيد', $case->fresh()->status);
        $this->actingAs($lawyer)->post(route('lawyer.cases.najiz.register', $case), [
            'case_no' => '4700123456', 'court' => 'المحكمة التجارية بالرياض', 'circuit' => 'الدائرة التجارية الأولى',
            'registered_at' => now()->toDateString(), 'hearing_day' => now()->addDays(10)->toDateString(),
            'hearing_time' => '09:00', 'hearing_mode' => 'حضورية',
        ])->assertRedirect();
        $this->assertSame('منظورة', $case->fresh()->status);

        // ── المرحلة 5: الجلسات — جدولة ← انعقاد ← حكم ──
        $day = now()->addWeek()->format('Y-m-d');
        $this->actingAs($lawyer)->post(route('lawyer.cases.hearings.add', $case), [
            'title' => 'الجلسة الأولى', 'day' => $day, 'time' => '10:00', 'court' => 'المحكمة التجارية',
        ])->assertRedirect();
        $hearing = $case->hearings()->firstOrFail();
        // «الجلسة القادمة» الحية تظهر للعميل في بطاقة القضية
        $this->assertNotSame('—', $case->fresh()->toCard()['next']);
        $this->actingAs($client)->get(route('cases.show', $case))->assertOk();

        $this->actingAs($lawyer)->post(route('lawyer.cases.hearings.record', [$case, $hearing]), [
            'status' => 'منعقدة', 'outcome' => 'تبودلت المذكرات وحُجزت للحكم.',
        ])->assertRedirect();
        $this->assertSame('منعقدة', $hearing->fresh()->status);

        $this->actingAs($lawyer)->post(route('lawyer.cases.ruling', $case), ['ruling' => 'إلزام المدّعى عليه بالمبلغ.'])->assertRedirect();
        $this->assertSame('صدر الحكم', $case->fresh()->status);

        // ── المرحلة 6: زر «تحويل لتنفيذ» بعد الحكم ثم إغلاق الإدارة ──
        $this->actingAs($lawyer)->post(route('lawyer.cases.execute', $case))->assertRedirect();
        $exec = Execution::where('case_id', $case->id)->firstOrFail();
        $this->assertSame($lawyer->id, $exec->assigned_lawyer_id);
        $this->actingAs($client)->get(route('execs'))->assertInertia(fn ($p) => $p->has('execs', 1));

        $this->actingAs($admin)->post(route('admin.cases.close', $case))->assertRedirect();
        $this->assertSame('مغلقة', $case->fresh()->status);
    }

    public function test_execution_flow_ten_stages_across_all_roles(): void
    {
        // تدفّق التنفيذ العشري كاملاً بلا Queue::fake. كان يمضي على الاحتياط الحتميّ،
        // والاحتياط لم يعد يرفع المرحلة (قالب لا يفحص مستنداً لا يقرّر تقدّم طلب) —
        // فيُحاكى تحليل فعليّ ناجح ليختبر التدفّق مساره الحقيقيّ.
        [$client, $employee, $lawyer, $admin] = $this->roles();
        config(['services.gemini.key' => 'test-key', 'services.glm.key' => '']);
        Cache::flush();
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => json_encode([
                    'summary' => 'سند تنفيذيّ مستوفٍ بعد فحص المرفقات.',
                    'missing' => [],
                    'procedures' => ['تقديم طلب تنفيذ إلكتروني'],
                ], JSON_UNESCAPED_UNICODE)]]]]],
            ], 200),
        ]);

        // 1-2: العميل يقدّم ← التحليل يكتمل ← قيد الدراسة
        $this->actingAs($client)->post('/exec-flow', [
            'sanad' => 'شيك', 'subject' => 'تحصيل شيك مرتجع', 'defendant' => 'مؤسسة الرمال', 'amount' => 85000,
        ])->assertRedirect();
        $exec = Execution::where('user_id', $client->id)->firstOrFail();
        $this->assertSame(2, $exec->stage);

        // محادثة التبويب الموحّد: رسالة العميل + ردّ الموظف — بلا ردّ AI تلقائي على رسائل العميل
        // (رسالة «تحليل» AI الأولى عند التقديم مقصودة؛ العدّ يثبت ألا ردود آلية جديدة بعدها)
        $aiBefore = $exec->messages()->where('who', 'ai')->count();
        $this->actingAs($client)->post(route('exec-flow.messages.store', $exec), ['body' => 'ما المستجدات؟'])->assertNoContent();
        $this->actingAs($employee)->post(route('exec-flow.messages.store', $exec), ['body' => 'نتابع طلبكم.'])->assertNoContent();
        $this->assertSame($aiBefore, $exec->messages()->where('who', 'ai')->count());

        // 3-5: المحامي يلتقط ويسعّر ← الإدارة تعتمد وتُرسل العرض
        $act = fn (User $actor, string $action, array $payload = []) => $this->actingAs($actor)
            ->post(route('exec-flow.act', $exec), array_merge(['action' => $action], $payload))->assertRedirect();

        $act($lawyer, 'accept');
        $this->assertSame(3, $exec->fresh()->stage);
        $act($lawyer, 'saveFee', ['fee' => 6000, 'duration' => '30 يوم', 'payMethod' => 'دفعة واحدة']);
        $this->assertSame(4, $exec->fresh()->stage);
        $act($admin, 'approveFee');
        $this->assertSame(5, $exec->fresh()->stage);

        // 6-7: العميل يقبل العرض ← فاتورة ← السداد يفتح الملفّ لدى المكتب (والرفع في ناجز يليه)
        $act($client, 'acceptOffer');
        $this->assertSame(6, $exec->fresh()->stage);
        $this->assertSame(6900, Invoice::where('exec_id', $exec->id)->firstOrFail()->amount);

        ExecFee::settleInvoice($exec->fresh()); // تسوية بوّابة ميسّر تُحاكى كما في ExecFlowTest
        $exec->refresh();
        $this->assertSame(7, $exec->stage); // «بانتظار الرفع في ناجز» — لا يقفز إلى «قيد التنفيذ»
        $this->assertNotEmpty($exec->exec_no);

        // 9: المحامي يوثّق إجراءً ← الإدارة تغلق الملف بسببه
        $act($lawyer, 'addProcedure', ['title' => 'حجز تحفظي على الحسابات']);
        $this->assertSame(2, $exec->fresh()->procedures()->count());
        $act($admin, 'close', ['reason' => 'سداد كامل']);
        $this->assertSame(9, $exec->fresh()->stage);
        $this->assertSame('سداد كامل', $exec->fresh()->closed_reason);

        // الرؤية الختامية: كل دور يرى الملف في تبويبه الموحّد
        $this->actingAs($client)->get(route('execs'))->assertInertia(fn ($p) => $p->has('execs', 1));
        $this->actingAs($lawyer)->get(route('lawyer.execs'))->assertInertia(fn ($p) => $p->has('execs', 1));
        $this->actingAs($employee)->get(route('employee.execs'))->assertInertia(fn ($p) => $p->has('execs', 1));
        $this->actingAs($admin)->get(route('admin.execs'))->assertInertia(fn ($p) => $p->has('execs', 1));
    }
}
