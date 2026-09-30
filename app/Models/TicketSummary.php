<?php

namespace App\Models;

use App\Domain\Journey\GuardsJourneyState;
use App\Models\Concerns\TracksRevisions;
use App\Support\RichHtml;
use App\Support\SummaryText;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ملخص ملف التذكرة — يجهّزه «الفريق القانوني» (الذكاء الاصطناعي) للمستشار:
 * تلخيص القضية + تلخيص المرفقات + تجهيز الوقائع + تحديد النقاط المهمة.
 * يراجعه المحامي ويعتمده، فيصل اعتماده إلى محادثة العميل.
 */
class TicketSummary extends Model
{
    use GuardsJourneyState;
    use TracksRevisions;

    protected $fillable = [
        'ticket_id', 'lawyer_id', 'case_summary', 'attachments_summary', 'facts', 'key_points', 'status', 'approved_at',
        'case_summary_html', 'attachments_summary_html', 'facts_html', 'key_points_html',
        'lawyer_approved_at', 'lawyer_approved_by', 'edited_at',
        'result', 'result_status', 'ai_generated',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
        'lawyer_approved_at' => 'datetime',
        'edited_at' => 'datetime',
        'ai_generated' => 'boolean',
    ];

    /**
     * **حقول الملخّص الأربعة — كلٌّ بنسختين** (طلب المالك 2026-09-30): `{حقل}_html` المنسّقة أصلٌ يحرّره المحامي
     * والإدارة ويصل العميلَ بتنسيقه، و`{حقل}` النصّ العاديّ مشتقٌّ منها (`RichHtml::toPlain`) لما يقرأ نصّاً:
     * سياق الذكاء، و`TicketResult::hasSubstance`، والمعاينات، وسجلّ المراجعات.
     */
    public const TEXT_FIELDS = ['case_summary', 'attachments_summary', 'facts', 'key_points'];

    protected static function booted(): void
    {
        // نصٌّ عاديّ كُتب بلا نسخته المنسّقة (توليد الذكاء، القالب، واجهةٌ ترسل نصّاً) — تسقط المنسّقة القديمة
        // فيُعرض النصّ الجديد لا ما سبقه
        static::saving(function (TicketSummary $summary): void {
            foreach (self::TEXT_FIELDS as $field) {
                if ($summary->isDirty($field) && ! $summary->isDirty($field.'_html')) {
                    $summary->setAttribute($field.'_html', null);
                }
            }
        });
    }

    /**
     * مدخلات التحرير إلى أعمدة: `{حقل}_html` يُنقّى ويُشتقّ منه نصّه، وإلّا فالنصّ العاديّ كما هو.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string|null>
     */
    public static function editableInput(array $input): array
    {
        $out = [];
        foreach (self::TEXT_FIELDS as $field) {
            if (array_key_exists($field.'_html', $input)) {
                $html = RichHtml::clean((string) $input[$field.'_html']);
                $plain = RichHtml::toPlain($html);
                $out[$field.'_html'] = $plain === '' ? null : $html;
                $out[$field] = $plain === '' ? null : $plain;
            } elseif (array_key_exists($field, $input)) {
                $out[$field] = $input[$field] === null ? null : (string) $input[$field];
            }
        }

        return $out;
    }

    /** النسخة المنسّقة المنقّاة لحقل — وإن لم تكن فنصّه العاديّ فقراتٍ وقوائم. */
    public function html(string $field): string
    {
        $stored = (string) $this->getAttribute($field.'_html');

        return trim($stored) !== ''
            ? RichHtml::clean($stored)
            : SummaryText::html((string) $this->getAttribute($field));
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** @return BelongsTo<User, $this> */
    public function lawyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lawyer_id');
    }

    /** اعتمدته الإدارة ونُشر للعميل (المرحلة الثانية). */
    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    /** اعتمده المحامي (المرحلة الأولى) — لا يصل العميلَ شيءٌ به (قرار المالك 2026-09-14). */
    public function isLawyerApproved(): bool
    {
        return $this->lawyer_approved_at !== null;
    }

    // الشكل الذي تتوقعه واجهة المحامي/الإدارة
    public function toData(): array
    {
        return [
            'id' => $this->id,
            'ref' => $this->ticket?->number,
            'caseSummary' => $this->case_summary,
            'attachmentsSummary' => $this->attachments_summary,
            'facts' => $this->facts,
            'keyPoints' => $this->key_points,
            'html' => [
                'caseSummary' => $this->html('case_summary'),
                'attachmentsSummary' => $this->html('attachments_summary'),
                'facts' => $this->html('facts'),
                'keyPoints' => $this->html('key_points'),
            ],
            'status' => $this->status,
            'approved' => $this->isApproved(),
            'lawyerApproved' => $this->isLawyerApproved(),
            'aiGenerated' => (bool) $this->ai_generated,
            'result' => $this->result,
            'resultStatus' => $this->result_status,
        ];
    }

    /** نصوص الملخّص والرأي — نسخٌ على التذكرة المالكة (`ContentRevisions`). */
    public function revisionKinds(): array
    {
        return [
            'ticket_summary' => ['case_summary', 'attachments_summary', 'facts', 'key_points'],
            'ticket_result' => ['result'],
        ];
    }

    public function revisionOwner(): ?Model
    {
        return $this->ticket_id ? Ticket::find($this->ticket_id) : null;
    }
}
