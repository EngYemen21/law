<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * سلامة لوحة العميل بعد إعادة التصميم — أعطال رُصدت بتدقيق الفروقات وأُثبتت تشغيلياً.
 *
 * أخطرها: route('appts') غير الموجود كان يُسقط /dashboard بـ500 لأي موعد حضوري/هاتفي
 * «اليوم» — والاختبار القائم أعمى عنه لأنه ينشئ موعداً مرئياً فقط (يدخل فرع canJoin).
 * كذلك /ticket/{رقم} بالمفرد (404)، وتجاوز حجز الاستشارة حدّ subject الخادمي (422).
 */
class ClientRedesignIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private function client(): User
    {
        return User::factory()->create(['role' => Role::Client]);
    }

    /** موعد حضوري «اليوم» (غير قابل للانضمام) ⇒ اللوحة تعمل لا تنفجر بـ500. */
    public function test_dashboard_survives_a_today_office_appointment(): void
    {
        $client = $this->client();
        Appointment::create([
            'user_id' => $client->id, 'ext_id' => 'APT-RD-1', 'type' => 'استشارة حضورية',
            'ico' => 'office', 'lawyer' => 'المحامي', 'day' => 'اليوم', 'time' => '11:00 ص',
            'starts_at' => now()->setTime(11, 0), 'duration_min' => 60,
            'place' => 'المقر الرئيسي', 'status' => 'مؤكد', 'tone' => 'b-green',
            'when_kind' => 'today',
        ]);

        $this->actingAs($client)->get(route('dashboard'))->assertOk();
    }

    /** وموعد هاتفي اليوم كذلك — نفس الفرع المنفجر سابقاً. */
    public function test_dashboard_survives_a_today_phone_appointment(): void
    {
        $client = $this->client();
        Appointment::create([
            'user_id' => $client->id, 'ext_id' => 'APT-RD-2', 'type' => 'استشارة هاتفية',
            'ico' => 'phone', 'lawyer' => 'المحامي', 'day' => 'اليوم', 'time' => '02:00 م',
            'starts_at' => now()->setTime(14, 0), 'duration_min' => 30,
            'place' => 'اتصال هاتفي', 'status' => 'مؤكد', 'tone' => 'b-cyan',
            'when_kind' => 'today',
        ]);

        $this->actingAs($client)->get(route('dashboard'))->assertOk();
    }

    /** كل روابط مركز التنبيهات تشير لمسارات معرَّفة فعلاً — لا 404 مزروعة في الحمولة. */
    public function test_action_alert_links_resolve_to_real_routes(): void
    {
        $client = $this->client();
        Appointment::create([
            'user_id' => $client->id, 'ext_id' => 'APT-RD-3', 'type' => 'استشارة حضورية',
            'ico' => 'office', 'lawyer' => 'المحامي', 'day' => 'اليوم', 'time' => '11:00 ص',
            'starts_at' => now()->setTime(11, 0), 'duration_min' => 60,
            'place' => 'المقر', 'status' => 'مؤكد', 'tone' => 'b-green', 'when_kind' => 'today',
        ]);
        Ticket::create([
            'user_id' => $client->id, 'number' => 'SB-RD-1', 'type' => 'نزاع',
            'subject' => 'تذكرة بانتظار مستندات', 'priority' => 'عالية',
            'status' => 'بانتظار مستندات', 'tone' => 'b-amber',
        ]);

        $response = $this->actingAs($client)->get(route('dashboard'));
        $response->assertOk();

        $alerts = $response->viewData('page')['props']['actionAlerts'] ?? [];
        $this->assertNotEmpty($alerts, 'يجب أن تُنتج البيانات أعلاه تنبيهين على الأقل');

        foreach ($alerts as $alert) {
            $link = $alert['link'] ?? '';
            if ($link === '' || str_starts_with($link, 'http')) {
                continue; // روابط خارجية (Zoom) خارج نطاق الفحص
            }
            $path = parse_url($link, PHP_URL_PATH) ?? $link;
            $matched = collect(Route::getRoutes()->get('GET'))
                ->contains(fn ($r) => preg_match('#^'.preg_replace('#\{[^}]+\}#', '[^/]+', $r->uri()).'$#u', ltrim($path, '/')));
            $this->assertTrue($matched, "رابط التنبيه «{$link}» لا يطابق أي مسار GET معرَّف");
        }
    }

    /** حجز استشارة بموضوع وملاحظات طويلة يجب ألا يسقط بـ422 على حدّ subject. */
    public function test_booking_with_long_subject_and_notes_succeeds(): void
    {
        $client = $this->client();

        // نفس التركيب الذي تبنيه الواجهة: موضوع + (تصنيف) + ملاحظات مطوَّلة
        $subject = str_repeat('نزاع تعاقدي مطوَّل ', 3); // ~57 حرفاً
        $notes = str_repeat('تفاصيل إضافية عن النزاع والتساؤلات القانونية. ', 4); // ~180 حرفاً
        $composed = mb_substr(trim($subject).' (قضايا الشركات) — '.trim($notes), 0, 120);

        $this->actingAs($client)->post('/book', [
            'type' => 'video',
            'subject' => $composed,
            'specialty' => 'قضايا الشركات',
        ])->assertSessionHasNoErrors();
    }
}
