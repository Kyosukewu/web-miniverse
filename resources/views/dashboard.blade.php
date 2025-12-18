<!DOCTYPE html>
<html lang="zh-Hant">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MINIVERSE - 影片分析儀表板</title>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    @include('dashboard.styles')
</head>

<body>
    <div class="page-container">
        <aside class="sidebar">
            <div id="statusMessageContainer"></div>

            <h3>篩選與排序</h3>
            <form id="filterSortForm" method="GET" action="{{ route('dashboard.index') }}">
                <div class="filter-group">
                    <label for="keywordSearchInput">關鍵字搜尋</label>
                    <input type="text" id="keywordSearchInput" name="search" value="{{ $searchTerm }}" placeholder="ID, 標題, 摘要, 關鍵字...">
                </div>
                <div class="filter-group">
                    <label for="sortBySelect">排序依據</label>
                    <select id="sortBySelect" name="sortBy">
                        <option value="importance" {{ $sortBy === 'importance' ? 'selected' : '' }}>評分</option>
                        <option value="published_at" {{ $sortBy === 'published_at' ? 'selected' : '' }}>發布時間</option>
                        <option value="source_id" {{ $sortBy === 'source_id' ? 'selected' : '' }}>素材編號</option>
                        <option value="fetched_at" {{ $sortBy === 'fetched_at' ? 'selected' : '' }}>擷取時間</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="sortOrderSelect">排序順序</label>
                    <select id="sortOrderSelect" name="sortOrder">
                        <option value="desc" {{ $sortOrder === 'desc' ? 'selected' : '' }}>優先顯示新的/重要的 (降冪)</option>
                        <option value="asc" {{ $sortOrder === 'asc' ? 'selected' : '' }}>優先顯示舊的/次要的 (升冪)</option>
                    </select>
                </div>
                <div class="filter-group">
                    <div class="filter-buttons">
                        <button type="submit" class="apply-btn">
                            <span class="btn-icon">✓</span>
                            <span>套用篩選</span>
                        </button>
                        <button type="button" id="resetFilterBtn" class="cancel-btn">
                            <span class="btn-icon">✕</span>
                            <span>重置篩選</span>
                        </button>
                    </div>
                </div>
            </form>

            <h3>控制面板</h3>
            <div class="control-panel">
                {{-- <button id="triggerTextAnalysisBtn" class="control-btn primary">手動觸發文本元數據分析</button>
                <button id="triggerVideoAnalysisBtn" class="control-btn secondary">手動觸發影片內容分析</button> --}}
                <div class="checkbox-controls">
                    <button type="button" id="selectAllBtn" class="control-btn secondary">
                        <span class="btn-icon">☑</span>
                        <span>全選</span>
                    </button>
                    <button type="button" id="deselectAllBtn" class="control-btn secondary">
                        <span class="btn-icon">☐</span>
                        <span>取消全選</span>
                    </button>
                </div>
                <button id="exportExcelBtn" class="control-btn export-btn">
                    <span class="btn-icon">📊</span>
                    <span>匯出資料</span>
                </button>
            </div>
        </aside>

        <main class="main-content">
            <h1>MINIVERSE - 影片分析儀表板</h1>
            <div class="video-card-list">
                @if($videos->count() > 0)
                    @foreach($videos as $index => $videoData)
                        @php
                            $video = $videoData['video'];
                            $analysisResult = $videoData['analysis_result'];
                            $overallRating = $analysisResult['overall_rating'] ?? null;
                            $overallRatingLetter = $analysisResult['overall_rating_letter'] ?? '';
                            $importanceClass = 'importance-unknown';
                            if ('' !== $overallRatingLetter) {
                                $importanceClass = match(strtoupper($overallRatingLetter)) {
                                    'S' => 'importance-s',
                                    'A' => 'importance-a',
                                    'B' => 'importance-b',
                                    'C' => 'importance-c',
                                    'N' => 'importance-n',
                                    default => 'importance-unknown',
                                };
                            }
                        @endphp
                        <div class="video-card {{ $importanceClass }}">
                            <div class="card-header" onclick="toggleDetails('details-{{ $index }}', this, event)">
                                <div class="card-checkbox-wrapper" onclick="event.stopPropagation();">
                                    <input type="checkbox" class="video-checkbox" id="video-checkbox-{{ $video->id }}" value="{{ $video->id }}" data-video-id="{{ $video->id }}" onclick="event.stopPropagation();">
                                    <label for="video-checkbox-{{ $video->id }}" class="checkbox-label" onclick="event.stopPropagation();"></label>
                                </div>
                                <div class="card-title-section">
                                    @if('' !== $overallRatingLetter)
                                        @php
                                            $ratingClass = match(strtoupper($overallRatingLetter)) {
                                                'S' => 'rating-s',
                                                'A' => 'rating-a',
                                                'B' => 'rating-b',
                                                'C' => 'rating-c',
                                                'N' => 'rating-n',
                                                default => 'no-data',
                                            };
                                        @endphp
                                        <span class="rating-badge {{ $ratingClass }}" title="重要性評級: {{ $overallRatingLetter }} ({{ $overallRating }}/5)">
                                            {{ $overallRatingLetter }}
                                        </span>
                                    @else
                                        <span class="rating-badge no-data" title="重要性評分未分析">?</span>
                                    @endif
                                    @if($video->source_name)
                                        <span class="source-badge" title="來源: {{ $video->source_name }}">{{ strtoupper($video->source_name) }}</span>
                                    @endif
                                    <h2 class="@if($video->title) copyable-field @endif" @if($video->title) data-copy-text="{{ addslashes($video->title) }}" title="點擊複製標題" @endif>
                                        {!! $video->title ?? '<span class="no-data">(無標題)</span>' !!}
                                    </h2>
                                    @if($videoData['flag_emoji'])
                                        <span class="flag-icon" title="主要地點: {{ $video->location }}">{{ $videoData['flag_emoji'] }}</span>
                                    @endif
                                </div>
                                <span class="expand-indicator">▼ 展開</span>
                            </div>

                            @if(null !== $analysisResult && null !== $analysisResult['short_summary'])
                            <div class="card-short-summary">
                                <p class="summary-content copyable-field" data-copy-text="{{ addslashes($analysisResult['short_summary']) }}" title="點擊複製短摘要">
                                    {{ $analysisResult['short_summary'] }}
                                </p>
                            </div>
                            @endif

                            <div class="card-identity-meta">
                                <p><span class="icon icon-id label">素材編號:</span> {{ $videoData['combined_source_id'] }} (DB ID: {{ $video->id }})</p>
                                @if($video->published_at)
                                    <p><span class="icon icon-date label">發布時間:</span> {{ $video->published_at->format('Y-m-d H:i:s') }}</p>
                                @else
                                    <p><span class="icon icon-date label">發布時間:</span> <span class="no-data">N/A</span></p>
                                @endif
                                <p><span class="icon icon-duration label">影片長度:</span> 
                                    @if($video->duration_secs)
                                        {{ sprintf('%02d:%02d', $videoData['formatted_duration_minutes'], $videoData['formatted_duration_seconds']) }}
                                    @else
                                        <span class="no-data">N/A</span>
                                    @endif
                                </p>
                                @if($video->restrictions)
                                <div class="info-row">
                                    <span class="info-label">限制條件：</span>
                                    <span class="info-value copyable-field" data-copy-text="{{ addslashes($video->restrictions) }}" title="點擊複製限制條件">
                                        {{ $video->restrictions }}
                                    </span>
                                </div>
                                @endif
                                @if($video->tran_restrictions)
                                <div class="info-row">
                                    <span class="info-label">限制條件(翻譯)：</span>
                                    <span class="info-value copyable-field" data-copy-text="{{ addslashes($video->tran_restrictions) }}" title="點擊複製轉檔限制">
                                        {{ $video->tran_restrictions }}
                                    </span>
                                </div>
                                @endif
                            </div>

                            <div class="card-summary-section">
                                <div class="video-player-container @if(isset($videoData['video_url']) && preg_match('/^https?:\/\/(www\.)?(youtube\.com|youtu\.be)/i', $videoData['video_url'])) has-youtube @endif">
                                    @if($videoData['video_url'])
                                        @php
                                            $videoUrl = $videoData['video_url'];
                                            $isYouTubeUrl = preg_match('/^https?:\/\/(www\.)?(youtube\.com|youtu\.be)/i', $videoUrl);
                                            $isFullUrl = preg_match('/^https?:\/\//i', $videoUrl);
                                            // For GCS proxy URLs, add ?download parameter for download link
                                            $isGcsProxy = str_contains($videoUrl, '/gcs-proxy/');
                                            $downloadUrl = $isGcsProxy ? $videoUrl . '?download' : $videoUrl;
                                        @endphp
                                        @if($isYouTubeUrl)
                                            @php
                                                // Extract YouTube video ID for embedding
                                                $youtubeId = null;
                                                if (preg_match('/(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/)([a-zA-Z0-9_-]+)/', $videoUrl, $matches)) {
                                                    $youtubeId = $matches[1];
                                                }
                                            @endphp
                                            @if($youtubeId)
                                                <iframe 
                                                    src="https://www.youtube.com/embed/{{ $youtubeId }}" 
                                                    frameborder="0" 
                                                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" 
                                                    allowfullscreen>
                                                </iframe>
                                            @else
                                                <div class="video-placeholder">
                                                    <p>無法解析 YouTube 連結</p>
                                                    <a href="{{ $videoUrl }}" target="_blank" rel="noopener noreferrer">點擊開啟 YouTube 影片</a>
                                                </div>
                                            @endif
                                        @elseif($isFullUrl)
                                            {{-- For other full URLs (direct video file URLs), use video tag --}}
                                            <div class="video-download-container">
                                                <a href="{{ $downloadUrl }}" class="video-download-btn" title="下載影片">
                                                    <span class="btn-icon">⬇</span>
                                                    <span>下載</span>
                                                </a>
                                            </div>
                                            <video controls preload="none" width="100%" height="100%" data-lazy-video>
                                                <source data-src="{{ $videoUrl }}" type="video/mp4">
                                                您的瀏覽器不支援影片播放。
                                                <a href="{{ $downloadUrl }}" target="_blank" rel="noopener noreferrer">點擊下載影片</a>
                                            </video>
                                        @else
                                            {{-- For relative paths, use video tag with /media/ prefix --}}
                                            <div class="video-download-container">
                                                <a href="{{ $downloadUrl }}" class="video-download-btn" title="下載影片">
                                                    <span class="btn-icon">⬇</span>
                                                    <span>下載</span>
                                                </a>
                                            </div>
                                            <video controls preload="none" width="100%" height="100%" data-lazy-video>
                                                <source data-src="{{ $videoUrl }}" type="video/mp4">
                                                您的瀏覽器不支援影片播放。
                                            </video>
                                        @endif
                                    @else
                                        <div class="video-placeholder">無影片預覽</div>
                                    @endif
                                </div>
                                <div class="summary-content-wrapper">
                                    @if(null !== $analysisResult && null !== $analysisResult['importance_score'])
                                        <div class="importance-section">
                                            @if(isset($analysisResult['importance_score']['key_factors']) && is_array($analysisResult['importance_score']['key_factors']))
                                                <p class="factors-title">
                                                    主要因素:
                                                </p>
                                                <ul class="copyable-field" data-copy-text="{{ addslashes(implode('; ', $analysisResult['importance_score']['key_factors'])) }}" title="點擊複製主要因素">
                                                    @foreach($analysisResult['importance_score']['key_factors'] as $factor)
                                                        <li>{{ $factor }}</li>
                                                    @endforeach
                                                </ul>
                                            @endif
                                            @if(isset($analysisResult['importance_score']['assessment_details']))
                                                <p class="details-title">
                                                    評估說明:
                                                </p>
                                                <pre class="copyable-field" data-copy-text="{{ addslashes($analysisResult['importance_score']['assessment_details']) }}" title="點擊複製評估說明">{{ $analysisResult['importance_score']['assessment_details'] }}</pre>
                                            @endif
                                        </div>
                                    @else
                                        <div class="importance-section">
                                            @if(isset($video->file_size_mb) && $video->file_size_mb > 300)
                                                <p class="no-data file-size-exceeded">⚠️ 影片檔案超過大小限制無法分析（{{ number_format($video->file_size_mb, 2) }} MB > 300 MB）</p>
                                            @else
                                            <p class="no-data">（影片內容尚未分析，無評分資訊）</p>
                                            @endif
                                        </div>
                                    @endif
                                    
                                    <div class="status-block-summary">
                                        @if(null !== $analysisResult && null !== $analysisResult['error_message'])
                                            <p>
                                                <span class="icon icon-error label">分析錯誤:</span> 
                                                <span class="status-value-error copyable-field" data-copy-text="{{ addslashes($analysisResult['error_message']) }}" title="點擊複製錯誤訊息">
                                                    {{ $analysisResult['error_message'] }}
                                                </span>
                                            </p>
                                        @endif
                                    </div>
                                </div>
                            </div>

                            <div class="video-meta-footer">
                                <p>
                                    <span class="icon icon-status label">分析狀態:</span> 
                                    <span>{{ $video->analysis_status->value }}</span>
                                </p>
                                <p>
                                    <span class="icon icon-version label">XML 檔案版本:</span> 
                                    <span>{{ $video->xml_file_version ?? 0 }}</span>
                                </p>
                                <p>
                                    <span class="icon icon-version label">MP4 檔案版本:</span> 
                                    <span>{{ $video->mp4_file_version ?? 0 }}</span>
                                </p>
                                @if(isset($video->file_size_mb) && $video->file_size_mb !== null)
                                    <p class="{{ $video->file_size_mb > 300 ? 'file-size-warning' : '' }}">
                                        <span class="icon icon-size label">檔案大小:</span> 
                                        <span>{{ number_format($video->file_size_mb, 2) }} MB</span>
                                        @if($video->file_size_mb > 300)
                                            <span class="size-warning-badge">超過限制</span>
                                        @endif
                                    </p>
                                @endif
                                @if(null !== $analysisResult && null !== $analysisResult['prompt_version'])
                                    <p class="prompt-version-info">
                                        <span class="icon icon-prompt label">影片 Prompt 版本:</span> 
                                        <span>{{ $analysisResult['prompt_version'] }}</span>
                                    </p>
                                @else
                                    <p class="prompt-version-info"><span class="icon icon-prompt label">影片 Prompt 版本:</span> <span class="no-data">N/A</span></p>
                                @endif
                                @if($video->prompt_version)
                                    <p class="prompt-version-info">
                                        <span class="icon icon-prompt label">文本 Prompt 版本:</span> 
                                        <span>{{ $video->prompt_version }}</span>
                                    </p>
                                @else
                                    <p class="prompt-version-info"><span class="icon icon-prompt label">文本 Prompt 版本:</span> <span class="no-data">N/A</span></p>
                                @endif
                            </div>

                            <div id="details-{{ $index }}" class="card-details" style="display: none;">
                                <h3><span class="icon icon-shotlist"></span>畫面 (SHOTLIST - TXT)</h3>
                                @if($videoData['shotlist_content_processed'])
                                    @php
                                        // 為複製準備：使用 htmlspecialchars 轉義，瀏覽器會自動解碼
                                        $shotlistContentCopy = htmlspecialchars($videoData['shotlist_content_processed'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                                    @endphp
                                    <pre class="copyable-field" data-copy-text="{{ $shotlistContentCopy }}" title="點擊複製畫面內容">{{ $videoData['shotlist_content_processed'] }}</pre>
                                @else
                                    <pre><span class="no-data">無</span></pre>
                                @endif
                                
                                @if(null !== $analysisResult)
                                    @if(null !== $analysisResult['visual_description'])
                                        <h3><span class="icon icon-description"></span>原始畫面描述 (AI)</h3>
                                        <pre class="copyable-field" data-copy-text="{{ addslashes($analysisResult['visual_description']) }}" title="點擊複製原始畫面描述">{{ $analysisResult['visual_description'] }}</pre>
                                    @endif
                                @endif
                                
                                <h3><span class="icon icon-location"></span>主要地點 (TXT)</h3>
                                @if($video->location)
                                    <p class="copyable-field" data-copy-text="{{ addslashes($video->location) }}" title="點擊複製主要地點">{{ $video->location }}</p>
                                @else
                                    <p><span class="no-data">無</span></p>
                                @endif
                                
                                @if(null !== $analysisResult && isset($analysisResult['video_mentioned_locations']) && is_array($analysisResult['video_mentioned_locations']))
                                    <h3><span class="icon icon-location"></span>影片中其他地點 (AI)</h3>
                                    <ul class="copyable-field" data-copy-text="{{ addslashes(implode("\n", $analysisResult['video_mentioned_locations'])) }}" title="點擊複製影片中其他地點">
                                        @foreach($analysisResult['video_mentioned_locations'] as $location)
                                            <li>{{ $location }}</li>
                                        @endforeach
                                    </ul>
                                @endif
                                
                                @if(null !== $analysisResult)
                                    @if(null !== $analysisResult['short_summary'])
                                        <h3><span class="icon icon-summary"></span>短摘要 (AI)</h3>
                                        <pre class="copyable-field" data-copy-text="{{ addslashes($analysisResult['short_summary']) }}" title="點擊複製短摘要">{{ $analysisResult['short_summary'] }}</pre>
                                    @endif
                                    
                                    @if(null !== $analysisResult['bulleted_summary'])
                                        <h3><span class="icon icon-summary"></span>列點摘要 (AI)</h3>
                                        <pre class="copyable-field" data-copy-text="{{ addslashes($analysisResult['bulleted_summary']) }}" title="點擊複製列點摘要">{{ $analysisResult['bulleted_summary'] }}</pre>
                                    @endif
                                
                                    @if(isset($analysisResult['bites']) && is_array($analysisResult['bites']) && count($analysisResult['bites']) > 0)
                                        @php
                                            $bitesText = '';
                                            foreach($analysisResult['bites'] as $bite) {
                                                $timeLine = $bite['time_line'] ?? '';
                                                $speaker = $bite['speaker'] ?? '';
                                                $quote = $bite['quote'] ?? '';
                                                $bitesText .= "[{$timeLine}] {$speaker}: \"{$quote}\"\n";
                                            }
                                        @endphp
                                        <h3><span class="icon icon-bite"></span>BITE (AI)</h3>
                                        <ul class="copyable-field" data-copy-text="{{ addslashes(trim($bitesText)) }}" title="點擊複製 BITE">
                                            @foreach($analysisResult['bites'] as $bite)
                                                <li>
                                                    <span class="label">{{ $bite['time_line'] ?? '' }}</span>
                                                    <span class="label">{{ $bite['speaker'] ?? '' }}:</span>
                                                    "{{ $bite['quote'] ?? '' }}"
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif
                                
                                    @if(isset($analysisResult['keywords']) && is_array($analysisResult['keywords']) && count($analysisResult['keywords']) > 0)
                                        @php
                                            $keywordsText = '';
                                            foreach($analysisResult['keywords'] as $keyword) {
                                                $category = $keyword['category'] ?? '';
                                                $keywordText = $keyword['keyword'] ?? '';
                                                $keywordsText .= "{$category}: {$keywordText}\n";
                                            }
                                        @endphp
                                        <h3><span class="icon icon-keywords"></span>關鍵字 (AI)</h3>
                                        <ul class="copyable-field" data-copy-text="{{ addslashes(trim($keywordsText)) }}" title="點擊複製關鍵字">
                                            @foreach($analysisResult['keywords'] as $keyword)
                                                <li><span class="label">{{ $keyword['category'] ?? '' }}:</span> {{ $keyword['keyword'] ?? '' }}</li>
                                            @endforeach
                                        </ul>
                                    @endif
                                
                                    @if(isset($analysisResult['consolidated_categories']) && is_array($analysisResult['consolidated_categories']) && count($analysisResult['consolidated_categories']) > 0)
                                        <h3><span class="icon icon-category"></span>分類/主題 (綜合 - TXT &amp; AI)</h3>
                                        <ul class="copyable-field" data-copy-text="{{ addslashes(implode("\n", $analysisResult['consolidated_categories'])) }}" title="點擊複製分類/主題">
                                            @foreach($analysisResult['consolidated_categories'] as $category)
                                                <li>{{ $category }}</li>
                                            @endforeach
                                        </ul>
                                    @elseif(isset($videoData['primary_subjects']) && is_array($videoData['primary_subjects']) && count($videoData['primary_subjects']) > 0)
                                        <h3><span class="icon icon-category"></span>分類/主題 (綜合 - TXT &amp; AI)</h3>
                                        <ul class="copyable-field" data-copy-text="{{ addslashes(implode("\n", $videoData['primary_subjects'])) }}" title="點擊複製分類/主題">
                                            @foreach($videoData['primary_subjects'] as $subject)
                                                <li>{{ $subject }}</li>
                                            @endforeach
                                        </ul>
                                    @endif
                                
                                    @if(null !== $analysisResult['material_type'])
                                        <h3><span class="icon icon-material"></span>素材類型 (AI)</h3>
                                        <p class="copyable-field" data-copy-text="{{ addslashes($analysisResult['material_type']) }}" title="點擊複製素材類型">{{ $analysisResult['material_type'] }}</p>
                                    @endif

                                    <hr style="margin: 20px 0;">
                                    
                                    @if(null !== $analysisResult['transcript'])
                                        <h3><span class="icon icon-transcript"></span>原文逐字稿 (AI)</h3>
                                        <pre class="copyable-field" data-copy-text="{{ addslashes($analysisResult['transcript']) }}" title="點擊複製原文逐字稿">{{ $analysisResult['transcript'] }}</pre>
                                    @endif
                                    
                                    @if(null !== $analysisResult['translation'])
                                        <h3><span class="icon icon-translation"></span>逐字稿翻譯 (AI)</h3>
                                        <pre class="copyable-field" data-copy-text="{{ addslashes($analysisResult['translation']) }}" title="點擊複製逐字稿翻譯">{{ $analysisResult['translation'] }}</pre>
                                    @endif
                                @else
                                    <p class="no-data">影片尚未進行內容分析 (無 AI 分析結果)</p>
                                @endif
                            </div>
                        </div>
                    @endforeach
                @else
                    <p class="no-data">目前沒有影片數據可顯示。</p>
                @endif
            </div>

            @if($videos->hasPages())
                <div class="pagination-wrapper">
                    {{ $videos->links() }}
                </div>
            @endif
        </main>
    </div>

    @include('dashboard.scripts')
</body>

</html>

