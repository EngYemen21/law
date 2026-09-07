<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **زرُّ التنزيل ينزّل — أو يقول إنه لا ينزّل.**
 *
 * كانت أزرار الأرشيف الثلاثة روابط `<a href>` عاديّة تُرسَل لمجرّد وجود `meet_id`.
 * وحين لا يكون الملفّ مبنيّاً يردّ `RecordingArchive::download` بـ`back()` — فالمتصفّح
 * يتبع التحويل، **وتُعاد الصفحة بلا ملفّ**. المستخدم يرى ومضةً ولا شيء، فيعيد النقر
 * ظنّاً أنّ الأولى ضاعت، **فتُجدوَل مهمّةُ بناءٍ في كلّ نقرة**.
 *
 * و`RecordingArchive::isReady` كانت قائمةً ولا يستدعيها الأرشيف أصلاً.
 */
class ArchiveDownloadHonestyTest extends TestCase
{
    use RefreshDatabase;

    private function screen(): string
    {
        return (string) preg_replace(
            '#/\*.*?\*/|//[^\n]*#su',
            '',
            (string) file_get_contents(resource_path('js/pages/admin/archive.tsx'))
        );
    }

    /** **الحارس الأثمن:** البطاقة تحمل الجهوزيّة، والزرّ يقرؤها. */
    public function test_the_archive_row_declares_readiness(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $client = User::factory()->create(['role' => Role::Client]);

        Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-ARC-'.uniqid(), 'subject' => 'نزاع',
            'type' => 'استشارة', 'channel' => 'مرئية', 'status' => 'منتهية',
            'session' => 'منتهية', 'tone' => 'b-green', 'lawyer' => 'مستشار',
            'meet_id' => '9876543210',
        ]);

        $res = $this->actingAs($admin)->get('/admin/archive');
        $res->assertOk();

        $row = collect($res->viewData('page')['props']['rows'])->first();

        $this->assertNotNull($row['zip'], 'الرابط يصل — فالمسار قائم');
        $this->assertFalse($row['videoReady'], 'ولا ملفَّ على القرص — وهذه عين الحالة التي كانت تكذب');
        $this->assertFalse($row['audioReady']);
        $this->assertFalse($row['transcriptReady']);
    }

    /** ولا زرَّ تنزيلٍ لا يفحص الجهوزيّة: المكوّن مصدرٌ واحد. */
    public function test_no_raw_download_anchor_survives(): void
    {
        $code = $this->screen();

        foreach (['a.zip', 'a.audioZip', 'a.transcript'] as $field) {
            $this->assertStringNotContainsString(
                "href={{$field}}>",
                $code,
                "«{$field}» ما زال رابطاً خامّاً يعِد بملفٍّ قد لا يوجد"
            );
        }

        $this->assertSame(6, substr_count($code, '<MediaButton'), 'ثلاثةُ أزرارٍ في عرضَين');
        $this->assertStringContainsString('ready={a.videoReady}', $code);
    }
}
