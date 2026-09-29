<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * دفتر مدفوعات البوّابة (ledger): صفّ لكلّ حدث دفع يصل من ميسّر — تدقيق ومطابقة محاسبيّة.
 * يُسجَّل تلقائيّاً من App\Support\PaymentReconciler::settle (idempotent عبر gateway_payment_id).
 *
 * **الوحدة:** `amount` مختلط — بالهللة في صفوف البوّابة وبالريال في التحصيل اليدويّ، فلا يُجمع.
 * `amount_halalas` **بالهللة دائماً** وهو وحده القابل للجمع والمطابقة.
 *
 * **سند القبض** (`receipt_no` · `method` · `received_at` · `actor_id` · `note`): يُمنح للدفعة
 * **الناجحة** وحدها عبر `Finance\ReceiptVoucher::issue` — والمحاولة الفاشلة تبقى في الدفتر للتدقيق
 * ولا تُعدّ مقبوضاً (`scopeReceived`).
 */
class Payment extends Model
{
    /** الحالة التي تعني أنّ المال وصل — كما تردّها ميسّر، ويكتبها التحصيل اليدويّ. */
    public const PAID = 'paid';

    /** طرق القبض ← تسميتها على السند وفي الشاشة. */
    public const METHODS = [
        'gateway' => 'بوّابة الدفع (ميسّر)',
        'cash' => 'نقداً',
        'bank_transfer' => 'تحويل بنكيّ',
    ];

    /** طرق التحصيل اليدويّ التي يختارها الإداريّ. */
    public const MANUAL_METHODS = ['cash', 'bank_transfer'];

    protected $fillable = [
        'invoice_id', 'gateway', 'method', 'gateway_invoice_id', 'gateway_payment_id', 'receipt_no',
        'status', 'amount', 'amount_halalas', 'currency', 'source_channel', 'raw', 'reconciled_at',
        'received_at', 'actor_id', 'note',
    ];

    protected $casts = [
        'amount' => 'integer',
        'amount_halalas' => 'integer',
        'raw' => 'array',
        'reconciled_at' => 'datetime',
        'received_at' => 'datetime',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * **المقبوض فعلاً** — الدفعة الناجحة وحدها. كان تبويب المقبوضات ومجموعه يعدّان كلّ صفٍّ في
     * الدفتر، فمحاولةٌ فاشلة بخمسمائة ريال تظهر مقبوضاً وتُضاف إلى الدخل.
     *
     * @param  Builder<Payment>  $query
     * @return Builder<Payment>
     */
    public function scopeReceived(Builder $query): Builder
    {
        return $query->where('status', self::PAID);
    }

    public function isReceived(): bool
    {
        return $this->status === self::PAID;
    }

    /** طريقة القبض كما تُطبع — والتحصيل اليدويّ القديم بلا طريقةٍ مسجّلة يُسمّى بما هو. */
    public function methodLabel(): string
    {
        return self::METHODS[(string) $this->method]
            ?? ($this->gateway === 'manual' ? 'تحصيل يدويّ' : self::METHODS['gateway']);
    }

    /** من قبض: المستخدم المسجِّل، وإلّا الاسم المحفوظ في لقطة التحصيل القديم، والبوّابة باسمها. */
    public function receiverLabel(): string
    {
        $raw = $this->raw;
        $legacy = is_array($raw) && is_string($raw['actor'] ?? null) ? trim($raw['actor']) : '';

        if ($this->actor_id !== null) {
            return $this->actor->name;
        }

        return $legacy !== '' ? $legacy : ($this->gateway === 'manual' ? '—' : 'بوّابة الدفع');
    }
}
