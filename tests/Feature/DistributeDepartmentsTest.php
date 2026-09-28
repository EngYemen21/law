<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Execution;
use App\Models\User;
use Database\Seeders\LegalCatalogueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **فلتر الأقسام في «توزيع وإسناد الأعمال» أقسامٌ لا محاكم**: قسم ملفّ التنفيذ قسم «التنفيذ» في الكتالوج؛
 * وكان اسمَ دائرة التنفيذ، فامتلأ الفلتر بالمحاكم بين الأقسام القانونيّة.
 */
class DistributeDepartmentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_executions_are_filed_under_the_enforcement_department_not_their_court(): void
    {
        $this->seed(LegalCatalogueSeeder::class);
        Execution::create([
            'user_id' => User::factory()->create(['role' => Role::Client])->id, 'number' => 'EXE-DD-'.uniqid(),
            'sanad' => 'شيك', 'subject' => 'تحصيل', 'amount' => 1000, 'status' => 'قيد الدراسة', 'stage' => 2, 'tone' => 'b-blue',
            'court' => 'محكمة التنفيذ بجدة - الدائرة 5',
        ]);

        $this->actingAs(User::factory()->create(['role' => Role::Admin]))->get(route('admin.distribute'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('executions.0.dept', 'التنفيذ')
                ->where('executions.0.courtName', 'محكمة التنفيذ بجدة - الدائرة 5')
                ->where('departments', fn ($d) => ! collect($d)->contains(fn ($x) => str_contains(json_encode($x, JSON_UNESCAPED_UNICODE), 'الدائرة'))));
    }
}
