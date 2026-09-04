<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **عقدُ بطاقة الاستشارة: ما تقرؤه الواجهة يجب أن يُرسله الخادم.**
 *
 * ثلاثةُ حقولٍ كانت الواجهة تقرؤها ولا يُرسلها `toCard` قطّ — فلا خطأ ولا أثر،
 * بل صمتٌ يشبه العمل: عدّاد «جلسات اليوم» صفرٌ أبداً لأنه يخرج عند `!c.day`،
 * وحقلُ تدوين الجلسة يُفتح فارغاً والتدوين محفوظ، وزرّ «تحويل إلى قضية» معطّلٌ
 * دائماً لأن حارسه `!consult.ticket_id`.
 *
 * وهذه الاختبارات تُثبّت الحقول **بأسمائها التي تقرؤها الواجهة**: من غيّر اسماً
 * في `toCard` يسقط هنا بدل أن يُعطّل زرّاً صامتاً في الإنتاج.
 */
class ConsultCardContractTest extends TestCase
{
    use RefreshDatabase;

    private function consultWithTicket(): Consult
    {
        $client = User::factory()->create(['role' => Role::Client]);

        $ticket = Ticket::create([
            'user_id' => $client->id, 'number' => 'SB-CARD-'.uniqid(), 'type' => 'نزاع تجاري',
            'department' => 'القضايا التجارية', 'subject' => 'مطالبة',
            'status' => 'قيد المعالجة', 'tone' => 'b-amber',
        ]);

        return Consult::create([
            'user_id' => $client->id, 'ticket_id' => $ticket->id,
            'ref' => 'CS-CARD-'.uniqid(), 'subject' => 'مطالبة', 'type' => 'استشارة',
            'lawyer' => 'أ. سارة القحطاني', 'channel' => 'مرئية', 'status' => 'مجدولة', 'session' => 'بانتظار الجلسة',
            'session_notes' => 'العميل يملك عقداً موقّعاً بتاريخ ١٤٤٦/٠٣/١٢.',
            'starts_at' => now()->addDay()->setTime(11, 0),
        ]);
    }

    /** رقمُ التذكرة يصل، و**رقماً لا معرّفاً**: مسار التحويل يربط `Ticket` بـ`number`. */
    public function test_the_card_carries_the_parent_ticket_number_not_its_id(): void
    {
        $consult = $this->consultWithTicket();
        $card = $consult->load('ticket')->toCard();

        $this->assertArrayHasKey('ticketNo', $card, 'بغيابه يبقى زرّ «تحويل إلى قضية» معطّلاً دائماً');
        $this->assertSame($consult->ticket->number, $card['ticketNo']);
        $this->assertSame('number', (new Ticket)->getRouteKeyName(), 'المسار يربط بالرقم — فلا يُرسَل المعرّف');
    }

    /** واستشارةٌ بلا تذكرة أمّ تُعطي `null` صريحاً — لا انهياراً. */
    public function test_a_consult_without_a_parent_ticket_yields_null(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $consult = Consult::create([
            'user_id' => $client->id, 'ref' => 'CS-NOTKT-'.uniqid(), 'subject' => 'استفسار',
            'type' => 'استشارة', 'lawyer' => 'أ. سارة القحطاني', 'channel' => 'هاتفية', 'status' => 'مجدولة', 'session' => 'بانتظار الجلسة',
        ]);

        $this->assertNull($consult->toCard()['ticketNo']);
    }

    /** وتدوينُ الجلسة يصل بطاقة المكتب — وإلّا فُتح الدرج فارغاً فوق تدوينٍ محفوظ. */
    public function test_the_card_carries_the_saved_session_notes(): void
    {
        $card = $this->consultWithTicket()->toCard();

        $this->assertArrayHasKey('sessionNotes', $card);
        $this->assertStringContainsString('عقداً موقّعاً', (string) $card['sessionNotes']);
    }

    /** و`startsAt` — مصدرُ عدّاد «جلسات اليوم» بعد أن كان يقرأ `day` غير الموجودة. */
    public function test_the_card_carries_a_machine_readable_start_for_the_today_counter(): void
    {
        $card = $this->consultWithTicket()->toCard();

        $this->assertNotNull($card['startsAt'], 'بلا لحظةٍ آليّة يعود العدّاد صفراً أبداً');
        $this->assertArrayNotHasKey('day', $card, 'لا يُبعث `day` — والواجهة لم تعد تقرؤها');
    }

    /** **وبطاقةُ العميل لا تنكشف:** رقمُ التذكرة والتدوين الداخليّ للمكتب وحده. */
    public function test_the_client_card_exposes_neither_internal_notes_nor_the_ticket_number(): void
    {
        $card = $this->consultWithTicket()->toClientCard();

        $this->assertArrayNotHasKey('sessionNotes', $card);
        $this->assertArrayNotHasKey('ticketNo', $card);
    }
}
