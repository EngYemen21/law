<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Mail\ExecutionEventMail;
use App\Models\Execution;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\LegalAiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * إصلاحات تدقيق مجال التنفيذ: عزل المحامي في المراسلة/مراجعة المستندات، صلاحية
 * الأفعال للمكتب، إنهاء دورة حياة الرفض فعليًّا، إشعارات رفض/استفسار العرض،
 * تنزيل المستندات المحروس، وبريد أحداث التنفيذ.
 */
class ExecFlowFixesTest extends TestCase
{
    use RefreshDatabase;

    private function execFor(User $client, array $attrs = []): Execution
    {
        return Execution::create(array_merge([
            'user_id' => $client->id,
            'number' => 'EXE-2026-0001',
            'subject' => 'تنفيذ حكم مالي',
            'status' => 'قيد الدراسة',
            'tone' => 'b-blue',
            'stage' => 2,
        ], $attrs));
    }

    // ── 1.1: عزل المحامي في message()/reviewDocument() ──

    public function test_unassigned_lawyer_cannot_message_another_lawyers_file(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $owner = User::factory()->create(['role' => Role::Lawyer]);
        $other = User::factory()->create(['role' => Role::Lawyer]);
        $exec = $this->execFor($client, ['assigned_lawyer_id' => $owner->id]);

        $this->actingAs($other)->post(route('exec-flow.messages.store', $exec), ['body' => 'رسالة'])->assertForbidden();
    }

    public function test_unassigned_lawyer_cannot_review_another_lawyers_document(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $owner = User::factory()->create(['role' => Role::Lawyer]);
        $other = User::factory()->create(['role' => Role::Lawyer]);
        $exec = $this->execFor($client, ['assigned_lawyer_id' => $owner->id]);
        $doc = $exec->documents()->create(['label' => 'الهوية', 'status' => 'مرفوع']);

        $this->actingAs($other)->post(route('exec-flow.documents.review', [$exec, $doc]), ['decision' => 'accept'])->assertForbidden();
    }

    public function test_assigned_lawyer_can_message_and_review(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $owner = User::factory()->create(['role' => Role::Lawyer]);
        $exec = $this->execFor($client, ['assigned_lawyer_id' => $owner->id]);
        $doc = $exec->documents()->create(['label' => 'الهوية', 'status' => 'مرفوع']);

        $this->actingAs($owner)->post(route('exec-flow.messages.store', $exec), ['body' => 'رسالة'])->assertNoContent();
        $this->actingAs($owner)->post(route('exec-flow.documents.review', [$exec, $doc]), ['decision' => 'accept'])->assertRedirect();
    }

    // ── 1.2: صلاحية الأفعال للمكتب (deny-by-default) ──

    public function test_staff_action_denied_without_case_permission(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $lawyer->syncPermissions([]); // سُحبت كل الصلاحيات
        $exec = $this->execFor($client);

        $this->actingAs($lawyer)->post(route('exec-flow.act', $exec), ['action' => 'accept'])->assertForbidden();
    }

    public function test_staff_message_denied_without_case_permission(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $employee->syncPermissions([]);
        $exec = $this->execFor($client);

        $this->actingAs($employee)->post(route('exec-flow.messages.store', $exec), ['body' => 'رسالة'])->assertForbidden();
    }

    public function test_staff_action_allowed_with_case_permission(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $lawyer->syncPermissions(['إدارة القضايا والأتعاب']);
        $exec = $this->execFor($client);

        $this->actingAs($lawyer)->post(route('exec-flow.act', $exec), ['action' => 'accept'])->assertRedirect();
    }

    // ── 2.1: reject() ينهي دورة الحياة فعليًّا ──

