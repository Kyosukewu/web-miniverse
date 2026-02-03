<?php

namespace App\Exceptions;

use Exception;

/**
 * Gemini API 異常
 *
 * 用於區分 API 層級錯誤（系統性）和資料層級錯誤（個別影片）
 */
class GeminiApiException extends Exception
{
    /**
     * 是否為 API 層級的系統性錯誤
     *
     * @var bool
     */
    private bool $isApiLevelError;

    /**
     * HTTP 狀態碼（如果有）
     *
     * @var int|null
     */
    private ?int $httpStatusCode;

    /**
     * 錯誤類型
     *
     * @var string
     */
    private string $errorType;

    public function __construct(
        string $message,
        bool $isApiLevelError,
        ?int $httpStatusCode = null,
        string $errorType = 'unknown',
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->isApiLevelError = $isApiLevelError;
        $this->httpStatusCode = $httpStatusCode;
        $this->errorType = $errorType;
    }

    /**
     * 是否為 API 層級錯誤（需要立即停止處理）
     */
    public function isApiLevelError(): bool
    {
        return $this->isApiLevelError;
    }

    /**
     * 是否為資料層級錯誤（可以繼續處理其他影片）
     */
    public function isDataLevelError(): bool
    {
        return !$this->isApiLevelError;
    }

    /**
     * 獲取 HTTP 狀態碼
     */
    public function getHttpStatusCode(): ?int
    {
        return $this->httpStatusCode;
    }

    /**
     * 獲取錯誤類型
     */
    public function getErrorType(): string
    {
        return $this->errorType;
    }

    /**
     * 從 Guzzle 異常創建 API 層級錯誤
     */
    public static function fromGuzzleException(\Throwable $e, string $sanitizedMessage): self
    {
        $httpStatusCode = null;
        $errorType = 'api_error';

        // 嘗試獲取 HTTP 狀態碼
        if (method_exists($e, 'getResponse') && $e->getResponse()) {
            $httpStatusCode = $e->getResponse()->getStatusCode();

            // 根據狀態碼分類
            $errorType = match (true) {
                $httpStatusCode === 403 => 'forbidden',
                $httpStatusCode === 429 => 'rate_limit',
                $httpStatusCode === 401 => 'unauthorized',
                $httpStatusCode >= 500 => 'server_error',
                default => 'api_error',
            };
        }

        return new self(
            $sanitizedMessage,
            true,  // API 層級錯誤
            $httpStatusCode,
            $errorType,
            0,
            $e
        );
    }

    /**
     * 創建檔案層級錯誤（檔案過大）
     */
    public static function fileTooLarge(float $fileSizeMB, float $maxSizeMB): self
    {
        return new self(
            "影片檔案過大 ({$fileSizeMB}MB)，超過 Gemini API 限制 ({$maxSizeMB}MB)",
            false,  // 資料層級錯誤
            null,
            'file_too_large'
        );
    }

    /**
     * 創建檔案層級錯誤（檔案不存在或無法讀取）
     */
    public static function fileNotAccessible(string $filePath, string $reason = ''): self
    {
        $message = "無法讀取影片檔案: {$filePath}";
        if ($reason) {
            $message .= " ({$reason})";
        }

        return new self(
            $message,
            false,  // 資料層級錯誤
            null,
            'file_not_accessible'
        );
    }

    /**
     * 創建檔案層級錯誤（下載失敗）
     */
    public static function downloadFailed(string $source, string $reason = ''): self
    {
        $message = "下載影片失敗: {$source}";
        if ($reason) {
            $message .= " ({$reason})";
        }

        return new self(
            $message,
            false,  // 資料層級錯誤
            null,
            'download_failed'
        );
    }
}
