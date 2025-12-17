<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\StorageService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
     * @return Response|StreamedResponse
     */
    public function stream(Request $request, string $path): Response|StreamedResponse
    {
        try {
            // Path is already decoded by Laravel routing
            $filePath = $path;
            
            Log::info('[GcsProxyController] 請求 GCS 檔案', [
                'raw_path' => $path,
                'file_path' => $filePath,
                'download' => $request->has('download'),
            ]);

            // Get GCS disk
            $disk = Storage::disk('gcs');

            // Check if file exists
            if (!$disk->exists($filePath)) {
                Log::warning('[GcsProxyController] 檔案不存在', [
                    'path' => $filePath,
                    'tried_paths' => [$filePath, urldecode($filePath)],
                ]);
                return response('File not found', 404);
            }

            // Get file metadata
            $mimeType = $disk->mimeType($filePath);
            $size = $disk->size($filePath);
            $fileName = basename($filePath);

            Log::info('[GcsProxyController] 開始處理檔案', [
                'path' => $filePath,
                'size' => number_format($size / 1024 / 1024, 2) . ' MB',
                'mime_type' => $mimeType,
            ]);

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
                return $this->handleRangeRequestStream($request, $disk, $filePath, $size, $headers);
            }

            // Use stream response for large files (more memory efficient)
            try {
                $stream = $disk->readStream($filePath);
            } catch (\Exception $e) {
                Log::error('[GcsProxyController] readStream 異常', [
                    'path' => $filePath,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                return response('Unable to read file: ' . $e->getMessage(), 500);
            }
            
            if (false === $stream || !is_resource($stream)) {
                Log::error('[GcsProxyController] 無法開啟檔案串流', [
                    'path' => $filePath,
                    'stream_type' => gettype($stream),
                ]);
                return response('Unable to read file', 500);
            }

            Log::info('[GcsProxyController] 檔案串流成功建立', [
                'path' => $filePath,
                'stream_resource' => is_resource($stream),
            ]);

            // Return stream response
            return response()->stream(function () use ($stream) {
                fpassthru($stream);
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }, 200, $headers);
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
     * Handle HTTP Range request for video streaming using stream.
     *
     * @param Request $request
     * @param \Illuminate\Contracts\Filesystem\Filesystem $disk
     * @param string $filePath
     * @param int $fileSize
     * @param array<string, mixed> $headers
     * @return Response|StreamedResponse
     */
    private function handleRangeRequestStream(Request $request, $disk, string $filePath, int $fileSize, array $headers): Response|StreamedResponse
    {
        $range = $request->header('Range');
        
        // Parse range header (e.g., "bytes=0-1023")
        if (!preg_match('/bytes=(\d+)-(\d*)/', $range, $matches)) {
            // Invalid range, return full content
            $stream = $disk->readStream($filePath);
            if (false === $stream) {
                return response('Unable to read file', 500);
            }
            
            return response()->stream(function () use ($stream) {
                fpassthru($stream);
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }, 200, $headers);
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

        Log::info('[GcsProxyController] 返回部分內容 (Range Request)', [
            'start' => $start,
            'end' => $end,
            'length' => number_format($length / 1024, 2) . ' KB',
            'total_size' => number_format($fileSize / 1024 / 1024, 2) . ' MB',
        ]);

        // Update headers for partial content
        $headers['Content-Length'] = $length;
        $headers['Content-Range'] = "bytes {$start}-{$end}/{$fileSize}";

        // Open stream and seek to start position
        try {
            $stream = $disk->readStream($filePath);
        } catch (\Exception $e) {
            Log::error('[GcsProxyController] Range Request - readStream 異常', [
                'path' => $filePath,
                'error' => $e->getMessage(),
            ]);
            return response('Unable to read file: ' . $e->getMessage(), 500);
        }
        
        if (false === $stream || !is_resource($stream)) {
            Log::error('[GcsProxyController] Range Request - 無法開啟檔案串流', [
                'path' => $filePath,
                'stream_type' => gettype($stream),
            ]);
            return response('Unable to read file', 500);
        }

        return response()->stream(function () use ($stream, $start, $length) {
            // Seek to start position
            if (!is_resource($stream)) {
                Log::error('[GcsProxyController] Stream became invalid before seeking');
                return;
            }
            fseek($stream, $start);
            
            // Read and output the requested range
            $remaining = $length;
            $chunkSize = 8192; // 8KB chunks
            
            while ($remaining > 0 && !feof($stream)) {
                $readSize = min($chunkSize, $remaining);
                $data = fread($stream, $readSize);
                if (false === $data) {
                    break;
                }
                echo $data;
                $remaining -= strlen($data);
                
                // Flush output buffer
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            }
            
            if (is_resource($stream)) {
                fclose($stream);
            }
        }, 206, $headers);
    }
}

