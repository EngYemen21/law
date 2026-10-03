<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Mail\InvoicePaidMail;
use App\Mail\PaymentProofSubmittedMail;
use App\Models\Invoice;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * **إشعارات الإثبات اليدويّ ونتيجته** (قرار المالك 2026-10-03).
 *
 * ثبت بالكود: رفعُ العميل إثبات تحويل لم يكن يُشعر الإدارة بشيء — تعلم به إن فتحت «المالية» فقط. واعتمادُ
 * دفعة فاتورةٍ غير الاستشارة لم يكن يُشعر العميل في حسابه. الآن إشعارٌ في الجرس وبريد للطرفين.
 */
class PaymentNoticesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Mail::fake();
    }

    private function dueInvoice(User $client): Invoice
    {
        return Invoice::create([
            'user_id' => $client->id, 'number' => 'INV-PN-'.uniqid(), 'description' => 'أتعاب قضية',
            'amount' => 1500, 'status' => 'مستحقة', 'tone' => 'b-amber', 'due_label' => 'خلال أسبوع', 'paid' => false,
        ]);
    }

    public function test_an_uploaded_proof_notifies_every_admin_by_bell_and_email(): void
    {
        $admins = User::factory()->count(2)->create(['role' => Role::Admin]);
        $client = User::factory()->create(['role' => Role::Client, 'name' => 'سارة العميلة']);
        $invoice = $this->dueInvoice($client);

        $this->actingAs($client)->post(route('invoices.proof', $invoice), [
            'file' => UploadedFile::fake()->create('transfer.jpg', 40, 'image/jpeg'),
        ])->assertRedirect();

        foreach ($admins as $admin) {
            $this->assertTrue(
                UserNotification::where('user_id', $admin->id)->where('body', 'like', "%{$invoice->number}%")->where('body', 'like', '%سارة العميلة%')->exists(),
                'كلّ مدير يُشعَر في الجرس برقم الفاتورة واسم العميل'
            );
        }
        Mail::assertQueued(PaymentProofSubmittedMail::class, fn (PaymentProofSubmittedMail $m) => $m->invoice->is($invoice)
            && $m->hasTo($admins[0]->email) && $m->hasTo($admins[1]->email));
    }

    public function test_collecting_a_non_consult_invoice_notifies_the_client_by_bell_and_email(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $client = User::factory()->create(['role' => Role::Client]);
        $invoice = $this->dueInvoice($client);

        $this->actingAs($admin)->post(route('admin.invoices.pay', $invoice), ['method' => 'bank_transfer'])
            ->assertSessionHas('flash');

        $this->assertTrue($invoice->fresh()->paid);
        $this->assertSame(1, UserNotification::where('user_id', $client->id)->where('body', 'like', "%تم اعتماد دفعتك للفاتورة {$invoice->number}%")->count());
        Mail::assertQueued(InvoicePaidMail::class, fn (InvoicePaidMail $m) => $m->invoice->is($invoice) && $m->hasTo($client->email));
    }

    public function test_collecting_twice_notifies_the_client_once(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $client = User::factory()->create(['role' => Role::Client]);
        $invoice = $this->dueInvoice($client);

        $this->actingAs($admin)->post(route('admin.invoices.pay', $invoice), ['method' => 'cash']);
        $this->actingAs($admin)->post(route('admin.invoices.pay', $invoice), ['method' => 'cash']);

        $this->assertSame(1, UserNotification::where('user_id', $client->id)->where('body', 'like', '%تم اعتماد دفعتك%')->count());
        Mail::assertQueuedCount(1);
    }
}
