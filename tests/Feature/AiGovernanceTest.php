<?php

namespace Tests\Feature;

use App\Enums\AiSource;
use App\Models\AiRun;
use App\Services\Ai\AiDataClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * حوكمة البيانات (P5): تصنيف رباعيّ يحكم الاحتفاظ والإرسال والتسجيل.
 *
 * **المدد الافتراضيّة ليست قراراً نهائياً** — هي افتراضات محافظة تنتظر اعتماد
 * المكتب، ويضبطها `services.ai.retention`. ما يُختبَر هنا هو الآليّة: أن التصنيف
 * يحكم فعلاً، وأن المسح لا يقع بالخطأ، وأن التجريد يُبقي القياس التاريخيّ.
 */
class AiGovernanceTest extends TestCase
{
    use RefreshDatabase;

    private function aiRun(array $overrides = []): AiRun
    {
        // created_at ليست في fillable (عمداً: النموذج سجلّ) فتُتجاهَل صامتةً عبر create()
        $createdAt = $overrides['created_at'] ?? null;
        unset($overrides['created_at']);

        $run = AiRun::create(array_merge([
            'task_type' => 'consult',
            'entity_type' => 'App\\Models\\Consult',
            'entity_id' => 1,
            'entity_ref' => 'CN-2026-1',
            'source' => AiSource::AiSuccess->value,
            'status' => AiRun::STATUS_COMPLETED,
            'trace_id' => (string) Str::uuid(),
            'review_note' => 'ملاحظة مراجع تخصّ ملفّ عميل',
            'confidence_signals' => ['documents_attached' => true],
        ], $overrides));

        if ($createdAt !== null) {
            $run->forceFill(['created_at' => $createdAt])->saveQuietly();
        }

        return $run->fresh();
    }

    // ── التصنيف يحكم لا يسمّي ──

    public function test_the_most_sensitive_class_never_leaves_raw_and_is_never_logged(): void
    {
        $this->assertFalse(AiDataClass::Restricted->mayLeaveTheOffice(), 'الهويّات والمستندات لا تُرسَل خاماً');
        $this->assertFalse(AiDataClass::Restricted->mayBeLogged());
        $this->assertFalse(AiDataClass::Confidential->mayBeLogged(), 'بيانات الملفّ لا تُسجَّل في السجلّ التشغيليّ');

        $this->assertTrue(AiDataClass::Internal->mayBeLogged());
        $this->assertTrue(AiDataClass::Public->mayBeLogged());
    }

    /** الأحسّ يُحتفَظ به أقصر: أعلى خطراً وأقلّ حاجةً للبقاء. */
    public function test_retention_shortens_as_sensitivity_rises(): void
    {
        config(['services.ai.retention' => []]);

        $this->assertNull(AiDataClass::Public->retentionDays(), 'المؤشّرات المجهّلة تبقى للقياس التاريخيّ');
        $this->assertGreaterThan(
            AiDataClass::Confidential->retentionDays(),
            AiDataClass::Internal->retentionDays()
        );
        $this->assertGreaterThan(
            AiDataClass::Restricted->retentionDays(),
            AiDataClass::Confidential->retentionDays()
        );
    }

    public function test_configuration_overrides_the_conservative_default(): void
    {
        config(['services.ai.retention.restricted' => 30]);

        $this->assertSame(30, AiDataClass::Restricted->retentionDays());
    }

    public function test_every_ai_run_field_has_a_declared_class(): void
    {
        foreach (['task_type', 'model', 'entity_ref', 'review_note', 'confidence', 'حقل.جديد'] as $field) {
            $this->assertInstanceOf(AiDataClass::class, AiDataClass::ofAiRunField($field));
        }

        // المجهول يُعامَل سرّياً — الافتراض الآمن لا المتساهل
        $this->assertSame(AiDataClass::Confidential, AiDataClass::ofAiRunField('حقل.جديد'));
    }

    // ── المسح: لا يقع بالخطأ ──

