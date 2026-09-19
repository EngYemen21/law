<?php

namespace Tests\Feature;

use App\Support\LegalCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * **كتالوج الأقسام مصدرٌ واحد** — على نمط TicketPriorityCatalogueTest.
 *
 * كانت القائمة في خمسة أماكن (LEGAL_CATS · SVC · DEPTS · Specialties::ALL/SYNONYMS ·
 * AiPromptRegistry::DEPARTMENTS · LegalKnowledge::REQUIRES_SPECIFIC_AUTHORITY). يحرس هذا الاختبار
 * ألّا تعود نسخةٌ منها في الشيفرة، وأنّ كلّ صياغةٍ قديمة كانت تُكتب في البيانات ما زالت تُطابَق.
 */
class LegalCatalogueSourceOfTruthTest extends TestCase
{
    use RefreshDatabase;

    /** معرّفاتٌ لا يجوز أن تعود كشيفرة (التعليقات مستثناة: تذكر التاريخ عمداً). */
    private const DEAD_IDENTIFIERS = [
        '/\bLEGAL_CATS\b/', '/\blegalCatServices\b/', '/\bDEPTS\b/', '/\bSVC_GROUPS\b/', '/\bSVC\b/',
        '/Specialties::ALL\b/', '/\bSYNONYMS\b/', '/AiPromptRegistry::DEPARTMENTS\b/', '/REQUIRES_SPECIFIC_AUTHORITY\b/',
    ];

    /** يحذف التعليقات من شيفرة PHP أو TS قبل البحث. */
    private function withoutComments(string $code): string
    {
        $code = preg_replace('#/\*.*?\*/#s', '', $code) ?? $code;

        return preg_replace('#(^|[^:])//[^\n]*#', '$1', $code) ?? $code;
    }

    public function test_no_hardcoded_department_list_returns_to_the_code(): void
    {
        $files = array_merge(
            File::allFiles(app_path()),
            File::allFiles(resource_path('js')),
        );

        foreach ($files as $file) {
            if (! in_array($file->getExtension(), ['php', 'ts', 'tsx'], true)) {
                continue;
            }

            $code = $this->withoutComments($file->getContents());
            foreach (self::DEAD_IDENTIFIERS as $pattern) {
                $this->assertDoesNotMatchRegularExpression($pattern, $code, "{$file->getRelativePathname()} يعيد قائمة أقسامٍ ثابتة ({$pattern}).");
            }
        }
    }

    /**
     * صياغاتٌ قديمة كُتبت فعلاً في التذاكر والقضايا والمحامين: أقسام نموذج التذكرة السابق، وتخصّصات
     * الخادم، وأقسام صفحة الاستشارة — كلٌّ منها يُطابَق قسماً في الكتالوج بلا مطابقةٍ احتوائيّة.
     */
    public function test_every_legacy_department_wording_still_resolves(): void
    {
        $legacy = [
            // نموذج التذكرة السابق
            'الأحوال الشخصية والأسرة', 'التركات والمواريث', 'القضايا التجارية', 'الشركات والاستثمار', 'القضايا العمالية',
            'القضايا العقارية', 'المقاولات والإنشاءات', 'القضايا الإدارية', 'القضايا الجزائية والجنائية', 'الجرائم المعلوماتية والتقنية',
            'التنفيذ', 'الأوراق التجارية والمطالبات المالية', 'القضايا المصرفية والتمويلية', 'التأمين', 'الأخطاء الطبية',
            'الحوادث والمرور', 'الملكية الفكرية', 'التحكيم والوساطة وتسوية المنازعات', 'العقود والاتفاقيات', 'الزكاة والضرائب والجمارك',
            'المنافسة والامتثال التجاري', 'حماية المستهلك والتجارة الإلكترونية', 'الإعلام والنشر', 'النقل والخدمات اللوجستية',
            'الأوقاف والمنظمات غير الربحية', 'الخدمات القانونية الحكومية والتنظيمية', 'الاستشارات القانونية',
            'الترافع والتمثيل القانوني', 'الصياغة والأعمال القانونية المساندة',
            // تخصّصات الخادم وأقسام الطاقم القانونيّة
            'الأحوال الشخصية', 'الشركات', 'العقارات', 'البنوك والتمويل', 'الجرائم المعلوماتية', 'القضايا الجنائية', 'التركات والأوقاف',
            // صفحة طلب الاستشارة
            'القسم التجاري', 'قسم القضايا العمالية', 'القسم العقاري', 'القسم الإداري', 'القسم الجزائي', 'قسم القضايا المالية والمصرفية',
            'قسم المقاولات والتحكيم الهندسي', 'قسم الأوراق التجارية', 'قسم القضايا الطبية', 'قسم المرور والحوادث',
            'قسم المواريث والتركات', 'قسم الأوقاف والوصايا', 'قسم الشركات والحوكمة', 'قسم العقود', 'قسم الاندماج والاستحواذ',
            'قسم الإفلاس والتصفية', 'قسم الاستثمار والتراخيص', 'قسم الضرائب والزكاة', 'قسم التقنية وحماية البيانات',
            'قسم التحكيم وتسوية المنازعات', 'قسم الاستشارات العامة', 'قسم حماية المستهلك',
        ];

        foreach ($legacy as $wording) {
            $this->assertNotNull(LegalCatalogue::resolveDepartment($wording), "«{$wording}» لم يعد يُطابَق أيّ قسم.");
        }
    }

    public function test_merged_departments_exist_only_as_aliases_of_the_general_department(): void
    {
        foreach (['الاستشارات القانونية', 'الترافع والتمثيل القانوني', 'الصياغة والأعمال القانونية المساندة'] as $merged) {
            $this->assertFalse(LegalCatalogue::departments(activeOnly: false)->contains('name', $merged), "«{$merged}» قسمٌ مستقلّ.");
            $this->assertSame(LegalCatalogue::GENERAL_CODE, LegalCatalogue::resolveDepartment($merged)?->code);
        }
    }
}
