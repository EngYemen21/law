<?php

namespace Tests\Feature;

use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * **نوعٌ واحد لكلّ بانٍ في الخادم** (`resources/js/types/`) — كانت بطاقة التذكرة للطاقم مُعرَّفةً تسع
 * مرّات، ومستند القضيّة أربعاً بحقولٍ متباعدة. الصفحة تمدّ النوع المشترك (`extends`) ولا تنسخ حقوله.
 */
class SharedFrontendTypesTest extends TestCase
{
    public function test_no_page_redeclares_a_shared_payload_type(): void
    {
        $offenders = [];
        foreach (Finder::create()->files()->in(resource_path('js/pages'))->name('*.tsx') as $file) {
            // `interface EmpTicket {` بلا extends · `interface TicketCard {` · `interface CaseDoc {` · `interface TicketDoc {`
            if (preg_match('/\binterface (EmpTicket|EmpTransferTicket|TicketCard|TicketActions|CaseDoc|TicketDoc) \{/', $file->getContents(), $m)) {
                $offenders[] = $file->getRelativePathname().": {$m[1]}";
            }
        }

        $this->assertSame([], $offenders, 'نوعٌ منسوخ من حمولة الخادم — استورده من @/types');
        foreach (['ticket.ts', 'case-document.ts'] as $f) {
            $this->assertStringContainsString("from './".basename($f, '.ts')."'", (string) file_get_contents(resource_path('js/types/index.ts')));
        }
    }
}
