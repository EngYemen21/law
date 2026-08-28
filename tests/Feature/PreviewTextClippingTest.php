<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Execution;
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * أعمدة المعاينة (last_message · update_text · last_action) عرضها varchar(255)
 * بينما نصّ الرسالة يصلها بلا سقف، و'strict' => true في اتصال mysql يجعل القاعدة
 * ترمي SQLSTATE[22001] بدل أن تقصّ — فيفشل الإدراج كلّه ولا تُنشأ التذكرة أصلاً.
 *
 * ⚠️ الحزمة تعمل على sqlite في الذاكرة (phpunit.xml)، وsqlite **لا يفرض عرض varchar**
 * إطلاقاً. فلو بُني هذا الملفّ على «هل يسقط الطلب؟» لمرّ أخضر بلا أي إصلاح — تماماً كما
 * تمرّ اليوم كل اختبارات الحزمة بينما المستخدم يرى 500 على mysql. لذلك التوكيدات هنا
 * على **طول القيمة المحفوظة** لا على غياب الانهيار: مستقلّة عن المحرّك، وتسقط قبل
 * الإصلاح على sqlite وmysql معاً.
 */
class PreviewTextClippingTest extends TestCase
{
    use RefreshDatabase;

    /** الحدّ الأقصى لعمود المعاينة — نفس عرض varchar في المهاجرات. */
    private const PREVIEW_MAX = 255;

    private function client(): User
    {
        return User::factory()->create(['role' => Role::Client]);
    }

    /** حالة المستخدم المبلَّغ عنها حرفياً: نصّ طويل في «تفاصيل الطلب» عند فتح تذكرة. */
    public function test_opening_a_ticket_with_a_long_body_clips_the_preview(): void
    {
        $client = $this->client();
        $long = str_repeat('مرحبا الف ', 500); // 5000 حرف — الحدّ الأقصى المسموح في التحقّق

        $this->actingAs($client)->post('/tickets', [
            'type' => 'رأي قانوني مكتوب',
            'department' => 'الاستشارات القانونية',
            'details' => $long,
        ])->assertRedirect();

        $ticket = Ticket::firstOrFail();

        $this->assertLessThanOrEqual(
            self::PREVIEW_MAX,
            mb_strlen((string) $ticket->last_message),
            'عمود المعاينة last_message تجاوز عرض varchar(255) — سيسقط الإدراج على mysql.'
        );
    }
    /** ردّ طويل: المعاينة تُقصّ بينما نصّ الرسالة نفسه يبقى كاملاً في جدول الرسائل. */
    public function test_a_long_reply_clips_the_preview_but_keeps_the_message_intact(): void
    {
        $client = $this->client();
        $this->actingAs($client)->post('/tickets', ['type' => 'نزاع تجاري'])->assertRedirect();
        $ticket = Ticket::firstOrFail();

        $long = str_repeat('نصّ طويل جداً ', 300);
        $this->actingAs($client)->post(route('tickets.messages.store', $ticket), ['body' => $long])->assertNoContent();

        $ticket->refresh();
        $this->assertLessThanOrEqual(self::PREVIEW_MAX, mb_strlen((string) $ticket->last_message));

        // الجوهر: القصّ يخصّ المعاينة وحدها — المحتوى الحقيقي لا يُمسّ
        // العلاقة messages() تحمل orderBy('id') مدمجاً، وlatest() يضيف ترتيباً ثانياً لا يقلبه
        $body = (string) $ticket->messages()->where('who', 'client')->get()->last()?->body;
        $this->assertGreaterThan(self::PREVIEW_MAX, mb_strlen($body), 'نصّ الرسالة قُصّ أيضاً — القصّ تسرّب من المعاينة إلى المحتوى.');
        $this->assertStringContainsString(rtrim($long), html_entity_decode($body, ENT_QUOTES, 'UTF-8'));
    }

    /** حارس ضدّ القصّ المفرط: النصّ القصير يُحفظ حرفياً بلا «…». */
    public function test_short_text_is_left_untouched(): void
    {
        $client = $this->client();
        $short = 'لدي خلاف حول عقد توريد.';

        $this->actingAs($client)->post('/tickets', ['type' => 'نزاع تجاري', 'details' => $short])->assertRedirect();

        $this->assertSame($short, Ticket::firstOrFail()->last_message);
    }

