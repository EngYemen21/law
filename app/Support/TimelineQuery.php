<?php

namespace App\Support;

use App\Models\Appointment;
use App\Models\CaseHearing;
use App\Models\Consult;
use App\Models\Meeting;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * مصدر موحّد قابل للترشيح والبحث والتصفيح لكل ارتباطات المستخدم الزمنية.
 *
 * لماذا: كانت الصفحة تُحمّل الأنواع الأربعة كاملةً في حمولة Inertia واحدة ثم تدمجها الواجهة —
 * فلا ترشيح خادميّ ولا بحث ولا تصفيح، وحجم الحمولة ينمو مع عمر الحساب بلا سقف.
 *
 * الحلّ اتحاد (UNION) على مستوى القاعدة يُعيد **الحدّ الأدنى** لكل صفّ (النوع · المعرّف ·
 * الموعد · نصّ البحث · الحالة)، فيقع الترشيح والترتيب والتصفيح في القاعدة. ثم تُحمَّل نماذج
 * **الصفحة الحالية وحدها** لبناء البطاقات — لا تاريخ الحساب كلّه.
 */
class TimelineQuery
{
    /** الأنواع المدعومة — المفتاح يُستعمل في الترشيح من الواجهة. */
    public const KINDS = ['appointment', 'consult', 'hearing', 'meeting'];

    /**
     * @param  array{q?: string, kind?: string, status?: string, from?: string, to?: string, sort?: string}  $filters
     */
    public static function paginate(int $userId, array $filters, int $perPage = 12, int $page = 1): LengthAwarePaginator
    {
        $union = self::union($userId, $filters);

        $total = self::outer($union, $filters)->count();

        $desc = ($filters['sort'] ?? 'asc') === 'desc';
        $rows = self::outer($union, $filters)
            // غير المجدول في الذيل دائماً (في الاتجاهين) — سجلّ بلا موعد ليس «الأقدم»
            ->orderByRaw('starts_at IS NULL')
            ->orderBy('starts_at', $desc ? 'desc' : 'asc')
            // مفتاح فاصل إلزاميّ: الصفوف بلا starts_at متساوية تماماً في المفاتيح أعلاه،
            // وبلا فاصل يترك SQL ترتيبها للمحرّك — فيظهر صفّ في صفحتين ويختفي آخر.
            // الاتجاه asc دائماً في الحالتين: الغرض الحتميّة لا الدلالة.
            ->orderBy('kind')
            ->orderBy('model_id')
            ->forPage($page, $perPage)
            ->get();

        return new LengthAwarePaginator($rows, $total, $perPage, $page, ['path' => request()->url()]);
    }

    /** عدّاد لكل نوع ضمن نفس الترشيح — لشرائح الواجهة. */
    public static function countsByKind(int $userId, array $filters): array
    {
        $base = array_diff_key($filters, ['kind' => null]); // العدّاد يتجاهل ترشيح النوع نفسه
        $rows = self::outer(self::union($userId, $base), $base)
            ->select('kind', DB::raw('count(*) as c'))->groupBy('kind')->pluck('c', 'kind');

        $out = ['all' => (int) $rows->sum()];
        foreach (self::KINDS as $k) {
            $out[$k] = (int) ($rows[$k] ?? 0);
        }

        return $out;
    }

    /**
     * البحث النصّي يُطبَّق على الغلاف الخارجي لا داخل الاتحاد: `haystack` اسم مستعار،
     * وSQL لا يتيح الأسماء المستعارة في WHERE الخاصّ بنفس الـSELECT.
     */
    private static function outer(BuilderContract $union, array $filters): Builder
    {
        $q = DB::query()->fromSub($union, 't');

        if (filled($filters['q'] ?? null)) {
            $needle = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($filters['q'])).'%';
            // ESCAPE صريح: MySQL يفترض الشرطة المائلة افتراضاً، أمّا sqlite (محرّك الحزمة)
            // فبلا محرف تهريب افتراضي إطلاقاً — فبحثٌ يحوي % أو _ كان يبحث عن الشرطة
            // المائلة حرفياً فلا يطابق شيئاً. نفس فارق المحرّكين المعالَج في joinText().
            $q->whereRaw('haystack like ? escape ?', [$needle, '\\']);
        }

