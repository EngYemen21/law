<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Journey\Enums\HearingStatus;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\CaseHearing;
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Models\User;
use App\Support\EventStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * الجلسات القضائية وتواريخ المحاكم — الإدارة العليا
 *
 * منظومة إشراف ومتابعة مركزية لكافة الجلسات القضائية، المحاكم، الدوائر،
 * مواعيد الحضور، سلاسل التأجيلات، والجلسات الفائتة بانتظار تدوين النتيجة.
 */
class CourtHearingController extends Controller
{
    private const PER_PAGE = 25;

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', 'max:40'],
            'court' => ['nullable', 'string', 'max:120'],
            'lawyer_id' => ['nullable', 'integer'],
            'preset' => ['nullable', 'string', 'in:all,today,week,month,custom'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'sort' => ['nullable', 'string', 'in:nearest,newest,furthest'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = CaseHearing::query()
            ->with([
                'legalCase.user',
                'legalCase.assignedLawyer',
                'postponedFrom',
                'postponedTo',
                'documents',
            ]);

        $this->applyFilters($query, $filters);
        $this->applySorting($query, $filters['sort'] ?? 'nearest');

        $paginator = $query->paginate(self::PER_PAGE)->withQueryString();

        $rows = collect($paginator->items())->map(fn (CaseHearing $h) => $this->transformRow($h));

        return Inertia::render('admin/hearings', [
            'hearings' => [
                'data' => $rows,
                'meta' => [
                    'currentPage' => $paginator->currentPage(),
                    'lastPage' => $paginator->lastPage(),
                    'perPage' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'from' => $paginator->firstItem(),
                    'to' => $paginator->lastItem(),
                ],
                'links' => [
                    'prev' => $paginator->previousPageUrl(),
                    'next' => $paginator->nextPageUrl(),
                ],
            ],
            'kpis' => $this->computeKPIs(),
            'options' => [
                'courts' => $this->getCourtOptions(),
                'lawyers' => $this->getLawyerOptions(),
            ],
            'filters' => [
                'q' => $filters['q'] ?? '',
                'status' => $filters['status'] ?? 'all',
                'court' => $filters['court'] ?? '',
                'lawyer_id' => isset($filters['lawyer_id']) ? (int) $filters['lawyer_id'] : null,
                'preset' => $filters['preset'] ?? 'all',
                'from' => $filters['from'] ?? '',
                'to' => $filters['to'] ?? '',
                'sort' => $filters['sort'] ?? 'nearest',
            ],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', 'max:40'],
            'court' => ['nullable', 'string', 'max:120'],
            'lawyer_id' => ['nullable', 'integer'],
            'preset' => ['nullable', 'string', 'in:all,today,week,month,custom'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'sort' => ['nullable', 'string', 'in:nearest,newest,furthest'],
        ]);

        $query = CaseHearing::query()
            ->with([
                'legalCase.user',
                'legalCase.assignedLawyer',
                // الخصم على التذكرة التي وُلدت منها القضيّة (`tickets.opponent_name`)
                'legalCase.ticket',
                'postponedFrom',
                'postponedTo',
            ]);

        $this->applyFilters($query, $filters);
        $this->applySorting($query, $filters['sort'] ?? 'nearest');

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="court-hearings-'.now()->format('Y-m-d').'.csv"',
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        return response()->stream(function () use ($query) {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }

            // إضافة UTF-8 BOM لضمان قراءة ملف الـ CSV باللغة العربية في Microsoft Excel
            fwrite($handle, "\xEF\xBB\xBF");

            // ترويسة ملف الإكسل
            fputcsv($handle, [
                'معرّف الجلسة',
                'عنوان الجلسة',
                'رقم القضية',
                'موضوع القضية',
                'المحكمة',
                'الدائرة القضائية',
                'تاريخ الجلسة',
                'وقت الجلسة',
                'المدّة المتوقّعة (دقائق)',
                'المحامي المسند',
                'العميل',
                'الخصم',
                'حالة الجلسة',
                'نتيجة وقرار الجلسة',
                'سلسلة التأجيل',
            ]);

            $query->chunk(200, function ($hearings) use ($handle) {
                foreach ($hearings as $h) {
                    $lapsed = $h->isLapsed();
                    $statusLabel = $lapsed ? 'فائتة (بانتظار النتيجة)' : (string) $h->status;

                    $postponementChain = '—';
                    if ($h->postponed_from_id && $h->postponedFrom) {
                        $postponementChain = 'مؤجلة من جلسة سابقة ('.$h->postponedFrom->label().')';
                    } elseif ($h->postponedTo) {
                        $postponementChain = 'أُجّلت إلى جلسة لاحقة ('.$h->postponedTo->label().')';
                    }

                    fputcsv($handle, [
                        $h->id,
                        $h->title ?: 'جلسة قضائية',
                        $h->legalCase?->number ?: '—',
                        $h->legalCase?->update_text ?: ($h->legalCase?->type ?: '—'),
                        $h->court ?: ($h->legalCase?->court ?: '—'),
                        $h->legalCase?->circuit ?: '—',
                        $h->starts_at?->translatedFormat('Y-m-d') ?: (string) $h->day,
                        $h->starts_at?->translatedFormat('h:i A') ?: (string) $h->time,
                        // المدّة المتوقّعة كما أُدخلت — فراغها قرارٌ صادق («—») لا صفرٌ مختلق
                        $h->duration_min ?: '—',
                        $h->legalCase?->assigned_lawyer ?: ($h->legalCase?->assignedLawyer?->name ?? 'غير مسند'),
                        $h->legalCase?->user?->name ?? '—',
                        // عمود «الخصم» كان يُملأ بالقسم — الخصم اسمُ الطرف الآخر من التذكرة الأصل
                        $h->legalCase?->ticket?->opponent_name ?: '—',
                        $statusLabel,
                        $h->outcome ?: '—',
                        $postponementChain,
                    ]);
                }
            });

            fclose($handle);
        }, 200, $headers);
    }

    private function applyFilters(Builder $query, array $filters): void
    {
        // 1. البحث النصي
        if (! empty($filters['q'])) {
            $q = trim((string) $filters['q']);
            $query->where(function (Builder $builder) use ($q) {
                $builder->where('case_hearings.title', 'like', "%{$q}%")
                    ->orWhere('case_hearings.court', 'like', "%{$q}%")
                    ->orWhere('case_hearings.outcome', 'like', "%{$q}%")
                    ->orWhereHas('legalCase', function (Builder $caseQ) use ($q) {
                        $caseQ->where('number', 'like', "%{$q}%")
                            ->orWhere('type', 'like', "%{$q}%")
                            ->orWhere('department', 'like', "%{$q}%")
                            ->orWhere('circuit', 'like', "%{$q}%")
                            ->orWhere('assigned_lawyer', 'like', "%{$q}%")
                            ->orWhere('najiz_case_no', 'like', "%{$q}%")
                            ->orWhere('update_text', 'like', "%{$q}%")
                            ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$q}%"));
                    });
            });
        }

        // 2. تصفية الحالة
        $status = $filters['status'] ?? 'all';
        if ($status !== 'all' && ! empty($status)) {
            match ($status) {
                'today' => $query->whereDate('case_hearings.starts_at', today()),
                'upcoming' => $query->where('case_hearings.status', HearingStatus::Scheduled->value)
                    ->where('case_hearings.starts_at', '>=', now()),
                'lapsed' => $query->where(function (Builder $b) {
                    $b->where('case_hearings.status', HearingStatus::Lapsed->value)
                        ->orWhere(function (Builder $sub) {
                            $sub->where('case_hearings.status', HearingStatus::Scheduled->value)
                                ->whereNotNull('case_hearings.starts_at')
                                ->where('case_hearings.starts_at', '<', now());
                        });
                }),
                'held' => $query->where('case_hearings.status', HearingStatus::Held->value),
                'postponed' => $query->where('case_hearings.status', HearingStatus::Postponed->value),
                'cancelled' => $query->where('case_hearings.status', HearingStatus::Cancelled->value),
                default => $query->where('case_hearings.status', $status),
            };
        }

        // 3. تصفية المحكمة
        if (! empty($filters['court'])) {
            $court = (string) $filters['court'];
            $query->where(function (Builder $b) use ($court) {
                $b->where('case_hearings.court', $court)
                    ->orWhereHas('legalCase', fn ($c) => $c->where('court', $court));
            });
        }

        // 4. تصفية المحامي
        if (! empty($filters['lawyer_id'])) {
            $lawyerId = (int) $filters['lawyer_id'];
            $query->whereHas('legalCase', fn ($c) => $c->where('assigned_lawyer_id', $lawyerId));
        }

        // 5. تصفية النطاق الزمني
        $preset = $filters['preset'] ?? 'all';
        if ($preset === 'today') {
            $query->whereDate('case_hearings.starts_at', today());
        } elseif ($preset === 'week') {
            $query->whereBetween('case_hearings.starts_at', [now()->startOfWeek(), now()->endOfWeek()]);
        } elseif ($preset === 'month') {
            $query->whereBetween('case_hearings.starts_at', [now()->startOfMonth(), now()->endOfMonth()]);
        } elseif ($preset === 'custom') {
            if (! empty($filters['from'])) {
                $query->whereDate('case_hearings.starts_at', '>=', $filters['from']);
            }
            if (! empty($filters['to'])) {
                $query->whereDate('case_hearings.starts_at', '<=', $filters['to']);
            }
        }
    }

