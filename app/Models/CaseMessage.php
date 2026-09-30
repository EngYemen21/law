<?php

namespace App\Models;

use App\Events\CaseMessageBroadcast;
use App\Models\Concerns\RecordsSender;
use App\Models\Concerns\TracksRevisions;
use App\Support\CasePleading;
use App\Support\Live;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CaseMessage extends Model
{
    use RecordsSender;
    use TracksRevisions;

    protected $fillable = ['case_id', 'who', 'name', 'role', 'body', 'time_label', 'withheld_at'];

    protected $casts = ['withheld_at' => 'datetime'];

    // بثّ كل رسالة قضية لحظياً فور إنشائها (لا حاجة لاستدعاء يدوي في المتحكمات)
    protected static function booted(): void
    {
        static::created(function (CaseMessage $m) {
            // **الرسالة المحجوبة لا تُبثّ.** قناة `case.{id}` يُخوَّل عليها العميل
            // (`routes/channels.php:44` — `ownerOrStaff`)، فالبثّ يتجاوز ترشيح
            // الحمولة الخادميّ ويوصلها إليه لحظة إنشائها. وهو الباب الثاني نفسه
            // الذي سُدّ في `ConsultStatusBroadcast`. والمكتب يراها عند التحميل،
            // ويصل بثّها العميلَ عند الإطلاق (`AiReviewOutcome::releaseCasePleading`).
            if ($m->withheld_at !== null) {
                return;
            }

            Live::push(new CaseMessageBroadcast($m));
        });
    }

    /**
     * ما يجوز عرضه لهذا الطرف — مصدرٌ واحد لترشيحٍ كان مكرّراً في ثلاثة عروض.
     *
     * `$internal` يعني «للمكتب»: يرى الملاحظات الداخليّة والمخرجات المحجوبة بانتظار
     * الاعتماد. والعميل يرى ما عداهما. وتكرار الشرط في المتحكّمات هو ما جعل مسودّة
     * اللائحة تصله: العروض الثلاثة كانت نسخةً واحدة `who != 'note'` بلا فاصل.
     */
    public function scopeVisibleTo(Builder $query, bool $internal): Builder
    {
        return $query
            ->where('who', '!=', 'note')
            ->when(! $internal, fn (Builder $q) => $q->whereNull('withheld_at'));
    }

    public function legalCase(): BelongsTo
    {
        return $this->belongsTo(LegalCase::class, 'case_id');
    }

    /** الملفّ الذي تنتمي إليه الرسالة — يقرؤه `RecordsSender` لنقل مسؤوليّة المحادثة. */
    public function conversation(): ?Model
    {
        return $this->legalCase;
    }

    // الشكل الذي تتوقعه الواجهة (يطابق Message في chat.ts)
    // `forClient`: حمولةٌ تصل العميل (صفحته أو بثٌّ على قناته) — لا عنوان IP فيها أيّاً كان الباني
    public function toMessage(bool $forClient = false): array
    {
        return [
            'id' => $this->id,
            'who' => $this->who,
            'name' => $this->senderName($forClient),
            'role' => $this->role,
            'text' => $this->body,
            'time' => $this->time_label,
        ] + $this->senderIpField($forClient);
    }

    /** مسودّة اللائحة وحدها من رسائل القضيّة تُحفظ نسخاً — على القضيّة المالكة (`ContentRevisions`). */
    public function revisionKinds(): array
    {
        return $this->role === CasePleading::DRAFT_ROLE ? ['case_pleading' => ['body']] : [];
    }

    public function revisionOwner(): ?Model
    {
        return $this->case_id ? LegalCase::find($this->case_id) : null;
    }
}
