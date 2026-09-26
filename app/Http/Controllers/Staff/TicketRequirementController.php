<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Concerns\ScopedToLawyer;
use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketDocument;
use App\Support\TicketDocumentRequirements;
use App\Support\TicketWritePolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * **قائمة مستندات القسم لتذكرة — للطاقم** (قرار المالك 2026-09-26): ما استُوفي، وبأيّ مرفق، ومن حكم
 * به، وما لم يُتحقّق منه — مع التأكيد والإلغاء اليدويّين حين يتعذّر الفحص الآليّ أو يخطئ.
 *
 * متحكّمٌ واحد لثلاث لوحات (الموظّف والمحامي والإدارة) — كلٌّ من مساره وبحارسه: الموظّف بصلاحيّة
 * «إدارة التذاكر» على المسار، والمحامي بإسناد التذكرة إليه (`guardAssigned`)، والإدارة بدورها.
 * المنطق كلّه في `TicketDocumentRequirements`؛ هنا التحقّق والنقل.
 */
class TicketRequirementController extends Controller
{
    use ScopedToLawyer;

    public function show(Request $request, Ticket $ticket): JsonResponse
    {
        $this->authorizeTicket($request, $ticket);

        return response()->json(TicketDocumentRequirements::toData($ticket));
    }

    /**
     * `document_id` ⇒ هذا المرفق يستوفي البند؛ `null` ⇒ إلغاء استيفاء البند (أيّاً كان من حكم به).
     */
    public function update(Request $request, Ticket $ticket): JsonResponse
    {
        $this->authorizeTicket($request, $ticket);
        TicketWritePolicy::assertWritable($ticket);

        $data = $request->validate([
            'requirement' => ['required', 'string', 'max:160'],
            'document_id' => ['nullable', 'integer'],
        ]);

        if ($data['document_id'] ?? null) {
            $document = TicketDocument::query()->whereKey($data['document_id'])->where('ticket_id', $ticket->id)->firstOrFail();
            TicketDocumentRequirements::markByStaff($ticket, $data['requirement'], $document, $request->user());
        } else {
            TicketDocumentRequirements::unmark($ticket, $data['requirement'], $request->user());
        }

        return response()->json(TicketDocumentRequirements::toData($ticket));
    }

    /** المحامي على ما أُسند إليه وحده؛ الموظّف والإدارة يحرسهما المسار. */
    private function authorizeTicket(Request $request, Ticket $ticket): void
    {
        if ($request->user()->isLawyer()) {
            $this->guardAssigned($ticket);
        }
    }
}
