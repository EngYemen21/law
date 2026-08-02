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
        abort_unless(MoyasarWebhook::secret() !== null, 503, 'Moyasar webhook غير مُهيّأ.');

        abort_unless(MoyasarWebhook::verify($request->all()), 403, 'سرّ Moyasar غير صالح.');

        // لا تثق بجسم الحدث: أعد جلب الدفعة من ميسّر بالمعرّف ثم سوِّها (اتّساقاً مع مسار الـcallback).
        $paymentId = (string) data_get($request->input('data'), 'id', '');
        $payment = $paymentId !== '' ? app(MoyasarService::class)->fetchPayment($paymentId) : null;

        if ($payment === null) {
            Log::warning('moyasar.webhook.fetch_failed', ['payment_id' => $paymentId]);

            return response()->json(['ok' => false], 502); // فشل تحقّق عابر → اطلب من ميسّر إعادة الإرسال
        }

        // حدث الدفع الناجح: نسوّي الفاتورة (idempotent). أنواع أخرى تُقبَل بلا أثر.
        PaymentReconciler::settle($payment, 'webhook');

        return response()->json(['ok' => true]);
    }
}
