<script>
    const textAnalysisBtn = document.getElementById('triggerTextAnalysisBtn');
    const videoAnalysisBtn = document.getElementById('triggerVideoAnalysisBtn');
    const exportExcelBtn = document.getElementById('exportExcelBtn');
    const statusMessageContainer = document.getElementById('statusMessageContainer');
    const filterSortForm = document.getElementById('filterSortForm');
    const keywordSearchInput = document.getElementById('keywordSearchInput');
    const sortBySelect = document.getElementById('sortBySelect');
    const sortOrderSelect = document.getElementById('sortOrderSelect');
    const videoCardList = document.querySelector('.video-card-list');

    // 儲存所有影片卡片的原始順序
    let originalVideoCards = Array.from(videoCardList.querySelectorAll('.video-card'));

    function resetToOriginal() {
        // 重置搜尋和排序選項
        keywordSearchInput.value = '';
        sortBySelect.value = 'importance';
        sortOrderSelect.value = 'desc';

        // 重新載入頁面
        window.location.href = '{{ route("dashboard.index") }}';
    }

    // 綁定重置按鈕事件
    document.getElementById('resetFilterBtn').addEventListener('click', resetToOriginal);

    function displayStatusMessage(message, type) {
        const messageDiv = document.createElement('div');
        messageDiv.className = 'status-message status-' + type;
        messageDiv.textContent = message;
        statusMessageContainer.appendChild(messageDiv);
        setTimeout(() => {
            messageDiv.remove();
        }, 7000);
    }

    function triggerAnalysis(buttonElement, endpoint, buttonText) {
        console.log(`按鈕 '${buttonText}' 被點擊！`);
        const btn = buttonElement;
        btn.disabled = true;
        displayStatusMessage(`正在觸發 ${buttonText}，請稍候...`, 'info');
        fetch(endpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}'
            }
        })
        .then(response => {
            if (!response.ok) {
                return response.json().then(errData => {
                    throw new Error(errData.error || `HTTP 錯誤！狀態碼: ${response.status}`);
                }).catch(() => {
                    throw new Error(`HTTP 錯誤！狀態碼: ${response.status}`);
                });
            }
            return response.json();
        })
        .then(data => {
            displayStatusMessage(data.message || `${buttonText} 已成功觸發！`, 'success');
        })
        .catch(error => {
            displayStatusMessage(`錯誤 (${buttonText}): ${error.message}`, 'error');
        })
        .finally(() => {
            setTimeout(() => {
                btn.disabled = false;
            }, 10000);
        });
    }

    function exportToExcel() {
        const btn = exportExcelBtn;
        
        // 收集選中的 checkbox
        const checkedBoxes = document.querySelectorAll('.video-checkbox:checked');
        const selectedIds = Array.from(checkedBoxes).map(cb => cb.value);

        // 獲取當前的篩選和排序參數
        const searchTerm = document.getElementById('keywordSearchInput').value;
        const sortBy = document.getElementById('sortBySelect').value;
        const sortOrder = document.getElementById('sortOrderSelect').value;

        // 構建確認訊息
        let confirmMessage = '';
        if (selectedIds.length === 0) {
            confirmMessage = '未選擇任何資料，將匯出<strong>全部資料</strong>（依目前篩選與排序條件）。\n\n確定要匯出嗎？';
        } else {
            confirmMessage = `已選擇 <strong>${selectedIds.length} 筆資料</strong>，確定要匯出嗎？`;
        }

        // 顯示確認對話框
        Swal.fire({
            title: '確認匯出資料',
            html: confirmMessage,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: '確定匯出',
            cancelButtonText: '取消',
            confirmButtonColor: '#217346',
            cancelButtonColor: '#6c757d',
            reverseButtons: true
        }).then((result) => {
            if (result.isConfirmed) {
                // 顯示處理中提示
                Swal.fire({
                    title: '正在匯出...',
                    html: selectedIds.length === 0 
                        ? '正在準備匯出全部資料，請稍候...' 
                        : `正在準備匯出 ${selectedIds.length} 筆資料，請稍候...`,
                    icon: 'info',
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    showConfirmButton: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });

                btn.disabled = true;

                // 構建 URL，包含選中的 ID、篩選和排序參數
                const params = new URLSearchParams();
                if (selectedIds.length > 0) {
                    params.append('ids', selectedIds.join(','));
                }
                if (searchTerm) params.append('search', searchTerm);
                if (sortBy) params.append('sortBy', sortBy);
                if (sortOrder) params.append('sortOrder', sortOrder);

                const url = '{{ route("dashboard.export") }}?' + params.toString();

                // 使用 fetch 下載檔案
        fetch(url)
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP 錯誤！狀態碼: ${response.status}`);
                }
                return response.blob();
            })
            .then(blob => {
                        // 創建下載連結
                        const downloadUrl = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                        a.href = downloadUrl;
                        a.download = `影片分析資料_${new Date().toISOString().split('T')[0]}.xlsx`;
                document.body.appendChild(a);
                a.click();
                        window.URL.revokeObjectURL(downloadUrl);
                document.body.removeChild(a);

                        // 顯示成功提示
                        Swal.fire({
                            icon: 'success',
                            title: '匯出成功！',
                            text: selectedIds.length === 0 
                                ? '全部資料已成功匯出' 
                                : `${selectedIds.length} 筆資料已成功匯出`,
                            timer: 2000,
                            showConfirmButton: true,
                            confirmButtonText: '確定'
                        });

                        btn.disabled = false;
            })
            .catch(error => {
                        // 顯示錯誤提示
                        Swal.fire({
                            icon: 'error',
                            title: '匯出失敗',
                            text: error.message || '匯出過程中發生錯誤，請稍後再試',
                            confirmButtonText: '確定'
                        });

                btn.disabled = false;
            });
            }
        });
    }

    // 全選功能
    function selectAll() {
        const checkboxes = document.querySelectorAll('.video-checkbox');
        checkboxes.forEach(cb => cb.checked = true);
        const count = checkboxes.length;
        Swal.fire({
            icon: 'success',
            title: '已全選',
            text: `已選擇 ${count} 筆資料`,
            timer: 2000,
            showConfirmButton: false,
            toast: true,
            position: 'top-end'
        });
    }

    // 取消全選功能
    function deselectAll() {
        const checkboxes = document.querySelectorAll('.video-checkbox');
        checkboxes.forEach(cb => cb.checked = false);
        Swal.fire({
            icon: 'info',
            title: '已取消全選',
            text: '已取消所有選擇',
            timer: 2000,
            showConfirmButton: false,
            toast: true,
            position: 'top-end'
        });
    }

    // 綁定事件處理器
    if (textAnalysisBtn) {
    textAnalysisBtn.addEventListener('click', () => triggerAnalysis(textAnalysisBtn, '/manual-text-analyze', '文本元數據分析'));
    }
    if (videoAnalysisBtn) {
    videoAnalysisBtn.addEventListener('click', () => triggerAnalysis(videoAnalysisBtn, '/manual-video-analyze', '影片內容分析'));
    }
    exportExcelBtn.addEventListener('click', exportToExcel);
    
    // 綁定全選/取消全選按鈕
    const selectAllBtn = document.getElementById('selectAllBtn');
    const deselectAllBtn = document.getElementById('deselectAllBtn');
    if (selectAllBtn) {
        selectAllBtn.addEventListener('click', selectAll);
    }
    if (deselectAllBtn) {
        deselectAllBtn.addEventListener('click', deselectAll);
    }

    // 展開/收合卡片詳情
    function toggleDetails(detailsId, headerElement, event) {
        // 如果點擊的是 checkbox 或可複製欄位，不觸發展開/收合
        if (event) {
            if (event.target && (
                event.target.classList.contains('video-checkbox') ||
                event.target.closest('.copyable-field') ||
                event.target.closest('.card-checkbox-wrapper')
            )) {
                return;
            }
        }
        
        const detailsElement = document.getElementById(detailsId);
        const expandIndicator = headerElement.querySelector('.expand-indicator');
        
        if (detailsElement.style.display === 'none' || !detailsElement.style.display) {
            detailsElement.style.display = 'block';
            expandIndicator.textContent = '▲ 收起';
        } else {
            detailsElement.style.display = 'none';
            expandIndicator.textContent = '▼ 展開';
        }
    }

    // 阻止 checkbox 點擊事件冒泡到 card-header
    document.addEventListener('click', function(e) {
        if (e.target && e.target.classList.contains('video-checkbox')) {
            e.stopPropagation();
        }
    });

    // 複製到剪貼簿功能
    function copyToClipboard(text, event) {
        // 阻止事件冒泡
        if (event) {
            event.stopPropagation();
            event.preventDefault();
        }

        // 使用 Clipboard API
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(function() {
                Swal.fire({
                    icon: 'success',
                    title: '已複製',
                    text: '內容已複製到剪貼簿',
                    timer: 1500,
                    showConfirmButton: false,
                    toast: true,
                    position: 'top-end'
                });
            }).catch(function(err) {
                // 如果 Clipboard API 失敗，使用 fallback 方法
                fallbackCopyToClipboard(text);
            });
        } else {
            // Fallback 方法
            fallbackCopyToClipboard(text);
        }
    }

    // Fallback 複製方法
    function fallbackCopyToClipboard(text) {
        const textArea = document.createElement('textarea');
        textArea.value = text;
        textArea.style.position = 'fixed';
        textArea.style.left = '-999999px';
        textArea.style.top = '-999999px';
        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();
        
        try {
            const successful = document.execCommand('copy');
            if (successful) {
                Swal.fire({
                    icon: 'success',
                    title: '已複製',
                    text: '內容已複製到剪貼簿',
                    timer: 1500,
                    showConfirmButton: false,
                    toast: true,
                    position: 'top-end'
                });
            } else {
                Swal.fire({
                    icon: 'error',
                    title: '複製失敗',
                    text: '無法複製內容，請手動複製',
                    timer: 2000,
                    showConfirmButton: false,
                    toast: true,
                    position: 'top-end'
                });
            }
        } catch (err) {
            Swal.fire({
                icon: 'error',
                title: '複製失敗',
                text: '無法複製內容，請手動複製',
                timer: 2000,
                showConfirmButton: false,
                toast: true,
                position: 'top-end'
            });
        } finally {
            document.body.removeChild(textArea);
        }
    }

    // 為所有可複製欄位添加點擊事件
    document.addEventListener('click', function(e) {
        // 如果點擊的是 checkbox，不處理複製
        if (e.target.closest('.video-checkbox')) {
            return;
        }
        
        const copyableField = e.target.closest('.copyable-field');
        if (copyableField) {
            // 阻止事件冒泡，避免觸發 card-header 的展開/收合
            e.stopPropagation();
            e.preventDefault();
            
            const copyText = copyableField.getAttribute('data-copy-text');
            if (copyText) {
                copyToClipboard(copyText, e);
            }
        }
    });
</script>

