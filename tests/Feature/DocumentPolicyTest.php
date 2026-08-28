<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * 🔴 كانت DocumentPolicy::view تعيد true لأي محامٍ/موظف بلا أي تضييق — وDocument
 * لا يحمل أصلاً رابط إسناد لمحامٍ. الثغرة غير قابلة للوصول اليوم فقط لأن المسار داخل
 * مجموعة role:client، فهي لغم ينفجر لحظة إضافة مسار للطاقم.
 */
class DocumentPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function clientDocument(): array
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $doc = Document::create([
            'user_id' => $client->id,
            'name' => 'صك ملكية.pdf',
            'meta' => 'PDF · 1 ميجابايت',
            'direction' => 'وارد',
            'path' => 'client-docs/1/x.pdf',
            'mime' => 'application/pdf',
            'size' => 1024,
        ]);

        return [$client, $doc];
    }

    public function test_owner_may_view_their_document(): void
    {
        [$client, $doc] = $this->clientDocument();

        $this->assertTrue(Gate::forUser($client)->allows('view', $doc));
    }

    public function test_another_client_may_not_view_it(): void
    {
        [, $doc] = $this->clientDocument();
        $intruder = User::factory()->create(['role' => Role::Client]);

        $this->assertFalse(Gate::forUser($intruder)->allows('view', $doc));
    }

    /** المحامي لا صلة له بمستند العميل — لا عمود إسناد على Document أصلاً. */
    public function test_lawyer_may_not_view_a_client_document(): void
    {
        [, $doc] = $this->clientDocument();
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);

        $this->assertFalse(Gate::forUser($lawyer)->allows('view', $doc));
    }

    /** الموظف كذلك — لا يُمنح وصولاً شاملاً لملفات العملاء بلا مسار مقصود يحرسه. */
    public function test_employee_may_not_view_a_client_document(): void
    {
        [, $doc] = $this->clientDocument();
        $employee = User::factory()->create(['role' => Role::Employee]);

        $this->assertFalse(Gate::forUser($employee)->allows('view', $doc));
    }

    /** الإدارة تتجاوز عبر Gate::before — إشراف مقصود وموثّق. */
    public function test_admin_may_view_any_document(): void
    {
        [, $doc] = $this->clientDocument();
        $admin = User::factory()->create(['role' => Role::Admin]);

        $this->assertTrue(Gate::forUser($admin)->allows('view', $doc));
    }
}
