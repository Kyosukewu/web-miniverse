<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\StorageService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GcsProxyController extends Controller
{
    public function __construct(
        private StorageService $storageService
    ) {
    }

    /**
     * Stream or download file from GCS through proxy.
     * This allows private GCS files to be accessed by authenticated users.
     *
     * @param Request $request
     * @param string $path
     * @return Response
     */
    public function stream(Request $request, string $path): Response
    {
        try {
            // Decode the path
            $filePath = urldecode($path);
            
            Log::info('[GcsProxyController] 請求 GCS 檔案', [
                'path' => $filePath,
                'download' => $request->has('download'),
            ]);

            // Get GCS disk
            $disk = Storage::disk('gcs');

            // Check if file exists
            if (!$disk->exists($filePath)) {
                Log::warning('[GcsProxyController] 檔案不存在', [
                    'path' => $filePath,
                ]);
                return response('File not found', 404);
            }

            // Get file content
            $content = $disk->get($filePath);
            if (false === $content) {
                Log::error('[GcsProxyController] 無法讀取檔案', [
                    'path' => $filePath,
                ]);
                return response('Unable to read file', 500);
            }

            // Get file metadata
            $mimeType = $disk->mimeType($filePath);
            $size = $disk->size($filePath);
            $fileName = basename($filePath);

            // Prepare headers
            $headers = [
                'Content-Type' => $mimeType ?: 'application/octet-stream',
                'Content-Length' => $size,
                'Accept-Ranges' => 'bytes',
                'Cache-Control' => 'public, max-age=3600',
            ];

            // If download parameter is present, force download
            if ($request->has('download')) {
                $headers['Content-Disposition'] = 'attachment; filename="' . $fileName . '"';
            } else {
                // For video streaming, use inline disposition
                $headers['Content-Disposition'] = 'inline; filename="' . $fileName . '"';
            }

            // Handle range requests for video streaming
            if ($request->hasHeader('Range')) {
                return $this->handleRangeRequest($request, $content, $size, $headers);
            }

            Log::info('[GcsProxyController] 檔案成功返回', [
                'path' => $filePath,
                'size' => $size,
                'mime_type' => $mimeType,
            ]);

            // Return full content
            return response($content, 200, $headers);
        } catch (\Exception $e) {
            Log::error('[GcsProxyController] 處理請求失敗', [
                'path' => $path,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response('Internal server error', 500);
        }
    }

    /**
     * Handle HTTP Range request for video streaming.
     *
     * @param Request $request
     * @param string $content
     * @param int $fileSize
     * @param array<string, mixed> $headers
     * @return Response
     */
    private function handleRangeRequest(Request $request, string $content, int $fileSize, array $headers): Response
    {
        $range = $request->header('Range');
        
        // Parse range header (e.g., "bytes=0-1023")
        if (!preg_match('/bytes=(\d+)-(\d*)/', $range, $matches)) {
            return response($content, 200, $headers);
        }

        $start = (int) $matches[1];
        $end = !empty($matches[2]) ? (int) $matches[2] : $fileSize - 1;
        
        // Validate range
        if ($start > $end || $start >= $fileSize || $end >= $fileSize) {
            return response('Requested Range Not Satisfiable', 416, [
                'Content-Range' => "bytes */{$fileSize}",
            ]);
        }

        $length = $end - $start + 1;
        $partialContent = substr($content, $start, $length);

        Log::info('[GcsProxyController] 返回部分內容 (Range Request)', [
            'start' => $start,
            'end' => $end,
            'length' => $length,
            'total_size' => $fileSize,
        ]);

        // Update headers for partial content
        $headers['Content-Length'] = $length;
        $headers['Content-Range'] = "bytes {$start}-{$end}/{$fileSize}";

        return response($partialContent, 206, $headers);
    }
}

