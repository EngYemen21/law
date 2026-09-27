<?php

namespace Tests\Feature;

use App\Models\Meeting;
use App\Support\ReferenceNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * **كلّ رقمٍ مرجعيّ من `ReferenceNumber::next`** — يفحص التكرار ويتوسّع عند امتلاء السنة. كان مرجع
 * الاجتماع يُولَّد في موضعين بـ`'M-'.yy.random_int(100, 999)`: ٩٠٠ قيمة في السنة بلا فحصٍ على عمودٍ
 * فريد، فيفشل إنشاء الاجتماع بخطأ خادم حين يتكرّر (وسقط به اختبارٌ في الحزمة الكاملة).
 */
class ReferenceNumberSourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_reference_is_built_from_a_bare_random_number(): void
    {
        $offenders = [];
        foreach (Finder::create()->files()->in(app_path())->name('*.php')->notName('ReferenceNumber.php') as $file) {
            foreach (explode("\n", $file->getContents()) as $i => $line) {
                if (preg_match("/'(ref|number|ext_id)'\\s*=>\\s*'[^']*'\\s*\\..*random_int\\(/", $line)
                    || preg_match('/\\$number\\s*=\\s*"[A-Z]+-.*random_int\\(/', $line)) {
                    $offenders[] = $file->getRelativePathname().':'.($i + 1);
                }
            }
        }

        $this->assertSame([], $offenders, 'رقمٌ مرجعيّ عشوائيّ بلا فحص تكرار — استعمل ReferenceNumber::next');
    }

    public function test_meeting_references_do_not_collide(): void
    {
        $refs = [];
        foreach (range(1, 40) as $i) {
            $ref = ReferenceNumber::next(Meeting::class, 'ref', 'M');
            Meeting::create(['ref' => $ref, 'title' => 'اجتماع', 'when_label' => 'اليوم', 'status' => 'قادم']);
            $refs[] = $ref;
        }

        $this->assertCount(40, array_unique($refs));
        $this->assertMatchesRegularExpression('/^M-\\d{4}-\\d{4}$/', $refs[0]);
    }
}
