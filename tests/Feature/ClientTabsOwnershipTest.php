<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\ConsultStatus;
use App\Enums\DocumentDirection;
use App\Enums\Role;
use App\Models\Consult;
use App\Models\Document;
use App\Models\User;
use App\Support\ConsultReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsConsultJourney;
use Tests\TestCase;

/**
 * **كلّ تبويبٍ للعميل يعرض ما يخصّه** (جرد تبويبات العميل 2026-10-04، قرار المالك).
 *
 * ١) «الاجتماعات» لا تحمل استشاراتٍ — كان تبويب «الاستشارات المرئية» يكرّر «استشاراتي» بموعدٍ من نصٍّ مخزَّن قديم.
 * ٢) «حجز استشارة» نموذجٌ وحده — كانت قائمة الطلبات الجارية وزرّ دفعها نسخةً ثانية من «استشاراتي».
 * ٣) «أحدث الوثائق الصادرة» صادرةٌ فعلاً — كانت تعرض ما رفعه العميل، وقيمة الاتّجاه نصٌّ حرّ.
 * ٤) «حجز الموعد الآن» بحكم الخادم نفسه — كان يبقى بعد طلب الاستشارة ويفتح الحجز بلا رقم التذكرة.
 * ٥) التقويم بطبقة تصفيةٍ واحدة (الخادم) — كانت شرائح المكوّن وبحثه يصفّيان الصفحة المعروضة وحدها فوق شريط الخادم.
 * ٦) «تتبع القرار» يفتح ملفّ التنفيذ نفسه لا قائمة التنفيذ.
 * ٧) استشارةٌ قائمة «حتى تكتمل أو تُلغى» — لا طلبَ ثانٍ فوق موعدٍ مؤكَّد ولو صُحّحت التذكرة يدويّاً.
 * ٨) تقرير الاستشارة يقرأ الموعد من `starts_at` (`whenLabel`) لا النصّ المخزَّن `when_label` القديم.
 */
class ClientTabsOwnershipTest extends TestCase
{
    use BuildsConsultJourney;
    use RefreshDatabase;

    private function client(): User
    {
        return User::factory()->create(['role' => Role::Client]);
    }

    public function test_1_meetings_page_carries_no_consults(): void
    {
        $this->actingAs($this->client())->get(route('meetings'))->assertOk()
            ->assertInertia(fn ($p) => $p->component('meetings')->missing('videoConsults')->missing('stats.videoConsultsCount'));

        $page = (string) file_get_contents(resource_path('js/pages/meetings.tsx'));
        $this->assertStringNotContainsString("setActiveTab('consults')", $page);
        $this->assertStringNotContainsString('Consult::', (string) file_get_contents(app_path('Http/Controllers/MeetingController.php')));
    }

    public function test_2_book_page_is_the_form_only_and_requests_live_in_my_consults(): void
    {
        $client = $this->client();
        $this->actingAs($client)->post(route('book.store'), [
            'type' => 'video', 'specialty' => 'القضايا التجارية', 'subject' => 'هيكلة شراكة',
        ])->assertRedirect(route('myconsults'));

        $this->actingAs($client)->get(route('book'))->assertOk()
            ->assertInertia(fn ($p) => $p->component('book')->missing('pending'));
        $this->actingAs($client)->get(route('myconsults'))
            ->assertInertia(fn ($p) => $p->has('consults', 1)->where('stats.pendingBooking', 1));

        $this->assertStringNotContainsString('BookingActions', (string) file_get_contents(resource_path('js/pages/book.tsx')));
    }

    public function test_3_dashboard_recent_documents_are_issued_ones_from_the_documents_source(): void
    {
        Storage::fake('local');
        $client = $this->client();
        Document::create(['user_id' => $client->id, 'name' => 'صك ملكية رفعه العميل', 'meta' => '—', 'direction' => DocumentDirection::Up, 'path' => 'd/1.pdf']);
        Document::create(['user_id' => $client->id, 'name' => 'مذكرة صادرة من المكتب', 'meta' => '—', 'direction' => DocumentDirection::Out, 'path' => 'd/2.pdf']);

        $this->actingAs($client)->get(route('dashboard'))
            ->assertInertia(fn ($p) => $p->has('recentDocs', 1)->where('recentDocs.0.name', 'مذكرة صادرة من المكتب'));

        $this->actingAs($client)->get(route('documents'))
            ->assertInertia(fn ($p) => $p->has('docsOut', 1)->has('docsUp', 1)->where('docsUp.0.name', 'صك ملكية رفعه العميل'));
    }