    /** العمودان الشقيقان بنفس العرض ونفس الخطر. */
    public function test_case_and_execution_previews_clip_too(): void
    {
        $client = $this->client();
        $long = str_repeat('تحديث طويل ', 200);

        $case = LegalCase::create([
            'user_id' => $client->id, 'number' => 'CASE-2026-9101', 'type' => 'نزاع',
            'status' => 'نشطة', 'tone' => 'b-blue', 'update_text' => $long,
        ]);
        $exec = Execution::create([
            'user_id' => $client->id, 'number' => 'EXE-2026-9101', 'subject' => 'تنفيذ',
            'status' => 'جديد', 'tone' => 'b-blue', 'last_action' => $long,
        ]);

        $this->assertLessThanOrEqual(self::PREVIEW_MAX, mb_strlen((string) $case->fresh()->update_text));
        $this->assertLessThanOrEqual(self::PREVIEW_MAX, mb_strlen((string) $exec->fresh()->last_action));
    }

    /**
     * القصّ بالأحرف لا بالبايتات: العربية بايتان للحرف، وsubstr كان سيبتر المحرف الأخير
     * نصفين فيُخزَّن محرف تالف — وطول varchar في mysql يُحسب بالأحرف فيمرّ الطول ويفسد النصّ.
     */
    public function test_arabic_is_clipped_by_characters_not_bytes(): void
    {
        $clipped = Ticket::clipPreview(str_repeat('ع', 300));

        $this->assertSame(self::PREVIEW_MAX, mb_strlen($clipped));
        $this->assertStringEndsWith('…', $clipped);
        $this->assertSame($clipped, mb_convert_encoding($clipped, 'UTF-8', 'UTF-8'), 'نتج محرف تالف من القصّ بالبايتات.');
    }
    /**
     * مطلب صاحب المنتج: حقل الرسالة بلا سقف. 50000 حرف عربي = 100000 بايت — أكثر من
     * ضعف سعة TEXT (65535)، فلولا التوسيع إلى MEDIUMTEXT وإزالة max:5000 لسقط الطلب.
     */
    public function test_a_fifty_thousand_character_message_is_stored_whole(): void
    {
        $client = $this->client();
        $this->actingAs($client)->post('/tickets', ['type' => 'نزاع تجاري'])->assertRedirect();
        $ticket = Ticket::firstOrFail();

        $huge = str_repeat('ن', 50000);
        $this->actingAs($client)->post(route('tickets.messages.store', $ticket), ['body' => $huge])->assertNoContent();

        $body = (string) $ticket->messages()->where('who', 'client')->get()->last()?->body;
        $this->assertSame(50000, mb_strlen($body), 'نصّ الرسالة لم يُحفظ كاملاً.');
        $this->assertSame($huge, $body);
    }

    /**
     * أسوأ حالات تمدّد التهريب: نصّ كلّه محارف تتحوّل إلى كيانات HTML أطول منها.
     * 20000 محرف `"` تصير 120000 حرفاً بعد e() — تتجاوز TEXT وحدها.
     */
    public function test_escaping_expansion_does_not_overflow_the_column(): void
    {
        $client = $this->client();
        $this->actingAs($client)->post('/tickets', ['type' => 'نزاع تجاري'])->assertRedirect();
        $ticket = Ticket::firstOrFail();

        $hostile = str_repeat('"&<>', 5000); // 20000 محرف ⇒ ~120000 بعد التهريب
        $this->actingAs($client)->post(route('tickets.messages.store', $ticket), ['body' => $hostile])->assertNoContent();

        $body = (string) $ticket->messages()->where('who', 'client')->get()->last()?->body;
        $this->assertGreaterThan(65535, mb_strlen($body), 'التهريب لم يتمدّد كما هو متوقّع — الاختبار لا يفحص ما يدّعيه.');
        $this->assertSame($hostile, html_entity_decode($body, ENT_QUOTES, 'UTF-8'), 'النصّ لم يُسترجع مطابقاً للأصل.');

        // والمعاينة قُصّت رغم ضخامة الرسالة
        $this->assertLessThanOrEqual(self::PREVIEW_MAX, mb_strlen((string) $ticket->fresh()->last_message));
    }
}