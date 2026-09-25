<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\Invoice;
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Models\User;
use App\Support\LawyerName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **مسحٌ شامل: لا يصل العميلَ اسمُ محامٍ كاملاً — في أيّ شاشة.**
 *
 * قرار المالك 2026-09-11: العميل يرى «سارة. ق» لا «سارة القحطاني».
 *
 * **ولماذا مسحٌ شامل لا فحصُ بطاقة؟** لأنّ التقنيع يُطبَّق حقلاً حقلاً، فيُنسى حقلٌ في كلّ
 * جولة: نُسي في قوالب البريد الأربعة، وفي البثّ اللحظيّ، وفي واجهة التفرّغ، وفي بطاقة
 * «المستشار المخصص» (رُصدت 2026-09-25). أربع مرّاتٍ لعلّةٍ واحدة. فالحارس يفحص **الحمولة
 * كاملةً** لا حقلاً بعينه: أيّ حقلٍ جديد يُضاف ويحمل الاسم يُسقِط هذا الاختبار فوراً.
 *
 * **والجذر عولج كذلك:** `LawyerName::forClient` كانت تمرّر العمود النصّيّ خاماً حين يغيب
 * المحامي المسنَد — فيصل العميلَ ما كُتب في الصفّ أيّاً كان، وقد كان اسمَ إداريّ.
 */
class LawyerNameNeverLeaksTest extends TestCase
{
    use RefreshDatabase;

    private const FULL = 'سارة القحطاني';

    private const SHORT = 'سارة. ق';

    private User $client;

