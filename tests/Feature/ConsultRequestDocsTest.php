<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **طلبُ المستندات يقول ما المطلوب.**
 *
 * كان الحقل `nullable` بافتراضيّ «مستند إضافي مطلوب»، وصفحةُ رحلة الاستشارة ترسل
 * حمولةً **فارغة** أصلاً — فهذا حالُه الغالب. فيصل العميلَ إشعارٌ باسم المكتب لا
 * يقول ما المطلوب، ويعلق ملفّه «بانتظار استكمال البيانات» بانتظار شيءٍ مجهول،
 * فيتّصل ليسأل أو ينتظر بلا فعل.
 */
class ConsultRequestDocsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:Consult,1:User,2:User} */
    private function consult(): array
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);

        $consult = Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-DOC-'.uniqid(), 'subject' => 'نزاع تجاري',
            'type' => 'استشارة', 'channel' => 'مرئية', 'status' => 'قيد مراجعة الموظف',
            'session' => 'بانتظار الجلسة', 'tone' => 'b-blue',
            'lawyer' => $lawyer->name, 'assigned_lawyer_id' => $lawyer->id,
        ]);

        return [$consult, $lawyer, $client];
    }

    /** طلبٌ بلا بيان يُرفض — ولا يُخترع نصٌّ باسم المكتب. */
    public function test_an_empty_request_is_refused_instead_of_inventing_one(): void
    {
        [$consult, $lawyer, $client] = $this->consult();

        $this->actingAs($lawyer)
            ->post("/lawyer/consults/{$consult->id}/reqdocs", [])
            ->assertSessionHasErrors('docs');

        $fresh = $consult->fresh();
        $this->assertSame('قيد مراجعة الموظف', $fresh->status, 'ولا تُعلَّق الاستشارة');
        $this->assertEmpty($fresh->missing ?? [], 'ولا يُضاف مطلوبٌ مجهول');
        $this->assertSame(0, UserNotification::where('user_id', $client->id)->count(), 'ولا يُشعَر العميل بشيء');
    }

    /** والنصّ المبهم جداً كذلك — حرفان لا يصفان مستنداً. */
    public function test_a_too_short_request_is_refused(): void
    {
        [$consult, $lawyer] = $this->consult();

        $this->actingAs($lawyer)
            ->post("/lawyer/consults/{$consult->id}/reqdocs", ['docs' => 'أ'])
            ->assertSessionHasErrors('docs');
    }

    /** والطلب المبيَّن يصل العميل **بنصّه**. */
    public function test_a_named_request_reaches_the_client_verbatim(): void
    {
        [$consult, $lawyer, $client] = $this->consult();

        $this->actingAs($lawyer)
            ->post("/lawyer/consults/{$consult->id}/reqdocs", ['docs' => 'صورة السجل التجاري سارية'])
            ->assertRedirect();

        $fresh = $consult->fresh();
        $this->assertSame('بانتظار استكمال البيانات', $fresh->status);
        $this->assertContains('صورة السجل التجاري سارية', $fresh->missing);

        $this->assertSame(
            1,
            UserNotification::where('user_id', $client->id)
                ->where('body', 'like', '%صورة السجل التجاري سارية%')->count(),
            'العميل يعرف ما المطلوب منه'
        );
    }
}
