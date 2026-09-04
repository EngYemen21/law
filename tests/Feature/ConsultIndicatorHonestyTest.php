<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **لا مؤشّرَ يعتمد حقلاً لا يكتبه أحد.**
 *
 * كان عدّاد «المتأخّرة» شرطه `(c.mins || 0) > 100`، و`mins` **لا كاتبَ له في `app/`
 * كلّه**: عمودٌ بافتراضيّ صفر في هجرة، ثمّ `toCard` — ولا شيء بينهما. فالشرط كاذبٌ
 * أبداً. ولم يكن يُعرض، فبقي مخفيّاً — وهذا أسوأ من عدّادٍ يكذب علناً: لا أحد يراه
 * ليشكّ فيه.
 *
 * وهو خامسُ عطلٍ من نوعه في المشروع بعد `'محولة إلى قضية'` و`'بانتظار التأكيد'`
 * و«جلسات اليوم» و`'مؤكدة'`. فالحارس **يمسح الشاشة آلياً** بدل إصلاح واحدٍ بعد واحد.
 */
class ConsultIndicatorHonestyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * حقولٌ تُرسَل في البطاقة ولا يكتبها أيّ مسار — يُمنع بناء مؤشّرٍ عليها.
     *
     * @return array<string, string> اسم الحقل في البطاقة => اسم العمود
     */
    private const UNWRITTEN = ['mins' => 'mins'];

    private function screen(): string
    {
        // تُسقط التعليقات: بعضها يشرح العطل باقتباس شيفرته فيُشعل الحارس على شرحه
        return (string) preg_replace(
            '#/\*.*?\*/|//[^\n]*#su',
            '',
            (string) file_get_contents(resource_path('js/pages/employee/consults.tsx'))
        );
    }

    /** **الحارس الأثمن:** لا مؤشّر مبنيٌّ على حقلٍ ميت. */
    public function test_no_indicator_depends_on_a_field_no_path_writes(): void
    {
        $code = $this->screen();
        $offenders = [];

        foreach (array_keys(self::UNWRITTEN) as $field) {
            if (str_contains($code, "c.{$field}")) {
                $offenders[] = "«{$field}» يُقرأ في الشاشة ولا يكتبه أيّ مسار";
            }
        }

        $this->assertSame([], $offenders, implode("\n", $offenders));
    }

    /** والحقولُ المذكورة **ما زالت بلا كاتب** — وإلّا سقط المبرّر لا الحارس. */
    public function test_the_unwritten_fields_are_still_unwritten(): void
    {
        foreach (self::UNWRITTEN as $column) {
            $written = false;

            foreach ([app_path('Http/Controllers'), app_path('Support'), app_path('Jobs'), app_path('Console')] as $dir) {
                foreach ($this->phpFiles($dir) as $file) {
                    $src = (string) file_get_contents($file);
                    if (str_contains($src, "'{$column}' =>") || str_contains($src, "->{$column} =")) {
                        $written = true;
                        break 2;
                    }
                }
            }

            $this->assertFalse(
                $written,
                "«{$column}» صار له كاتب — أعد المؤشّر المبنيّ عليه بدل إبقائه محذوفاً"
            );
        }
    }

    /** **ومؤشّر التحويل صادقٌ ومعروض** — مشتقٌّ من قضيّةٍ قائمة لا من حالةٍ ميتة. */
    public function test_the_conversion_indicator_is_derived_from_a_real_case(): void
    {
        $code = $this->screen();

        $this->assertStringContainsString('!!c.caseNo', $code, 'الدليل وجود قضيّة للتذكرة');
        $this->assertStringContainsString('telemetry.toCase', $code, 'ويُعرض لا يُحسب ويُهمَل');
        $this->assertStringNotContainsString('محولة إلى قضية', $code, 'لا عودةَ للحالة الميتة');
    }

    /** **ورادار «المنعقدة الآن» يعني المنعقدة** — لا ما قارب أن يبدأ. */
    public function test_the_live_radar_counts_only_running_sessions(): void
    {
        $code = $this->screen();

        $this->assertStringNotContainsString(
            "c.channel === 'مرئية' && c.startable",
            $code,
            '`startable` تعني «لم تبدأ بعدُ» — فلا تدخل رادار المنعقدة'
        );
        $this->assertStringNotContainsString(
            '<Badge text="جلسة جارية"',
            $code,
            'الشارة تُشتقّ من الحالة لا تُكتب بيدٍ'
        );
    }

    /** **والإسناد يُقاس بالإسناد** — لا بنصٍّ يضمن الخادم ألّا يخلو. */
    public function test_assignment_is_measured_by_the_id_not_the_placeholder(): void
    {
        $code = $this->screen();

        $this->assertStringNotContainsString(
            "c.lawyer === '—'",
            $code,
            'السنتينل وراثةٌ من بياناتٍ ثابتة — والخادم يكتب نائباً نصّياً دائماً'
        );
        $this->assertStringContainsString('c.lawyerId != null', $code);
    }

    /** والبطاقة تحمل المعرّف فعلاً — وإلّا كان الشرط الجديد ميتاً كسابقه. */
    public function test_the_card_actually_carries_the_lawyer_id(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);

        $assigned = Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-IND-'.uniqid(), 'subject' => 'نزاع',
            'type' => 'استشارة', 'channel' => 'مرئية', 'status' => 'جديدة',
            'session' => 'بانتظار الجلسة', 'tone' => 'b-blue',
            'lawyer' => $lawyer->name, 'assigned_lawyer_id' => $lawyer->id,
        ]);

        $unassigned = Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-IND-'.uniqid(), 'subject' => 'نزاع',
            'type' => 'استشارة', 'channel' => 'مرئية', 'status' => 'جديدة',
            'session' => 'بانتظار الجلسة', 'tone' => 'b-blue',
            // النائب النصّيّ الذي يكتبه `ConsultBooking` حين لا محاميَ مسنَداً
            'lawyer' => 'المستشار القانوني',
        ]);

        $this->assertSame($lawyer->id, $assigned->toCard()['lawyerId']);
        $this->assertNull($unassigned->toCard()['lawyerId'], 'النائب النصّيّ لا يُعدّ إسناداً');
        $this->assertNotEmpty($unassigned->toCard()['lawyer'], 'ومع ذلك لا يخلو النصّ — وهذه علّة العطل');
    }

    /** @return array<int,string> */
    private function phpFiles(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $out[] = $file->getPathname();
            }
        }

        return $out;
    }
}
