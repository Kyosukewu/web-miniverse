<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Log;

class PromptService
{
    private array $textFileAnalysisVersions;
    private array $videoAnalysisVersions;
    private array $fullAnalysisVersions;
    private string $textFileAnalysisCurrentVersion;
    private string $videoAnalysisCurrentVersion;
    private string $fullAnalysisCurrentVersion;

    /**
     * Create a new prompt service instance.
     *
     * @param array $promptConfig
     */
    public function __construct(array $promptConfig)
    {
        $this->textFileAnalysisVersions = $promptConfig['text_file_analysis']['versions'] ?? [];
        $this->videoAnalysisVersions = $promptConfig['video_analysis']['versions'] ?? [];
        $this->fullAnalysisVersions = $promptConfig['full_analysis']['versions'] ?? [];
        $this->textFileAnalysisCurrentVersion = $promptConfig['text_file_analysis']['current_version'] ?? 'v3';
        $this->videoAnalysisCurrentVersion = $promptConfig['video_analysis']['current_version'] ?? 'v6';
        $this->fullAnalysisCurrentVersion = $promptConfig['full_analysis']['current_version'] ?? 'v1';
    }

    /**
     * Get text file analysis prompt.
     *
     * @param string|null $version
     * @return string
     * @throws \Exception
     */
    public function getTextFileAnalysisPrompt(?string $version = null): string
    {
        $versionKey = $version ?? $this->textFileAnalysisCurrentVersion;

        if (!isset($this->textFileAnalysisVersions[$versionKey])) {
            Log::warning('[PromptService] TextFileAnalysis Prompt 版本未找到', [
                'version' => $versionKey,
                'available_versions' => array_keys($this->textFileAnalysisVersions),
            ]);
            throw new \Exception("未在 versions map 中找到文本分析 Prompt 的檔案路徑 (版本: {$versionKey})");
        }

        $promptFilePath = $this->textFileAnalysisVersions[$versionKey];

        if ('' === $promptFilePath) {
            Log::warning('[PromptService] TextFileAnalysis Prompt 版本的路徑為空', [
                'version' => $versionKey,
            ]);
            throw new \Exception("文本分析 Prompt 版本 '{$versionKey}' 的檔案路徑為空");
        }

        return $this->readPromptFile($promptFilePath, $versionKey, 'text_file_analysis');
    }

    /**
     * Get video analysis prompt.
     *
     * @param string|null $version
     * @return string
     * @throws \Exception
     */
    public function getVideoAnalysisPrompt(?string $version = null): string
    {
        $versionKey = $version ?? $this->videoAnalysisCurrentVersion;

        if (!isset($this->videoAnalysisVersions[$versionKey])) {
            Log::warning('[PromptService] VideoAnalysis Prompt 版本未找到', [
                'version' => $versionKey,
                'available_versions' => array_keys($this->videoAnalysisVersions),
            ]);
            throw new \Exception("未在 versions map 中找到影片分析 Prompt 的檔案路徑 (版本: {$versionKey})");
        }

        $promptFilePath = $this->videoAnalysisVersions[$versionKey];

        if ('' === $promptFilePath) {
            Log::warning('[PromptService] VideoAnalysis Prompt 版本的路徑為空', [
                'version' => $versionKey,
            ]);
            throw new \Exception("影片分析 Prompt 版本 '{$versionKey}' 的檔案路徑為空");
        }

        return $this->readPromptFile($promptFilePath, $versionKey, 'video_analysis');
    }

    /**
     * Get current text file analysis prompt version.
     *
     * @return string
     */
    public function getTextFileAnalysisCurrentVersion(): string
    {
        return $this->textFileAnalysisCurrentVersion;
    }

    /**
     * Get current video analysis prompt version.
     *
     * @return string
     */
    public function getVideoAnalysisCurrentVersion(): string
    {
        return $this->videoAnalysisCurrentVersion;
    }

    /**
     * Get full analysis prompt (text + video combined).
     *
     * @param string|null $version
     * @return string
     * @throws \Exception
     */
    public function getFullAnalysisPrompt(?string $version = null): string
    {
        $versionKey = $version ?? $this->fullAnalysisCurrentVersion;

        if (!isset($this->fullAnalysisVersions[$versionKey])) {
            Log::warning('[PromptService] FullAnalysis Prompt 版本未找到', [
                'version' => $versionKey,
                'available_versions' => array_keys($this->fullAnalysisVersions),
            ]);
            throw new \Exception("未在 versions map 中找到完整分析 Prompt 的檔案路徑 (版本: {$versionKey})");
        }

        $promptFilePath = $this->fullAnalysisVersions[$versionKey];

        if ('' === $promptFilePath) {
            Log::warning('[PromptService] FullAnalysis Prompt 版本的路徑為空', [
                'version' => $versionKey,
            ]);
            throw new \Exception("完整分析 Prompt 版本 '{$versionKey}' 的檔案路徑為空");
        }

        return $this->readPromptFile($promptFilePath, $versionKey, 'full_analysis');
    }

    /**
     * Get current full analysis prompt version.
     *
     * @return string
     */
    public function getFullAnalysisCurrentVersion(): string
    {
        return $this->fullAnalysisCurrentVersion;
    }

    /**
     * Read prompt file from path.
     *
     * @param string $filePath
     * @param string $versionKey
     * @param string $promptType
     * @return string
     * @throws \Exception
     */
    private function readPromptFile(string $filePath, string $versionKey, string $promptType): string
    {
        if (!file_exists($filePath)) {
            Log::error('[PromptService] Prompt 檔案不存在', [
                'file_path' => $filePath,
                'version' => $versionKey,
                'type' => $promptType,
            ]);
            throw new \Exception("Prompt 檔案 '{$filePath}' (版本 '{$versionKey}') 不存在");
        }

        $promptContent = file_get_contents($filePath);

        if (false === $promptContent) {
            Log::error('[PromptService] 讀取 Prompt 檔案失敗', [
                'file_path' => $filePath,
                'version' => $versionKey,
                'type' => $promptType,
            ]);
            throw new \Exception("讀取 prompt 檔案 '{$filePath}' (版本 '{$versionKey}') 失敗");
        }

        Log::info('[PromptService] 成功讀取 Prompt 檔案', [
            'file_path' => $filePath,
            'version' => $versionKey,
            'type' => $promptType,
        ]);

        return trim($promptContent);
    }
}

