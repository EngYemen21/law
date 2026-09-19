<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Jobs\EscalateUnassignedTicketJob;
use App\Mail\TicketEscalatedMail;
use App\Models\Ticket;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\MailService;
use App\Support\TicketAssignment;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * سياسة إسناد المحامي — حتميّة بالكامل، وتصعيد بدل إسناد خاطئ صامت.
 *
 * كان `pickLawyer` يرشّح بالتخصّص ثم **يسقط على كل المحامين** إن لم يتطابق قسم، فيُسنِد الأقلّ
 * حملاً: تذكرة عمالية تُسنَد لمحامي عقارات بلا أن يعلم أحد. ثم يُسأل الـAI «اختر الأنسب تخصّصاً»
 * من مسبح مُرشَّح بالتخصّص سلفاً — بكلفة تصل 150 ثانية (‏WebTimeLimit::raise) وقيمة تقارب الصفر.
 *
 * القرار: الاختيار حتميّ (تخصّص ← أقلّ حملاً ← أقدم)، وبلا متخصّص تبقى التذكرة بلا محامٍ
 * ويُصعَّد الأمر للإدارة ولحاملي «توزيع التذاكر» ليُسنِدوا بوعي.
 *
 * **وقرار المالك 2026-09-20 ألغى الإسناد التلقائيّ عند الفتح:** التذكرة تُفتح بلا محامٍ، والإسناد
 * بيد الموظّف («تحويل التذاكر») أو الإدارة («توزيع التذاكر»). والسياسة أعلاه صارت سياسةَ اختيارٍ
 * لمن ينادي `pickLawyer` (زرّ التوزيع الجماعيّ)، والتصعيد يقع بالكنس المجدول بعد مهلة الإعدادات.
 */
class LawyerAssignmentPolicyTest extends TestCase
{
    use RefreshDatabase;

    private const DEPT = 'القضايا العمالية';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    private function lawyer(string $dept, int $id = 0): User
    {
        return User::factory()->create([
            'role' => Role::Lawyer, 'status' => 'active',
            'distribution_mode' => 'auto', 'department' => $dept,
        ]);
    }

    private function openTicket(User $client): Ticket
    {
        $this->actingAs($client)->post('/tickets', [
            'type' => 'نزاع عمالي',
            'department' => self::DEPT,
            'details' => 'تفاصيل الطلب',
        ])->assertRedirect();

        return Ticket::firstOrFail();
    }

    /**
     * ⚠️ حارس الانحدار الأهمّ: أي نداء AI يعود إلى مسار الإسناد يُسقط هذا الاختبار.
     * `Http::fake()` يمنع أي طلب خارجي فعليّ، والتوكيد يُثبت أنه لم يُحاوَل أصلاً.
     */
    public function test_assignment_makes_no_outbound_ai_call(): void
    {
        Http::fake();
        // مفاتيح مزوّد مضبوطة: بدونها لا يُحاول run() نداءً أصلاً فيمرّ الاختبار بلا معنى
        config(['services.gemini.key' => 'fake-key', 'services.glm.key' => 'fake-key']);

        $client = User::factory()->create(['role' => Role::Client]);
        $match = $this->lawyer(self::DEPT);
        $ticket = Ticket::create([
            'user_id' => $client->id, 'number' => 'SB-NOAI-1', 'type' => 'نزاع عمالي',
            'department' => self::DEPT, 'status' => 'قيد التحليل', 'tone' => 'b-blue',
        ]);

        // النداء المباشر يعزل سياسة الاختيار عن مسار الفرز (TriageTicketOnOpenJob) الذي
        // يُجري نداءات AI مشروعة — فلو فُحص عبر HTTP لسقط الاختبار بسبب غير المقصود.
        $picked = TicketAssignment::pickLawyer($ticket, requireSpecialty: true);

        $this->assertSame($match->id, $picked?->id);
        Http::assertNothingSent();
    }

