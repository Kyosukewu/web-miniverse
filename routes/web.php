<?php

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', [DashboardController::class, 'index'])->name('dashboard.index');
Route::get('/dashboard', [DashboardController::class, 'index']);
Route::get('/export', [DashboardController::class, 'export'])->name('dashboard.export');

// GCS Proxy route for video streaming/download
Route::get('/gcs-proxy/{path}', [App\Http\Controllers\GcsProxyController::class, 'stream'])
    ->where('path', '.*')
    ->name('gcs.proxy');

// Route to serve files from storage/app directory
Route::get('/storage/app/{path}', function (string $path) {
    // Security: prevent path traversal attacks
    $path = str_replace('..', '', $path);
    $path = ltrim($path, '/');
    
    // Build full file path
    $filePath = storage_path('app/' . $path);
    
    // Verify the file is within storage/app directory (prevent directory traversal)
    $storageAppPath = storage_path('app');
    $realFilePath = realpath($filePath);
    $realStoragePath = realpath($storageAppPath);
    
    if (false === $realFilePath || false === $realStoragePath) {
        abort(404);
    }
    
    if (!str_starts_with($realFilePath, $realStoragePath)) {
        abort(403, 'Access denied');
    }
    
    // Check if file exists
    if (!file_exists($realFilePath) || !is_file($realFilePath)) {
        abort(404);
    }
    
    // Determine MIME type
    $mimeType = mime_content_type($realFilePath);
    if (false === $mimeType) {
        $mimeType = 'application/octet-stream';
    }
    
    // Serve the file with appropriate headers
    return response()->file($realFilePath, [
        'Content-Type' => $mimeType,
        'Accept-Ranges' => 'bytes',
    ]);
})->where('path', '.*')->name('storage.app');
