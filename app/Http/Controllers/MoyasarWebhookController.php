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

        // لا تثق بجسم الحدث وحده إن أمكن: أعد جلب الدفعة من ميسّر بالمعرّف، وإلا استخدم بيانات الحدث المعتمدة.
        $raw = json_decode((string) $request->getContent(), true) ?: [];
        $data = $request->input('data') ?: ($raw['data'] ?? []);
        $id = (string) (data_get($data, 'id') ?: '');
        $payment = null;

        if (str_starts_with($id, 'pay_')) {
            $payment = app(MoyasarService::class)->fetchPayment($id) ?: ($data['status'] === 'paid' ? $data : null);
        } elseif (str_starts_with($id, 'inv_') && ! empty($data['payments'])) {
            $last = end($data['payments']);
            if (! empty($last['id'])) {
                $payment = app(MoyasarService::class)->fetchPayment((string) $last['id']) ?: ($last['status'] === 'paid' ? $last : null);
            }
        } elseif ($id !== '') {
            $payment = app(MoyasarService::class)->fetchPayment($id) ?: (isset($data['status']) ? $data : null);
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
