<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **نوعُ بطاقة العميل يصف ما يصلها فعلاً.**
 *
 * كانت `myconsults.tsx` تعلن `ConsultCard[]` بينما الخادم يمرّر `toClientCard()`
 * — نصف المفاتيح تقريباً. فحقولٌ **إلزاميّة** في النوع (`client`, `phone`,
 * `hostLink`, `priority`, `employee`, `aiSummary`, `audit`…) لا تصل العميل أبداً،
 * و**TypeScript يضمن وجودها كذباً**: أوّل سطرٍ يقرأ أحدها يمرّ الفحص وينكسر في
 * المتصفّح صامتاً. ولا عطلَ اليوم — وهذا بالضبط وقت الإصلاح.
 */
class ClientConsultCardContractTest extends TestCase
{
    use RefreshDatabase;

    private function card(): array
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);

        return Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-CLI-'.uniqid(), 'subject' => 'نزاع تجاري',
            'type' => 'استشارة', 'channel' => 'مرئية', 'status' => 'مؤكد', 'session' => 'بانتظار الجلسة',
            'tone' => 'b-blue', 'lawyer' => $lawyer->name, 'assigned_lawyer_id' => $lawyer->id,
        ])->toClientCard();
    }

    /** كلّ مفتاحٍ يعلنه النوع يرسله الخادم. */
    public function test_every_declared_field_is_actually_sent(): void
    {
        $card = $this->card();
        $type = (string) file_get_contents(resource_path('js/lib/consult-ui.tsx'));

        $start = strpos($type, 'export interface ClientConsultCard {');
        $this->assertNotFalse($start, 'النوع موجود');
        $body = substr($type, $start, strpos($type, "\n}", $start) - $start);

        preg_match_all('/^\s{2}(\w+)\??:/m', $body, $m);
        $declared = $m[1];

        $this->assertNotEmpty($declared);

        $missing = array_values(array_diff($declared, array_keys($card)));
        $this->assertSame([], $missing, "النوع يعلن ما لا يُرسَل:\n".implode("\n", $missing));
    }

    /** ولا يتسرّب إلى العميل ما حُجب عنه عمداً. */
    public function test_the_internal_fields_never_reach_the_client(): void
    {
        $card = $this->card();

        foreach (['aiSummary', 'aiClass', 'aiLawyer', 'audit', 'employee', 'hostLink', 'sessionNotes', 'ticketNo', 'zoomSummary'] as $internal) {
            $this->assertArrayNotHasKey($internal, $card, "«{$internal}» داخليّ ولا يصل العميل");
        }
    }
}
