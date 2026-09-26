<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AdminDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **لوحة الإدارة تُخزَّن مؤقّتاً مصفوفاتٍ صافية** (رُصد في المتصفّح 2026-09-26).
 *
 * `cache.serializable_classes = false` يمنع إعادة بناء الكائنات عند القراءة، فكانت مجموعات اللوحة
 * (أحمال المحامين · التخصّصات · الجلسات · النبض) تعود فارغةً من الذاكرة المؤقّتة. والخدمة تتخطّى
 * الذاكرة في بيئة الاختبار، فلم يرَ ذلك أيّ اختبار — هذا الحارس يمرّر الناتج بطريق التخزين نفسه.
 */
class AdminDashboardCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_cached_metrics_survive_the_cache_unserialize_policy(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        Ticket::create(['user_id' => $client->id, 'number' => 'SB-DC-1', 'type' => 'نزاع تجاري', 'status' => 'قيد التحليل', 'tone' => 'b-blue', 'assigned_lawyer_id' => $lawyer->id]);
        LegalCase::create(['user_id' => $client->id, 'number' => 'CS-DC-1', 'type' => 'تجاري', 'status' => 'منظورة', 'department' => 'القسم التجاري', 'assigned_lawyer_id' => $lawyer->id]);

        $metrics = app(AdminDashboardService::class)->get360Data(bypassCache: true);

        // كما تفعل الذاكرة المؤقّتة: تسلسلٌ ثمّ قراءةٌ بلا أيّ صنفٍ مسموح
        $roundTrip = unserialize(serialize($metrics), ['allowed_classes' => (bool) config('cache.serializable_classes')]);

        $this->assertSame($metrics, $roundTrip, 'في ناتج اللوحة كائنٌ لا يعبر الذاكرة المؤقّتة سليماً');
        $this->assertNotEmpty($roundTrip['lawyersWorkload']);
        $this->assertNotEmpty($roundTrip['practiceAreas']);
        $this->assertNotEmpty($roundTrip['liveActivity']);
        $this->assertStringNotContainsString('__PHP_Incomplete_Class', json_encode($roundTrip));
    }
}
