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
            'publishedFrom' => $params['publishedFrom'],
            'publishedTo' => $params['publishedTo'],
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

    /**
     * Display all videos status list.
     *
     * @param Request $request
     * @return View
     */
    public function status(Request $request): View
    {
        $searchTerm = $request->query('search', '');
        $sourceName = $request->query('source', '');
        $sortBy = $request->query('sortBy', 'id');
        $sortOrder = $request->query('sortOrder', 'desc');
        $publishedFrom = $request->query('published_from', '');
        $publishedTo = $request->query('published_to', '');
        $hideMissingFiles = $request->query('hide_missing_files', false) === '1' || $request->query('hide_missing_files', false) === 'on';
        
        // 建立查詢
        $query = $this->videoRepository->getAllVideosQuery(
            $searchTerm,
            $sourceName,
            $sortBy,
            $sortOrder,
            $publishedFrom,
            $publishedTo,
            $hideMissingFiles
        );
        
        // 分頁
        $perPage = (int) $request->query('per_page', 50);
        $videos = $query->paginate($perPage)->withQueryString();
        
        return view('status', [
            'videos' => $videos,
            'searchTerm' => $searchTerm,
            'sourceName' => $sourceName,
            'sortBy' => $sortBy,
            'sortOrder' => $sortOrder,
            'publishedFrom' => $publishedFrom,
            'publishedTo' => $publishedTo,
            'hideMissingFiles' => $hideMissingFiles,
        ]);
    }

}
