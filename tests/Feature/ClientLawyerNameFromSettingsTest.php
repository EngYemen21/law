<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\Setting;
use App\Models\User;
use App\Support\ChatSenderLabel;
use App\Support\LawyerName;
use App\Support\RoomDetails;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **اسم المحامي أمام العميل = ما كتبته الإدارة في الإعدادات — في كلّ خانة** (قرار المالك 2026-09-27).
 *
 * كان حقل «المحامي» في «مسمّيات المتحدّثين» يُطبَّق فوق رسائل المحادثة وحدها، ويرى العميل في
 * «جلستك جاهزة … مع امواج» وتفاصيل الغرفة والمحضر اسمَ المحامي — كاملاً إن كان كلمةً واحدة.
 */
class ClientLawyerNameFromSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function consultWith(User $lawyer): Consult
    {
        $client = User::factory()->create(['role' => Role::Client]);

        return Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-2026-'.uniqid(), 'subject' => 'مكافأة نهاية الخدمة',
            'channel' => 'مرئية', 'lawyer' => $lawyer->name, 'assigned_lawyer_id' => $lawyer->id,
            'day' => 'الأحد', 'time' => '10ص', 'when_label' => 'الأحد', 'session' => 'بانتظار', 'status' => 'جديدة',
        ]);
    }

    public function test_the_admin_label_replaces_the_lawyer_name_on_every_client_surface(): void
    {
        Setting::put(ChatSenderLabel::LAWYER, 'المستشار القانوني المختصّ');
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'name' => 'امواج']);
        $consult = $this->consultWith($lawyer);
        $client = $consult->user;

        $this->assertSame('المستشار القانوني المختصّ', $consult->lawyerForClient());
        $this->assertSame('المستشار القانوني المختصّ', $consult->toClientCard()['lawyer']);

        $rows = collect(RoomDetails::for($consult, $client)['rows'])->pluck('value')->implode(' | ');
        $this->assertStringNotContainsString('امواج', $rows, 'تفاصيل الجلسة تكشف الاسم');

        $this->assertSame('المستشار القانوني المختصّ', LawyerName::assignedTo($lawyer));
    }

    public function test_an_empty_label_keeps_the_short_name(): void
    {
        Setting::put(ChatSenderLabel::LAWYER, '');
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'name' => 'محمد البقمي']);

        $this->assertSame('محمد. ب', $this->consultWith($lawyer)->lawyerForClient());
    }

    public function test_staff_still_see_the_real_name(): void
    {
        Setting::put(ChatSenderLabel::LAWYER, 'المستشار القانوني المختصّ');
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'name' => 'امواج']);
        $consult = $this->consultWith($lawyer);
        $admin = User::factory()->create(['role' => Role::Admin]);

        $this->assertSame('امواج', $consult->toCard()['lawyer']);
        $rows = collect(RoomDetails::for($consult, $admin)['rows'])->pluck('value')->implode(' | ');
        $this->assertStringContainsString('امواج', $rows);
    }
}
