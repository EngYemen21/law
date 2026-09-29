<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\User;
use App\Support\Finance\ClientStatement;
use App\Support\Finance\ClientStatementDocument;
use App\Support\PdfRenderer;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * **كشف حساب العميل** (المرحلة ج) — صفحةٌ واحدة وPDF واحد للعميل (حسابه وحده) وللإدارة (أيّ عميل).
 * الفترة `from`/`to` وتقصيرها السنة الماليّة الجارية (قرار المالك: السنة الميلاديّة من يناير) حتى اليوم.
 */
class ClientStatementController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->page($request, $request->user(), '/statement/pdf', null);
    }

    public function pdf(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        return $this->document($request, $request->user());
    }

    public function forClient(Request $request, User $client): Response
    {
        abort_unless($client->role === Role::Client, 404);

        return $this->page($request, $client, "/admin/clients/{$client->id}/statement/pdf", "/admin/clients/{$client->id}");
    }

    public function forClientPdf(Request $request, User $client): \Symfony\Component\HttpFoundation\Response
    {
        abort_unless($client->role === Role::Client, 404);

        return $this->document($request, $client);
    }

    private function page(Request $request, User $client, string $pdfUrl, ?string $backUrl): Response
    {
        [$from, $to] = $this->period($request);

        return Inertia::render('statement', [
            'client' => ['id' => $client->id, 'name' => $client->name],
            'statement' => ClientStatement::build($client, $from, $to),
            'pdfUrl' => $pdfUrl,
            'backUrl' => $backUrl,
        ]);
    }

    private function document(Request $request, User $client): \Symfony\Component\HttpFoundation\Response
    {
        [$from, $to] = $this->period($request);
        $statement = ClientStatement::build($client, $from, $to);

        return PdfRenderer::render(ClientStatementDocument::html($client, $statement), "statement-{$statement['from']}-{$statement['to']}.pdf");
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function period(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ], [
            'to.after_or_equal' => 'نهاية الفترة لا تسبق بدايتها.',
        ]);

        $today = CarbonImmutable::today();

        return [
            isset($data['from']) ? CarbonImmutable::parse($data['from']) : $today->startOfYear(),
            isset($data['to']) ? CarbonImmutable::parse($data['to']) : $today,
        ];
    }
}
