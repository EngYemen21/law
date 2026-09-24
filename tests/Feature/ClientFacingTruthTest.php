<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\InvoiceStatus;
use App\Enums\Role;
use App\Models\Consult;
use App\Models\Invoice;
use App\Models\LegalCase;
use App\Models\User;
use App\Support\ConsultBooking;
use App\Support\Finance\RevenueSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **ما يصل العميل يقول الحقيقة** — ثلاثة أعطالٍ رُصدت في فحصٍ حيّ للشاشات (2026-09-25).
 *
 * 1. **الذمّة:** شاشة العميل كانت تحسب المستحقّ بـ`!paid` وحده، فتعُدّ الفاتورة **الملغاة**
 *    ديناً: عُرض عليه ٢٤٬٠٣٥ ر.س وذمّتُه ١٧٬٥١٩ — والفرق فاتورةٌ أُلغيت بإعادة التسعير.
 *    وشاشة الإدارة تعرض الصحيح من `RevenueSnapshot::receivables()`. رقمان لمفهومٍ واحد.
 * 2. **اسم المحامي:** بطاقة «المستشار المخصص» في لوحة العميل كانت ترسل الاسم خاماً، بينما
 *    سطرا القضايا والتذاكر في الملفّ نفسه يقنّعان (قرار المالك 2026-09-11: «محمد. ب»).
 * 3. **دور المستشار:** `ConsultBooking` كان يقبل أيّ معرّف مستخدمٍ في `lawyer_id` بلا فحص
 *    دور، فيُكتب اسم إداريٍّ في حقل المستشار ويقرأ العميل «المستشار: الإدارة العليا».
 */
class ClientFacingTruthTest extends TestCase
{
    use RefreshDatabase;

    private function client(): User
    {
        return User::factory()->create(['role' => Role::Client, 'name' => 'موكّل التجربة']);
    }

    private function invoice(User $client, int $amount, string $status, bool $paid = false): Invoice
    {
        return Invoice::create([
            'user_id' => $client->id,
            'number' => 'INV-T-'.$amount.'-'.substr((string) $status, 0, 4).'-'.uniqid(),
            'description' => 'فاتورة اختبار',
            'amount' => $amount,
            'status' => $status,
            'tone' => 'b-amber',
            'due_label' => 'تستحق قريباً',
            'paid' => $paid,
        ]);
    }

    /** **الحارس الأهمّ:** الملغاة والمعدومة ليستا ذمّةً — والعميل لا يُطالَب بهما. */
    public function test_a_cancelled_or_written_off_invoice_is_not_a_receivable(): void
    {
        $client = $this->client();

        $open = $this->invoice($client, 1419, InvoiceStatus::Due->value);
        $cancelled = $this->invoice($client, 6516, InvoiceStatus::Cancelled->value);
        $writtenOff = $this->invoice($client, 9000, InvoiceStatus::WrittenOff->value);
        $settled = $this->invoice($client, 500, InvoiceStatus::Paid->value, paid: true);

        $this->assertTrue(RevenueSnapshot::isReceivable($open), 'فاتورةٌ مستحقّة لم تُعَدّ ذمّة.');
        $this->assertFalse(RevenueSnapshot::isReceivable($cancelled), 'الملغاة عُدَّت ذمّةً — العميل يُطالَب بما أُلغي.');
        $this->assertFalse(RevenueSnapshot::isReceivable($writtenOff), 'المعدومة عُدَّت ذمّةً.');
        $this->assertFalse(RevenueSnapshot::isReceivable($settled), 'المدفوعة عُدَّت ذمّة.');
    }

    /** وبطاقة الفاتورة تحمل الحكم للواجهة، فلا تشتقّه بنفسها. */
    public function test_the_invoice_card_carries_the_receivable_verdict(): void
    {
        $client = $this->client();

        $this->assertTrue($this->invoice($client, 1419, InvoiceStatus::Due->value)->toCard()['receivable']);
        $this->assertFalse($this->invoice($client, 6516, InvoiceStatus::Cancelled->value)->toCard()['receivable']);
    }

    /** الذمّة المعروضة للعميل = ذمّة الإدارة نفسها — لا رقمان لمفهومٍ واحد. */
    public function test_the_client_total_matches_the_admin_definition(): void
    {
        $client = $this->client();
        $this->invoice($client, 1419, InvoiceStatus::Due->value);
        $this->invoice($client, 2300, InvoiceStatus::Due->value);
        $this->invoice($client, 6516, InvoiceStatus::Cancelled->value);

        $cards = Invoice::where('user_id', $client->id)->get()->map(fn (Invoice $i) => $i->toCard());
        $clientTotal = $cards->filter(fn (array $c) => $c['receivable'])->sum('amount');

        $this->assertSame(3719, $clientTotal, 'مجموع ما يراه العميل يخالف الذمّة الحقيقيّة.');
        $this->assertSame((int) RevenueSnapshot::receivables()->sum('amount'), $clientTotal);
    }

    /** بطاقة «المستشار المخصص» تقنّع كبقيّة ما يصل العميل. */
    public function test_the_advisor_card_shortens_the_lawyer_name(): void
    {
        $client = $this->client();
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'name' => 'سارة القحطاني']);

        LegalCase::create([
            'user_id' => $client->id, 'assigned_lawyer_id' => $lawyer->id,
            'number' => 'CASE-T-9001', 'type' => 'نزاع تجاري', 'status' => 'منظورة',
            'tone' => 'b-blue', 'update_text' => '—',
        ]);

        $this->actingAs($client)->get(route('dashboard'))
            ->assertInertia(fn ($p) => $p->where('assignedAdvisor.name', 'سارة. ق'));
    }

    /** **معرّفُ غير محامٍ لا يصير مستشاراً** — ولو مُرِّر صراحةً. */
    public function test_a_non_lawyer_id_never_becomes_the_consult_advisor(): void
    {
        $client = $this->client();
        $admin = User::factory()->create(['role' => Role::Admin, 'name' => 'الإدارة العليا']);

        $consult = ConsultBooking::request($client, [
            'type' => 'office',
            'subject' => 'استشارة اختبار الدور',
            'lawyer_id' => $admin->id,
        ]);

        $this->assertNotSame('الإدارة العليا', $consult->lawyer, 'اسم إداريٍّ كُتب في حقل المستشار.');
        $this->assertNull($consult->assigned_lawyer_id, 'أُسند غيرُ محامٍ إلى الاستشارة.');
        $this->assertSame('المستشار القانوني', $consult->lawyer);
    }

    /** ومحامٍ حقيقيّ يُقبل — القيد على الدور لا على الإسناد نفسه. */
    public function test_a_real_lawyer_is_still_accepted(): void
    {
        $client = $this->client();
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'name' => 'سارة القحطاني']);

        $consult = ConsultBooking::request($client, [
            'type' => 'office',
            'subject' => 'استشارة اختبار الدور',
            'lawyer_id' => $lawyer->id,
        ]);

        $this->assertSame($lawyer->id, $consult->assigned_lawyer_id);
        $this->assertSame('سارة القحطاني', $consult->lawyer);
    }

    /** والعميل يرى الاسم مقنَّعاً في بطاقة الاستشارة. */
    public function test_the_consult_card_shows_the_client_a_short_name(): void
    {
        $client = $this->client();
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'name' => 'سارة القحطاني']);

        $consult = ConsultBooking::request($client, [
            'type' => 'office', 'subject' => 'استشارة', 'lawyer_id' => $lawyer->id,
        ]);

        $this->assertSame('سارة. ق', Consult::find($consult->id)->toClientCard()['lawyer']);
    }
}
