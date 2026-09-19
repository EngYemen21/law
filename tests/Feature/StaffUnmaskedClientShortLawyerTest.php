<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Jobs\EscalateUnassignedTicketJob;
use App\Models\Consult;
use App\Models\Execution;
use App\Models\LegalCase;
use App\Models\Meeting;
use App\Models\Ticket;
use App\Models\User;
use App\Support\LawyerName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **قرار المالك 2026-09-11:**
 * - لا تقنيعَ لبيانات العميل في لوحات الإدارة العليا والمحامي والموظّف.
 * - وفي لوحة العميل يُعرض اسمُ المحامي «الاسمُ الأوّل. الحرفُ الأوّل من الثاني» — «محمد. ب».
 *
 * كان العميل يرى اسم المحامي على ثلاثة أوجه (صريحاً، و«أ. م••••د (مشفّر)»، ومقنَّعاً مرّتين)،
 * وكان الطاقم يرى «ع••••ه (مشفّر)» مكان اسم العميل الذي يعمل على ملفّه.
 */
class StaffUnmaskedClientShortLawyerTest extends TestCase
{
    use RefreshDatabase;

    // ————— ١ · الصيغة —————

    public function test_the_short_form_is_first_name_dot_first_letter(): void
    {
        foreach ([
            'محمد بندر' => 'محمد. ب',
            'المحامي محمد بندر' => 'محمد. ب',
            'أ. سارة القحطاني' => 'سارة. ق',  // «ال» التعريف ليست من الاسم
            'أ.خالد المالكي' => 'خالد. م',
            'د. فهد علي الشهري' => 'فهد. ع',
            'خالد' => 'خالد',
        ] as $in => $out) {
            $this->assertSame($out, LawyerName::short($in), $in);
        }
    }

