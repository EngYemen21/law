<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * **دراسة التنفيذ وإسناد محاميه في شاشة التنفيذ.** (قرارا المالك 2026-09-12)
 *
 * قراران يسهل أن ينقضهما تعديلٌ لاحق بصمت: (١) الدراسة تُعرض للمكتب وحده، وتجري في الخلفيّة
 * فلا تحجز الملفّ — غيابها حالةٌ هادئة لا خطأ، وما لم يعتمده المستشار لا يصل العميل.
 * (٢) الإسناد بابٌ لمن يملكه (`canAssign`) على ملفٍّ مفتوح وحده — والتسعير يلزمه إسناد.
 *
 * الاختبار على نصّ المصدر لأنّ المقيس ترتيبُ الشاشة وحرّاسها لا استجابة الخادم.
 */
class ExecStudyUiTest extends TestCase
{
    private function ui(): string
    {
        return (string) file_get_contents(resource_path('js/pages/execflow.tsx'));
    }

    /** ما بين تعريف تفاصيل العميل وبداية بطاقة الدراسة = مسار العميل وحده. */
    private function clientPath(): string
    {
        $ui = $this->ui();
        $from = strpos($ui, 'const ClientExecDetail');
        $to = strpos($ui, '// ── دراسة التنفيذ');
        $this->assertIsInt($from);
        $this->assertIsInt($to);

        return substr($ui, $from, $to - $from);
    }

    public function test_the_study_card_shows_the_basis_the_office_prices_against(): void
    {
        $ui = $this->ui();

        foreach ([
            'دراسة التنفيذ',
            'جاهزية السند',
            'درجة الصعوبة',
            'الإجراءات المتوقّعة',
            'المدة المتوقعة',
            'مؤشّرات التحصيل',
            'المخاطر',
            'مستندات ناقصة',
        ] as $needle) {
            $this->assertStringContainsString($needle, $ui, $needle);
        }
    }

    /** الدراسة للمكتب: تُعرض خلف حارس الدور، ولا تصل شاشة العميل أصلاً. */
    public function test_the_study_is_rendered_for_office_roles_only(): void
    {
        $this->assertStringContainsString("{role !== 'client' && <ExecStudyCard r={r} />}", $this->ui());

        $client = $this->clientPath();
        $this->assertStringNotContainsString('study', $client, 'مسار العميل لا يمسّ الدراسة');
        $this->assertStringNotContainsString('ExecStudyCard', $client);
        // ويبقى على بابه القديم الذي يحجب غير المعتمد والاحتياطيّ
        $this->assertStringContainsString('execAiPresentation(r, true)', $client);
    }

    /** الدراسة تجري في الخلفيّة ولا توقف الملفّ — فغيابها يُقال هادئاً لا يُترك صمتاً. */
    public function test_a_missing_study_reads_as_being_prepared_not_as_a_failure(): void
    {
        $ui = $this->ui();
        $this->assertStringContainsString('الدراسة قيد الإعداد', $ui);
        $this->assertStringContainsString('لا توقف سير الملفّ', $ui);
        // وبعد التسعير لا معنى لانتظارها، فتختفي البطاقة بدل انتظارٍ أبديّ
        $this->assertStringContainsString('if (r.stage > 4) {', $ui);
    }

    /** ما لم يعتمده المستشار يقرأه المكتب موسوماً — ولا توهم الشاشة أنّه بلغ العميل. */
    public function test_an_unapproved_study_is_marked_as_awaiting_the_consultant(): void
    {
        $ui = $this->ui();
        $this->assertStringContainsString('بانتظار اعتماد المستشار', $ui);
        $this->assertStringContainsString('ولا تصل العميل قبل الاعتماد', $ui);
    }

    /** الإسناد: لمن يملكه، على ملفٍّ مفتوح، وبموزّع الإجراءات نفسه. */
    public function test_the_assign_control_is_gated_on_permission_and_an_open_file(): void
    {
        $ui = $this->ui();
        $this->assertStringContainsString(
            "{role !== 'client' && r.canAssign && !r.closed && <ExecAssignCard r={r} lawyers={lawyers} act={act} />}",
            $ui,
        );
        $this->assertStringContainsString("act('assignLawyer', { lawyer_id: Number(sel) })", $ui);
        $this->assertStringContainsString('إسناد إلى محامٍ', $ui);
        $this->assertStringContainsString('إعادة الإسناد', $ui);
        // ولا أثر للإسناد في مسار العميل
        $this->assertStringNotContainsString('ExecAssignCard', $this->clientPath());
    }

    /** التسعير مقابل الدراسة: في بطاقة الإدارة وفي نموذج المحامي — وبقولٍ صريح حين لا دراسة. */
    public function test_pricing_states_the_basis_it_prices_against(): void
    {
        $ui = $this->ui();
        $this->assertSame(2, substr_count($ui, 'execStudyBasis(r.study)'), 'بطاقة الإدارة ونموذج المحامي كلاهما');
        $this->assertStringContainsString('أساس التسعير من الدراسة', $ui);
        $this->assertStringContainsString('لا دراسة تنفيذٍ بعد', $ui);
    }

    /** الإشارات تصف واقع الخادم: ملفٌّ بلا محامٍ لا يُسعَّر، ومن يملك الإسناد يُقال له ذلك. */
    public function test_the_hints_admit_that_an_unassigned_file_blocks_pricing(): void
    {
        $ui = $this->ui();
        $this->assertStringContainsString('بانتظار إسناد محامٍ', $ui);
        $this->assertStringContainsString('r.canAssign && execUnassigned(r) && r.stage >= 2', $ui);
        $this->assertStringContainsString('التسعير يلزمه إسناد الملفّ إليك', $ui);
    }

    /** عقد الخادم كما تستهلكه الشاشة — حقلٌ يسقط منه يكسر البطاقتين بصمت. */
    public function test_the_types_carry_the_whole_server_contract(): void
    {
        $types = (string) file_get_contents(resource_path('js/lib/exec-flow.ts'));

        foreach ([
            'export interface ExecStudy',
            'expectedProceduresCount: number',
            'durationEstimate: string',
            'recovery: string[]',
            'risks: string[]',
            'pending: boolean',
            'approved: boolean',
            'study?: ExecStudy | null',
            'canAssign?: boolean',
            'lawyerId?: number | null',
        ] as $needle) {
            $this->assertStringContainsString($needle, $types, $needle);
        }

        // غياب `lawyerId` (قبل هجرة العقد) لا يقلب كلّ ملفّ إلى «بلا محامٍ»
        $this->assertStringContainsString('r.lawyerId === undefined ? !r.lawyer : r.lawyerId === null', $types);
    }
}
