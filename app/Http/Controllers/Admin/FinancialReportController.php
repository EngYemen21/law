<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Finance\FinanceBoard;
use App\Support\Finance\ProfitAndLoss;
use App\Support\Finance\ProfitAndLossDocument;
use App\Support\PdfRenderer;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * **التقارير الماليّة** (المرحلة د) — صفحةٌ مستقلّة (قرار المالك 2026-09-29) وتصديرها PDF وCSV.
 * الفترة بمرشّح المالية نفسه (`FinanceBoard::period`)، والأرقام كلّها من `ProfitAndLoss::report`.
 */
class FinancialReportController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('admin/financial-reports', [
            'periods' => array_map(fn ($k, $label) => ['k' => $k, 'label' => $label], array_keys(FinanceBoard::PERIODS), FinanceBoard::PERIODS),
            'report' => ProfitAndLoss::report($this->period($request)),
        ]);
    }

    public function pdf(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $report = ProfitAndLoss::report($this->period($request));

        return PdfRenderer::render(ProfitAndLossDocument::html($report), $this->filename($report, 'pdf'));
    }

    public function csv(Request $request): StreamedResponse
    {
        $report = ProfitAndLoss::report($this->period($request));

        return response()->streamDownload(function () use ($report) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fwrite($out, "\u{FEFF}"); // BOM: يفتح Excel النصّ العربيّ صحيحاً
            foreach (ProfitAndLossDocument::csv($report) as $line) {
                fputcsv($out, $line, escape: '');
            }
            fclose($out);
        }, $this->filename($report, 'csv'), ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{key:string, from:CarbonInterface, to:CarbonInterface, label:string, fromDate:string, toDate:string} */
    private function period(Request $request): array
    {
        return FinanceBoard::period($request->query('period'), $request->query('from'), $request->query('to'));
    }

    /** @param  array<string, mixed>  $report */
    private function filename(array $report, string $ext): string
    {
        return "financial-report-{$report['period']['from']}-{$report['period']['to']}.{$ext}";
    }
}
