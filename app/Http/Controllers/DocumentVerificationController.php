<?php

namespace App\Http\Controllers;

use App\Support\DocumentVerification;
use App\Support\SettingsRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * صفحة التحقّق العامّة التي يفتحها رمز الاستجابة على الوثائق المطبوعة (`DocumentVerification`).
 *
 * **عامّةٌ بلا دخول** — يمسحها موظّف استقبال أو جهةٌ خارجيّة لا حساب لها. وحمايتها التوقيع لا
 * الجلسة. والرفض **صفحةٌ عربيّة بحالتها** لا صفحة خطأ عامّة: من مسح رمزاً معدَّلاً يحتاج أن يقرأ
 * «هذا الرابط لم يصدر من المكتب»، لا رمز ٤٠٣ مجرّداً. (ولذا لا `abort` هنا.)
 */
class DocumentVerificationController extends Controller
{
    public function __invoke(Request $request, string $kind, string $ref): Response
    {
        $office = SettingsRegistry::str('office_name');

        // نسبيّ: انظر توثيق `DocumentVerification` — التوقيع على المسار لا على المضيف
        if (! $request->hasValidSignature(false)) {
            return $this->page($office, 'invalid', null, 403);
        }

        $facts = DocumentVerification::facts($kind, $ref);
        if ($facts === null) {
            return $this->page($office, 'missing', null, 404);
        }

        return $this->page($office, 'valid', $facts, 200);
    }

    /**
     * @param  'valid'|'invalid'|'missing'  $state
     * @param  array{label:string, rows:list<array{0:string,1:string}>}|null  $facts
     */
    private function page(string $office, string $state, ?array $facts, int $status): Response
    {
        return response()
            ->view('document-verify', ['office' => $office, 'state' => $state, 'facts' => $facts], $status)
            // الحالة حيّة (تتغيّر بالاعتماد أو الإلغاء) — فلا تُخزَّن نسخةٌ قديمة منها
            ->header('Cache-Control', 'no-store, private')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
