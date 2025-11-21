<?php

declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GeminiClient
{
    private Client $httpClient;
    private string $apiKey;
    private string $textModel;
    private string $videoModel;
    private string $apiVersion;
    private string $baseUrl;

    /**
     * Create a new Gemini client instance.
     *
     * @param string $apiKey
     * @param string $textModel
     * @param string $videoModel
     * @param string $apiVersion
     */
    public function __construct(string $apiKey, string $textModel, string $videoModel, string $apiVersion = 'v1beta')
    {
        $this->apiKey = $apiKey;
        $this->textModel = $textModel;
        $this->videoModel = $videoModel;
        $this->apiVersion = $apiVersion;
        $this->baseUrl = sprintf('https://generativelanguage.googleapis.com/%s', $apiVersion);
        $this->httpClient = new Client([
            'timeout' => 300, // 5 minutes for video analysis
        ]);
    }

    /**
     * Analyze text content using Gemini API.
     *
     * @param string $textContent
     * @param string $prompt
     * @return string
     * @throws \Exception
     */
    public function analyzeText(string $textContent, string $prompt): string
    {
        if ('' === trim($textContent)) {
            throw new \InvalidArgumentException('要分析的文本內容不得為空');
        }

        if ('' === trim($prompt)) {
            throw new \InvalidArgumentException('文本分析的 Prompt 不得為空');
        }

        Log::info('[Gemini Client] AnalyzeText - 開始分析文本內容', [
            'length' => strlen($textContent),
        ]);

        $url = sprintf('%s/models/%s:generateContent?key=%s', $this->baseUrl, $this->textModel, $this->apiKey);

        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt],
                        ['text' => $textContent],
                    ],
                ],
            ],
        ];

        // responseMimeType is only supported in v1beta
        if ($this->apiVersion === 'v1beta') {
            $payload['generationConfig'] = [
                'responseMimeType' => 'application/json',
            ];
        }

        try {
            Log::info('[Gemini Client] AnalyzeText - 正在向 Gemini API 發送請求');
            $response = $this->httpClient->post($url, [
                'json' => $payload,
            ]);

            $responseData = json_decode($response->getBody()->getContents(), true);

            if (null === $responseData || !isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
                throw new \Exception('Gemini API 文本分析回應無效或為空');
            }

            $rawJsonResponse = $responseData['candidates'][0]['content']['parts'][0]['text'];
            Log::info('[Gemini Client] AnalyzeText - 收到 API 的原始文字回應', [
                'length' => strlen($rawJsonResponse),
                'raw_text' => $rawJsonResponse,
            ]);

            $cleanedJsonString = $this->cleanJsonString($rawJsonResponse);
            Log::info('[Gemini Client] AnalyzeText - 清理後的 JSON 字串', [
                'length' => strlen($cleanedJsonString),
                'cleaned_text' => $cleanedJsonString,
            ]);

            if (!json_validate($cleanedJsonString)) {
                Log::error('[Gemini Client] AnalyzeText - 清理後的字串仍然不是有效的 JSON', [
                    'cleaned_json' => $cleanedJsonString,
                ]);
                throw new \Exception('清理後的字串不是有效的 JSON (文本分析)');
            }

            return $cleanedJsonString;
        } catch (GuzzleException $e) {
            Log::error('[Gemini Client] AnalyzeText - API 請求失敗', [
                'error' => $e->getMessage(),
            ]);
            throw new \Exception('Gemini API 文本分析失敗: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Analyze video content using Gemini API.
     *
     * @param string $videoPath
     * @param string $prompt
     * @return array<string, mixed>
     * @throws \Exception
     */
    public function analyzeVideo(string $videoPath, string $prompt): array
    {
        if (!file_exists($videoPath)) {
            throw new \InvalidArgumentException('影片檔案不存在: ' . $videoPath);
        }

        Log::info('[Gemini Client] AnalyzeVideo - 開始分析影片', [
            'video_path' => $videoPath,
            'prompt_preview' => Str::limit($prompt, 100),
        ]);

        $videoData = file_get_contents($videoPath);
        if (false === $videoData) {
            throw new \Exception('讀取影片檔案失敗: ' . $videoPath);
        }

        $videoMimeType = $this->getVideoMimeType($videoPath);
        Log::info('[Gemini Client] 使用影片 MIME 類型', ['mime_type' => $videoMimeType]);

        $videoBase64 = base64_encode($videoData);

        $url = sprintf('%s/models/%s:generateContent?key=%s', $this->baseUrl, $this->videoModel, $this->apiKey);

        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt],
                        [
                            'inline_data' => [
                                'mime_type' => $videoMimeType,
                                'data' => $videoBase64,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        // responseMimeType is only supported in v1beta
        if ($this->apiVersion === 'v1beta') {
            $payload['generationConfig'] = [
                'responseMimeType' => 'application/json',
            ];
        }

        try {
            Log::info('[Gemini Client] AnalyzeVideo - 正在向 Gemini API 發送請求');
            $response = $this->httpClient->post($url, [
                'json' => $payload,
            ]);

            $responseData = json_decode($response->getBody()->getContents(), true);

            if (null === $responseData || !isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
                throw new \Exception('Gemini API 影片分析回應無效或為空');
            }

            $rawJsonResponse = $responseData['candidates'][0]['content']['parts'][0]['text'];
            Log::info('[Gemini Client] AnalyzeVideo - 收到 API 的原始文字回應', [
                'length' => strlen($rawJsonResponse),
                'raw_text' => $rawJsonResponse,
            ]);

            $cleanedJsonString = $this->cleanJsonString($rawJsonResponse);
            Log::info('[Gemini Client] AnalyzeVideo - 清理後的 JSON 字串準備解析', [
                'length' => strlen($cleanedJsonString),
                'cleaned_text' => $cleanedJsonString,
            ]);

            if (!json_validate($cleanedJsonString)) {
                Log::error('[Gemini Client] AnalyzeVideo - 清理後的字串仍然不是有效的 JSON', [
                    'cleaned_json' => $cleanedJsonString,
                ]);
                throw new \Exception('清理後的字串不是有效的 JSON (影片分析)');
            }

            $analysis = json_decode($cleanedJsonString, true);
            if (null === $analysis) {
                Log::error('[Gemini Client] AnalyzeVideo - 無法將 Gemini API 回應解析為 JSON', [
                    'cleaned_json' => $cleanedJsonString,
                ]);
                throw new \Exception('無法將 Gemini API 回應解析為 JSON (影片分析)');
            }

            Log::info('[Gemini Client] 影片 JSON 回應解析成功', ['video_path' => $videoPath]);

            return $analysis;
        } catch (GuzzleException $e) {
            Log::error('[Gemini Client] AnalyzeVideo - API 請求失敗', [
                'error' => $e->getMessage(),
            ]);
            throw new \Exception('Gemini API 影片分析失敗: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Clean JSON string from LLM response.
     *
     * @param string $rawResponse
     * @return string
     */
    private function cleanJsonString(string $rawResponse): string
    {
        $cleaned = trim($rawResponse);

        // Remove markdown code block markers
        if (Str::startsWith($cleaned, '```json')) {
            $cleaned = Str::after($cleaned, '```json');
            if (Str::endsWith($cleaned, '```')) {
                $cleaned = Str::beforeLast($cleaned, '```');
            }
        } elseif (Str::startsWith($cleaned, '```')) {
            $cleaned = Str::after($cleaned, '```');
            if (Str::endsWith($cleaned, '```')) {
                $cleaned = Str::beforeLast($cleaned, '```');
            }
        }

        $cleaned = trim($cleaned);

        // Find the outermost JSON structure
        $firstBrace = strpos($cleaned, '{');
        $lastBrace = strrpos($cleaned, '}');
        $firstBracket = strpos($cleaned, '[');
        $lastBracket = strrpos($cleaned, ']');

        $isObject = (false !== $firstBrace && false !== $lastBrace && $lastBrace > $firstBrace);
        $isArray = (false !== $firstBracket && false !== $lastBracket && $lastBracket > $firstBracket);

        $potentialJson = $cleaned;
        if ($isObject && (!$isArray || ($isArray && $firstBrace < $firstBracket))) {
            $potentialJson = substr($cleaned, $firstBrace, $lastBrace - $firstBrace + 1);
        } elseif ($isArray && (!$isObject || ($isObject && $firstBracket < $firstBrace))) {
            $potentialJson = substr($cleaned, $firstBracket, $lastBracket - $firstBracket + 1);
        }

        $potentialJson = trim($potentialJson);

        // Remove BOM
        $potentialJson = ltrim($potentialJson, "\xEF\xBB\xBF");

        // Try to parse and reformat JSON
        $jsonObj = json_decode($potentialJson, true);
        if (null !== $jsonObj) {
            $formatted = json_encode($jsonObj, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (false !== $formatted) {
                return $formatted;
            }
        }

        return $potentialJson;
    }

    /**
     * Get video MIME type based on file extension.
     *
     * @param string $videoPath
     * @return string
     */
    private function getVideoMimeType(string $videoPath): string
    {
        $ext = strtolower(pathinfo($videoPath, PATHINFO_EXTENSION));

        return match ($ext) {
            'mp4' => 'video/mp4',
            'mov' => 'video/quicktime',
            'mpeg', 'mpg' => 'video/mpeg',
            'avi' => 'video/x-msvideo',
            'wmv' => 'video/x-ms-wmv',
            'flv' => 'video/x-flv',
            'webm' => 'video/webm',
            'mkv' => 'video/x-matroska',
            'ts' => 'video/mp2t',
            default => 'video/mp4',
        };
    }
}

