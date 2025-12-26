<?php

declare(strict_types=1);

namespace App\Exports;

use App\Models\Video;
use App\Repositories\VideoRepository;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithMapping;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class VideoAnalysisExport implements FromCollection, WithChunkReading, WithColumnWidths, WithHeadings, WithStyles, WithTitle, WithMapping
{
    protected string $timestamp;
    protected string $searchTerm;
    protected string $sortBy;
    protected string $sortOrder;
    protected VideoRepository $videoRepository;
    protected array $selectedIds;

    public function __construct(
        VideoRepository $videoRepository,
        string $timestamp,
        string $searchTerm = '',
        string $sortBy = '',
        string $sortOrder = '',
        array $selectedIds = []
    ) {
        $this->videoRepository = $videoRepository;
        $this->timestamp = $timestamp;
        $this->searchTerm = $searchTerm;
        $this->sortBy = $sortBy;
        $this->sortOrder = $sortOrder;
        $this->selectedIds = $selectedIds;
    }

    /**
     * 取得資料庫資料
     */
    public function collection()
    {
        // 如果有選中的 ID，只匯出選中的資料
        if (!empty($this->selectedIds)) {
            $videos = $this->videoRepository->getByIdsWithAnalysis(
                $this->selectedIds,
                $this->sortBy,
                $this->sortOrder
            );
        } else {
            // 使用 VideoRepository 來取得資料，與 DashboardController 保持一致
            // 為了匯出全部資料，使用查詢建構器並移除限制
            $query = $this->videoRepository->getAllWithAnalysisQuery(
                $this->searchTerm,
                $this->sortBy,
                $this->sortOrder
            );
            // 移除 limit 和 offset，匯出所有符合條件的資料
            $videos = $query->get();
        }

        return $videos;
    }

    public function chunkSize(): int
    {
        return 1000;
    }

    /**
     * 自訂每一列要匯出的欄位順序與內容
     */
    public function map($video): array
    {
        $analysisResult = $video->analysisResult;

        // 格式化影片長度
        $formattedDuration = 'N/A';
        if (null !== $video->duration_secs) {
            $minutes = (int) ($video->duration_secs / 60);
            $seconds = $video->duration_secs % 60;
            $formattedDuration = sprintf('%02d:%02d', $minutes, $seconds);
        }

        // 格式化發布時間（轉換為 UTC+8）
        $publishedAt = \App\Helpers\DashboardHelper::formatDateTimeToUtc8($video->published_at);

        // 格式化擷取時間（轉換為 UTC+8）
        $fetchedAt = \App\Helpers\DashboardHelper::formatDateTimeToUtc8($video->fetched_at);

        // 處理分類/主題
        $subjects = [];
        if (null !== $video->subjects) {
            $subjects = is_string($video->subjects) 
                ? json_decode($video->subjects, true) 
                : $video->subjects;
            $subjects = is_array($subjects) ? $subjects : [];
        }

        $topics = [];
        if (null !== $analysisResult && null !== $analysisResult->topics) {
            $topics = is_string($analysisResult->topics)
                ? json_decode($analysisResult->topics, true)
                : $analysisResult->topics;
            $topics = is_array($topics) ? $topics : [];
        }

        $consolidatedCategories = array_unique(array_merge($subjects, $topics));
        sort($consolidatedCategories);
        $categoriesStr = implode('; ', $consolidatedCategories);

        // 處理關鍵字
        $keywords = [];
        if (null !== $analysisResult && null !== $analysisResult->keywords) {
            $keywords = is_string($analysisResult->keywords)
                ? json_decode($analysisResult->keywords, true)
                : $analysisResult->keywords;
            $keywords = is_array($keywords) ? $keywords : [];
        }
        $keywordsStr = '';
        if (is_array($keywords) && count($keywords) > 0) {
            $keywordParts = [];
            foreach ($keywords as $keyword) {
                if (is_array($keyword) && isset($keyword['category']) && isset($keyword['keyword'])) {
                    $keywordParts[] = $keyword['category'] . ': ' . $keyword['keyword'];
                }
            }
            $keywordsStr = implode('; ', $keywordParts);
        }

        // 處理地點
        $mentionedLocations = [];
        if (null !== $analysisResult && null !== $analysisResult->mentioned_locations) {
            $mentionedLocations = is_string($analysisResult->mentioned_locations)
                ? json_decode($analysisResult->mentioned_locations, true)
                : $analysisResult->mentioned_locations;
            $mentionedLocations = is_array($mentionedLocations) ? $mentionedLocations : [];
        }
        $locationsStr = '';
        if (null !== $video->location) {
            $locationsStr = $video->location;
        }
        if (count($mentionedLocations) > 0) {
            $locationsStr .= ($locationsStr ? '; ' : '') . implode('; ', $mentionedLocations);
        }

        // 處理 BITE
        $bites = [];
        if (null !== $analysisResult && null !== $analysisResult->bites) {
            $bites = is_string($analysisResult->bites)
                ? json_decode($analysisResult->bites, true)
                : $analysisResult->bites;
            $bites = is_array($bites) ? $bites : [];
        }
        $bitesStr = '';
        if (is_array($bites) && count($bites) > 0) {
            $biteParts = [];
            foreach ($bites as $bite) {
                if (is_array($bite)) {
                    $timeLine = $bite['time_line'] ?? '';
                    $speaker = $bite['speaker'] ?? '';
                    $quote = $bite['quote'] ?? '';
                    $biteParts[] = "[{$timeLine}] {$speaker}: \"{$quote}\"";
                }
            }
            $bitesStr = implode(' | ', $biteParts);
        }

        // 處理重要性評級和評估詳情
        $overallRatingLetter = '';
        $importanceDetails = '';
        if (null !== $analysisResult) {
            $overallRatingLetter = $analysisResult->overall_rating_letter;
            
            if (null !== $analysisResult->importance_score) {
                $importanceScore = is_string($analysisResult->importance_score)
                    ? json_decode($analysisResult->importance_score, true)
                    : $analysisResult->importance_score;
                
                if (is_array($importanceScore)) {
                    $keyFactors = $importanceScore['key_factors'] ?? [];
                    $assessmentDetails = $importanceScore['assessment_details'] ?? '';
                    
                    if (is_array($keyFactors) && count($keyFactors) > 0) {
                        $importanceDetails = '主要因素: ' . implode('; ', $keyFactors);
                    }
                    if ($assessmentDetails) {
                        $importanceDetails .= ($importanceDetails ? ' | ' : '') . '評估說明: ' . $assessmentDetails;
                    }
                }
            }
        }

        // 處理 Prompt 版本：優先使用 analysisResult 中的 prompt_version，如果不存在則使用 video 中的
        $promptVersion = 'N/A';
        if (null !== $analysisResult && null !== $analysisResult->prompt_version) {
            $promptVersion = $analysisResult->prompt_version;
        } elseif ($video->prompt_version) {
            $promptVersion = $video->prompt_version;
        }

        return [
            $video->id,
            $video->source_name ?? 'N/A',
            $video->source_id ?? 'N/A',
            $video->title ?? 'N/A',
            $publishedAt,
            $fetchedAt,
            $formattedDuration,
            $video->location ?? 'N/A',
            $locationsStr ?: 'N/A',
            $categoriesStr ?: 'N/A',
            $keywordsStr ?: 'N/A',
            $analysisResult->short_summary ?? 'N/A',
            $analysisResult->bulleted_summary ?? 'N/A',
            $analysisResult->visual_description ?? 'N/A',
            $analysisResult->material_type ?? 'N/A',
            $overallRatingLetter ?: 'N/A',
            $importanceDetails ?: 'N/A',
            $bitesStr ?: 'N/A',
            $analysisResult->transcript ?? 'N/A',
            $analysisResult->translation ?? 'N/A',
            $video->shotlist_content ?? 'N/A',
            $video->nas_path ?? 'N/A',
            $video->analysis_status->value ?? 'N/A',
            $promptVersion,
            $analysisResult->error_message ?? '',
        ];
    }

    /**
     * 設定 Excel 標題列
     */
    public function headings(): array
    {
        return [
            'ID',
            '來源名稱',
            '來源ID',
            '標題',
            '發布時間',
            '擷取時間',
            '影片長度',
            '主要地點',
            '所有地點',
            '分類/主題',
            '關鍵字',
            '短摘要',
            '列點摘要',
            '畫面描述',
            '素材類型',
            '重要性評級',
            '重要性評估詳情',
            'BITE',
            '原文逐字稿',
            '逐字稿翻譯',
            '畫面內容 (Shotlist)',
            '檔案路徑',
            '分析狀態',
            'Prompt 版本',
            '錯誤訊息',
        ];
    }

    /**
     * 設定 Excel 頁籤名稱
     */
    public function title(): string
    {
        return "{$this->timestamp}__影片分析資料";
    }

    /**
     * 設定 Excel 欄寬
     */
    public function columnWidths(): array
    {
        return [
            'A' => 10,  // ID
            'B' => 15,  // 來源名稱
            'C' => 15,  // 來源ID
            'D' => 40,  // 標題
            'E' => 20,  // 發布時間
            'F' => 20,  // 擷取時間
            'G' => 15,  // 影片長度
            'H' => 20,  // 主要地點
            'I' => 40,  // 所有地點
            'J' => 30,  // 分類/主題
            'K' => 40,  // 關鍵字
            'L' => 50,  // 短摘要
            'M' => 50,  // 列點摘要
            'N' => 50,  // 畫面描述
            'O' => 20,  // 素材類型
            'P' => 15,  // 重要性評級
            'Q' => 60,  // 重要性評估詳情
            'R' => 60,  // BITE
            'S' => 60,  // 原文逐字稿
            'T' => 60,  // 逐字稿翻譯
            'U' => 60,  // 畫面內容
            'V' => 50,  // 檔案路徑
            'W' => 20,  // 分析狀態
            'X' => 20,  // Prompt 版本
            'Y' => 40,  // 錯誤訊息
        ];
    }

    /**
     * 設定 Excel 樣式
     */
    public function styles(Worksheet $sheet)
    {
        $highestRow = $sheet->getHighestRow();
        $highestColumn = $sheet->getHighestColumn();

        $sheet->getStyle("A1:{$highestColumn}{$highestRow}")->getFont()->setSize(12);

        $sheet->getStyle('1')->getFont()->setBold(true);

        $sheet->getStyle("A1:{$highestColumn}{$highestRow}")
            ->getAlignment()
            ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT)
            ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP);

        // 設定標題列背景色
        $sheet->getStyle('1:1')->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFE0E0E0');

        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}

