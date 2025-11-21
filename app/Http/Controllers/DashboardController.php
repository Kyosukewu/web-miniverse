<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exports\VideoAnalysisExport;
use App\Repositories\VideoRepository;
use App\Services\DashboardService;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DashboardController extends Controller
{
    public function __construct(
        private VideoRepository $videoRepository,
        private DashboardService $dashboardService
    ) {
    }

    /**
     * Display the dashboard.
     *
     * @param Request $request
     * @return View
     */
    public function index(Request $request): View
    {
        $paginatedVideos = $this->dashboardService->getPaginatedVideos($request, 50);
        $params = $this->dashboardService->getQueryParameters($request);

        return view('dashboard', [
            'videos' => $paginatedVideos,
            'searchTerm' => $params['searchTerm'],
            'sortBy' => $params['sortBy'],
            'sortOrder' => $params['sortOrder'],
        ]);
    }


    /**
     * Export videos analysis data to Excel.
     *
     * @param Request $request
     * @return BinaryFileResponse
     */
    public function export(Request $request): BinaryFileResponse
    {
        // Get export parameters from service
        $params = $this->dashboardService->getExportParameters($request);

        // Generate timestamp for filename
        $timestamp = date('YmdHis');

        // Create export instance
        $export = new VideoAnalysisExport(
            $this->videoRepository,
            $timestamp,
            $params['searchTerm'],
            $params['sortBy'],
            $params['sortOrder'],
            $params['selectedIds']
        );

        // Generate filename
        $filename = "影片分析資料_{$timestamp}.xlsx";

        // Export to Excel
        return Excel::download($export, $filename);
    }

}
