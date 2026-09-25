<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * **رسائل التحقّق تصل المستخدم بالعربيّة.**
 *
 * كان `APP_LOCALE=en` ولا مجلّد `lang/`، فكلّ قاعدة تحقّقٍ مبنيّة في لارافل تخرج بالإنجليزيّة.
 * ورُصد حيّاً (2026-09-25) أنّ العميل يرى:
 *
 *     The file field must be a file of type: pdf, jpg, jpeg, png, doc, docx.
 *
 * تحت رسالةٍ عربيّة من قواعد المشروع المخصّصة — تناقضٌ في الشاشة الواحدة. والعلّة ليست في
 * الرسائل التي رُئيت بل في **غياب الترجمة أصلاً**، فتشمل كلّ شاشةٍ وكلّ نموذج.
 *
 * ويحرس هذا الملفّ الجذر لا العَرَض: اللغةَ نفسها، ووجودَ ترجمةٍ لكلّ قاعدةٍ يستعملها المشروع،
 * وخلوَّ المخرَج من الإنجليزيّة.
 */
class ArabicValidationMessagesTest extends TestCase
{
    /** القواعد المستعملة فعلاً في متحكّمات المشروع — تُوسَّع متى استُعملت قاعدةٌ جديدة. */
    private const RULES_IN_USE = [
        'required', 'string', 'integer', 'numeric', 'boolean', 'array', 'file',
        'email', 'date', 'date_format', 'mimes', 'in', 'exists', 'confirmed',
        'after', 'before', 'url', 'required_with', 'required_without',
    ];

    /** القواعد ذات الصيغ الأربع (مصفوفة · ملفّ · رقم · نصّ). */
    private const SIZED_RULES = ['min', 'max', 'between', 'size', 'gt', 'gte', 'lt', 'lte'];

    public function test_the_application_speaks_arabic(): void
    {
        $this->assertSame('ar', config('app.locale'), 'لغة التطبيق ليست العربيّة — سترجع رسائل الإطار إنجليزيّة.');
        $this->assertSame('ar', config('app.fallback_locale'), 'لغة الاحتياط ليست العربيّة.');
    }

    public function test_every_rule_the_project_uses_has_an_arabic_message(): void
    {
        foreach (self::RULES_IN_USE as $rule) {
            $this->assertNotSame(
                "validation.{$rule}",
                trans("validation.{$rule}"),
                "القاعدة «{$rule}» بلا ترجمة عربيّة — ستصل المستخدم بالإنجليزيّة."
            );
        }
    }

    public function test_the_sized_rules_are_translated_in_all_four_shapes(): void
    {
        foreach (self::SIZED_RULES as $rule) {
            foreach (['array', 'file', 'numeric', 'string'] as $shape) {
                $key = "validation.{$rule}.{$shape}";

                $this->assertNotSame($key, trans($key), "«{$rule}» بصيغة «{$shape}» بلا ترجمة.");
            }
        }
    }

    /** **الفحص الحقيقيّ:** رسالةٌ مولَّدة فعلاً — لا مجرّد وجود مفتاح. */
    public function test_a_real_validation_failure_speaks_arabic(): void
    {
        $messages = Validator::make(
            ['reason' => 'قصير', 'file' => 'ليس ملفّاً'],
            ['reason' => ['required', 'string', 'min:10'], 'file' => ['file'], 'subject' => ['required']]
        )->errors()->all();

        $this->assertNotEmpty($messages);

        foreach ($messages as $message) {
            $this->assertDoesNotMatchRegularExpression(
                '/\b(field|must|required|invalid|the)\b/i',
                $message,
                "رسالةٌ إنجليزيّة تصل المستخدم: «{$message}»"
            );
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', $message, "رسالةٌ بلا حرفٍ عربيّ: «{$message}»");
        }
    }

    /** واسم الحقل يُعرض بالعربيّة لا بمعرّفه البرمجيّ («حقل reason مطلوب»). */
    public function test_field_names_are_shown_in_arabic(): void
    {
        $message = Validator::make([], ['reason' => ['required']])->errors()->first('reason');

        $this->assertStringContainsString('السبب', $message, 'اسم الحقل يظهر بمعرّفه البرمجيّ لا بالعربيّة.');
        $this->assertStringNotContainsString('reason', $message);
    }
}
