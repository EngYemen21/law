<?php

namespace App\Http\Controllers;

use App\Services\Payments\PaymentGateways;
use App\Support\PaymentReconciler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * مستقبِل إشعارات بوّابات الدفع (Webhooks) — يؤكّد الدفع ويحدّث حالة الفاتورة/الاستشارة تلقائياً.
 * عامّ بلا مصادقة (البوّابة تناديه)، والبوّابة من المسار (`/webhooks/moyasar`، `/webhooks/payments/{gateway}`)
 * وتتحقّق هي من هويّة المُرسِل.
 */
class PaymentWebhookController extends Controller
{
    public function handle(Request $request, string $gateway): JsonResponse
    {
        // بوّابةٌ غير مسجّلة في الإعداد ⇒ لا مستقبِل (404) — لا استثناء ولا كشفٌ لما هو مسجّل
        $gateways = app(PaymentGateways::class);
        abort_unless(in_array($gateway, $gateways->names(), true), 404);
        $driver = $gateways->get($gateway);

        Log::info('payment.webhook.hit', [
            'gateway' => $gateway,
            'type' => $request->input('type'),
            'id' => data_get($request->input('data'), 'id'),
            'ip' => $request->ip(),
        ]);

        abort_unless($driver->webhookConfigured(), 503, 'إشعارات بوّابة الدفع غير مهيّأة.');

        abort_unless($driver->verifyWebhook($request), 403, 'سرّ إشعار بوّابة الدفع غير صالح.');

        // **لا ثقةَ بجسم الحدث**: الدفعة تُجلب من البوّابة بمعرّفها، ولا تسويةَ بلا جلبٍ ناجح (فصل البيئات 2026-09-29).
        // كان يرجع إلى بيانات الحدث إن فشل الجلب — فحدثٌ بسرٍّ مسرَّب أو دفعةٌ تجريبيّة كانت تسوّي فاتورةً حقيقيّة.
        // والجلب بمفتاح البيئة نفسها يطابق الوضع: مفتاحٌ تجريبيّ لا يرى دفعةً حقيقيّة، والعكس.
        $paymentId = $driver->paymentIdFromWebhook($request);
        $payment = $paymentId !== null ? $driver->fetchPayment($paymentId) : null;

        if ($payment === null) {
            Log::warning('payment.webhook.fetch_failed', ['gateway' => $gateway, 'id' => $paymentId]);

            return response()->json(['ok' => false], 502); // فشل تحقّق عابر → اطلب من البوّابة إعادة الإرسال
        }

        // حدث الدفع الناجح: نسوّي الفاتورة (idempotent). أنواع أخرى تُقبَل بلا أثر.
        PaymentReconciler::settle($payment, 'webhook');

        return response()->json(['ok' => true]);
    }
}
