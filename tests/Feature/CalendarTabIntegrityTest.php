<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Consult;
use App\Models\Meeting;
use App\Models\User;
use App\Support\TimelineCard;
use App\Support\TimelineQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * سلامة تبويب «التقويم والمواعيد» — عيوب رُصدت بمراجعة سطرية وأُثبتت على القاعدة الحيّة.
 *
 * كل اختبار هنا يقفل عطلاً كان **خارج التغطية** رغم أن الحزمة كلّها خضراء:
 * التداخل (بطاقتان لحدث واحد) · التصفيح غير الحتميّ · الدمج بلا فرز زمني ·
 * الترتيب بالمعرّف عند المحامي · N+1 · تهريب LIKE.
 */
class CalendarTabIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private function client(): User
    {
        return User::factory()->create(['role' => Role::Client]);
    }

    private function appt(User $c, string $ext, $at): Appointment
    {
        return Appointment::create([
            'user_id' => $c->id, 'ext_id' => $ext, 'type' => 'استشارة حضورية', 'ico' => 'office',
            'lawyer' => 'المحامي', 'day' => $at ? $at->format('Y-m-d') : 'غير محدد',
            'time' => $at ? $at->format('H:i') : '—',
            'starts_at' => $at, 'duration_min' => 60, 'place' => 'الرياض',
            'status' => 'مؤكد', 'tone' => 'b-green', 'when_kind' => 'up',
        ]);
    }

    private function meeting(User $c, string $ref, $at, ?int $lawyerId = null): Meeting
    {
        return Meeting::create([
            'user_id' => $c->id, 'ref' => $ref, 'title' => 'اجتماع مراجعة',
            'when_label' => $at ? $at->format('Y-m-d') : 'غير محدد', 'starts_at' => $at,
            'status' => 'قادم', 'dur' => '60 دقيقة', 'assigned_lawyer_id' => $lawyerId,
        ]);
    }

    private function consult(User $c, string $ref, $at, array $extra = []): Consult
    {
        return Consult::create(array_merge([
            'user_id' => $c->id, 'ref' => $ref, 'subject' => 'نزاع عمّالي',
            'channel' => 'مرئية', 'lawyer' => 'مستشار',
            'day' => $at ? $at->format('Y-m-d') : null, 'time' => $at ? $at->format('H:i') : null,
            'when_label' => $at ? $at->format('Y-m-d') : null, 'starts_at' => $at,
            'status' => 'جديدة', 'session' => 'بانتظار الجلسة',
        ], $extra));
    }

    // ————— ١ · التداخل: الموعد المرافق لاستشارة لا يُعرض مرّة ثانية —————

    /**
     * حجز الاستشارة يُنشئ Appointment مرافقاً ويربطه بـconsults.appointment_id.
     * قبل الإصلاح كان الاتحاد يضمّ الاثنين فيُعرض الحدث الواقعي الواحد بطاقتين.
     */
    public function test_a_consult_and_its_companion_appointment_render_as_one_row(): void
    {
        $client = $this->client();
        $at = now()->addDays(3)->setTime(11, 0);

        $appt = $this->appt($client, 'AP-DUP-1', $at);
        $this->consult($client, 'CN-DUP-1', $at, ['appointment_id' => $appt->id]);

        $rows = TimelineQuery::paginate($client->id, [], 50, 1);

        $this->assertCount(1, $rows->items(), 'الحدث الواحد يجب أن يُنتج صفاً واحداً لا صفّين');
        $this->assertSame('consult', $rows->items()[0]->kind, 'البطاقة الباقية هي الاستشارة (الأغنى) لا الموعد');
    }

    /** العدّادات تتبع الاتحاد نفسه — وإلا اختلف رقم الشريحة عن عدد الصفوف. */
    public function test_counts_do_not_count_the_companion_appointment(): void
    {
        $client = $this->client();
        $at = now()->addDays(3)->setTime(11, 0);

        $appt = $this->appt($client, 'AP-DUP-2', $at);
        $this->consult($client, 'CN-DUP-2', $at, ['appointment_id' => $appt->id]);

        $counts = TimelineQuery::countsByKind($client->id, []);

        $this->assertSame(0, $counts['appointment'], 'الموعد المرافق لا يُعدّ');
        $this->assertSame(1, $counts['consult']);
        $this->assertSame(1, $counts['all']);
    }

    /** موعد مستقلّ (لا استشارة له) يبقى ظاهراً — الاستثناء دقيق لا شامل. */
    public function test_a_standalone_appointment_is_still_listed(): void
    {
        $client = $this->client();
        $this->appt($client, 'AP-SOLO-1', now()->addDays(2)->setTime(9, 0));

        $rows = TimelineQuery::paginate($client->id, [], 50, 1);

        $this->assertCount(1, $rows->items());
        $this->assertSame('appointment', $rows->items()[0]->kind);
    }

    // ————— ٢ · التصفيح الحتميّ —————

    /**
     * الصفوف بلا starts_at متساوية تماماً في مفتاح الترتيب، فبلا مفتاح فاصل
     * يترك ترتيبها للمحرّك: صفّ يظهر في صفحتين وآخر يختفي كلّياً.
     */
    public function test_pagination_is_deterministic_when_rows_share_a_sort_key(): void
    {
        $client = $this->client();
        for ($i = 1; $i <= 6; $i++) {
            $this->meeting($client, "MT-TIE-{$i}", null);
        }

        $first = collect(TimelineQuery::paginate($client->id, [], 3, 1)->items())
            ->map(fn ($r) => $r->kind.'-'.$r->model_id)->all();
        $second = collect(TimelineQuery::paginate($client->id, [], 3, 2)->items())
            ->map(fn ($r) => $r->kind.'-'.$r->model_id)->all();

        $this->assertCount(3, $first);
        $this->assertCount(3, $second);
        $this->assertEmpty(array_intersect($first, $second), 'لا صفّ يظهر في صفحتين');
        $this->assertCount(6, array_unique(array_merge($first, $second)), 'لا صفّ يضيع بين الصفحتين');
    }

    /** الاستدعاء المكرّر بنفس المدخلات يعطي نفس الترتيب. */
    public function test_repeated_calls_return_the_same_order(): void
    {
        $client = $this->client();
        for ($i = 1; $i <= 5; $i++) {
            $this->meeting($client, "MT-STABLE-{$i}", null);
        }

        $keys = fn () => collect(TimelineQuery::paginate($client->id, [], 10, 1)->items())
            ->map(fn ($r) => $r->kind.'-'.$r->model_id)->all();

        $this->assertSame($keys(), $keys());
    }

    // ————— ٣ · لوحات الطاقم: فرز زمني بعد الدمج —————

    /**
     * المتحكّم يدمج بـconcat: كل الجلسات ثم كل الاجتماعات ثم كل الاستشارات.
     * فاستشارة اليوم كانت تظهر بعد اجتماع الأسبوع القادم — ثلاث قوائم مكدّسة لا تقويم.
     */
    public function test_employee_calendar_events_are_in_chronological_order(): void
    {
        $employee = User::factory()->create(['role' => Role::Employee]);
        $employee->syncPermissions(Permission::whereIn('name', ['جدولة المواعيد'])->get());
        $client = $this->client();

        $this->meeting($client, 'MT-ORD-LATE', now()->addDays(9)->setTime(14, 0));
        $this->consult($client, 'CN-ORD-EARLY', now()->addDays(2)->setTime(11, 0));

        $this->actingAs($employee)->get('/employee/calendar')
            ->assertInertia(fn (Assert $p) => $p->where('events', function ($events) {
                $kinds = collect($events)->pluck('kindKey')->all();
                $stamps = collect($events)->pluck('startsAt')->all();

                return $kinds === ['consult', 'meeting']
                    && ! in_array(null, $stamps, true)
                    && $stamps === collect($stamps)->sort()->values()->all();
            })->etc());
    }

    /** «بلا موعد» في الذيل — نفس الدلالة المعتمدة في بقيّة المشروع. */
    public function test_undated_events_sink_to_the_tail_for_staff(): void
    {
        $employee = User::factory()->create(['role' => Role::Employee]);
        $employee->syncPermissions(Permission::whereIn('name', ['جدولة المواعيد'])->get());
        $client = $this->client();

        $this->meeting($client, 'MT-NULL', null);
        $this->consult($client, 'CN-DATED', now()->addDays(4)->setTime(10, 0));

        $this->actingAs($employee)->get('/employee/calendar')
            ->assertInertia(fn (Assert $p) => $p->where('events', fn ($events) => collect($events)->last()['startsAt'] === null
            )->etc());
    }

    // ————— ٤ · تقويم المحامي: الترتيب بالموعد لا بالمعرّف —————

    /**
     * كان يستعمل latest('id') — فمع سقف CalendarWindow::LIMIT يقع القصّ على
     * الأقدم إنشاءً وهي غالباً الأقرب انعقاداً. هنا ترتيب المعرّف معاكس لترتيب التاريخ.
     */
    public function test_lawyer_calendar_orders_by_date_not_by_id(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $client = $this->client();

        // المعرّف تصاعديّ والتاريخ تنازليّ — latest('id') يعطي العكس تماماً
        $this->meeting($client, 'MT-L-1', now()->addDays(20)->setTime(9, 0), $lawyer->id);
        $this->meeting($client, 'MT-L-2', now()->addDays(10)->setTime(9, 0), $lawyer->id);
        $this->meeting($client, 'MT-L-3', now()->addDays(5)->setTime(9, 0), $lawyer->id);

        $this->actingAs($lawyer)->get('/lawyer/calendar')
            ->assertInertia(fn (Assert $p) => $p->where('events', function ($events) {
                $refs = collect($events)->pluck('title')->all();
                $stamps = collect($events)->pluck('startsAt')->all();

                // بلا تأكيد عدم الفراغ ينجح الاختبار زائفاً: [null,null,null] تبدو مرتّبة
                return count($stamps) === 3
                    && ! in_array(null, $stamps, true)
                    && $stamps === collect($stamps)->sort()->values()->all()
                    && count($refs) === 3;
            })->etc());
    }

    // ————— ٥ · N+1 —————

    /** بناء البطاقات يجب أن يُحمّل مسبقاً كل ما تقرأه البواني — فعدد الاستعلامات ثابت. */
    public function test_card_hydration_query_count_does_not_grow_with_consults(): void
    {
        $count = function (int $n): int {
            $client = $this->client();
            for ($i = 1; $i <= $n; $i++) {
                $this->consult($client, "CN-N1-{$n}-{$i}", now()->addDays($i)->setTime(10, 0));
            }
            $rows = collect(TimelineQuery::paginate($client->id, [], 50, 1)->items());

            DB::flushQueryLog();
            DB::enableQueryLog();
            TimelineCard::hydrate($rows, $client);
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $this->assertSame($count(1), $count(4), 'عدد الاستعلامات لا يتبع عدد الاستشارات');
    }

    // ————— ٦ · تهريب LIKE —————

    /** البحث عن محرف بدل (%) يجب أن يطابقه حرفياً لا أن يُلغى تهريبه. */
    public function test_searching_for_a_literal_percent_matches_it(): void
    {
        $client = $this->client();
        $this->consult($client, 'CN-PCT-1', now()->addDays(2)->setTime(10, 0), ['subject' => 'خصم 50% نهائي']);
        $this->consult($client, 'CN-PCT-2', now()->addDays(3)->setTime(10, 0), ['subject' => 'نزاع عمّالي']);

        $rows = TimelineQuery::paginate($client->id, ['q' => '%'], 50, 1);

        $this->assertCount(1, $rows->items(), 'المطابقة على المحرف الحرفي لا على كل الصفوف ولا على صفر');
    }
}
