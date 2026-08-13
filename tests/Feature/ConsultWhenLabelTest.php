<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * موعد الاستشارة في «استشاراتي» يُعرض بصياغة عربية موحّدة من starts_at الحقيقي،
 * لا نص when_label الخام (مثل «2026-08-10 · 14:00») الذي كان يختلف حسب مسار الإنشاء.
 */
class ConsultWhenLabelTest extends TestCase
{
    use RefreshDatabase;

    private function makeConsult(User $client, array $overrides = []): Consult
    {
        return Consult::create(array_merge([
            'user_id' => $client->id,
            'ref' => 'CS-26-5001',
            'subject' => 'نزاع تجاري',
            'lawyer' => 'أ. سارة القحطاني',
            'channel' => 'هاتفية',
            'status' => 'مؤكدة',
            'when_label' => '2026-08-10 · 14:00', // النص الخام الذي كان يُعرض كما هو
            'starts_at' => Carbon::create(2026, 8, 10, 14, 0, 0),
            'duration_min' => 30,
        ], $overrides));
    }

    public function test_when_is_human_arabic_not_raw_iso(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $consult = $this->makeConsult($client);

        foreach ([$consult->toClientCard()['when'], $consult->toCard()['when']] as $when) {
            $this->assertNotSame('2026-08-10 · 14:00', $when); // ليست الصياغة الخام
            $this->assertStringContainsString('2026', $when);   // السنة قائمة
            $this->assertMatchesRegularExpression('/ص|م/u', $when); // صياغة 12 ساعة عربية
        }
    }

    public function test_falls_back_to_stored_label_when_no_starts_at(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $consult = $this->makeConsult($client, ['starts_at' => null, 'when_label' => 'الأحد · 10ص']);

        $this->assertSame('الأحد · 10ص', $consult->whenLabel());
        $this->assertSame('الأحد · 10ص', $consult->toClientCard()['when']);
    }
}
