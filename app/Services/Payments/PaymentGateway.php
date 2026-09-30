<?php

namespace App\Services\Payments;

use App\Models\Invoice;
use Illuminate\Http\Request;

/**
 * **عقد بوّابة الدفع.** إضافة بوّابةٍ جديدة = صنفٌ يطبّق هذا العقد + سطرٌ في
 * `config('services.payments.gateways')` — بلا مساسٍ بالتسوية ولا بالمتحكّمات ولا بالمجال.
 *
 * ما يبقى على المنصّة لا على البوّابة: مطابقة المبلغ والعملة، وidempotency، والدفتر وسند القبض،
 * وانتقال حالة المجال (`PaymentReconciler`).
 */
interface PaymentGateway
{
    /** الاسم في الإعداد والدفتر (`payments.gateway`، `invoices.gateway`) — ثابتٌ لا يُترجم. */
    public function name(): string;

    /** الاسم المعروض للعميل وفي السجلّ («ميسّر»). */
    public function label(): string;

    /** مفاتيحها مضبوطة وصالحةٌ لهذه البيئة — وإلّا لا دفع (المتحكّمات تردّ 503). */
    public function isConfigured(): bool;

    /**
     * رابط صفحة الدفع المستضافة لفاتورة المنصّة — يعيد استعمال فاتورة البوّابة المفتوحة **بمبلغها الحاليّ**،
     * ويكتب مرجعها في `gateway_ref` و`gateway`. **لا يُعيد فاتورةً مدفوعة إلى البوّابة إطلاقاً**، وفاتورةُ
     * بوّابةٍ دُفعت ولم يصل إشعارها يُعاد فيها `$callbackUrl` بدفعتها لتُسوّى. null عند التعذّر.
     */
    public function hostedUrlForInvoice(Invoice $invoice, string $callbackUrl): ?string;

    /** يجلب الدفعة من البوّابة بمعرّفها — مصدر الحقيقة الوحيد (لا ثقة بمعطيات العودة ولا بجسم الإشعار). */
    public function fetchPayment(string $paymentId): ?GatewayPayment;

    /** معرّف الدفعة في عنوان العودة من صفحة الدفع. */
    public function paymentIdFromCallback(Request $request): string;

    /** سرّ الإشعارات مضبوط — وإلّا يُرفض كلّ إشعار (503). */
    public function webhookConfigured(): bool;

    /** الإشعار صادرٌ من البوّابة فعلاً (مقارنة ثابتة الزمن). */
    public function verifyWebhook(Request $request): bool;

    /** معرّف الدفعة التي يخصّها الإشعار — لتُجلب من البوّابة، لا ليُوثق بجسمه. */
    public function paymentIdFromWebhook(Request $request): ?string;

    /**
     * **إشعار الفاتورة المستضافة** (نداء البوّابة الخادميّ حين تُدفع فاتورتها) — معرّف الدفعة المدفوعة كما تراها
     * البوّابة **بعد إعادة جلب فاتورتها** بالمفتاح السرّيّ، لا من جسم الطلب. null: لا فاتورة ولا دفعة مدفوعة.
     */
    public function paymentIdFromInvoiceNotification(Request $request): ?string;

    /**
     * مخالفات إعدادها لـ`env:check` (`EnvironmentAudit`).
     *
     * @return list<array{level: 'fail'|'warn', key: string, message: string}>
     */
    public function auditFindings(bool $production): array;
}
