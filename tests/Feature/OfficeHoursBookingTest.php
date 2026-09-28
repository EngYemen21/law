<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Setting;
use App\Models\User;
use App\Support\LawyerAvailability;
use App\Support\SettingsRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsConsultJourney;
use Tests\TestCase;

/**
 * **الحجز بدوام المكتب** (قرار المالك 2026-09-28): ٠٩:٠٠–٢٢:٠٠، الأحد–الخميس — إعداداتٌ تضبطها الإدارة
 * (`consult_day_start`/`consult_day_end`/`consult_work_days`)، ويقرؤها المحرّك والشبكات والحارس معاً.
 */
class OfficeHoursBookingTest extends TestCase
{
    use BuildsConsultJourney;
    use RefreshDatabase;

    private function lawyer(): User
    {
        return User::factory()->create(['role' => Role::Lawyer, 'status' => 'active', 'department' => 'القضايا التجارية']);
    }

    private function nextWeek(int $weekday): string
    {
        return now()->startOfWeek(Carbon::SUNDAY)->addDays(7 + $weekday)->toDateString();
    }

    public function test_the_defaults_are_the_office_hours_and_days(): void
    {
        $this->assertSame([9, 22], LawyerAvailability::workHours());
        $this->assertSame([0, 1, 2, 3, 4], LawyerAvailability::workDays());
        $this->assertSame('0,1,2,3,4', HandleInertiaRequests::sharedSettings()['consult_work_days'], 'الشبكات تقرأ الأيّام من الخادم');
    }

    public function test_a_day_off_has_no_slots_instead_of_the_next_days_slots(): void
    {
        // كان `slotsFor` ينقل الجمعة صامتاً إلى الأحد فتعرض شبكة الجمعة شرائح الأحد
        $this->assertSame([], LawyerAvailability::slotsFor($this->lawyer()->id, $this->nextWeek(5)));
    }

    public function test_the_server_refuses_a_booking_outside_the_office_days_or_hours(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $this->lawyer();
        $consult = $this->requestPricedAndPaid($client, $this->ticketWithApprovedOpinion($client, ['status' => 'بانتظار حجز الاستشارة']), 'video');

        $this->adminPublishes($consult, ['date' => $this->nextWeek(5), 'time' => '10:00'])
            ->assertStatus(422)->assertJsonPath('errors.time.0', 'هذا اليوم خارج أيّام دوام المكتب — اختر يوماً من أيّام الدوام.');
        $this->adminPublishes($consult, ['date' => $this->nextWeek(1), 'time' => '08:30'])->assertStatus(422);
        // آخر بدايةٍ تنتهي شريحتها (٦٠د) قبل ٢٢:٠٠
        $this->adminPublishes($consult, ['date' => $this->nextWeek(1), 'time' => '21:30'])
            ->assertStatus(422)->assertJsonPath('errors.time.0', 'الموعد خارج ساعات دوام المكتب (09:00–22:00).');

        $this->adminPublishes($consult, ['date' => $this->nextWeek(1), 'time' => '21:00'])->assertOk();
    }

    public function test_the_admin_sets_the_days_and_they_are_stored_sorted_once(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);

        $this->actingAs($admin)->post(route('admin.settings.update'), ['consult_work_days' => '6,0,1,1'])->assertSessionHasNoErrors();
        $this->assertSame('0,1,6', Setting::get('consult_work_days'));
        $this->assertSame([0, 1, 6], LawyerAvailability::workDays());

        $this->actingAs($admin)->post(route('admin.settings.update'), ['consult_work_days' => '7'])->assertSessionHasErrors('consult_work_days');
        $this->actingAs($admin)->post(route('admin.settings.update'), ['consult_work_days' => ''])->assertSessionHasErrors('consult_work_days');
    }

    public function test_a_corrupt_stored_value_falls_back_instead_of_closing_booking(): void
    {
        Setting::put('consult_work_days', 'x,9');
        SettingsRegistry::flush();

        $this->assertSame(LawyerAvailability::WORK_DAYS, LawyerAvailability::workDays());
    }

    public function test_the_booking_screens_read_the_days_from_one_hook(): void
    {
        $hook = (string) file_get_contents(resource_path('js/lib/consult-slots.ts'));
        $this->assertStringContainsString('consult_work_days', $hook);

        foreach (['employee/schedule', 'employee/ticketchat'] as $page) {
            $src = (string) file_get_contents(resource_path("js/pages/{$page}.tsx"));
            $this->assertStringContainsString('gridOn(', $src, $page);
            $this->assertStringContainsString('emptyText=', $src, "{$page}: يوم العطلة لا يعرض الشبكة العامّة");
        }
    }

    /** يوم العطلة في شبكة المستشارين: شارة «عطلة» لا «مكتمل اليوم»، ولا «NaN%» في بطاقة المستشار الواحد. */
    public function test_the_day_off_is_not_shown_as_fully_booked(): void
    {
        $src = (string) file_get_contents(resource_path('js/pages/employee/schedule.tsx'));

        $this->assertStringContainsString('{dayHours.length === 0 ? (', $src);
        $this->assertStringContainsString('stats.total > 0 ? Math.round((stats.free / stats.total) * 100) : 0', $src);
    }
}
