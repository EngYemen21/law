<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\TicketStatus;
use App\Models\Execution;
use App\Models\Ticket;
use App\Models\User;
use App\Support\TicketJourney;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * **حارس نقل التذاكر المحوَّلة لتنفيذٍ إلى حالتها الصحيحة** (مهاجرة 2026_09_24_230000).
 *
 * أُضيفت `TicketStatus::ConvertedToExecution` لأنّ مسار التنفيذ كان يكتب حالةَ القضية، فيقرأ
 * عميل التنفيذ «تم تحويل الطلب إلى قضية رسمية» وهو في ملفّ تنفيذ. والحالة الجديدة تُصلح ما
 * يأتي لا ما مضى — فالمهاجرة تنقل الصفوف القائمة، وهذا الحارس يثبّت معيارها:
 *
 * - **تُنقل** التذكرة بحالة القضية **ولها ملفّ تنفيذ**.
 * - **لا تُنقل** تذكرةٌ حُوِّلت لقضيةٍ حقيقيّة بلا تنفيذ — وهو ما يمنع النقلَ الجائر.
 * - واللون يُشتقّ من المصدر الواحد فلا ينشأ صفٌّ لونُه يخالف حالته.
 */
class ConvertedExecutionTicketsMigratedTest extends TestCase
{
    use RefreshDatabase;

    private function ticket(string $number, string $status): Ticket
    {
        return Ticket::create([
            'user_id' => User::factory()->create()->id,
            'number' => $number,
            'type' => 'نزاع تجاري',
            'department' => 'القسم التجاري',
            'status' => $status,
            'tone' => 'b-amber',
        ]);
    }

    private function execFor(Ticket $ticket): void
    {
        Execution::create([
            'user_id' => $ticket->user_id,
            'ticket_id' => $ticket->id,
            'number' => 'EX-'.$ticket->number,
            'subject' => 'تنفيذ حكم',
            'status' => 'طلب جديد',
            'tone' => 'b-blue',
        ]);
    }

    /** المهاجرة قُبيل هذا الاختبار نُفِّذت بـRefreshDatabase؛ فتُستدعى يدويّاً على صفوفٍ نُصِبت بعدها. */
    private function runMigration(string $direction = 'up'): void
    {
        $migration = require database_path('migrations/2026_09_24_230000_move_converted_tickets_to_execution_status.php');
        $migration->{$direction}();
    }

    public function test_a_ticket_with_an_execution_file_moves_to_the_execution_status(): void
    {
        $ticket = $this->ticket('SB-2026-9001', TicketStatus::ConvertedToCase->value);
        $this->execFor($ticket);

        $this->runMigration();

        $fresh = $ticket->fresh();
        $this->assertSame(TicketStatus::ConvertedToExecution->value, $fresh->status, 'لم تُنقل تذكرةٌ لها ملفّ تنفيذ.');
        $this->assertSame(
            TicketJourney::toneFor(TicketStatus::ConvertedToExecution->value),
            $fresh->tone,
            'اللون لم يُشتقّ من المصدر الواحد بعد النقل.'
        );
    }

    /** **الحارس الأهمّ:** لا تُنقل قضيّةٌ حقيقيّة بلا تنفيذ. */
    public function test_a_real_case_conversion_without_an_execution_is_left_alone(): void
    {
        $ticket = $this->ticket('SB-2026-9002', TicketStatus::ConvertedToCase->value);

        $this->runMigration();

        $this->assertSame(
            TicketStatus::ConvertedToCase->value,
            $ticket->fresh()->status,
            'نُقلت تذكرةُ قضيّةٍ لا ملفَّ تنفيذ لها — نقلٌ جائر.'
        );
    }

    public function test_the_rollback_returns_the_ticket_to_the_case_status(): void
    {
        $ticket = $this->ticket('SB-2026-9003', TicketStatus::ConvertedToExecution->value);
        $this->execFor($ticket);

        $this->runMigration('down');

        $this->assertSame(TicketStatus::ConvertedToCase->value, $ticket->fresh()->status, 'التراجع لم يعكس النقل.');
    }

    /** لا صفَّ يبقى بعد النقل حالتُه حالةُ القضية وله ملفّ تنفيذ. */
    public function test_no_ticket_is_left_mislabelled_after_the_migration(): void
    {
        $withExec = $this->ticket('SB-2026-9004', TicketStatus::ConvertedToCase->value);
        $this->execFor($withExec);
        $this->ticket('SB-2026-9005', TicketStatus::ConvertedToCase->value);

        $this->runMigration();

        $left = DB::table('tickets')
            ->where('tickets.status', TicketStatus::ConvertedToCase->value)
            ->whereExists(fn ($q) => $q->select(DB::raw(1))
                ->from('executions')
                ->whereColumn('executions.ticket_id', 'tickets.id'))
            ->count();

        $this->assertSame(0, $left, 'بقي صفٌّ بحالة القضية وله ملفّ تنفيذ.');
    }
}
