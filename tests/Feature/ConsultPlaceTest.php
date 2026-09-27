<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Consult;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * مكان/قناة الجلسة — مصدر واحد (Consult::placeLabel) بعد فصل الدلالة عن «الفرع» التنظيمي.
 * كان مكان الاستشارة الحضورية يُقرأ من عمود الفرع (فرع المحامي) فيُعرض للعميل
 * «الفرع الرئيسي — جدة» مكانَ عنوان الجلسة الفعلي المخزَّن على الموعد.
 */
class ConsultPlaceTest extends TestCase
{
    use RefreshDatabase;

    private function client(): User
    {
        return User::factory()->create(['role' => Role::Client]);
    }

    private function consult(User $client, string $channel, ?Appointment $appt = null): Consult
    {
        return Consult::create([
            'user_id' => $client->id,
            'appointment_id' => $appt?->id,
            'ref' => 'CN-2026-'.uniqid(),
            'subject' => 'استشارة قانونية',
            'status' => 'جديدة',
            'session' => 'بانتظار الجلسة',
            'channel' => $channel,
            'lawyer' => 'أ. سارة القحطاني',
        ]);
    }

    public function test_office_consult_place_comes_from_its_appointment(): void
    {
        $client = $this->client();
        $appt = Appointment::create([
            'user_id' => $client->id,
            'ext_id' => 'AP-26-7001',
            'type' => 'استشارة حضورية',
            'ico' => 'office',
            'lawyer' => 'أ. سارة القحطاني',
            'day' => 'الأحد 23 أغسطس',
            'time' => '10:00',
            'place' => 'الرياض — حي العليا',
            'status' => 'مؤكد',
            'tone' => 'b-green',
            'when_kind' => 'up',
        ]);

        $consult = $this->consult($client, 'حضورية', $appt);

        $this->assertSame('الرياض — حي العليا', $consult->placeLabel());
        $this->assertSame('الرياض — حي العليا', $consult->toClientCard()['place']);
    }

    public function test_video_and_phone_place_is_channel_derived_without_appointment(): void
    {
        $client = $this->client();

        $this->assertSame('اجتماع إلكتروني', $this->consult($client, 'مرئية')->placeLabel());
        $this->assertSame('مكالمة هاتفية', $this->consult($client, 'هاتفية')->placeLabel());
    }

    public function test_office_consult_without_appointment_falls_back_to_office_address(): void
    {
        config(['office.address' => 'الرياض — حي العليا']);

        $this->assertSame('الرياض — حي العليا', $this->consult($this->client(), 'حضورية')->placeLabel());
    }

    public function test_consult_without_appointment_exposes_no_place_on_cards(): void
    {
        $consult = $this->consult($this->client(), 'حضورية');

        $this->assertSame('', $consult->toClientCard()['place']);
        $this->assertSame('', $consult->toCard()['place']);
    }

    public function test_appointment_card_pdf_uses_appointment_place(): void
    {
        $client = $this->client();
        $appointment = Appointment::create([
            'user_id' => $client->id,
            'ext_id' => 'AP-26-7002',
            'type' => 'استشارة حضورية',
            'ico' => 'office',
            'lawyer' => 'أ. سارة القحطاني',
            'day' => 'الأحد 23 أغسطس',
            'time' => '10:00',
            'place' => 'الرياض — حي العليا',
            'status' => 'مؤكد',
            'tone' => 'b-green',
            'when_kind' => 'up',
        ]);

        $this->assertSame('الرياض — حي العليا', $appointment->toCard()['place']);
    }
}