    public function test_placeholders_are_never_shortened(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'name' => 'محمد بندر']);
        $admin = User::factory()->create(['role' => Role::Admin, 'name' => 'مدير المكتب']);

        $this->assertSame('محمد. ب', LawyerName::forClient($lawyer, 'المحامي محمد بندر', '—'));
        // ملفٌّ مرفوعٌ للإدارة: المسنَدُ مديرٌ والحقلُ نائب — يبقى النائب
        $this->assertSame(EscalateUnassignedTicketJob::SENIOR_LABEL, LawyerName::forClient($admin, EscalateUnassignedTicketJob::SENIOR_LABEL, '—'));
        $this->assertSame('المستشار المختص', LawyerName::forClient(null, 'المستشار المختص', '—'));
        $this->assertSame('بانتظار الإسناد', LawyerName::forClient(null, '', 'بانتظار الإسناد'));
    }

    // ————— ٢ · شاشات العميل —————

    public function test_client_screens_show_the_short_lawyer_name(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'name' => 'محمد بندر']);
        $assigned = ['user_id' => $client->id, 'assigned_lawyer_id' => $lawyer->id];

        $ticket = Ticket::create($assigned + ['number' => 'TK-LN-1', 'type' => 'استشارة', 'status' => 'جديدة', 'assigned_lawyer' => $lawyer->name]);
        $ticket->messages()->create(['who' => 'lawyer', 'name' => $lawyer->name, 'role' => 'المحامي', 'body' => '<p>مرحباً</p>']);
        LegalCase::create($assigned + ['number' => 'C-LN-1', 'type' => 'تجارية', 'status' => 'جارية', 'assigned_lawyer' => $lawyer->name]);
        Consult::create($assigned + [
            'ref' => 'CN-LN-1', 'subject' => 'نزاع', 'type' => 'استشارة', 'channel' => 'مرئية',
            'status' => 'جديدة', 'session' => 'بانتظار الجلسة', 'tone' => 'b-blue', 'lawyer' => $lawyer->name,
        ]);

        $this->actingAs($client)->get('/myconsults')
            ->assertInertia(fn ($p) => $p->where('consults.0.lawyer', 'محمد. ب'));
        $this->actingAs($client)->get('/tickets')
            ->assertInertia(fn ($p) => $p->where('tickets.0.lawyer', 'محمد. ب'));
        $this->actingAs($client)->get('/cases')
            ->assertInertia(fn ($p) => $p->where('cases.0.assignedLawyer', 'محمد. ب'));
        $this->actingAs($client)->get(route('tickets.show', $ticket))
            ->assertInertia(fn ($p) => $p->where('messages', fn ($ms) => collect($ms)->contains(fn ($m) => $m['who'] === 'lawyer' && $m['name'] === 'محمد. ب')));

        $meeting = Meeting::create(['ref' => 'M-LN-1', 'title' => 'اجتماع', 'when_label' => 'غداً', 'status' => 'قادم', 'user_id' => $client->id, 'assigned_lawyer_id' => $lawyer->id]);
        $this->assertSame('محمد. ب', $meeting->fresh()->toCard()['lawyer']);

        $exec = Execution::create($assigned + ['number' => 'EX-LN-1', 'subject' => 'تنفيذ حكم', 'stage' => 2, 'assigned_lawyer' => $lawyer->name]);
        $this->assertSame('محمد. ب', $exec->toFlowCard(false)['lawyer'], 'بطاقة العميل');
        $this->assertSame('محمد بندر', $exec->toFlowCard(false, true)['lawyer'], 'وبطاقة الطاقم الاسمَ كاملاً');
    }

    public function test_an_escalated_consult_keeps_its_label_for_the_client(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $consult = Consult::create([
            'user_id' => $client->id, 'assigned_lawyer_id' => $admin->id, 'ref' => 'CN-LN-2', 'subject' => 'نزاع',
            'type' => 'استشارة', 'channel' => 'مرئية', 'status' => 'جديدة', 'session' => 'بانتظار الجلسة',
            'tone' => 'b-blue', 'lawyer' => EscalateUnassignedTicketJob::SENIOR_LABEL,
        ]);

        $this->assertSame(EscalateUnassignedTicketJob::SENIOR_LABEL, $consult->toClientCard()['lawyer']);
    }

    // ————— ٣ · لوحات الطاقم: الاسم صريح —————

    public function test_staff_screens_carry_the_real_client_name(): void
    {
        $client = User::factory()->create(['role' => Role::Client, 'name' => 'عبدالله محمد العتيبي']);
        $exec = Execution::create(['user_id' => $client->id, 'number' => 'EX-LN-2', 'subject' => 'تنفيذ', 'stage' => 2]);

        foreach ([Role::Admin, Role::Lawyer, Role::Employee] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->assertSame('عبدالله محمد العتيبي', Ticket::maskClient('عبدالله محمد العتيبي'), $role->value);
        }

        $this->assertSame('عبدالله محمد العتيبي', $exec->fresh()->toFlowCard(false, true)['client']);

        // شاشات التنفيذ الثلاث للطاقم لا تطلب التقنيع
        $src = file_get_contents(app_path('Http/Controllers/ExecFlowController.php'));
        $this->assertStringNotContainsString('toFlowCard(true', $src);
    }

    public function test_no_masking_helper_survives_on_staff_or_client_screens(): void
    {
        // المُقنِّع الوحيد في الواجهة صار تمريراً
        $data = file_get_contents(resource_path('js/lib/employee-data.ts'));
        $fn = substr($data, strpos($data, 'export function maskClient'), 400);
        $this->assertStringNotContainsString('••••', $fn);
        $this->assertStringNotContainsString('مشفّر', $fn);

        // ولا مقنِّعَ لاسم المحامي في شاشة المواعيد ولا في الخادم
        $this->assertStringNotContainsString('maskLawyer(', file_get_contents(resource_path('js/pages/appointments.tsx')));
        // ولا قاصَّ في «استشاراتي»: `lawyerFirst` كانت تبتر «محمد. ب» إلى «محمد.» (قيسَ في المتصفّح)
        $this->assertStringNotContainsString('lawyerFirst(', file_get_contents(resource_path('js/pages/myconsults.tsx')));
        foreach (['Http/Controllers/AppointmentController.php', 'Http/Controllers/ExecFlowController.php', 'Support/ConsultReport.php'] as $f) {
            $this->assertStringNotContainsString('Mask::lawyer(', file_get_contents(app_path($f)), $f);
        }

        // وجوال المستخدم في لوحة الإدارة صريح
        $this->assertStringNotContainsString('Phone::mask(', file_get_contents(app_path('Http/Controllers/Admin/StaffController.php')));
    }
}
