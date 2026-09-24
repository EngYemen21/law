<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use App\Support\ConsultBooking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsConsultJourney;
use Tests\TestCase;

/**
 * **الموعد المقترح يُرى في صفحة الاستشارة** — لا في درج الإدارة وحده.
 *
 * 🔴 الموظّف يقترح فيُكتب الموعد على «الموعد» لا على الاستشارة حتى تعتمده الإدارة، فكانت
 * صفحة الاستشارة عند الموظّف والمحامي بلا تاريخٍ ولا وقتٍ ولا محامٍ — ويبدو الحجز كأنّه لم يُحفظ.
 */
class ConsultProposalVisibilityTest extends TestCase
{
    use BuildsConsultJourney, RefreshDatabase;

    public function test_the_consult_card_carries_the_pending_proposal(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active', 'name' => 'المحامي محمد بندر']);
        $consult = $this->priceAndPay(ConsultBooking::request($client, ['type' => 'phone', 'subject' => 'استشارة']));
        $date = now()->addDay()->format('Y-m-d');

        $this->employeeProposes($consult, ['date' => $date, 'time' => '10:00', 'type' => 'phone', 'lawyer_id' => $lawyer->id])
            ->assertSuccessful();

        $proposal = $consult->fresh()->load('appointment')->toCard()['proposal'];

        $this->assertNotNull($proposal, 'الاقتراح لا يصل صفحة الاستشارة');
        $this->assertSame($date, $proposal['date']);
        $this->assertSame('10:00', $proposal['time']);
        $this->assertSame('المحامي محمد بندر', $proposal['lawyer']);
    }

    public function test_the_consult_page_renders_the_proposal_banner(): void
    {
        $lib = (string) preg_replace(
            '#/\*.*?\*/|//[^\n]*#su',
            '',
            (string) file_get_contents(resource_path('js/lib/consult-ui.tsx'))
        );

        // **يُفحص ما يراه المستخدم لا صيغةُ الشرط.** كان التأكيد الأوّل `'c.proposal ?'` يشترط
        // عاملاً ثلاثيّاً بعينه، فسقط حين صارت الكتلة `{c.proposal && (…)}` — وهي صيغةٌ تعرض
        // البانر نفسه. الشرط تفصيلٌ برمجيّ يتغيّر بلا أثرٍ على المستخدم؛ والبانر هو العقد.
        $this->assertMatchesRegularExpression('/c\.proposal\s*(\?|&&)/u', $lib, 'البانر لم يعد مشروطاً بوجود اقتراح.');
        $this->assertStringContainsString('موعد مقترح:', $lib, 'ضاع عنوان بطاقة الموعد المقترح.');
        $this->assertStringContainsString('بانتظار اعتماد الإدارة', $lib);
        $this->assertStringContainsString('proposalWhen(c.proposal)', $lib);
    }
}