    /**
     * **الفتح لا يُسنِد أحداً** (قرار المالك 2026-09-20) — ولو وُجد متخصّص فارغ الحمل.
     * الإسناد بيد الطاقم، وهذا الحارس يُسقط عودة الإسناد التلقائيّ من أيّ باب.
     */
    public function test_opening_a_ticket_assigns_nobody(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $this->lawyer(self::DEPT);
        User::factory()->create(['role' => Role::Admin]);

        $ticket = $this->openTicket($client);

        $this->assertNull($ticket->assigned_lawyer_id, 'عاد الإسناد التلقائيّ عند فتح التذكرة.');
        $this->assertNotSame('محالة للقسم القانوني', $ticket->status, 'التذكرة أُحيلت بلا قرارٍ بشريّ.');
    }

    /**
     * بقيت بلا إسنادٍ بشريّ حتى انقضت المهلة ⇒ **تُسنَد للإدارة العليا** (لا تبقى يتيمة)،
     * ولا يُفرَض محامٍ غير متخصّص، ويصل إشعار داخليّ وبريد لكلّ من يملك قرار الإسناد.
     */
    public function test_an_unassigned_ticket_escalates_to_senior_management(): void
    {
        Mail::fake();
        $client = User::factory()->create(['role' => Role::Client]);
        $admin = User::factory()->create(['role' => Role::Admin, 'email' => 'admin@example.test']);
        $holder = User::factory()->create(['role' => Role::Employee, 'email' => 'holder@example.test']);
        $holder->syncPermissions(Permission::whereIn('name', ['توزيع التذاكر'])->get());

        $outsider = $this->lawyer('العقارات'); // قسم مختلف

        $this->openTicket($client);
        // الكنس المجدول بعد انقضاء المهلة (الإعداد الحيّ ساعتان؛ صفرٌ هنا لاختصار الانتظار)
        $this->artisan('tickets:escalate-unassigned', ['--minutes' => 0])->assertSuccessful();
        $ticket = Ticket::firstOrFail()->fresh();

        // أُسنِدت للإدارة العليا لا لمحامٍ غير متخصّص
        $this->assertSame($admin->id, $ticket->assigned_lawyer_id, 'لم تُسنَد للإدارة العليا.');
        $this->assertSame(EscalateUnassignedTicketJob::SENIOR_LABEL, $ticket->assigned_lawyer);
        $this->assertNotSame($outsider->id, $ticket->assigned_lawyer_id, 'أُسنِد محامٍ غير متخصّص — إسناد خاطئ صامت.');

        // إشعار داخليّ لصاحب القرار: الإدارة وحامل صلاحية التوزيع
        foreach ([$admin, $holder] as $recipient) {
            $this->assertGreaterThan(
                0,
                UserNotification::where('user_id', $recipient->id)->where('body', 'like', '%بلا محامٍ متخصّص%')->count(),
                "لم يصل إشعار التصعيد إلى {$recipient->role->value}."
            );
        }

        // وبريد مستقلّ (لا يُدمَج في بريد «تذكرة جديدة» الروتينيّ)
        Mail::assertQueued(TicketEscalatedMail::class, fn ($m) => $m->hasTo('admin@example.test'));
        Mail::assertQueued(TicketEscalatedMail::class, fn ($m) => $m->hasTo('holder@example.test'));
    }

    /**
     * التصعيد يُقدّم حالة التذكرة أيضاً — لا يكتفي بكتابة المحامي.
     *
     * كان يكتب assigned_lawyer فقط، فتبقى التذكرة عند «قيد التحليل» بينما الإسناد العادي
     * يرفعها إلى «محالة للقسم القانوني». الأثر ليس تجميلياً: **العميل يرى تذكرته عالقة إلى
     * الأبد** رغم أنها أُحيلت فعلاً للإدارة العليا.
     */
    public function test_escalation_advances_the_status_like_a_normal_assignment(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        User::factory()->create(['role' => Role::Admin]);
        $this->lawyer('العقارات'); // قسم مختلف ⇒ لا متخصّص

        $this->openTicket($client);
        $this->artisan('tickets:escalate-unassigned', ['--minutes' => 0])->assertSuccessful();

        $this->assertSame('محالة للقسم القانوني', Ticket::firstOrFail()->fresh()->status, 'التذكرة بقيت عالقة عند حالتها الأولى.');
    }

