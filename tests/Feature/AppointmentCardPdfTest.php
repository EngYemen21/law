<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * بطاقة الموعد PDF — يُصيَّر فعلياً عبر Browsershot (كروم مخفي حقيقي)، لا صورة/محاكاة.
 */
class AppointmentCardPdfTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_downloads_real_pdf_card(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $appointment = Appointment::create([
            'user_id' => $client->id,
            'ext_id' => 'AP-26-9001',
            'type' => 'استشارة مرئية',
            'ico' => 'video',
            'lawyer' => 'أ. سارة القحطاني',
            'day' => 'الاثنين 10 أغسطس',
            'time' => '11:00',
            'place' => 'اجتماع إلكتروني',
            'status' => 'مؤكد',
            'tone' => 'b-green',
            'when_kind' => 'up',
        ]);

        $response = $this->actingAs($client)->get(route('appointments.card', $appointment));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('AP-26-9001.pdf', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_other_client_cannot_download_foreign_card(): void
    {
        $owner = User::factory()->create(['role' => Role::Client]);
        $intruder = User::factory()->create(['role' => Role::Client]);
        $appointment = Appointment::create([
            'user_id' => $owner->id,
            'ext_id' => 'AP-26-9002',
            'type' => 'استشارة حضورية',
            'ico' => 'office',
            'lawyer' => 'أ. سارة القحطاني',
            'day' => 'الثلاثاء 11 أغسطس',
            'time' => '13:00',
            'place' => 'الرياض — حي العليا',
            'status' => 'مؤكد',
            'tone' => 'b-green',
            'when_kind' => 'up',
        ]);

        $this->assertPageRefused($this->actingAs($intruder)->get(route('appointments.card', $appointment)));
    }
}