    private User $lawyer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = User::factory()->create(['role' => Role::Client, 'name' => 'موكّل التجربة']);
        $this->lawyer = User::factory()->create(['role' => Role::Lawyer, 'name' => self::FULL]);
    }

    private function seedEverything(): void
    {
        $ticket = Ticket::create([
            'user_id' => $this->client->id, 'number' => 'SB-LEAK-1', 'type' => 'نزاع تجاري',
            'department' => 'القسم التجاري', 'status' => 'قيد التحليل', 'tone' => 'b-blue',
            'assigned_lawyer' => self::FULL, 'assigned_lawyer_id' => $this->lawyer->id,
        ]);

        LegalCase::create([
            'user_id' => $this->client->id, 'ticket_id' => $ticket->id, 'number' => 'CASE-LEAK-1',
            'type' => 'نزاع تجاري', 'status' => 'منظورة', 'tone' => 'b-blue', 'update_text' => '—',
            'assigned_lawyer' => self::FULL, 'assigned_lawyer_id' => $this->lawyer->id,
        ]);

        Consult::create([
            'user_id' => $this->client->id, 'ref' => 'CN-LEAK-1', 'subject' => 'استشارة',
            'channel' => 'مرئية', 'status' => 'بانتظار تحديد الموعد', 'session' => 'بانتظار الجلسة',
            'lawyer' => self::FULL, 'assigned_lawyer_id' => $this->lawyer->id,
            'price' => 450, 'vat' => 68, 'total' => 518,
        ]);

        Invoice::create([
            'user_id' => $this->client->id, 'number' => 'INV-LEAK-1', 'description' => 'استشارة CN-LEAK-1',
            'amount' => 518, 'status' => 'مستحقة', 'tone' => 'b-amber', 'due_label' => 'تستحق قريباً',
        ]);
    }

    /** @return list<string> */
    private function screens(): array
    {
        return ['dashboard', 'tickets', 'myconsults', 'cases', 'invoices', 'appointments'];
    }

    /**
     * حمولة الصفحة **مفكوكة الترميز**.
     *
     * إنيرشيا تضع الحمولة في `data-page` بترميز `\uXXXX`، فالبحث عن نصٍّ عربيّ في الـHTML
     * الخام لا يجده أبداً — ويمرّ الحارس فراغاً وهو لا يفحص شيئاً. كشفه اختبارُ «الاسم
     * المقنَّع يصل فعلاً» حين سقط، وهو موجودٌ لهذا بالضبط.
     */
    private function payloadOf(string $route): ?string
    {
        // **تُقرأ خصائص إنيرشيا من مساعد الإطار.** كشطُ الـHTML يتعثّر بترميز `\uXXXX`
        // وباختلاف القوالب، وبناءُ رأس `X-Inertia` بيدٍ يرتدّ 409 على اختلاف النسخة.
        //
        // وما ليس صفحةَ إنيرشيا (تحويلٌ أو ملفّ) يُتخطّى — ويحرس العدّادُ ألّا يُتخطّى الكلّ.
        $response = $this->actingAs($this->client)->get(route($route));

        if ($response->getStatusCode() !== 200) {
            return null;
        }

        $props = null;
        try {
            $response->assertInertia(function ($page) use (&$props) {
                $props = $page->toArray()['props'] ?? [];

                return true;
            });
        } catch (\Throwable) {
            return null;
        }

        $decoded = $props;

        return is_array($decoded) ? (string) json_encode($decoded, JSON_UNESCAPED_UNICODE) : null;
    }

    /**
     * **الحارس الأهمّ:** لا شاشةَ عميلٍ تحمل الاسم الكامل في حمولتها.
     *
     * يُفحص الرد الخام (‏JSON حمولة Inertia) لا العناصر المعروضة: الحقل المخفيّ اليوم
     * قد يُعرض غداً، والاسم وصل المتصفّح فعلاً.
     */
    public function test_no_client_screen_carries_the_full_lawyer_name(): void
    {
        $this->seedEverything();

        $scanned = 0;

        foreach ($this->screens() as $route) {
            $payload = $this->payloadOf($route);

            if ($payload === null) {
                continue;
            }

            $scanned++;
            $this->assertStringNotContainsString(
                self::FULL,
                $payload,
                "الشاشة «{$route}» تحمل اسم المحامي كاملاً في حمولتها."
            );
        }

        // ولا يمرّ الحارس فراغاً إن تغيّرت المسارات فصارت كلّها تُتخطّى
        $this->assertGreaterThanOrEqual(5, $scanned, 'الحارس لم يفحص إلّا شاشاتٍ قليلة — تحقّق من أسماء المسارات.');
    }

    /** وليس الحجبَ الكامل: الاسم المقنَّع يصل فعلاً — وإلّا لصار الاختبار يمرّ بشاشةٍ فارغة. */
    public function test_the_short_name_does_reach_the_client(): void
    {
        $this->seedEverything();

        $this->assertStringContainsString(
            self::SHORT,
            (string) $this->payloadOf('myconsults'),
            'الاسم المقنَّع لا يصل العميل — الحارس يفحص شاشةً لا تعرض المحامي أصلاً.'
        );
    }

    // ── جذر العائلة: الدالّة نفسها ───────────────────────────────────────────────

    public function test_a_verified_lawyer_is_shortened(): void
    {
        $this->assertSame(self::SHORT, LawyerName::forClient($this->lawyer, self::FULL, '—'));
    }

    /** **النصّ المخزَّن لا يمرّ خاماً** — ولو كان اسماً يبدو سليماً. */
    public function test_a_stored_name_without_a_verified_lawyer_is_withheld(): void
    {
        $this->assertSame('المستشار المكلف', LawyerName::forClient(null, self::FULL, 'المستشار المكلف'));
    }

    /** ولا اسمُ غير المحامي: إداريٌّ يتابع الملفّ لا يُعرض اسمُه على العميل. */
    public function test_a_non_lawyer_user_is_withheld(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin, 'name' => 'نورة الدوسري']);

        $this->assertSame('—', LawyerName::forClient($admin, 'نورة الدوسري', '—'));
    }

    /** والتسمية لا تُقنَّع فتصير «المستشار. ا» — العطل المعكوس. */
    public function test_a_placeholder_label_is_never_shortened(): void
    {
        foreach (LawyerName::PLACEHOLDERS as $label) {
            $out = LawyerName::forClient(null, $label, '—');

            $this->assertSame($label, $out, "التسمية «{$label}» لم تمرّ كما هي.");
            $this->assertStringNotContainsString('. ', $out, 'قُنِّعت تسميةٌ ليست اسماً.');
        }
    }

    /**
     * **والتسمية تصل العميل فعلاً — لا تُحجب.**
     *
     * حُجبت يوماً بحجّة أنّ النصّ المخزَّن كلّه مشبوه، فرأى صاحبُ ملفٍّ مُصعَّد «—» مكان
     * «الإدارة العليا»: خبرٌ يعنيه أُسقط باسم الخصوصيّة. فالحدُّ بين الاثنين هو القائمة
     * المعدودة لا حجبٌ شامل.
     */
    public function test_an_escalated_file_still_tells_the_client_who_holds_it(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin, 'name' => 'مدير المكتب']);

        $this->assertSame(
            LawyerName::SENIOR,
            LawyerName::forClient($admin, LawyerName::SENIOR, '—'),
            'الملفّ المُصعَّد لا يخبر صاحبه أنّه عند الإدارة العليا.'
        );
    }
}