    private function applySorting(Builder $query, string $sort): void
    {
        match ($sort) {
            'newest' => $query->orderBy('case_hearings.id', 'desc'),
            'furthest' => $query->orderByRaw('case_hearings.starts_at is null')
                ->orderBy('case_hearings.starts_at', 'desc'),
            default => $query->orderByRaw('case_hearings.starts_at is null')
                ->orderBy('case_hearings.starts_at', 'asc'),
        };
    }

    private function transformRow(CaseHearing $h): array
    {
        $startsAt = $h->starts_at;
        $lapsed = $h->isLapsed();

        return [
            'id' => $h->id,
            'title' => $h->title ?: 'جلسة قضائية',
            'day' => $startsAt?->locale('ar')->translatedFormat('l d F Y') ?: (string) ($h->day ?: '—'),
            'time' => $startsAt?->locale('ar')->translatedFormat('h:i A') ?: (string) ($h->time ?: '—'),
            'startsAt' => $startsAt?->toIso8601String(),
            'relativeDate' => $this->relativeDateLabel($startsAt),
            'court' => $h->court ?: ($h->legalCase?->court ?: 'المحكمة المختصة'),
            'circuit' => $h->legalCase?->circuit ?: '—',
            'status' => (string) $h->status,
            'lapsed' => $lapsed,
            // الشارة من الخادم: نصُّ العرض (`EventStatus::forHearing`) ونغمتُه (`HearingStatus::tone`) —
            // كان `switch` على النصوص العربيّة في الشاشة يلوّن بلوحةٍ غير لوحة بقيّة الشاشات
            'statusLabel' => EventStatus::forHearing($h),
            'tone' => $h->liveTone(),
            // «اليوم» علَمٌ لا مقارنةٌ بنصّ `relativeDate` في الواجهة
            'isToday' => (bool) $startsAt?->isToday(),
            // المدّة المتوقّعة (دقائق) كما أُدخلت — الشاشة تعرضها بصيغتها المشتركة (`hearingDurationLabel`)
            'durationMin' => $h->duration_min,
            'outcome' => $h->outcome ?: null,
            'postponedFromId' => $h->postponed_from_id,
            'postponedFrom' => $h->postponedFrom ? [
                'id' => $h->postponedFrom->id,
                'title' => $h->postponedFrom->title,
                'date' => $h->postponedFrom->label(),
                'outcome' => $h->postponedFrom->outcome,
            ] : null,
            'postponedTo' => $h->postponedTo ? [
                'id' => $h->postponedTo->id,
                'title' => $h->postponedTo->title,
                'date' => $h->postponedTo->label(),
            ] : null,
            'documents' => $h->documents->map(fn ($doc) => [
                'id' => $doc->id,
                'name' => $doc->name,
                'docType' => $doc->doc_type,
                'size' => $doc->size ? round($doc->size / 1024, 1).' KB' : null,
                'date' => $doc->created_at?->locale('ar')->translatedFormat('d M Y'),
                'downloadUrl' => route('admin.documents.download-file', ['path' => $doc->path, 'name' => $doc->name]),
            ])->values()->all(),
            'case' => $h->legalCase ? [
                'id' => $h->legalCase->id,
                'no' => $h->legalCase->number,
                'title' => $h->legalCase->update_text ?: ($h->legalCase->type.' - '.$h->legalCase->number),
                'type' => $h->legalCase->type,
                'status' => $h->legalCase->status,
                'tone' => $h->legalCase->tone,
                'client' => Ticket::maskClient($h->legalCase->user?->name ?? 'عميل غير مسجل'),
                'realClient' => $h->legalCase->user?->name ?? '—',
                'lawyer' => $h->legalCase->assigned_lawyer ?: ($h->legalCase->assignedLawyer?->name ?? 'غير مسند'),
                'url' => '/admin/cases/'.urlencode($h->legalCase->number),
            ] : null,
        ];
    }

