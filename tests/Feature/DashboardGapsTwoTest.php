<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\LegalCase;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * تتمّة نواقص اللوحات (الدفعة ب): بيانات مُرسلة كانت تُهمَل، وإجراء غائب عن المحامي.
 */
class DashboardGapsTwoTest extends TestCase
{
    use RefreshDatabase;

    // ── حمولة prices تصل صفحة الحجز سليمة — والواجهة لا تعرضها **عمداً** ──
    // قرار منتج (2026-08-25): لا سعر ثابت مُعلن للعميل؛ التسعير تحدّده الإدارة لكل طلب
    // على حدة بعد دراسته. فعدم عرض prices في book.tsx ليس ثغرة تجاهل — لا «تُصلحه»
    // بإظهار السعر. الاختبار يحرس سلامة الحمولة (تُستهلك في شاشة تسعير الإدارة) فقط.

    public function test_booking_page_receives_live_admin_prices(): void
    {
        Setting::put('price_office', 777);
        Setting::put('vat_rate', 5);
        $client = User::factory()->create(['role' => Role::Client]);

        $this->actingAs($client)->get(route('book'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('book')
                ->where('prices.office', 777)
                ->where('prices.vat', 5)
                ->has('specialties'));
    }

    // ── الإيراد حسب النوع: القسمة على 1000 كانت تُصفّر كل ما دون 500 ر.س ──

    public function test_revenue_by_service_is_in_full_riyals(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $client = User::factory()->create(['role' => Role::Client]);
        Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-R-1', 'subject' => 'نزاع', 'channel' => 'هاتفية',
            'lawyer' => 'أ. سارة', 'session' => 'منتهية', 'status' => 'مكتملة',
            'price' => 350, 'vat' => 52, 'total' => 402, 'paid_at' => now(),
        ]);

        // 402 ر.س كانت تُقرّب إلى 0 فيظهر العمود فارغاً
        $this->actingAs($admin)->get(route('admin.revenue'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('byService.0.v', 402));
    }

    // ── المحامي المسؤول لم يكن يملك وسيلة لمخاطبة موكّله من ملفّ القضية ──

    public function test_assigned_lawyer_can_reply_inside_case_file(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $client = User::factory()->create(['role' => Role::Client]);
        $case = LegalCase::create([
            'user_id' => $client->id, 'number' => 'CASE-R-1', 'type' => 'تجاري',
            'assigned_lawyer_id' => $lawyer->id, 'status' => 'منظورة', 'tone' => 'b-blue',
        ]);

        $this->actingAs($lawyer)->post(route('lawyer.cases.reply', $case), ['body' => 'تمت مراجعة الملف.'])
            ->assertNoContent();

        $this->assertDatabaseHas('case_messages', ['case_id' => $case->id, 'who' => 'lawyer']);
    }

    public function test_unassigned_lawyer_cannot_reply_inside_case_file(): void
    {
        $mine = User::factory()->create(['role' => Role::Lawyer]);
        $other = User::factory()->create(['role' => Role::Lawyer]);
        $client = User::factory()->create(['role' => Role::Client]);
        $case = LegalCase::create([
            'user_id' => $client->id, 'number' => 'CASE-R-2', 'type' => 'تجاري',
            'assigned_lawyer_id' => $other->id, 'status' => 'منظورة', 'tone' => 'b-blue',
        ]);

        $this->actingAs($mine)->post(route('lawyer.cases.reply', $case), ['body' => 'محاولة'])
            ->assertForbidden();
    }

    public function test_lawyer_reply_escapes_markup(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $client = User::factory()->create(['role' => Role::Client]);
        $case = LegalCase::create([
            'user_id' => $client->id, 'number' => 'CASE-R-3', 'type' => 'تجاري',
            'assigned_lawyer_id' => $lawyer->id, 'status' => 'منظورة', 'tone' => 'b-blue',
        ]);

        $this->actingAs($lawyer)->post(route('lawyer.cases.reply', $case), ['body' => '<img src=x onerror=alert(1)>']);

        $body = $case->messages()->latest('id')->firstOrFail()->body;
        $this->assertStringNotContainsString('<img', $body);
    }
}
