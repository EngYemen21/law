<?php

namespace Tests\Feature;

use App\Domain\Journey\StateWriteGuard;
use App\Domain\Journey\Transition;
use App\Domain\Journey\TransitionDenied;
use App\Domain\Journey\Workflow;
use App\Enums\Role;
use App\Events\ConsultStatusBroadcast;
use App\Events\Journey\TransitionCompleted;
use App\Models\Consult;
use App\Models\JourneyTransition;
use App\Models\User;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

/**
 * **محرّك الرحلة — الكاتب الوحيد للحالة** (خطّة إعادة البناء 2026-09-14، الدفعة ١).
 *
 * يُقاس هنا الترتيب الذي كان مفقوداً: الحالة المصدر ← الفاعل ← الملفّ ← الكتابة ← السجلّ
 * ← الأحداث بعد الالتزام. وكلُّ رفضٍ لا يترك أثراً: لا حالة ولا سجلّ ولا حدث.
 */
class JourneyWorkflowEngineTest extends TestCase
{
    use RefreshDatabase;

    private function consult(string $status = 'بانتظار التسعير'): Consult
    {
        $client = User::factory()->create(['role' => Role::Client]);

        return Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-WF-'.uniqid(), 'subject' => 'نزاع تجاري',
            'type' => 'استشارة', 'channel' => 'مرئية', 'status' => $status,
            'session' => 'بانتظار الجلسة', 'priority' => 'متوسطة', 'lawyer' => 'المستشار القانوني',
        ]);
    }

    /** @return Transition<Consult> */
    private function transition(string $name = 'test.cancel', ?string $deny = null, ?string $guard = null, bool $explode = false): Transition
    {
        return new class($name, $deny, $guard, $explode) extends Transition
        {
            public function __construct(
                private string $label,
                private ?string $denyWhy,
                private ?string $guardWhy,
                private bool $explode,
            ) {}

            public function name(): string
            {
                return $this->label;
            }

            public function from(): array
            {
                return ['بانتظار التسعير', 'بانتظار السداد'];
            }

            public function to(Model $entity, array $payload): string
            {
                return 'ملغاة';
            }

            public function deny(Model $entity, ?User $actor): ?string
            {
                return $this->denyWhy;
            }

            public function guard(Model $entity, array $payload): ?string
            {
                return $this->guardWhy;
            }

            public function apply(Model $entity, ?User $actor, array $payload): void
            {
                $entity->setAttribute('priority', 'عالية');
                if ($this->explode) {
                    throw new RuntimeException('انهيار داخل الانتقال');
                }
            }

            public function record(array $payload): array
            {
                return ['note' => $payload['note'] ?? null];
            }
        };
    }

    private function assertDenied(int $status, callable $run): void
    {
        try {
            $run();
            $this->fail("كان يُنتظر رفضٌ بـ{$status}");
        } catch (TransitionDenied $e) {
            $this->assertSame($status, $e->getStatusCode());
        }
    }

    public function test_a_transition_writes_the_state_and_its_history_together(): void
    {
        $consult = $this->consult();
        $admin = User::factory()->create(['role' => Role::Admin]);

        $returned = Workflow::run($this->transition(), $consult, $admin, ['reason' => 'طلب العميل', 'note' => 'مرجع داخليّ']);

        $this->assertSame('ملغاة', $returned->status, 'النسخة في الذاكرة تتبع المخزَّن');
        $fresh = $consult->fresh();
        $this->assertSame('ملغاة', $fresh->status);
        $this->assertSame('عالية', $fresh->priority, 'كتابات apply تلتزم مع الحالة');

        $row = JourneyTransition::sole();
        $this->assertSame('Consult', $row->entity_type);
        $this->assertSame($consult->id, $row->entity_id);
        $this->assertSame($consult->ref, $row->entity_ref);
        $this->assertSame('test.cancel', $row->transition);
        $this->assertSame('بانتظار التسعير', $row->from_state);
        $this->assertSame('ملغاة', $row->to_state);
        $this->assertSame($admin->id, $row->actor_id);
        $this->assertSame('طلب العميل', $row->reason);
        $this->assertSame(['note' => 'مرجع داخليّ'], $row->payload, 'لا يُحفظ من الحمولة إلا ما يسمح به الانتقال');
    }

    public function test_a_wrong_source_state_is_refused_and_leaves_no_trace(): void
    {
        $consult = $this->consult('منتهية');

        $this->assertDenied(422, fn () => Workflow::run($this->transition(), $consult));

        $this->assertSame('منتهية', $consult->fresh()->status);
        $this->assertSame(0, JourneyTransition::count());
    }

    public function test_an_actor_refusal_is_403_and_a_file_refusal_is_422(): void
    {
        $consult = $this->consult();

        $this->assertDenied(403, fn () => Workflow::run($this->transition(deny: 'ليس من صلاحيّتك'), $consult));
        $this->assertDenied(422, fn () => Workflow::run($this->transition(guard: 'الملفّ غير مكتمل'), $consult));

        $this->assertSame('بانتظار التسعير', $consult->fresh()->status);
        $this->assertSame(0, JourneyTransition::count());
    }

    public function test_a_failure_inside_the_transition_rolls_back_state_and_history(): void
    {
        $consult = $this->consult();

        try {
            Workflow::run($this->transition(explode: true), $consult);
            $this->fail('كان يُنتظر الانهيار');
        } catch (RuntimeException) {
        }

        $fresh = $consult->fresh();
        $this->assertSame('بانتظار التسعير', $fresh->status);
        $this->assertSame('متوسطة', $fresh->priority);
        $this->assertSame(0, JourneyTransition::count());
        $this->assertFalse(Workflow::running(), 'العدّاد يعود صفراً بعد الانهيار');
    }

    /** الأحداث بعد الالتزام: انتقالٌ تراجعت معاملته الخارجيّة لا يُعلَن. */
    public function test_events_are_dispatched_only_after_commit(): void
    {
        Event::fake([TransitionCompleted::class]);
        $consult = $this->consult();

        try {
            DB::transaction(function () use ($consult) {
                Workflow::run($this->transition(), $consult);
                throw new RuntimeException('تراجع المعاملة الخارجيّة');
            });
        } catch (RuntimeException) {
        }

        Event::assertNotDispatched(TransitionCompleted::class);
        $this->assertSame('بانتظار التسعير', $consult->fresh()->status);

        Workflow::run($this->transition(), $consult->fresh());

        Event::assertDispatched(TransitionCompleted::class, fn (TransitionCompleted $e) => $e->transition === 'test.cancel'
            && $e->from === 'بانتظار التسعير' && $e->to === 'ملغاة');
    }

    public function test_allowed_lists_only_the_transitions_open_to_this_actor_now(): void
    {
        $consult = $this->consult();

        $names = Workflow::allowed($consult, null, [
            $this->transition('open'),
            $this->transition('denied', deny: 'لا'),
            $this->transition('blocked', guard: 'لا'),
        ]);

        $this->assertSame(['open'], $names);
        $this->assertSame([], Workflow::allowed($this->consult('منتهية'), null, [$this->transition('open')]));
    }

    /** الكاتب يُحدَّد من المكدّس: أوّل ملفٍّ من التطبيق بعد المكتبات والنماذج والمحرّك. */
    public function test_the_state_write_guard_names_the_application_file_that_wrote(): void
    {
        $base = 'C:\\srv\\law';

        $fromController = [
            ['file' => 'C:\\srv\\law\\vendor\\laravel\\framework\\src\\Model.php'],
            ['file' => 'C:\\srv\\law\\app\\Models\\Consult.php'],
            ['file' => 'C:\\srv\\law\\app\\Domain\\Journey\\GuardsJourneyState.php'],
            ['function' => 'closure'],
            ['file' => 'C:\\srv\\law\\app\\Http\\Controllers\\Staff\\ConsultController.php'],
            ['file' => 'C:\\srv\\law\\routes\\web.php'],
        ];
        $this->assertSame('app/Http/Controllers/Staff/ConsultController.php', StateWriteGuard::writer($fromController, $base));

        $fromTest = [
            ['file' => 'C:\\srv\\law\\vendor\\laravel\\framework\\src\\Model.php'],
            ['file' => 'C:\\srv\\law\\tests\\Feature\\SomeTest.php'],
            ['file' => 'C:\\srv\\law\\app\\Http\\Controllers\\Staff\\ConsultController.php'],
        ];
        $this->assertNull(StateWriteGuard::writer($fromTest, $base), 'الاختبارات والبذور تُهيّئ حالاتٍ ولا تنتقل بها');
    }

    /**
     * تعذُّر Reverb بعد الحفظ لا يُجهض الطلب: الحالة محفوظة، والانتقال يعود سليماً، ومستمعو
     * الأحداث العاديّة يُطلَقون. قبل هذا كان `event()` يرمي فيضيع ما يليه عند المنادي (مهمّة ختم
     * الجلسة من خطّاف Zoom مثلاً) ويعود 500 على تغييرٍ تمّ — والاختبارات لا تراه لأنّ البثّ فيها `null`.
     */
    public function test_an_unreachable_broadcaster_does_not_abort_a_committed_transition(): void
    {
        Broadcast::extend('down', fn () => new class implements Broadcaster
        {
            public function auth($request) {}

            public function validAuthenticationResponse($request, $result) {}

            public function broadcast(array $channels, $event, array $payload = []): void
            {
                throw new BroadcastException('Reverb غير متاح');
            }
        });
        config(['broadcasting.default' => 'down', 'broadcasting.connections.down' => ['driver' => 'down']]);
        Event::fake([TransitionCompleted::class]);

        $consult = $this->consult();
        $broadcasting = new class extends Transition
        {
            public function name(): string
            {
                return 'test.broadcast';
            }

            public function from(): array
            {
                return ['بانتظار التسعير'];
            }

            public function to(Model $entity, array $payload): string
            {
                return 'ملغاة';
            }

            public function events(Model $entity, string $from, ?User $actor, array $payload): array
            {
                return [new ConsultStatusBroadcast($entity)];
            }
        };

        Workflow::run($broadcasting, $consult);

        $this->assertSame('ملغاة', $consult->fresh()->status);
        Event::assertDispatched(TransitionCompleted::class);
    }
}
