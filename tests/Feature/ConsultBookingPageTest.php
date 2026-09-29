<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * تدقيق صفحة «حجز استشارة» (قرارات المالك 2026-09-29).
 *
 * كانت الوقائع تُدمج في الموضوع وتُقصّ عند 120 حرفاً فلا يصل المسعّرَ والمحاميَ منها إلّا مطلعها؛
 * ونصوص الصفحة تَعِد العميل باختيار موعده والمكتبُ هو من يحدّده (قرار 2026-09-14)؛ ورابطا
 * تفرّغٍ للعميل بلا مستدعٍ في الواجهة.
 */
class ConsultBookingPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_facts_are_kept_whole_beside_a_short_subject(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $facts = str_repeat('وقائع النزاع والسؤال القانوني. ', 50); // ~1500 حرف

        $this->actingAs($client)->post(route('book.store'), [
            'type' => 'video', 'specialty' => 'القضايا العمالية',
            'subject' => 'فسخ عقد عمل قبل انتهاء مدته', 'details' => $facts,
        ])->assertRedirect(route('myconsults'))->assertSessionHasNoErrors();

        $consult = Consult::sole();
        $this->assertSame('فسخ عقد عمل قبل انتهاء مدته', $consult->subject, 'الموضوع عنوانٌ لا تُدمج فيه الوقائع');
        $this->assertSame(trim($facts), $consult->details, 'الوقائع كاملة بلا قصّ');

        // يقرؤها الطاقم (المسعّر والمحامي) وصاحبها
        $this->assertSame($consult->details, $consult->toCard()['details']);
        $this->assertSame($consult->details, $consult->toClientCard()['details']);
    }

    public function test_a_request_needs_a_subject_and_bounded_facts(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);

        $this->actingAs($client)->post(route('book.store'), ['type' => 'phone', 'specialty' => 'القضايا التجارية'])
            ->assertSessionHasErrors('subject');
        $this->actingAs($client)->post(route('book.store'), [
            'type' => 'phone', 'subject' => 'عقد', 'details' => str_repeat('س', 2001),
        ])->assertSessionHasErrors('details');

        $this->assertSame(0, Consult::count());
    }

    public function test_the_client_never_reads_availability(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);

        $this->actingAs($client)->getJson('/book/availability')->assertNotFound();
    }

    public function test_the_pages_promise_an_office_set_appointment_and_one_success_message(): void
    {
        $book = (string) file_get_contents(resource_path('js/pages/book.tsx'));
        $mine = (string) file_get_contents(resource_path('js/pages/myconsults.tsx'));

        foreach (['اختيار الفترة', 'واختيار موعد الجلسة', 'قبل السداد وحجز الموعد'] as $promise) {
            $this->assertStringNotContainsString($promise, $book, "«{$promise}» — العميل لا يختار موعده");
        }
        $this->assertStringNotContainsString('واختيار المواعيد', $mine);

        $this->assertStringNotContainsString('.slice(0, 120)', $book, 'لا قصّ للوقائع');
        $this->assertStringNotContainsString('onSuccess:', $book, 'رسالة النجاح من الخادم وحده');
        $this->assertStringNotContainsString('router.visit(', $book, 'الروابط بـ<Link>');
    }

    public function test_the_phone_layout_puts_the_form_first(): void
    {
        $book = (string) file_get_contents(resource_path('js/pages/book.tsx'));
        $css = (string) file_get_contents(resource_path('css/babylon.css'));

        // بطاقات الضمانات الثلاث أسفل النموذج محذوفة (قرار المالك 2026-09-29)
        $this->assertStringNotContainsString('ميثاق الجودة', $book);
        $this->assertStringNotContainsString('المادة 23', $book);

        // الهاتف: المراحل شريطٌ مضغوط بعد النموذج، والقنوات صفٌّ واحد صغير
        foreach (['book-page', 'book-steps', 'book-step-desc', 'book-channels', 'book-channel-desc'] as $cls) {
            $this->assertStringContainsString($cls, $book, "الصنف {$cls} في الصفحة");
        }
        $this->assertMatchesRegularExpression('/\\.book-steps\\{order:1;display:flex!important;overflow-x:auto/u', $css);
        $this->assertStringContainsString('.book-channels{display:flex!important', $css);
        $this->assertStringContainsString('.book-channel-chip,.book-channel-desc{display:none}', $css);
    }
}
