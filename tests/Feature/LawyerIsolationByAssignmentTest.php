<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\Meeting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * **المحامي معزولٌ بالإسناد لا بالاسم** (تدقيق اللوحات ٧).
 *
 * كانت لوحة المحامي وتقويمه يضمّان ما يطابق **اسمه النصّيّ** (`consults.lawyer` و`meetings.created_by`)
 * إلى ما أُسند إليه، فيرى محامٍ يشارك زميلاً اسمَه — أو بقي اسمه نصّاً بعد إعادة الإسناد —
 * اسمَ عميلٍ لغيره وموضوعه ورابط دخول جلسته.
 */
class LawyerIsolationByAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_and_calendar_ignore_records_that_only_share_the_lawyers_name(): void
    {
        $me = User::factory()->create(['role' => Role::Lawyer, 'name' => 'أ. سمير الحربي']);
        $namesake = User::factory()->create(['role' => Role::Lawyer, 'name' => 'أ. سمير الحربي']);
        $client = User::factory()->create(['role' => Role::Client]);
        $startsAt = now()->addDay()->setTime(11, 0);

        Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-ISO-'.uniqid(), 'subject' => 'ملفّ الزميل',
            'channel' => 'مرئية', 'lawyer' => 'أ. سمير الحربي', 'assigned_lawyer_id' => $namesake->id,
            'status' => 'جديدة', 'session' => 'بانتظار الجلسة', 'starts_at' => $startsAt, 'duration_min' => 45,
        ]);
        Meeting::create([
            'user_id' => $client->id, 'ref' => 'M-ISO-'.uniqid(), 'title' => 'اجتماع الزميل', 'type' => 'اجتماع عميل',
            'when_label' => 'غداً', 'status' => 'قادم', 'approve' => 'بانتظار', 'starts_at' => $startsAt,
            'assigned_lawyer_id' => $namesake->id, 'created_by' => 'أ. سمير الحربي',
        ]);

        $this->actingAs($me)->get(route('lawyer.dashboard'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->has('todayConsults', 0)
                ->where('openMeetings', 0));

        $this->actingAs($me)->get(route('lawyer.calendar'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('events', function (Collection $events) {
                $this->assertSame([], $events->pluck('title')->all());

                return true;
            }));

        // وصاحب الإسناد يراهما
        $this->actingAs($namesake)->get(route('lawyer.dashboard'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->has('todayConsults', 1)->where('openMeetings', 1));
    }
}
