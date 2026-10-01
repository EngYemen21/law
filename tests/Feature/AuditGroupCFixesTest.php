<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\User;
use App\Support\DayRange;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * **المجموعة (ج) من تدقيق قاعدة البيانات** (قرار المالك 2026-10-01): قيود الحذف، والأعمدة الميّتة،
 * والروابط والفهارس الناقصة، وتصفية السجلّين بمدىً يقبل الفهرس.
 */
class AuditGroupCFixesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_client_with_an_invoice_cannot_be_deleted(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        Invoice::create(['user_id' => $client->id, 'number' => 'INV-C-1', 'description' => 'أتعاب', 'amount' => 100, 'status' => 'غير مدفوعة', 'tone' => 'b-amber', 'due_label' => '—']);

        try {
            $client->delete();
            $this->fail('حُذف عميلٌ له فاتورة');
        } catch (QueryException) {
            // القيد RESTRICT صدّ الحذف
        }

        $this->assertDatabaseHas('users', ['id' => $client->id]);
        $this->assertDatabaseHas('invoices', ['number' => 'INV-C-1']);
    }

    public function test_a_user_without_records_can_still_be_deleted(): void
    {
        $user = User::factory()->create(['role' => Role::Client]);
        $user->delete();

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_the_dead_columns_are_gone(): void
    {
        foreach (['meetings' => ['is_up', 'before_items', 'during_items', 'after_items'], 'ai_runs' => ['model_version'], 'executions' => ['payment_reminder_sent_at']] as $table => $columns) {
            foreach ($columns as $column) {
                $this->assertFalse(Schema::hasColumn($table, $column), "{$table}.{$column}");
            }
        }
    }

    public function test_the_preventive_indexes_exist(): void
    {
        foreach ([
            'user_notifications' => ['user_id', 'is_read'], 'audit_logs' => ['created_at'], 'journey_transitions' => ['created_at'],
            'meetings' => ['status', 'starts_at'], 'case_hearings' => ['status', 'starts_at'], 'consults' => ['starts_at'],
            'invoices' => ['paid', 'due_at'], 'executions' => ['pay_due_at'], 'legal_documents' => ['case_id'],
        ] as $table => $columns) {
            $this->assertTrue(Schema::hasIndex($table, $columns), $table.'('.implode(',', $columns).')');
        }
    }

    /** المدى يطابق `whereDate` حدّاً بحدّ: آخر لحظةٍ في اليوم داخلَه، وأوّل لحظةٍ في الغد خارجَه. */
    public function test_the_day_range_matches_where_date_at_the_edges(): void
    {
        $last = AuditLog::create(['action' => 'آخر اليوم', 'description' => '—']);
        $last->forceFill(['created_at' => '2026-09-30 23:59:59'])->save();
        $next = AuditLog::create(['action' => 'أوّل الغد', 'description' => '—']);
        $next->forceFill(['created_at' => '2026-10-01 00:00:00'])->save();

        $range = fn (?string $from, ?string $to) => DayRange::apply(AuditLog::query(), 'created_at', $from, $to)->pluck('action')->all();
        $legacy = function (?string $from, ?string $to) {
            $q = AuditLog::query();
            $from && $q->whereDate('created_at', '>=', $from);
            $to && $q->whereDate('created_at', '<=', $to);

            return $q->pluck('action')->all();
        };

        foreach ([['2026-09-30', '2026-09-30'], ['2026-10-01', null], [null, '2026-09-30'], ['2026-09-30', '2026-10-01']] as [$from, $to]) {
            $this->assertSame($legacy($from, $to), $range($from, $to), "{$from}..{$to}");
        }

        $this->assertSame(['أوّل الغد'], DayRange::on(AuditLog::query(), 'created_at', CarbonImmutable::parse('2026-10-01'))->pluck('action')->all());
    }

    public function test_the_audit_log_screen_filters_by_day(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        AuditLog::create(['action' => 'قيد قديم', 'description' => '—'])->forceFill(['created_at' => '2026-09-01 10:00:00'])->save();
        AuditLog::create(['action' => 'قيد اليوم', 'description' => '—'])->forceFill(['created_at' => '2026-09-30 10:00:00'])->save();

        $this->actingAs($admin)->get(route('admin.audit-logs', ['from_date' => '2026-09-30', 'to_date' => '2026-09-30']))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('logs.data', fn ($rows) => collect($rows)->pluck('action')->contains('قيد اليوم')
                && ! collect($rows)->pluck('action')->contains('قيد قديم')));
    }
}
