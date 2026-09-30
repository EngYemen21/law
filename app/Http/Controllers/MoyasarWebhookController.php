<?php

namespace App\Http\Controllers;

use App\Services\MoyasarService;
use App\Support\MoyasarWebhook;
use App\Support\PaymentReconciler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * مستقبِل إشعارات Moyasar (Webhooks) — يؤكّد الدفع ويحدّث حالة الفاتورة/الاستشارة تلقائياً.
 * محميّ بالتحقّق من `secret_token` في جسم الحدث. عام بلا مصادقة (ميسّر يناديه).
 */
class MoyasarWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        Log::info('moyasar.webhook.hit', [
            'type' => $request->input('type'),
            'id' => data_get($request->input('data'), 'id'),
            'ip' => $request->ip(),
        ]);

        abort_unless(MoyasarWebhook::secret() !== null, 503, 'Moyasar webhook غير مُهيّأ.');

        abort_unless(MoyasarWebhook::verify($request), 403, 'سرّ Moyasar غير صالح.');

        // **لا ثقةَ بجسم الحدث**: الدفعة تُجلب من ميسّر بمعرّفها، ولا تسويةَ بلا جلبٍ ناجح (فصل البيئات 2026-09-29).
        // كان يرجع إلى بيانات الحدث إن فشل الجلب — فحدثٌ بسرٍّ مسرَّب أو دفعةٌ تجريبيّة كانت تسوّي فاتورةً حقيقيّة.
        // والجلب بمفتاح البيئة نفسها يطابق الوضع: مفتاحٌ تجريبيّ لا يرى دفعةً حقيقيّة، والعكس.
        $raw = json_decode((string) $request->getContent(), true) ?: [];
        $data = $request->input('data') ?: ($raw['data'] ?? []);
        $id = (string) (data_get($data, 'id') ?: '');
        $payment = null;

        if (str_starts_with($id, 'inv_') && ! empty($data['payments'])) {
            $last = end($data['payments']);
            $payment = empty($last['id']) ? null : app(MoyasarService::class)->fetchPayment((string) $last['id']);
        } elseif ($id !== '') {
            $payment = app(MoyasarService::class)->fetchPayment($id);
        }

        if ($payment === null) {
            Log::warning('moyasar.webhook.fetch_failed', ['id' => $id]);

            return response()->json(['ok' => false], 502); // فشل تحقّق عابر → اطلب من ميسّر إعادة الإرسال
        }

        // حدث الدفع الناجح: نسوّي الفاتورة (idempotent). أنواع أخرى تُقبَل بلا أثر.
        PaymentReconciler::settle($payment, 'webhook');

        return response()->json(['ok' => true]);
    }
}