    private function computeKPIs(): array
    {
        $now = now();
        $today = today();

        $total = CaseHearing::count();
        $todayCount = CaseHearing::whereDate('starts_at', $today)->count();
        $upcoming = CaseHearing::where('status', HearingStatus::Scheduled->value)
            ->where('starts_at', '>=', $now)
            ->count();

        $lapsed = CaseHearing::where(function (Builder $b) use ($now) {
            $b->where('status', HearingStatus::Lapsed->value)
                ->orWhere(function (Builder $sub) use ($now) {
                    $sub->where('status', HearingStatus::Scheduled->value)
                        ->whereNotNull('starts_at')
                        ->where('starts_at', '<', $now);
                });
        })->count();

        $held = CaseHearing::where('status', HearingStatus::Held->value)->count();
        $postponed = CaseHearing::where('status', HearingStatus::Postponed->value)->count();

        return [
            'total' => $total,
            'today' => $todayCount,
            'upcoming' => $upcoming,
            'lapsed' => $lapsed,
            'held' => $held,
            'postponed' => $postponed,
        ];
    }

    private function getCourtOptions(): array
    {
        $hearingCourts = CaseHearing::query()
            ->whereNotNull('court')
            ->where('court', '!=', '')
            ->distinct()
            ->pluck('court');

        $caseCourts = LegalCase::query()
            ->whereNotNull('court')
            ->where('court', '!=', '')
            ->distinct()
            ->pluck('court');

        return $hearingCourts->merge($caseCourts)
            ->unique()
            ->filter()
            ->values()
            ->all();
    }

    private function getLawyerOptions(): array
    {
        return User::query()
            ->where('role', Role::Lawyer)
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])
            ->values()
            ->all();
    }

    private function relativeDateLabel(?CarbonInterface $date): string
    {
        if (! $date) {
            return 'غير محدد';
        }

        if ($date->isToday()) {
            return 'اليوم';
        }
        if ($date->isTomorrow()) {
            return 'غداً';
        }
        if ($date->isYesterday()) {
            return 'أمس';
        }

        $days = (int) round(now()->diffInDays($date, false));

        if ($days > 0) {
            return "بعد {$days} يوم";
        }

        $absDays = abs($days);

        return "منذ {$absDays} يوم";
    }
}
