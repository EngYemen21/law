<?php

namespace Tests\Feature;

use App\Models\Consult;
use Tests\TestCase;

/**
 * **كلّ حالةٍ يعدّها تبويبٌ حالةٌ يكتبها مسار.**
 *
 * كانت `'محولة إلى قضية'` تُغذّي عدّاداً وتبويباً في ثلاث شاشات ولا يكتبها أيّ مسار
 * في `app/` — عدّادٌ صفرٌ أبداً يقول للمدير إن المكتب لا يُحوّل استشارةً قطّ. وزرّ
 * «تحويل إلى قضية» يُحوّل **التذكرة** ولا يمسّ `consults.status`.
 *
 * وهذه ثالثةُ حالةٍ ميتة يكتشفها المشروع بعد وقوعها: `'بانتظار التأكيد'` في
 * الاجتماعات، وعدّاد «جلسات اليوم». **وهذا الاختبار هو الوحيد الذي كان سيكشفها
 * قبل الشحن** — ولذلك هو أعمّ من هذه الدفعة: يمنع ميلاد الرابعة.
 */
class ConsultStatusCatalogueTest extends TestCase
{
    /** ملفّات الخادم التي تكتب حالة استشارة. */
    private function serverSource(): string
    {
        $files = [
            app_path('Http/Controllers/Staff/ConsultController.php'),
            app_path('Http/Controllers/ConsultController.php'),
            app_path('Http/Controllers/ConsultBookingController.php'),
            app_path('Http/Controllers/ZoomWebhookController.php'),
            app_path('Support/ConsultBooking.php'),
            app_path('Support/ConsultSummary.php'),
            app_path('Console/Commands/AutoCloseMissedConsults.php'),
        ];

        return implode("\n", array_map(
            fn ($f) => is_file($f) ? (string) file_get_contents($f) : '',
            $files
        ));
    }

    /** لا حالةَ في الكتالوج بلا كاتبٍ حيّ. */
    public function test_every_catalogued_status_is_written_by_some_path(): void
    {
        $source = $this->serverSource();
        $orphans = [];

        foreach (Consult::STATUSES as $status) {
            // إسنادٌ فعليّ لا ذكرٌ في رسالة: `'status' => '…'` أو `->status = '…'`
            $written = str_contains($source, "'status' => '{$status}'")
                || str_contains($source, "status = '{$status}'")
                || str_contains($source, "'{$status}', ") // وسائط `setConsultSession`
                || str_contains($source, ", '{$status}'");

            if (! $written) {
                $orphans[] = $status;
            }
        }

        $this->assertSame([], $orphans, "حالةٌ في الكتالوج لا يكتبها مسار:\n".implode("\n", $orphans));
    }

    /** والحالة الميتة لم تعد فيه — ولا عادت إليه. */
    public function test_the_dead_status_stays_out_of_the_catalogue(): void
    {
        $this->assertNotContains(
            'محولة إلى قضية',
            Consult::STATUSES,
            'زرّ التحويل يُحوّل التذكرة لا الاستشارة — فلا كاتبَ لهذه الحالة'
        );

        $this->assertNotContains('مغلقة', Consult::STATUSES, '«مغلقة» حالةُ قضيّة لا استشارة');
    }

    /** وحالتا النهاية الحقيقيّتان فيه — كانتا تقعان ولا تظهران في أيّ تبويب. */
    public function test_the_real_terminal_statuses_are_catalogued(): void
    {
        $this->assertContains('ملغاة', Consult::STATUSES);
        $this->assertContains('لم يحضر', Consult::STATUSES);
    }

    /** وحالات ما قبل الجلسة جزءٌ منه — مصدرٌ واحد لا قائمتان تنحرفان. */
    public function test_the_pre_session_statuses_are_a_subset_of_the_catalogue(): void
    {
        foreach (Consult::PRE_SESSION_STATUSES as $status) {
            $this->assertContains($status, Consult::STATUSES, "«{$status}» خارج الكتالوج");
        }
    }

    /**
     * **وقاموس الأولويّة واحد.** كان الخادم يقبل `'عادية'` — وهي أولويّة فرز
     * **التذكرة** يكتبها الذكاء — ويرفض `'منخفضة'` التي تحملها استشاراتٌ قائمة.
     * فصفٌّ في القاعدة لا يستطيع أحدٌ إعادة ضبطه على قيمته نفسها.
     */
    public function test_the_priority_vocabulary_matches_what_consults_actually_hold(): void
    {
        $rules = (string) file_get_contents(app_path('Http/Controllers/Staff/ConsultController.php'));

        $this->assertStringContainsString('in:عالية,متوسطة,منخفضة', $rules);
        $this->assertStringNotContainsString(
            'in:عالية,متوسطة,عادية',
            $rules,
            '«عادية» أولويّةُ تذكرة لا استشارة'
        );
    }

    /**
     * **والواجهة لا تعدّ حالةً خارج الكتالوج.**
     *
     * تمسح شاشات الاستشارات بحثاً عن مقارناتٍ نصّيّة على `status`، فتُسقط أيّ قيمةٍ
     * لا يكتبها الخادم — وهو بالضبط ما أنتج العدّاد الميت.
     */
    public function test_no_screen_counts_a_status_the_server_never_writes(): void
    {
        $screens = [
            resource_path('js/pages/employee/consults.tsx'),
            resource_path('js/pages/lawyer/consults.tsx'),
            resource_path('js/pages/admin/consults.tsx'),
        ];

        $offenders = [];

        foreach ($screens as $file) {
            if (! is_file($file)) {
                continue;
            }

            $src = (string) file_get_contents($file);
            // نُسقط تعليقات الشرح: تذكر العطل بنصّه فتُشعل الحارس على نفسه
            $src = (string) preg_replace('#/\*.*?\*/|//[^\n]*#su', '', $src);

            preg_match_all("/status\s*===\s*'([^']+)'/u", $src, $m);

            foreach (array_unique($m[1]) as $status) {
                if (! in_array($status, Consult::STATUSES, true)) {
                    $offenders[] = basename($file).": «{$status}»";
                }
            }
        }

        $this->assertSame([], $offenders, "شاشةٌ تعدّ حالةً لا يكتبها الخادم:\n".implode("\n", $offenders));
    }
}
