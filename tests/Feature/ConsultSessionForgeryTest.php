<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Jobs\FinalizeConsultJob;
use App\Models\Consult;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * **لا تُختَم جلسةٌ لم تنعقد.**
 *
 * `end()` كانت الدالّة الوحيدة بلا حارس حالة. ومعها زرُّ الإنهاء في غرفة الجلسة
 * بلا شرطٍ ولا تعطيل — فمن يفتح `/{role}/videoroom?ref=…` مباشرةً ويضغط «إنهاء»
 * يكتب «منتهية» ويُشعر الموكّل **«انتهت جلسة استشارتك»** ويُنهي اجتماع Zoom
 * ويُطلق توليد الملخّص، لجلسةٍ لم تبدأ قطّ.
 *
 * وهذا حرفيّاً التزوير الذي كُتب `noShow()` لمنعه — تعليقُه يصف «بدء+إنهاء فوري»
 * بأنّه «يزوّر السجل جلسةً منعقدة». وسُدَّ نصفُه بحارس النافذة على `start`، وبقي
 * البابُ الأوسع مفتوحاً: **لا حاجة إلى `start` أصلاً**.
 */
class ConsultSessionForgeryTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:Consult,1:User,2:User} */
    private function consult(array $overrides = []): array
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);

        $consult = Consult::create($overrides + [
            'user_id' => $client->id, 'ref' => 'CN-FRG-'.uniqid(), 'subject' => 'نزاع تجاري',
            'type' => 'استشارة', 'channel' => 'مرئية', 'status' => 'محالة للمحامي',
            'session' => 'بانتظار الجلسة', 'tone' => 'b-blue',
            'lawyer' => $lawyer->name, 'assigned_lawyer_id' => $lawyer->id,
            'starts_at' => now()->addMinutes(5),
        ]);

        return [$consult, $lawyer, $client];
    }

    /** **الحارس الأثمن:** جلسةٌ لم تبدأ لا تُختَم، ولا يُشعَر الموكّل بانعقادٍ لم يقع. */
    public function test_a_session_that_never_started_cannot_be_ended(): void
    {
        Queue::fake();
        [$consult, $lawyer, $client] = $this->consult();

        $this->actingAs($lawyer)
            ->post("/lawyer/consults/{$consult->id}/end", ['notes' => 'محضرٌ مختلَق.'])
            ->assertStatus(422);

        $fresh = $consult->fresh();
        $this->assertSame('بانتظار الجلسة', $fresh->session, 'ولا تُختَم');
        $this->assertSame('محالة للمحامي', $fresh->status);
        $this->assertNull($fresh->session_notes, 'ولا يُحفظ محضرٌ لجلسةٍ لم تقع');

        Queue::assertNotPushed(FinalizeConsultJob::class);
        $this->assertSame(
            0,
            UserNotification::where('user_id', $client->id)->count(),
            'ولا يصل الموكّل «انتهت جلسة استشارتك»'
        );
    }

    /** والفائتة كذلك — مخرجُها «لم يحضر» لا ختمُ جلسةٍ وهميّة. */
    public function test_a_no_show_session_cannot_be_ended(): void
    {
        [$consult, $lawyer] = $this->consult(['session' => 'لم تُعقد', 'status' => 'لم يحضر']);

        $this->actingAs($lawyer)
            ->post("/lawyer/consults/{$consult->id}/end", ['notes' => 'محضر.'])
            ->assertStatus(422);

        $this->assertSame('لم تُعقد', $consult->fresh()->session);
    }

    /** **ولا يتحوّل المنع إلى شلل:** الجلسة الجارية تُختَم كما كانت. */
    public function test_a_running_session_is_still_ended(): void
    {
        [$consult, $lawyer] = $this->consult(['session' => 'جلسة جارية', 'status' => 'قيد الاستشارة']);

        $this->actingAs($lawyer)
            ->post("/lawyer/consults/{$consult->id}/end", ['notes' => 'دوّن المستشار وقائع الجلسة.'])
            ->assertRedirect();

        $fresh = $consult->fresh();
        $this->assertSame('منتهية', $fresh->session);
        $this->assertStringContainsString('وقائع الجلسة', (string) $fresh->session_notes);
    }

    /**
     * **والتدوين المتأخّر يبقى ممكناً** — وهو مسارٌ مقصود.
     *
     * ويبهوك Zoom يختم الجلسة حين يخرج الطرفان، قبل أن يضغط المحامي «إنهاء». فلولا
     * قبولُ «منتهية» هنا لضاع محضرُه كلّه — وهو ما يصفه تعليق `end()` نفسه.
     */
    public function test_late_notes_on_an_already_ended_session_are_still_accepted(): void
    {
        [$consult, $lawyer] = $this->consult(['session' => 'منتهية', 'status' => 'منتهية']);

        $this->actingAs($lawyer)
            ->post("/lawyer/consults/{$consult->id}/end", ['notes' => 'تدوينٌ متأخّر بعد ختم الويبهوك.'])
            ->assertRedirect();

        $this->assertStringContainsString('متأخّر', (string) $consult->fresh()->session_notes);
    }

    /** **والمدّة لا تُختلق:** الغرفة لم تعد ترسل عدّاد فتح الصفحة مدّةً رسميّة. */
    public function test_the_room_no_longer_sends_a_fabricated_duration(): void
    {
        $room = (string) file_get_contents(resource_path('js/lib/consult-ui.tsx'));
        $code = (string) preg_replace('#/\*.*?\*/|//[^\n]*#su', '', $room);

        $this->assertStringNotContainsString(
            'duration: dur',
            $code,
            'عدّادٌ يبدأ عند فتح الصفحة ليس مدّةَ جلسة'
        );
        $this->assertStringNotContainsString(
            'ولّد الفريق القانوني ملخص الاستشارة',
            $code,
            'الوظيفة لا تنادي النموذج بلا مادّة — فلا يُوعَد بملخّص'
        );
    }
}