    /** إسناد يدويّ سبق تنفيذ الوظيفة ⇒ لا تُكتب الإدارة فوقه (سباق مشروع). */
    public function test_escalation_never_overwrites_a_manual_assignment(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        User::factory()->create(['role' => Role::Admin]);
        $manual = $this->lawyer('العقارات');

        $ticket = Ticket::create([
            'user_id' => $client->id, 'number' => 'SB-RACE-1', 'type' => 'نزاع عمالي',
            'department' => self::DEPT, 'status' => 'قيد التحليل', 'tone' => 'b-blue',
            'assigned_lawyer' => $manual->name, 'assigned_lawyer_id' => $manual->id,
        ]);

        (new EscalateUnassignedTicketJob($ticket->id))->handle(app(MailService::class));

        $this->assertSame($manual->id, $ticket->fresh()->assigned_lawyer_id, 'التصعيد داس إسناداً يدوياً.');
    }

    /** الكنس المُجدول يلتقط ما أفلت من الوظيفة (تذاكر قديمة/فشل طابور). */
    public function test_the_scheduled_sweep_picks_up_stragglers(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $admin = User::factory()->create(['role' => Role::Admin]);

        $stray = Ticket::create([
            'user_id' => $client->id, 'number' => 'SB-STRAY-1', 'type' => 'نزاع',
            'department' => self::DEPT, 'status' => 'قيد التحليل', 'tone' => 'b-blue',
        ]);

        $this->artisan('tickets:escalate-unassigned', ['--minutes' => 0])->assertSuccessful();

        $this->assertSame($admin->id, $stray->fresh()->assigned_lawyer_id, 'الكنس لم يلتقط التذكرة المعلّقة.');
    }

    /** السياسة الحتمية صراحةً بعد إزالة الـAI: تخصّص ← أقلّ حملاً ← أقدم معرّفاً. */
    public function test_specialty_then_load_then_id(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $busy = $this->lawyer(self::DEPT);
        $free = $this->lawyer(self::DEPT);
        $this->lawyer('العقارات'); // غير مطابق — يجب ألّا يُختار مهما كان حمله

        // حمل على الأوّل: تذكرة مفتوحة مسنَدة إليه
        Ticket::create([
            'user_id' => $client->id, 'number' => 'SB-LOAD-1', 'type' => 'نزاع',
            'status' => 'قيد التحليل', 'tone' => 'b-blue',
            'assigned_lawyer' => $busy->name, 'assigned_lawyer_id' => $busy->id,
        ]);

        $picked = TicketAssignment::pickLawyer(
            Ticket::make(['type' => 'نزاع عمالي', 'department' => self::DEPT]),
            requireSpecialty: true
        );

        $this->assertSame($free->id, $picked?->id, 'لم يُختر الأقلّ حملاً من المتخصّصين.');
    }

    /**
     * تذكرة **بلا قسم** يقبلها الاختيار الصارم: «لا يوجد متخصّص» تفترض تخصّصاً معلوماً.
     * فلولا هذا الشرط لعاد التوزيع الجماعيّ فارغاً على كلّ تذكرة لم يختر صاحبها قسماً.
     */
    public function test_a_ticket_without_a_department_is_still_pickable(): void
    {
        $any = $this->lawyer('العقارات');

        $picked = TicketAssignment::pickLawyer(Ticket::make(['type' => 'نزاع تجاري']), requireSpecialty: true);

        $this->assertSame($any->id, $picked?->id, 'تذكرة بلا قسم رُدّت بلا مرشَّح.');
    }

    /** الوضع غير الصارم (المنادون القائمون) يبقى كما هو: أيّ محامٍ أفضل من لا شيء. */
    public function test_permissive_mode_still_falls_back_to_any_lawyer(): void
    {
        $outsider = $this->lawyer('العقارات');

        $picked = TicketAssignment::pickLawyer(
            Ticket::make(['type' => 'نزاع عمالي', 'department' => self::DEPT])
        );

        $this->assertSame($outsider->id, $picked?->id, 'الوضع غير الصارم كُسر — منادون قائمون يعتمدون عليه.');
    }
}
