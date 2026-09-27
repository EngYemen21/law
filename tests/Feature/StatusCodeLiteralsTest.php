<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\TicketStatus;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * **رمز الحالة في الواجهة اسمُ حالةٍ حقيقيّ في الـEnum.**
 *
 * `Ticket::toCard`/`toEmployeeCard` ترسل `statusCode = TicketStatus::…->name` (`AwaitingDocs`)، وكانت
 * صفحتا تذاكر الموظّف والمحامي تقارنانه بـ`'awaiting_docs'` — مقارنةٌ لا تصدق أبداً، والعدّاد يعمل
 * بالمصادفة عبر نصّ الحالة العربيّ المجاور. يفحص هذا كلّ `statusCode ===/!== '…'` في الواجهة.
 */
class StatusCodeLiteralsTest extends TestCase
{
    public function test_every_status_code_literal_names_a_real_ticket_status(): void
    {
        $names = array_map(fn (TicketStatus $s) => $s->name, TicketStatus::cases());
        $found = 0;
        $bad = [];

        $files = Finder::create()->files()->in(resource_path('js'))
            ->exclude(['actions', 'routes'])->name(['*.ts', '*.tsx']);

        foreach ($files as $file) {
            preg_match_all("/statusCode\\s*[!=]==\\s*'([^']+)'/", $file->getContents(), $m);

            foreach ($m[1] as $literal) {
                $found++;

                if (! in_array($literal, $names, true)) {
                    $bad[] = $file->getRelativePathname().": '{$literal}'";
                }
            }
        }

        $this->assertGreaterThan(0, $found, 'تغيّر شكل المقارنة — حدِّث نمط الفحص لا تُسقطه');
        $this->assertSame([], $bad, 'رموز حالة لا وجود لها في TicketStatus: '.implode(', ', $bad));
    }
}