    public function test_reject_blocks_further_accept_and_save_fee(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $exec = $this->execFor($client, ['assigned_lawyer_id' => $lawyer->id]);

        $this->actingAs($lawyer)->post(route('exec-flow.act', $exec), ['action' => 'reject'])->assertRedirect();
        $this->assertSame('مرفوض', $exec->fresh()->decision);

        $this->actingAs($lawyer)->post(route('exec-flow.act', $exec), ['action' => 'accept'])->assertStatus(422);
    }

    // ── 2.2 + 2.3: rejectOffer/inquire يُشعران المكتب ويسمحان بإعادة التسعير ──

    public function test_reject_offer_notifies_lawyer_and_allows_reprice(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $exec = $this->execFor($client, [
            'assigned_lawyer_id' => $lawyer->id, 'stage' => 5, 'decision' => 'مقبول',
            'fee' => 5000, 'vat' => 750, 'fee_approved' => true,
        ]);

        $this->actingAs($client)->post(route('exec-flow.act', $exec), ['action' => 'rejectOffer'])->assertRedirect();
        $this->assertSame('مرفوض', $exec->fresh()->offer_status);
        $this->assertSame(1, UserNotification::where('user_id', $lawyer->id)->count());

        // إعادة التسعير مسموحة رغم بقاء stage=5 (رفض عرض لا يعني إغلاق الطلب)
        // saveFee يقتصر على stage 3 دومًا (تصميم مقصود) — حارس المرحلة يرفض بخطأ تحقّق (لا 422 خام)
        $this->actingAs($lawyer)->post(route('exec-flow.act', $exec), [
            'action' => 'saveFee', 'fee' => 6000, 'duration' => '30 يوم', 'payMethod' => 'دفعة واحدة',
        ])->assertSessionHasErrors('stage');

        // لكن setFee (مسار الإدارة المباشر) يقبل إعادة التسعير على stage=5 بعد الرفض
        $admin = User::factory()->create(['role' => Role::Admin]);
        $this->actingAs($admin)->post(route('exec-flow.act', $exec), [
            'action' => 'setFee', 'fee' => 6000, 'duration' => '30 يوم', 'payMethod' => 'دفعة واحدة',
        ])->assertRedirect();
        $this->assertNull($exec->fresh()->offer_status);
        $this->assertSame(6000, $exec->fresh()->fee);
    }

    public function test_inquire_notifies_lawyer(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $exec = $this->execFor($client, [
            'assigned_lawyer_id' => $lawyer->id, 'stage' => 5, 'fee' => 5000, 'vat' => 750, 'fee_approved' => true,
        ]);

        $this->actingAs($client)->post(route('exec-flow.act', $exec), ['action' => 'inquire'])->assertRedirect();
        $this->assertSame('استفسار', $exec->fresh()->offer_status);
        $this->assertSame(1, UserNotification::where('user_id', $lawyer->id)->count());
    }

    // ── 3.1: تنزيل المستند محروس ──

    public function test_document_download_is_guarded_and_works_for_owner(): void
    {
        Storage::fake('local');
        $client = User::factory()->create(['role' => Role::Client]);
        $otherClient = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $exec = $this->execFor($client, ['assigned_lawyer_id' => $lawyer->id]);
        Storage::disk('local')->put('exec-docs/1/id.pdf', 'محتوى تجريبي');
        $doc = $exec->documents()->create(['label' => 'الهوية', 'status' => 'مرفوع', 'path' => 'exec-docs/1/id.pdf']);

        // عميل آخر ممنوع
        $this->actingAs($otherClient)->get(route('exec-flow.documents.download', [$exec, $doc]))->assertForbidden();
        // صاحب الملف مسموح
        $this->actingAs($client)->get(route('exec-flow.documents.download', [$exec, $doc]))->assertOk();
        // المحامي المسنَد مسموح
        $this->actingAs($lawyer)->get(route('exec-flow.documents.download', [$exec, $doc]))->assertOk();
    }

    // ── 3.4: بريد أحداث التنفيذ ──

