<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\User;
use App\Support\ZoomSummaryText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * مادّة Zoom مصدرُ محتوىً — لا بديلٌ عن نصٍّ اعتمده إنسان.
 *
 * كان `ConsultSummary::pull` يكتب مخرج Zoom في `consults.summary` مباشرةً، لأن
 * الاستشارات وحدها بلا عمود `zoom_summary` خلافاً للاجتماعات. ومع مسار الاعتماد
 * صار ذلك يعني أن ملخّصاً وقّعه محامٍ وقرأه العميل يُستبدَل صامتاً بمخرج نموذج.
 *
 * والقاعدة منقولة حرفياً من `MeetingSummary`: المادّة تُحفظ دائماً، وحقلُ العميل
 * لا يُلمس إلّا إن كان فارغاً أو قالبياً.
 */
class ConsultZoomSummaryTest extends TestCase
{
    use RefreshDatabase;

    private function consult(array $attrs = []): Consult
    {
        $client = User::factory()->create(['role' => Role::Client]);

        return Consult::create(array_merge([
            'user_id' => $client->id, 'ref' => 'CN-ZS-'.uniqid(), 'subject' => 'نزاع تجاري',
            'type' => 'استشارة', 'channel' => 'مرئية', 'status' => 'منتهية', 'session' => 'منتهية', 'lawyer' => 'أ. سارة',
            'tone' => 'b-green',
        ], $attrs));
    }

    /** نصٌّ معتمد لا يُعدّ قالبياً — فلا يُستبدَل بمادّة Zoom. */
    public function test_an_approved_summary_is_not_a_placeholder(): void
    {
        $consult = $this->consult([
            'summary' => 'الوقائع: نزاع على مستخلصات مقاولة. الرأي القانوني: للعميل حقّ المطالبة.',
            'summary_approved_at' => now(),
        ]);

        $this->assertFalse(ZoomSummaryText::isPlaceholderSummary($consult->summary));
    }

    /**
     * واحتياطيّ الاستشارة يُعدّ قالبياً — بنصّه الحرفيّ.
     *
     * كانت القائمة تحمل «تعذّر إعداد الملخّص» والمكتوب فعلاً «تعذّر إعداد ملخّص
     * الاستشارة»، فلا تطابق: يبقى نصّ التعذّر معروضاً ومادّةُ Zoom الحقيقيّة مهملة.
     */
    public function test_the_consult_fallback_text_is_recognised_as_a_placeholder(): void
    {
        $fallback = "ملخص استشارة — CN-1\n\n"
            .'تعذّر إعداد ملخّص الاستشارة بالذكاء الاصطناعي حالياً. الاستشارة بشأن «نزاع» '
            .'بحاجة إلى إعداد الملخّص والرأي القانوني يدوياً من الفريق القانوني قبل اعتماده.';

        $this->assertTrue(ZoomSummaryText::isPlaceholderSummary($fallback));
    }

    /**
     * و`null` قالبيّ — وهو ما يجعل Zoom موردَ المادّة التلقائيّ لحالة «انتهت بلا تدوين».
     *
     * تلك الحالة تترك `summary` فارغاً عمداً (لا يُنادى النموذج بلا مادّة)، فحين تصل
     * مادّة Zoom لاحقاً تملأ الفراغ بلا شرطٍ خاصّ يُكتب لها.
     */
    public function test_an_empty_summary_is_a_placeholder_so_zoom_can_fill_it(): void
    {
        $this->assertTrue(ZoomSummaryText::isPlaceholderSummary(null));
        $this->assertTrue(ZoomSummaryText::isPlaceholderSummary('   '));
    }

    /** والعمود مفصول: مادّة Zoom تُحفظ ولا تُخلط بحقل العميل. */
    public function test_zoom_material_has_its_own_column(): void
    {
        $consult = $this->consult([
            'summary' => 'نصّ المحامي المعتمد.',
            'summary_approved_at' => now(),
            'zoom_summary' => 'مادّة Zoom: ما دار في الجلسة كما التقطه النظام.',
        ]);

        $card = $consult->fresh()->toCard();

        $this->assertSame('نصّ المحامي المعتمد.', $card['summary']);
        $this->assertStringContainsString('مادّة Zoom', (string) $card['zoomSummary']);
    }

    /** ومادّة Zoom لا تصل العميل — بطاقته لا تحملها أصلاً. */
    public function test_zoom_material_never_reaches_the_client_card(): void
    {
        $consult = $this->consult(['zoom_summary' => 'مادّة داخليّة من الجلسة.']);

        $this->assertArrayNotHasKey('zoomSummary', $consult->fresh()->toClientCard());
    }
}
