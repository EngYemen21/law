<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * **T6 — العميل يقرأ سبب الرفض من الخادم لا «تعذّر…» عامّة** (ثبت في المتصفّح 2026-09-30).
 *
 * «فتح تذكرة» كان يعرض «تعذّر إرسال التذكرة…» وإن جاء سببٌ محدّد (مرفقٌ مرفوض بلا حقلٍ ظاهر له)، وصفحة الحجز
 * تستخرج السبب بسلاسل يدويّة تقرأ حقلاً واحداً. المصدر الواحد: `serverMessage`/`firstError` (`lib/server-message.ts`).
 */
class ClientErrorMessagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_forms_surface_the_server_reason_through_the_shared_helpers(): void
    {
        $newTicket = (string) file_get_contents(resource_path('js/pages/newticket.tsx'));
        $this->assertStringContainsString("firstError(errors as Record<string, string>, 'تعذّر إرسال التذكرة", $newTicket);
        $this->assertStringNotContainsString("toast('تعذّر إرسال التذكرة، تحقّق من البيانات والمرفقات')", $newTicket);

        $book = (string) file_get_contents(resource_path('js/pages/book.tsx'));
        $this->assertStringContainsString("serverMessage(e, 'تعذّر إرسال الطلب')", $book);
        $this->assertStringContainsString("firstError(e, 'تعذّر إرسال الطلب')", $book);
        $this->assertStringNotContainsString('errors?.type?.[0]', $book);
    }

    /** لا نسخ يدويّة لـ`firstError` في صفحات العميل — كانت سبعٌ تكتب `Object.values(err)[0]` بأشكالٍ مختلفة. */
    public function test_client_pages_use_the_shared_first_error_helper(): void
    {
        $offenders = [];
        foreach (['documents', 'execflow', 'invoices', 'meetings', 'myconsults', 'profile', 'book', 'newticket'] as $page) {
            if (preg_match('/Object\.values\((e|err|errs|errors)\)\[0\]/', (string) file_get_contents(resource_path("js/pages/{$page}.tsx")))) {
                $offenders[] = $page;
            }
        }

        $this->assertSame([], $offenders);
    }

    /**
     * ولا في أيّ ملفٍّ من الواجهة — كانت ٥٢ نسخةً أخرى في ٢٩ ملفّاً من صفحات الطاقم والإدارة ومكوّناتها
     * (`?? fb` · `|| fb` · `String(…)` · `e.message || …`)، والمصدر الواحد `firstError` (`lib/server-message.ts`).
     */
    public function test_no_hand_rolled_first_error_anywhere_in_the_frontend(): void
    {
        $offenders = [];
        foreach ((new Finder)->files()->in(resource_path('js'))->name(['*.ts', '*.tsx'])->notName('server-message.ts') as $file) {
            if (preg_match('/Object\.values\((e|err|errs|errors)\)\[0\]/', $file->getContents())) {
                $offenders[] = $file->getRelativePathname();
            }
        }

        $this->assertSame([], $offenders);
    }

    /** سبب رفض المرفق باسمه العربيّ — كان «يجب أن يكون files.0 ملفّاً من نوع…». */
    public function test_a_rejected_attachment_is_named_in_arabic(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);

        $message = (string) $this->actingAs($client)->postJson('/tickets', [
            'type' => 'استشارة', 'subject' => 'اختبار', 'details' => 'نصّ',
            'files' => [UploadedFile::fake()->create('bad.exe', 10, 'application/x-msdownload')],
        ])->assertStatus(422)->json('errors')['files.0'][0];

        $this->assertStringContainsString('المرفق', $message);
        $this->assertStringNotContainsString('files.0', $message);
    }
}
