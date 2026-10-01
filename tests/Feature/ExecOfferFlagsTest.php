<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\ExecutionOfferStatus;
use App\Enums\Role;
use App\Models\Execution;
use App\Models\User;
use App\Support\ExecFlow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **X5 — حالة عرض التنفيذ أعلامٌ من الخادم لا نصوصٌ في الواجهة** (قاعدة CLAUDE.md: لا نصوص حالةٍ عربيّة
 * في الشروط). كانت `execflow.tsx` تقرّر إظهار بطاقة العرض بـ`['مقبول','مرفوض'].includes(offerStatus)`
 * وشارتها بـ`offerStatus === 'استفسار'`.
 */
class ExecOfferFlagsTest extends TestCase
{
    use RefreshDatabase;

    private function exec(?ExecutionOfferStatus $status): Execution
    {
        $client = User::factory()->create(['role' => Role::Client]);

        return Execution::create([
            'user_id' => $client->id, 'number' => 'EXE-OF-'.uniqid(), 'subject' => 'تنفيذ سند', 'sanad' => 'شيك',
            'stage' => 5, 'status' => ExecFlow::label(5), 'tone' => ExecFlow::tone(5), 'fee' => 8000, 'fee_approved' => true,
            'offer_status' => $status?->value,
        ]);
    }

    public function test_the_flow_card_carries_one_flag_per_offer_state(): void
    {
        $expect = [
            [null, false, false, false],
            [ExecutionOfferStatus::Accepted, true, false, false],
            [ExecutionOfferStatus::Rejected, false, true, false],
            [ExecutionOfferStatus::Inquiry, false, false, true],
        ];

        foreach ($expect as [$status, $accepted, $rejected, $inquiry]) {
            $card = $this->exec($status)->toFlowCard();
            $label = $status?->name ?? 'none';
            $this->assertSame($accepted, $card['offerAccepted'], $label);
            $this->assertSame($rejected, $card['offerRejected'], $label);
            $this->assertSame($inquiry, $card['offerInquiry'], $label);
        }
    }

    public function test_the_page_reads_the_flags_not_the_arabic_status(): void
    {
        $page = (string) file_get_contents(resource_path('js/pages/execflow.tsx'));

        foreach (ExecutionOfferStatus::cases() as $status) {
            $this->assertStringNotContainsString("'{$status->value}'", $page, "نصّ الحالة «{$status->value}» في شرطٍ بالواجهة");
        }
        $this->assertStringContainsString('!r.offerAccepted && !r.offerRejected', $page);
        $this->assertStringContainsString('r.offerInquiry ?', $page);
    }
}
