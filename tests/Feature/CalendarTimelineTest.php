<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Meeting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * التبويب الزمني: ترشيح وبحث وتصفيح **خادمية**.
 *
 * كانت الصفحة تُحمّل الأنواع الأربعة كاملةً في حمولة Inertia واحدة ثم تدمجها الواجهة —
 * فلا بحث ولا ترشيح، وحجم الحمولة ينمو مع عمر الحساب بلا سقف.
 */
class CalendarTimelineTest extends TestCase
{
    use RefreshDatabase;

    private function client(): User
    {
        return User::factory()->create(['role' => Role::Client]);
    }

    private function appt(User $c, string $ext, $at, string $type = 'استشارة حضورية'): Appointment
    {
        return Appointment::create([
            'user_id' => $c->id, 'ext_id' => $ext, 'type' => $type, 'ico' => 'office',
            'lawyer' => 'المحامي', 'day' => $at->format('Y-m-d'), 'time' => $at->format('H:i'),
            'starts_at' => $at, 'duration_min' => 60, 'place' => 'الرياض',
            'status' => 'مؤكد', 'tone' => 'b-green', 'when_kind' => 'up',
        ]);
    }

    private function meeting(User $c, string $ref, $at): Meeting
    {
        return Meeting::create([
            'user_id' => $c->id, 'ref' => $ref, 'title' => 'اجتماع مراجعة',
            'when_label' => $at->format('Y-m-d'), 'starts_at' => $at,
            'status' => 'قادم', 'dur' => '60 دقيقة',
        ]);
    }

    /** التصفيح حقيقيّ: الصفحة تحمل perPage لا كل السجلّات. */
    public function test_only_one_page_of_events_is_sent(): void
    {
        $client = $this->client();
        for ($i = 1; $i <= 15; $i++) {
            $this->appt($client, "AP-P{$i}", now()->addDays($i));
        }

        $this->actingAs($client)->get(route('calendar', ['per' => 12]))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('calendar')
                ->has('events', 12)              // لا 15
                ->where('meta.total', 15)
                ->where('meta.lastPage', 2)
                ->where('meta.page', 1));
    }

    /** والصفحة الثانية تحمل الباقي. */
    public function test_the_second_page_carries_the_remainder(): void
    {
        $client = $this->client();
        for ($i = 1; $i <= 15; $i++) {
            $this->appt($client, "AP-Q{$i}", now()->addDays($i));
        }

        $this->actingAs($client)->get(route('calendar', ['per' => 12, 'page' => 2]))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->has('events', 3)->where('meta.page', 2));
    }

    /** الترشيح بالنوع يقع في القاعدة لا في الواجهة. */
    public function test_filtering_by_kind_narrows_the_result(): void
    {
        $client = $this->client();
        $this->appt($client, 'AP-K1', now()->addDays(1));
        $this->meeting($client, 'M-K1', now()->addDays(2));

        $this->actingAs($client)->get(route('calendar', ['kind' => 'meeting']))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->has('events', 1)
                ->where('events.0.kindKey', 'meeting')
                ->where('meta.total', 1));
    }

    /** والبحث النصّي يطابق العنوان أو المرجع. */
    public function test_search_matches_the_title(): void
    {
        $client = $this->client();
        $this->appt($client, 'AP-S1', now()->addDays(1), 'استشارة عقارية');
        $this->appt($client, 'AP-S2', now()->addDays(2), 'نزاع عمالي');

        $this->actingAs($client)->get(route('calendar', ['q' => 'عقارية']))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->has('events', 1)->where('meta.total', 1));
    }

    /** العدّادات تتجاهل ترشيح النوع نفسه — وإلّا صارت كل شريحة تعرض عدد نفسها فقط. */
    public function test_kind_counts_ignore_the_kind_filter(): void
    {
        $client = $this->client();
        $this->appt($client, 'AP-C1', now()->addDays(1));
        $this->meeting($client, 'M-C1', now()->addDays(2));

        $this->actingAs($client)->get(route('calendar', ['kind' => 'meeting']))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('counts.appointment', 1)
                ->where('counts.meeting', 1)
                ->where('counts.all', 2));
    }

    /** والترتيب افتراضياً بالأقرب — لا بترتيب الإنشاء. */
    public function test_events_are_ordered_by_date(): void
    {
        $client = $this->client();
        $this->appt($client, 'AP-LATE', now()->addDays(9));
        $this->appt($client, 'AP-SOON', now()->addDays(1));

        $this->actingAs($client)->get(route('calendar'))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('events.0.id', 'AP-SOON'));
    }

    /** ترشيح غير مسموح يُرفض خادمياً لا يُتجاهل صامتاً. */
    public function test_an_invalid_kind_is_rejected(): void
    {
        $this->actingAs($this->client())->get(route('calendar', ['kind' => 'bogus']))
            ->assertSessionHasErrors('kind');
    }
}