        return $q;
    }

    /**
     * دمج نصوص البحث بصيغة يفهمها المحرّكان.
     *
     * `concat()` دالّة MySQL ولا وجود لها في sqlite (الذي تعمل عليه الحزمة)، وsqlite
     * يستعمل `||`. لا تُجرِّد Laravel هذا الفارق، فيُصرَّح به هنا بدل أن ينفجر في
     * بيئة دون أخرى — وهو أثر مباشر لاختلاف محرّك الاختبارات عن محرّك الإنتاج.
     */
    private static function joinText(array $columns): string
    {
        $parts = array_map(fn (string $c) => "coalesce({$c},'')", $columns);

        return DB::connection()->getDriverName() === 'sqlite'
            ? implode(" || ' ' || ", $parts)
            : 'concat('.implode(", ' ', ", $parts).')';
    }

    /**
     * الحالات الموجودة فعلاً لدى هذا المستخدم — تملأ قائمة الترشيح بلا قيم وهمية.
     *
     * ⚠️ حدّ معروف (قرار منتج معلّق، ليس عطلاً عابراً): القائمة والترشيح يقرآن العمود
     * **المخزَّن**، بينما البطاقة تعرض حالة **مشتقّة حيّاً** (EventStatus::forHearing يعيد
     * «فائتة — بانتظار النتيجة» وهو غير موجود في أي عمود). فالنتيجة أن ترشيح «مؤكد» قد
     * يعطي صفوفاً شارتها «منتهٍ»، و«لم ينعقد» غير قابلة للترشيح أصلاً. سدّ الفجوة يتطلّب
     * اشتقاق الحالة في القاعدة — لا تُصلَح موضعياً هنا.
     */
    public static function statuses(int $userId): array
    {
        return self::outer(self::union($userId, []), [])
            ->select('status')->distinct()->orderBy('status')
            ->pluck('status')->filter()->values()->all();
    }

    /** اتحاد المصادر الأربعة بأعمدة موحّدة. */
    private static function union(int $userId, array $filters): BuilderContract
    {
        $parts = [
            // حجز الاستشارة يُنشئ موعداً مرافقاً ويربطه بـconsults.appointment_id
            // (ConsultBooking::…) — فبلا هذا الاستثناء يظهر الحدث الواقعي الواحد بطاقتين
            // ويُعدّ مرّتين في meta.total وفي countsByKind. تبقى بطاقة الاستشارة لأنها
            // الأغنى: المرجع · رابط الدخول · الفاتورة · حالة الجلسة.
            'appointment' => Appointment::query()->where('user_id', $userId)
                ->whereDoesntHave('consult')
                ->selectRaw("'appointment' as kind, id as model_id, starts_at, status, ".self::joinText(['type']).' as haystack'),
            'consult' => Consult::query()->where('user_id', $userId)->where('status', '!=', 'ملغاة')
                ->selectRaw("'consult' as kind, id as model_id, starts_at, status, ".self::joinText(['subject', 'ref']).' as haystack'),
            'hearing' => CaseHearing::query()
                ->whereIn('case_id', DB::table('cases')->where('user_id', $userId)->select('id'))
                ->selectRaw("'hearing' as kind, id as model_id, starts_at, status, ".self::joinText(['title', 'court']).' as haystack'),
            'meeting' => Meeting::query()->where('user_id', $userId)->where('status', '!=', 'ملغى')
                ->selectRaw("'meeting' as kind, id as model_id, starts_at, status, ".self::joinText(['title']).' as haystack'),
        ];

        // ترشيح النوع يقع **قبل** الاتحاد فلا تُمسح جداول لا لزوم لها
        $kind = $filters['kind'] ?? 'all';
        if ($kind !== 'all' && isset($parts[$kind])) {
            $parts = [$kind => $parts[$kind]];
        }

        foreach ($parts as $k => $q) {
            self::applyCommon($q->getQuery(), $filters);
        }

        $list = array_values($parts);
        $first = array_shift($list);
        $union = $first->getQuery();
        foreach ($list as $next) {
            $union->unionAll($next->getQuery());
        }

        return $union;
    }

    /** الشروط المشتركة: البحث النصّي · الحالة · المدى الزمني. */
    private static function applyCommon(Builder $q, array $filters): void
    {
        if (filled($filters['status'] ?? null)) {
            $q->where('status', $filters['status']);
        }

        // whereNull محفوظ: سجلّ بلا موعد محدَّد يجب ألّا يختفي عند تحديد مدى
        if (filled($filters['from'] ?? null)) {
            $q->where(fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '>=', $filters['from'].' 00:00:00'));
        }
        if (filled($filters['to'] ?? null)) {
            $q->where(fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', $filters['to'].' 23:59:59'));
        }
    }
}
