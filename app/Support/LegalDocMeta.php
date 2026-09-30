<?php

namespace App\Support;

use App\Models\LegalDocument;

/**
 * **بيانات رأس المستند وتذييله — مصدرٌ واحد لـPDF وWord** (2026-09-30).
 *
 * كان ملفّ Word يبني رأسه وتذييله في المتصفّح بقيمٍ غير قيم PDF: تاريخ اليوم هجريّاً مقابل تاريخ الإنشاء ميلاديّاً،
 * ونصّ تذييلٍ مختلف، وبلا «حرّر بواسطة» ولا خانة التوقيع. الآن يقرأ المخرجان هذه البيانات نفسها.
 *
 * **بقرار المالك (2026-09-30):** لا اسمَ إنجليزيّاً تحت اسم المكتب، ولا سطرَ مراجع (الرقم المرجعي · التصنيف ·
 * القضية · التذكرة · التاريخ)، ولا عبارةَ «صادر من المنصة… سري ومحمي» — والتاريخ بجانب «حرّر بواسطة».
 *
 * القيم نصّيّةٌ خامّ (غير مُهرَّبة) — يهرّبها كلّ مخرَجٍ بطريقته.
 *
 * @phpstan-type Meta array{officeName: string, licenseNo: string, phone: string, email: string, address: string, showHeader: bool, logoPath: ?string, logoDataUri: ?string, date: string, title: string, author: string, approved: ?array{by: string, at: string}}
 */
final class LegalDocMeta
{
    /** @return Meta */
    public static function of(LegalDocument $doc): array
    {
        $header = $doc->header_config ?? LegalDocument::defaultHeader();
        $logoPath = self::logoPath((string) ($header['logoUrl'] ?? ''));
        $logoData = str_starts_with((string) ($header['logoUrl'] ?? ''), 'data:image') ? (string) $header['logoUrl'] : null;

        return [
            'showHeader' => ! empty($header['showHeader']),
            'officeName' => (string) (($header['officeName'] ?? '') ?: SettingsRegistry::str('office_name')),
            'licenseNo' => (string) ($header['licenseNo'] ?? ''),
            'phone' => (string) ($header['phone'] ?? ''),
            'email' => (string) ($header['email'] ?? ''),
            'address' => (string) ($header['address'] ?? ''),
            'logoPath' => $logoPath,
            'logoDataUri' => $logoData ?? ($logoPath !== null ? self::dataUri($logoPath) : null),
            'date' => $doc->created_at ? $doc->created_at->translatedFormat('d M Y') : now()->translatedFormat('d M Y'),
            'title' => (string) $doc->title,
            'author' => (string) ($doc->user->name ?? 'المحامي المختص'),
            'approved' => $doc->status === 'approved'
                ? ['by' => (string) ($doc->approver->name ?? 'الإدارة'), 'at' => $doc->approved_at ? $doc->approved_at->translatedFormat('d M Y') : '']
                : null,
        ];
    }

    /** ملفّ الشعار على القرص — المختار (داخل `public/` وحده) وإلّا الافتراضيّ (`021.png`). */
    private static function logoPath(string $logoUrl): ?string
    {
        if ($logoUrl !== '' && ! str_starts_with($logoUrl, 'data:') && ($file = self::publicImagePath($logoUrl)) !== null) {
            return $file;
        }

        $default = public_path('images/021.png');

        return is_file($default) ? $default : null;
    }

    /**
     * مسار صورة الشعار على القرص — **داخل `public/` حصراً وبامتداد صورة**، أو `null`.
     *
     * `logoUrl` يكتبه صاحب المستند في ترويسته؛ وكان يُمرَّر إلى `public_path()` كما هو، فـ`/../.env`
     * يقرأ ملف البيئة ويضمّنه في الـPDF (مفتاح التطبيق وكلمات المرور). `realpath` يحلّ `..` والروابط
     * الرمزيّة، ثم يُشترط أن يبقى الناتج تحت `public/`. (نُقل من `DocumentEditorController` ليقرأه PDF وWord معاً.)
     */
    public static function publicImagePath(string $url): ?string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        if (! preg_match('/\.(png|jpe?g|svg)$/i', $path)) {
            return null;
        }

        $root = realpath(public_path());
        $real = realpath(public_path(ltrim($path, '/')));

        return ($root !== false && $real !== false && is_file($real) && str_starts_with($real, $root.DIRECTORY_SEPARATOR))
            ? $real
            : null;
    }

    private static function dataUri(string $path): string
    {
        $mime = str_ends_with($path, '.svg') ? 'image/svg+xml' : (str_ends_with($path, '.png') ? 'image/png' : 'image/jpeg');

        return 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($path));
    }
}
