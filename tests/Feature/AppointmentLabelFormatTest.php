<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * تنسيق يوم/وقت الموعد بصياغة عربية مقروءة من starts_at الحقيقي — لا نص ISO/24 ساعة خامّ.
 */
class AppointmentLabelFormatTest extends TestCase
{
    use RefreshDatabase;

    public function test_day_and_time_labels_are_human_arabic_not_raw(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $appointment = Appointment::create([
            'user_id' => $client->id,
            'ext_id' => 'AP-26-7001',
            'type' => 'استشارة مرئية',
            'ico' => 'video',
            'lawyer' => 'أ. سارة القحطاني',
            'day' => '2026-08-08',
            'time' => '05:00',
            'starts_at' => Carbon::create(2026, 8, 8, 5, 0, 0),
            'duration_min' => 60,
            'branch' => 'اجتماع إلكتروني',
            'status' => 'مؤكد',
            'tone' => 'b-green',
            'when_kind' => 'up',
        ]);

        $card = $appointment->toCard();

        // ليست الصياغة الخام (ISO / 24 ساعة) — بل نصّ عربي مقروء
        $this->assertNotSame('2026-08-08', $card['day']);
        $this->assertNotSame('05:00', $card['time']);
        $this->assertStringContainsString('2026', $card['day']);
        $this->assertMatchesRegularExpression('/ص|م/u', $card['time']);
    }
}