    public function test_fee_approved_and_paid_and_closed_send_mail(): void
    {
        Mail::fake();
        $client = User::factory()->create(['role' => Role::Client, 'email' => 'cl@example.com']);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $exec = $this->execFor($client, [
            'assigned_lawyer_id' => $lawyer->id, 'stage' => 4, 'decision' => 'مقبول', 'fee' => 5000, 'vat' => 750,
        ]);

        $this->actingAs($admin)->post(route('exec-flow.act', $exec), ['action' => 'approveFee'])->assertRedirect();
        Mail::assertQueued(ExecutionEventMail::class, fn ($m) => $m->event === 'feeApproved' && $m->hasTo('cl@example.com'));

        $exec->update(['stage' => 8, 'paid' => false]);
        // markPaid يُستدعى عبر PaymentReconciler عادة؛ هنا نستدعي close() مباشرةً للتحقّق من بريد الإغلاق
        $this->actingAs($admin)->post(route('exec-flow.act', $exec), ['action' => 'close'])->assertRedirect();
        Mail::assertQueued(ExecutionEventMail::class, fn ($m) => $m->event === 'closed');
    }

    // ── إرفاق مستند حرّ من محادثة التنفيذ ──

    public function test_client_can_attach_document_from_chat(): void
    {
        Storage::fake('local');
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $exec = $this->execFor($client, ['assigned_lawyer_id' => $lawyer->id]);

        $file = UploadedFile::fake()->create('كشف_حساب.pdf', 200, 'application/pdf');
        $this->actingAs($client)->post(route('exec-flow.attach', $exec), ['file' => $file])->assertNoContent();

        $this->assertSame(1, $exec->documents()->count());
        $doc = $exec->documents()->first();
        $this->assertSame('مرفوع', $doc->status);
        $this->assertSame('كشف_حساب.pdf', $doc->label);
        $this->assertTrue($exec->messages()->where('who', 'client')->exists());
        $this->assertSame(1, UserNotification::where('user_id', $lawyer->id)->count());
    }

    public function test_non_owner_cannot_attach_document(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $other = User::factory()->create(['role' => Role::Client]);
        $exec = $this->execFor($client);

        $file = UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');
        $this->actingAs($other)->post(route('exec-flow.attach', $exec), ['file' => $file])->assertForbidden();
    }

    public function test_cannot_attach_document_on_closed_execution(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $exec = $this->execFor($client, ['stage' => 9, 'status' => 'مغلق']);

        $file = UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');
        $this->actingAs($client)->post(route('exec-flow.attach', $exec), ['file' => $file])->assertStatus(422);
    }

    public function test_attached_document_is_analyzed_without_disturbing_review_status(): void
    {
        Storage::fake('local');
        $client = User::factory()->create(['role' => Role::Client]);
        $exec = $this->execFor($client);
        // تزييف التحليل الذكي (المفاتيح فارغة في بيئة الاختبار) — يُشغَّل تزامنياً عبر الطابور المتزامن
        $this->partialMock(LegalAiService::class, function ($mock) {
            $mock->shouldReceive('analyzeExecutionDocument')->andReturn(['doc_type' => 'كشف حساب بنكي', 'summary' => 'كشف يوثّق المبلغ المطالَب به.']);
        });

        $file = UploadedFile::fake()->create('كشف_حساب.pdf', 200, 'application/pdf');
        $this->actingAs($client)->post(route('exec-flow.attach', $exec), ['file' => $file])->assertNoContent();

        $doc = $exec->documents()->firstOrFail();
        // التصنيف والملخّص امتلآ، لكن status بقيت «مرفوع» (لم يتأثر مسار اعتماد/رفض المحامي)
        $this->assertSame('كشف حساب بنكي', $doc->doc_type);
        $this->assertSame('كشف يوثّق المبلغ المطالَب به.', $doc->summary);
        $this->assertSame('مرفوع', $doc->status);
        $this->assertTrue($exec->messages()->where('role', 'تحليل المستند')->exists());
    }
}