    /** القيمة الشاذّة `in` (بيانات التجربة) تصير «مرفوعاً» فتظهر في «مستنداتك المرفوعة» ولا تضيع. */
    public function test_3b_the_migration_moves_legacy_in_documents_to_uploaded(): void
    {
        $client = $this->client();
        $row = fn (string $name, string $direction) => \DB::table('documents')->insertGetId([
            'user_id' => $client->id, 'name' => $name, 'meta' => '—', 'direction' => $direction, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $id = $row('صك قديم', 'in');
        $ar = $row('عقد وارد', 'وارد');
        $out = $row('مذكرة', 'صادر');

        (require database_path('migrations/2026_10_04_100000_normalize_document_direction.php'))->up();

        $this->assertSame(DocumentDirection::Up, Document::findOrFail($id)->direction);
        $this->assertSame(DocumentDirection::Up, Document::findOrFail($ar)->direction);
        $this->assertSame(DocumentDirection::Out, Document::findOrFail($out)->direction);
        $this->actingAs($client)->get(route('documents'))
            ->assertInertia(fn ($p) => $p->has('docsUp', 2)->has('docsOut', 1));
    }

    public function test_4_booking_alert_follows_the_server_guard_and_carries_the_ticket(): void
    {
        $client = $this->client();
        $ticket = $this->ticketWithApprovedOpinion($client, ['status' => 'بانتظار حجز الاستشارة']);

        $alerts = fn () => collect($this->actingAs($client)->get(route('dashboard'))->inertiaProps('actionAlerts'))
            ->where('type', 'needs_booking')->values();

        $this->assertTrue($ticket->fresh()->awaitsConsultRequest());
        $this->assertSame('/book?ticket='.$ticket->number, $alerts()->first()['link'], 'الحجز يُفتح مربوطاً بالتذكرة');

        // طلب الاستشارة من محادثة التذكرة (المسار الحقيقيّ) — التذكرة باقية «بانتظار حجز الاستشارة» حتى السداد
        $this->actingAs($client)->post(route('tickets.book', $ticket), ['type' => 'video'])->assertNoContent();
        $fresh = $ticket->fresh();
        $this->assertSame('بانتظار حجز الاستشارة', $fresh->status);

        $this->assertFalse($fresh->awaitsConsultRequest(), 'طُلبت الاستشارة — لا يُطلب حجزها ثانيةً');
        $this->assertFalse($fresh->toCard()['needsBooking']);
        $this->assertCount(0, $alerts(), 'لا تنبيه «حجز الموعد الآن» لطلبٍ قائم');
    }

    public function test_5_the_client_calendar_has_one_filter_layer_from_the_server(): void
    {
        $page = (string) file_get_contents(resource_path('js/pages/calendar.tsx'));
        $this->assertStringContainsString('filterToolbar={<TimelineToolbar', $page, 'شريط الخادم ظاهرٌ دائماً');
        $this->assertStringNotContainsString('showAdvancedFilters', $page, 'لا زرّ يُخفيه');

        $calendar = (string) file_get_contents(resource_path('js/components/babylon/UnifiedCalendar.tsx'));
        $this->assertStringContainsString('const serverFiltered = Boolean(filterToolbar);', $calendar);
        $this->assertSame(2, substr_count($calendar, '{!serverFiltered && ('), 'الشرائح والبحث الداخليّان يُطويان مع شريط الخادم');

        $this->actingAs($this->client())->get(route('calendar'))->assertOk()
            ->assertInertia(fn ($p) => $p->component('calendar')->has('filters')->has('counts')->has('statuses'));
    }

    public function test_6_track_decision_opens_the_execution_file(): void
    {
        $page = (string) file_get_contents(resource_path('js/pages/dashboard.tsx'));
        $this->assertStringContainsString('router.visit(`/execs?id=${encodeURIComponent(e.number)}`)', $page);
        $this->assertStringNotContainsString("onClick={() => go('execs')}", $page);
    }

    public function test_7_a_confirmed_consult_blocks_a_second_request_even_after_a_manual_correction(): void
    {
        $client = $this->client();
        $ticket = $this->ticketWithApprovedOpinion($client, ['status' => 'موعد مؤكد', 'approved_track' => 'consultation', 'approved_track_at' => now()]);
        $consult = Consult::create([
            'user_id' => $client->id, 'ticket_id' => $ticket->id, 'ref' => 'CN-LIVE-'.uniqid(), 'subject' => 'نزاع', 'channel' => 'مرئية', 'lawyer' => 'محامٍ',
            'session' => 'بانتظار الجلسة', 'status' => ConsultStatus::ReadyForLawyer->value, 'starts_at' => now()->addDay(),
        ]);

        // الباب الإداريّ الاستثنائيّ نفسه: تصحيحٌ مسبَّب إلى «بانتظار حجز الاستشارة»
        $this->actingAs($this->journeyAdmin())->post(route('admin.tickets.correct-status', $ticket), [
            'status' => 'بانتظار حجز الاستشارة', 'reason' => 'تصحيح حالة بعد مراجعة',
        ])->assertSessionHasNoErrors();
        $this->assertSame('بانتظار حجز الاستشارة', $ticket->fresh()->status);

        $this->assertTrue($ticket->fresh()->hasPendingConsult());
        $this->assertFalse($ticket->fresh()->awaitsConsultRequest(), 'لا «حجز الموعد الآن» وموعدها قائم');
        $this->actingAs($client)->post(route('tickets.book', $ticket), ['type' => 'video'])
            ->assertSessionHasErrors(['type' => 'يوجد طلب استشارة قائم لهذه التذكرة.']);
        $this->assertSame(1, $ticket->consults()->count(), 'لا استشارة ثانية');

        // انتهت أو أُلغيت ⇒ يجوز طلبٌ جديد كما كان
        $consult->forceFill(['status' => ConsultStatus::Cancelled->value])->save();
        $this->assertFalse($ticket->fresh()->hasPendingConsult());
        $this->assertTrue($ticket->fresh()->awaitsConsultRequest());
    }

    public function test_8_the_consult_report_reads_the_real_appointment_time(): void
    {
        $client = $this->client();
        $consult = Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-REP-'.uniqid(), 'subject' => 'نزاع', 'channel' => 'مرئية', 'lawyer' => 'محامٍ',
            'session' => 'بانتظار الجلسة', 'status' => ConsultStatus::ReadyForLawyer->value,
            'starts_at' => now()->setDate(2026, 10, 3)->setTime(3, 17), 'when_label' => '2026-09-29 · 10:00 AM',
        ]);

        $cells = collect(ConsultReport::doc($consult, $client->name)['blocks'][0]['cellRows'])->flatten(1)->pluck(1, 0);
        $this->assertSame($consult->whenLabel(), $cells['الموعد'], 'موعد «استشاراتي» نفسه');
        $this->assertStringNotContainsString('09-29', (string) $cells['الموعد']);
    }
}
