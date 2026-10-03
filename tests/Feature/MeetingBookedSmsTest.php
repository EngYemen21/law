<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Jobs\SendSmsJob;
use App\Models\Meeting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * **رسالة تأكيد موعد الاجتماع للعميل عند جدولته** (قرار المالك 2026-10-03).
 *
 * ثبت بالكود وبسجلّ الإنتاج: إنشاء الاجتماع من «إدارة الاجتماعات» يُرسل للعميل إشعاراً وبريداً ولا رسالة نصّيّة،
 * فلا تصله رسالةٌ إلّا عند فتح الدخول قبل الموعد بدقائق. الآن رسالةٌ قصيرة برقم الاجتماع وتاريخه ووقته — من
 * الإنشاء المباشر ومن نشر الدعوة. والاجتماع الداخليّ (بلا عميل) لا يُرسل شيئاً.
 */
class MeetingBookedSmsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake([SendSmsJob::class]);
        config(['services.taqnyat.api_key' => 'tok_test', 'services.taqnyat.sender' => 'Salasel']);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => Role::Admin]);
    }

    private function lawyer(): User
    {
        return User::factory()->create(['role' => Role::Lawyer, 'status' => 'active', 'department' => 'القضايا التجارية']);
    }

    private function day(): string
    {
        return now()->addDays(3)->toDateString();
    }

    public function test_creating_a_client_meeting_texts_the_client_its_ref_date_and_time(): void
    {
        $client = User::factory()->create(['role' => Role::Client, 'phone' => '0555550301']);

        $this->actingAs($this->admin())->post('/admin/meetings', [
            'title' => 'مراجعة مستندات', 'type' => 'اجتماع مع عميل', 'day' => $this->day(), 'time' => '10:00', 'client_id' => $client->id,
        ])->assertSessionHasNoErrors();

        $meeting = Meeting::firstOrFail();
        Bus::assertDispatchedTimes(SendSmsJob::class, 1);
        Bus::assertDispatched(SendSmsJob::class, fn (SendSmsJob $job) => $job->intlPhone === '966555550301'
            && str_contains($job->body, $meeting->ref)
            && str_contains($job->body, $meeting->starts_at->locale('ar')->translatedFormat('d F Y'))
            && str_contains($job->body, $meeting->starts_at->locale('ar')->translatedFormat('h:i A')));
    }

    public function test_an_internal_meeting_texts_no_one(): void
    {
        $this->actingAs($this->admin())->post('/admin/meetings', [
            'title' => 'اجتماع داخلي', 'type' => 'اجتماع داخلي', 'day' => $this->day(), 'time' => '11:00',
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, Meeting::count());
        Bus::assertNotDispatched(SendSmsJob::class);
    }

    public function test_an_invitation_published_by_the_admin_texts_the_client_too(): void
    {
        $client = User::factory()->create(['role' => Role::Client, 'phone' => '0555550302']);

        $this->actingAs($this->admin())->post('/admin/meetreqs', [
            'client_id' => $client->id, 'lawyer_id' => $this->lawyer()->id, 'service' => 'مراجعة عقد',
            'type' => 'استشارة مرئية', 'day' => $this->day(), 'time' => '12:00',
        ])->assertSessionHasNoErrors();

        Bus::assertDispatchedTimes(SendSmsJob::class, 1);
        Bus::assertDispatched(SendSmsJob::class, fn (SendSmsJob $job) => $job->intlPhone === '966555550302'
            && str_contains($job->body, Meeting::firstOrFail()->ref));
    }

    public function test_no_text_without_a_sendable_phone(): void
    {
        $client = User::factory()->create(['role' => Role::Client, 'phone' => '123']);

        $this->actingAs($this->admin())->post('/admin/meetings', [
            'title' => 'اجتماع', 'type' => 'اجتماع مع عميل', 'day' => $this->day(), 'time' => '13:00', 'client_id' => $client->id,
        ])->assertSessionHasNoErrors();

        Bus::assertNotDispatched(SendSmsJob::class);
    }
}