    /** حذف بيانات لا يقع من أمرٍ مجدول بلا نيّة صريحة. */
    public function test_purge_is_a_dry_run_until_forced(): void
    {
        config(['services.ai.retention' => ['confidential' => 1, 'internal' => 1]]);
        $this->aiRun(['created_at' => now()->subDays(400)]);

        $this->artisan('ai:purge')->assertExitCode(0);

        $this->assertSame(1, AiRun::count(), 'العرض وحده لا يحذف');
        $this->assertNotNull(AiRun::first()->entity_ref);
    }

    /** التجريد يُبقي الهيكل التشغيليّ: يبقى السؤال «كم أُنتج وكم رُفض» قابلاً للإجابة. */
    public function test_stripping_removes_client_fields_but_keeps_the_operational_shape(): void
    {
        config(['services.ai.retention' => ['confidential' => 30, 'internal' => null]]);
        $this->aiRun(['created_at' => now()->subDays(60)]);

        $this->artisan('ai:purge --force')->assertExitCode(0);

        $run = AiRun::first();
        $this->assertNotNull($run, 'القيد يبقى للقياس التاريخيّ');
        $this->assertNull($run->entity_ref, 'المعرّف المقروء يُجرَّد');
        $this->assertNull($run->entity_id);
        $this->assertNull($run->review_note);
        $this->assertNull($run->confidence_signals);

        $this->assertSame('consult', $run->task_type, 'نوع المهمّة يبقى');
        $this->assertSame(AiSource::AiSuccess, $run->source, 'والمصدر يبقى');
    }

    public function test_full_deletion_happens_only_after_the_longest_window(): void
    {
        config(['services.ai.retention' => ['confidential' => 30, 'internal' => 365]]);
        $this->aiRun(['created_at' => now()->subDays(60)]);   // يُجرَّد فقط
        $this->aiRun(['created_at' => now()->subDays(400)]);  // يُحذف

        $this->artisan('ai:purge --force')->assertExitCode(0);

        $this->assertSame(1, AiRun::count());
    }

    public function test_recent_records_are_untouched(): void
    {
        config(['services.ai.retention' => ['confidential' => 30, 'internal' => 365]]);
        $this->aiRun(['created_at' => now()->subDays(5)]);

        $this->artisan('ai:purge --force')->assertExitCode(0);

        $this->assertSame('CN-2026-1', AiRun::first()->entity_ref);
    }

    public function test_no_retention_configured_means_nothing_is_purged(): void
    {
        config(['services.ai.retention' => ['confidential' => null, 'internal' => null]]);
        $this->aiRun(['created_at' => now()->subDays(2000)]);

        $this->artisan('ai:purge --force')->assertExitCode(0);

        $this->assertSame(1, AiRun::count());
        $this->assertNotNull(AiRun::first()->entity_ref);
    }
    // ── التشفير في السكون ──

    /**
     * `review_note` هو الحقل الحرّ الوحيد في `ai_runs`: فيه يكتب المراجع لماذا رفض
     * مخرجاً — أي وقائع ملفٍّ بلغته. بقيّة الأعمدة رموزٌ وأزمنة ومعرّفات لا محتوى،
     * فمحلّ التشفير هذا الحقل وحده لا الجدول كلّه.
     */
    public function test_the_reviewer_note_is_encrypted_at_rest(): void
    {
        $run = AiRun::create([
            'task_type' => 'consult',
            'source' => AiSource::AiSuccess->value,
            'status' => AiRun::STATUS_COMPLETED,
            'trace_id' => (string) Str::uuid(),
            'review_note' => 'المخرج نسب للعميل إقراراً لم يرد في المحضر.',
        ]);

        $raw = (string) \DB::table('ai_runs')->where('id', $run->id)->value('review_note');

        $this->assertStringNotContainsString('المحضر', $raw, 'الملاحظة تُخزَّن مشفّرة لا خاماً');
        $this->assertSame('المخرج نسب للعميل إقراراً لم يرد في المحضر.', $run->fresh()->review_note);
    }
}
