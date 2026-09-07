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
 * والحارسُ يمسح **شاشات الاستشارات كلَّها** ومكتبتَيها — لا شاشةً واحدة.
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

    /** الشاشة التي وُلد الحارس منها — تُبقيها الفحوصُ القديمة مقصودةً بعينها. */
    private function screen(): string
    {
        return $this->screens()['employee/consults.tsx'];
    }

    /**
     * **كلّ شاشةٍ تعرض استشارة — لا شاشةٌ واحدة.**
     *
     * كان الحارس يمسح `employee/consults.tsx` وحدها، فمرّت من تحته **خمسة مؤشّرات**
     * مبنيّة على `mins` في شاشتَي الإدارة: «استشارات متأخرة» بشريطه الأحمر، وتبويبٌ
     * كامل، و«متوسط زمن المعالجة»، و«{n} طلب متأخر»، و«منذ {n} دقيقة». حارسٌ يحرس
     * بيتاً من سبعة يُطمئن أكثر ممّا يحمي.
     *
     * @return array<string,string> مسارٌ نسبيّ => شيفرتُه بلا تعليقات
     */
    private function screens(): array
    {
        $files = [
            'employee/consults.tsx' => 'js/pages/employee/consults.tsx',
            'lawyer/consults.tsx' => 'js/pages/lawyer/consults.tsx',
            'admin/consults.tsx' => 'js/pages/admin/consults.tsx',
            'admin/consult-requests.tsx' => 'js/pages/admin/consult-requests.tsx',
            'admin/consultrecv.tsx' => 'js/pages/admin/consultrecv.tsx',
            'myconsults.tsx' => 'js/pages/myconsults.tsx',
            'lib/consult-ui.tsx' => 'js/lib/consult-ui.tsx',
            'lib/employee-data.ts' => 'js/lib/employee-data.ts',
        ];

        $out = [];
        foreach ($files as $name => $rel) {
            $path = resource_path($rel);
            if (! is_file($path)) {
                continue;
            }

            // تُسقط التعليقات: بعضها يشرح العطل باقتباس شيفرته فيُشعل الحارس على شرحه
            $out[$name] = (string) preg_replace(
                '#/\*.*?\*/|//[^\n]*#su',
                '',
                (string) file_get_contents($path)
            );
        }

        return $out;
    }

    /** **الحارس الأثمن:** لا مؤشّر مبنيٌّ على حقلٍ ميت. */
    public function test_no_indicator_depends_on_a_field_no_path_writes(): void
    {
        $offenders = [];

        foreach ($this->screens() as $name => $code) {
            foreach (array_keys(self::UNWRITTEN) as $field) {
                if (preg_match('/\bc\.'.preg_quote($field, '/').'\b/u', $code)) {
                    $offenders[] = "{$name}: «{$field}» يُقرأ ولا يكتبه أيّ مسار";
                }
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

    /**
     * **والبديل مقيسٌ فعلاً — لا كذبةٌ محلَّ كذبة.**
     *
     * حذفُ مؤشّرٍ ميت سهل؛ والأصعبُ ألّا يُستبدل بحقلٍ ثانٍ يصل صفراً. `ageMins` يُشتقّ
     * من `created_at` عند كلّ قراءة، فيتحرّك مع الزمن ويُميّز «لم يُقَس» بـ`null`.
     */
    public function test_the_replacement_age_is_actually_measured(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);

        $fresh = Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-AGE-'.uniqid(), 'subject' => 'نزاع',
            'type' => 'استشارة', 'channel' => 'مرئية', 'status' => 'بانتظار التسعير',
            'session' => 'بانتظار الجلسة', 'tone' => 'b-amber', 'lawyer' => 'مستشار',
        ]);

        $old = Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-AGE-'.uniqid(), 'subject' => 'نزاع',
            'type' => 'استشارة', 'channel' => 'مرئية', 'status' => 'بانتظار التسعير',
            'session' => 'بانتظار الجلسة', 'tone' => 'b-amber', 'lawyer' => 'مستشار',
        ]);
        $old->forceFill(['created_at' => now()->subMinutes(300)])->saveQuietly();

        $this->assertLessThanOrEqual(1, $fresh->toCard()['ageMins'], 'الطلب الآن عمرُه دقائقُ لا أكثر');
        $this->assertSame(300, $old->fresh()->toCard()['ageMins'], 'والقديم يُقاس بعمره الحقيقيّ');

        // والحقل القديم لم يعد يصل أصلاً — فلا يُبنى عليه ثانية
        $this->assertArrayNotHasKey('mins', $fresh->toCard());
    }

    /** **ولا يُقرأ «لم يُقَس» صفراً**: العمر يقبل `null` في العقد لا `number` وحده. */
    public function test_the_contract_admits_unmeasured(): void
    {
        $this->assertStringContainsString(
            'ageMins: number | null;',
            (string) file_get_contents(resource_path('js/lib/consult-ui.tsx')),
            'صفرٌ لغيرِ المقيس هو العطل نفسه في ثوبٍ جديد'
        );

        foreach (['admin/consults.tsx', 'admin/consult-requests.tsx'] as $name) {
            $this->assertStringContainsString(
                'ageMins != null',
                $this->screens()[$name],
                "{$name}: يجب أن يُفحص «قِيس» قبل المقارنة"
            );
            $this->assertStringNotContainsString(
                'c.ageMins || 0',
                $this->screens()[$name],
                "{$name}: `|| 0` يُعيد الكذبة"
            );
        }
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
