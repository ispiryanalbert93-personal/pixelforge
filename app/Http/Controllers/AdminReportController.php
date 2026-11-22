<?php

namespace App\Http\Controllers;

use App\Http\Requests\AdminReportFilterRequest;
use App\Http\Resources\UsageReportResource;
use App\Services\Reporting\AdminReportService;
use Inertia\Inertia;
use Inertia\Response;

class AdminReportController extends Controller
{
    public function index(AdminReportFilterRequest $request, AdminReportService $reportService): Response
    {
        $validated = $request->validated();

        $report = $reportService->build(
            startDate: $validated['start_date'] ?? null,
            endDate: $validated['end_date'] ?? null,
        );

        return Inertia::render('Admin/Reports', [
            'report' => new UsageReportResource($report),
        ]);
    }
}
