<?php

namespace App\Console\Commands;

use App\Services\Ai\LegalSourceBundle;
use App\Services\Ai\LegalSourceSync;
use Illuminate\Console\Command;

/**
 * مزامنة المصادر القانونيّة المشحونة مع الشيفرة (`database/legal-sources/*.json`) — خطوةٌ في `deploy.sh`.
 *
 * بلا هذه الخطوة يبدأ كلّ خادمٍ جديد بجدول مصادر فارغ، فيعمل المساعد القانونيّ بلا سندٍ واحد.
 * وهي آمنة للتكرار: تشغيلها مرّتين لا يُنشئ شيئاً، ولا تستبدل نصّاً بلا إصدارٍ جديد، ولا تخفّض
 * حالة ما اعتمده محامٍ (السياسة كاملةً في `LegalSourceSync`).
 *
 * ملفٌّ معطوب ⇒ خروجٌ بفشل قبل أيّ كتابة — فيتوقّف النشر ويُرى الخطأ بدل مصادر ناقصة بصمت.
 */
class SyncLegalSources extends Command
{
    protected $signature = 'ai:sync-sources
        {--path= : مجلّد الملفّات (الافتراضيّ database/legal-sources)}
        {--dry-run : افحص واعرض ما سيحدث بلا كتابة}';

    protected $description = 'مزامنة المصادر القانونيّة المشحونة مع الشيفرة (الجديد مسودة، والمعتمد لا يُخفَّض)';

    public function handle(LegalSourceSync $sync): int
    {
        $dir = rtrim((string) ($this->option('path') ?: database_path('legal-sources')), '/\\');
        $files = glob($dir.DIRECTORY_SEPARATOR.'*.json') ?: [];
        sort($files);

        if ($files === []) {
            $this->error("لا ملفّات مصادر في {$dir}");

            return self::FAILURE;
        }

        $bundles = array_map(fn (string $f) => LegalSourceBundle::fromFile($f), $files);
        $ok = $sync->run($bundles, (bool) $this->option('dry-run'));

        if (! $ok) {
            foreach ($sync->errors as $error) {
                $this->error($error);
            }
            $this->error('لم يُكتب شيء — أصلِح الملفّات أعلاه ثمّ أعِد التشغيل.');

            return self::FAILURE;
        }

        $this->table(
            ['الملفّ', 'جديد (مسودة)', 'جديد معتمد بشهادة الملفّ', 'حُدِّث', 'بلا تغيير', 'معلَّق'],
            collect($sync->counts)->map(fn (array $c, string $file) => [
                $file, $c[LegalSourceSync::CREATED], $c[LegalSourceSync::CREATED_APPROVED],
                $c[LegalSourceSync::UPDATED], $c[LegalSourceSync::UNCHANGED], $c[LegalSourceSync::HELD],
            ])->values()->all(),
        );

        if ($sync->held !== []) {
            $this->warn('معلَّق — صفوفٌ معتمدة تغيّر نصّها في الملفّ فتُركت على نصّها المعتمد حتى يراجعها محامٍ: '
                .implode('، ', array_slice($sync->held, 0, 20)).(count($sync->held) > 20 ? ' …' : ''));
        }

        if ($this->option('dry-run')) {
            $this->warn('فحص فقط — أزِل --dry-run للكتابة.');
        }

        return self::SUCCESS;
    }
}
