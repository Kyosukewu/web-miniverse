<!DOCTYPE html>
<html lang="zh-Hant">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MINIVERSE - 影片狀態列表</title>
    @include('dashboard.styles')
    <style>
        .status-page {
            padding: 20px;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .page-header h1 {
            margin: 0;
            font-size: 24px;
            color: #333;
        }

        .nav-link {
            display: inline-block;
            padding: 8px 16px;
            background-color: #007bff;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            margin-left: 10px;
        }

        .nav-link:hover {
            background-color: #0056b3;
        }

        .filter-section {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .filter-form {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: flex-end;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            min-width: 200px;
        }

        .filter-group label {
            font-weight: 500;
            margin-bottom: 5px;
            color: #555;
        }

        .filter-group input,
        .filter-group select {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }

        .filter-buttons {
            display: flex;
            gap: 10px;
        }

        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
            display: inline-block;
        }

        .btn-primary {
            background-color: #007bff;
            color: white;
        }

        .btn-primary:hover {
            background-color: #0056b3;
        }

        .btn-secondary {
            background-color: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background-color: #545b62;
        }

        .status-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            border-radius: 8px;
            overflow: hidden;
        }

        .status-table thead {
            background-color: #343a40;
            color: white;
        }

        .status-table th {
            padding: 12px;
            text-align: left;
            font-weight: 600;
            border-bottom: 2px solid #dee2e6;
        }

        .status-table td {
            padding: 12px;
            border-bottom: 1px solid #dee2e6;
        }

        .status-table tbody tr:hover {
            background-color: #f8f9fa;
        }

        .status-table tbody tr:last-child td {
            border-bottom: none;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
        }

        .status-completed {
            background-color: #28a745;
            color: white;
        }

        .status-processing {
            background-color: #ffc107;
            color: #333;
        }

        .status-pending {
            background-color: #6c757d;
            color: white;
        }

        .status-failed {
            background-color: #dc3545;
            color: white;
        }

        .status-parsed {
            background-color: #17a2b8;
            color: white;
        }

        .status-synced {
            background-color: #007bff;
            color: white;
        }

        .status-updated {
            background-color: #6c757d;
            color: white;
        }

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin-top: 20px;
        }

        .pagination a,
        .pagination span {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            text-decoration: none;
            color: #007bff;
        }

        .pagination .active {
            background-color: #007bff;
            color: white;
            border-color: #007bff;
        }

        .pagination .disabled {
            color: #6c757d;
            cursor: not-allowed;
        }

        .text-center {
            text-align: center;
        }

        .text-truncate {
            max-width: 300px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
    </style>
</head>

<body>
    <div class="page-container">
        <div class="status-page">
            <div class="page-header">
                <h1>MINIVERSE - 影片狀態列表</h1>
                <div>
                    <a href="{{ route('dashboard.index') }}" class="nav-link">返回儀表板</a>
                </div>
            </div>

            <div class="filter-section">
                <form method="GET" action="{{ route('dashboard.status') }}" class="filter-form">
                    <div class="filter-group">
                        <label for="search">搜尋</label>
                        <input type="text" id="search" name="search" value="{{ $searchTerm }}" placeholder="ID, 來源ID, 標題...">
                    </div>
                    <div class="filter-group">
                        <label for="source">來源</label>
                        <select id="source" name="source">
                            <option value="">全部</option>
                            <option value="CNN" {{ $sourceName === 'CNN' ? 'selected' : '' }}>CNN</option>
                            <option value="AP" {{ $sourceName === 'AP' ? 'selected' : '' }}>AP</option>
                            <option value="RT" {{ $sourceName === 'RT' ? 'selected' : '' }}>RT</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="published_from">發布時間（起）</label>
                        <input type="date" id="published_from" name="published_from" value="{{ $publishedFrom }}">
                    </div>
                    <div class="filter-group">
                        <label for="published_to">發布時間（迄）</label>
                        <input type="date" id="published_to" name="published_to" value="{{ $publishedTo }}">
                    </div>
                    <div class="filter-group">
                        <label for="sortBy">排序依據</label>
                        <select id="sortBy" name="sortBy">
                            <option value="id" {{ $sortBy === 'id' ? 'selected' : '' }}>ID</option>
                            <option value="source_name" {{ $sortBy === 'source_name' ? 'selected' : '' }}>來源</option>
                            <option value="source_id" {{ $sortBy === 'source_id' ? 'selected' : '' }}>來源ID</option>
                            <option value="fetched_at" {{ $sortBy === 'fetched_at' ? 'selected' : '' }}>擷取時間</option>
                            <option value="analysis_status" {{ $sortBy === 'analysis_status' ? 'selected' : '' }}>分析狀態</option>
                            <option value="sync_status" {{ $sortBy === 'sync_status' ? 'selected' : '' }}>同步狀態</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="sortOrder">排序順序</label>
                        <select id="sortOrder" name="sortOrder">
                            <option value="desc" {{ $sortOrder === 'desc' ? 'selected' : '' }}>降冪</option>
                            <option value="asc" {{ $sortOrder === 'asc' ? 'selected' : '' }}>升冪</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <div class="filter-buttons">
                            <button type="submit" class="btn btn-primary">搜尋</button>
                            <a href="{{ route('dashboard.status') }}" class="btn btn-secondary">重置</a>
                        </div>
                    </div>
                </form>
            </div>

            <div style="overflow-x: auto;">
                <table class="status-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>來源</th>
                            <th>來源ID</th>
                            <th>標題</th>
                            <th>XML版本</th>
                            <th>MP4版本</th>
                            <th>擷取時間</th>
                            <th>發布時間</th>
                            <th>分析狀態</th>
                            <th>同步狀態</th>
                        </tr>
                    </thead>
                    <tbody>
                        @if($videos->count() > 0)
                            @foreach($videos as $video)
                                <tr>
                                    <td>{{ $video->id }}</td>
                                    <td>{{ $video->source_name }}</td>
                                    <td class="text-truncate" title="{{ $video->source_id }}">{{ $video->source_id }}</td>
                                    <td class="text-truncate" title="{{ $video->title ?? 'N/A' }}">{{ $video->title ?? 'N/A' }}</td>
                                    <td class="text-center">{{ $video->xml_file_version ?? '-' }}</td>
                                    <td class="text-center">{{ $video->mp4_file_version ?? '-' }}</td>
                                    <td>{{ \App\Helpers\DashboardHelper::formatDateTimeToUtc8($video->fetched_at) }}</td>
                                    <td>{{ \App\Helpers\DashboardHelper::formatDateTimeToUtc8($video->published_at) }}</td>
                                    <td>
                                        @php
                                            $analysisStatus = $video->analysis_status->value ?? 'pending';
                                            $statusClass = match($analysisStatus) {
                                                'completed' => 'status-completed',
                                                'processing', 'metadata_extracting', 'metadata_extracted' => 'status-processing',
                                                'pending' => 'status-pending',
                                                'failed', 'txt_analysis_failed', 'video_analysis_failed' => 'status-failed',
                                                default => 'status-pending',
                                            };
                                        @endphp
                                        <span class="status-badge {{ $statusClass }}">{{ $analysisStatus }}</span>
                                    </td>
                                    <td>
                                        @php
                                            $syncStatus = $video->sync_status ?? 'N/A';
                                            $syncClass = match($syncStatus) {
                                                'parsed' => 'status-parsed',
                                                'synced' => 'status-synced',
                                                'updated' => 'status-updated',
                                                default => 'status-pending',
                                            };
                                        @endphp
                                        <span class="status-badge {{ $syncClass }}">{{ $syncStatus }}</span>
                                    </td>
                                </tr>
                            @endforeach
                        @else
                            <tr>
                                <td colspan="10" class="text-center" style="padding: 40px;">
                                    沒有找到任何記錄
                                </td>
                            </tr>
                        @endif
                    </tbody>
                </table>
            </div>

            @if($videos->hasPages())
                <div class="pagination">
                    @if($videos->onFirstPage())
                        <span class="disabled">上一頁</span>
                    @else
                        <a href="{{ $videos->previousPageUrl() }}">上一頁</a>
                    @endif

                    @php
                        $currentPage = $videos->currentPage();
                        $lastPage = $videos->lastPage();
                        $startPage = max(1, $currentPage - 2);
                        $endPage = min($lastPage, $currentPage + 2);
                    @endphp

                    @if($startPage > 1)
                        <a href="{{ $videos->url(1) }}">1</a>
                        @if($startPage > 2)
                            <span class="disabled">...</span>
                        @endif
                    @endif

                    @for($page = $startPage; $page <= $endPage; $page++)
                        @if($page == $currentPage)
                            <span class="active">{{ $page }}</span>
                        @else
                            <a href="{{ $videos->url($page) }}">{{ $page }}</a>
                        @endif
                    @endfor

                    @if($endPage < $lastPage)
                        @if($endPage < $lastPage - 1)
                            <span class="disabled">...</span>
                        @endif
                        <a href="{{ $videos->url($lastPage) }}">{{ $lastPage }}</a>
                    @endif

                    @if($videos->hasMorePages())
                        <a href="{{ $videos->nextPageUrl() }}">下一頁</a>
                    @else
                        <span class="disabled">下一頁</span>
                    @endif
                </div>
            @endif

            <div style="margin-top: 20px; color: #6c757d; font-size: 14px;">
                總共 {{ $videos->total() }} 筆記錄，顯示第 {{ $videos->firstItem() ?? 0 }} - {{ $videos->lastItem() ?? 0 }} 筆
            </div>
        </div>
    </div>
</body>

</html>

