<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Jobs\SendSmsJob;
use App\Models\Consult;
use App\Models\User;
use App\Support\ConsultAppointments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Concerns\BuildsConsultJourney;
use Tests\TestCase;

/**
 * **رسالة تأكيد موعد الاستشارة** (قرار المالك 2026-10-03).
 *
 * ثبت بالكود: اعتماد الموعد يرسل بريداً وإشعاراً في الحساب ولا رسالة نصّيّة — فلا يعرف العميل موعده
 * برسالةٍ إلّا قبله بدقائق. الآن رسالةٌ قصيرة عند الاعتماد فيها رقم الاستشارة ونوعها وتاريخها ووقتها.
 */
class ConsultBookedSmsTest extends TestCase
{
    use BuildsConsultJourney;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake([SendSmsJob::class]);
        config(['services.taqnyat.api_key' => 'tok_test', 'services.taqnyat.sender' => 'Salasel']);
    }

    private function paidConsult(string $phone = '0555550201', string $type = 'office'): Consult
    {
        $client = User::factory()->create(['role' => Role::Client, 'phone' => $phone]);
        $ticket = $this->ticketWithApprovedOpinion($client, ['number' => 'SB-BS-'.uniqid(), 'status' => 'بانتظار حجز الاستشارة']);

        return $this->requestPricedAndPaid($client, $ticket, $type);
    }

    private function publish(Consult $consult, string $time = '10:00'): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active', 'department' => 'القضايا التجارية']);
        ConsultAppointments::publish($consult->fresh(), $this->journeyAdmin(), [
            'lawyer_id' => $lawyer->id, 'date' => now()->addDays(2)->toDateString(), 'time' => $time,
        ]);
    }

    public function test_the_client_gets_a_text_with_ref_type_date_and_time(): void
    {
        $consult = $this->paidConsult();

        $this->publish($consult);

        $fresh = $consult->fresh();
        Bus::assertDispatchedTimes(SendSmsJob::class, 1);
        Bus::assertDispatched(SendSmsJob::class, fn (SendSmsJob $job) => $job->intlPhone === '966555550201'
            && str_contains($job->body, $fresh->ref)
            && str_contains($job->body, (string) $fresh->channel)
            && str_contains($job->body, $fresh->starts_at->locale('ar')->translatedFormat('d F Y'))
            && str_contains($job->body, $fresh->starts_at->locale('ar')->translatedFormat('h:i A')));
    }

    public function test_a_video_consult_is_confirmed_too(): void
    {
        $this->publish($this->paidConsult('0555550202', 'video'));

        Bus::assertDispatched(SendSmsJob::class, fn (SendSmsJob $job) => $job->intlPhone === '966555550202');
    }

    public function test_no_text_without_a_sendable_phone(): void
    {
        $this->publish($this->paidConsult('123'));

        Bus::assertNotDispatched(SendSmsJob::class);
    }
}
