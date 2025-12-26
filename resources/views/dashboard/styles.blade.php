<style>
    body {
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, "Noto Sans", sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol", "Noto Color Emoji";
        margin: 0;
        padding: 0;
        background-color: #f0f2f5;
        color: #333;
        line-height: 1.6;
    }

    .page-container {
        display: flex;
        max-width: 100%;
        min-height: 100vh;
    }

    .sidebar {
        width: 280px;
        min-width: 250px;
        max-width: 320px;
        background-color: #ffffff;
        padding: 20px 15px;
        height: 100vh;
        overflow-y: auto;
        box-shadow: 2px 0 8px rgba(0, 0, 0, 0.1);
        position: sticky;
        top: 0;
        border-right: 1px solid #e0e0e0;
        flex-shrink: 0;
    }

    .sidebar h3 {
        font-size: 1.2em;
        color: #007bff;
        margin-top: 0;
        margin-bottom: 12px;
        border-bottom: 2px solid #007bff;
        padding-bottom: 8px;
    }

    .control-panel {
        margin-bottom: 20px;
        padding: 12px;
        background-color: #f8f9fa;
        border-radius: 6px;
        border: 1px solid #e9ecef;
    }

    .control-btn {
        width: 100%;
        padding: 8px 12px;
        margin-bottom: 8px;
        border: none;
        border-radius: 5px;
        font-size: 0.85em;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.2s ease;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
    }

    .control-btn .btn-icon {
        font-size: 1em;
        line-height: 1;
    }

    .control-btn:last-child {
        margin-bottom: 0;
    }

    .control-btn.primary {
        background-color: #007bff;
        color: white;
    }

    .control-btn.primary:hover {
        background-color: #0056b3;
        transform: translateY(-1px);
    }

    .control-btn.secondary {
        background-color: #ffc107;
        color: #212529;
    }

    .control-btn.secondary:hover {
        background-color: #e0a800;
        transform: translateY(-1px);
    }

    #deselectAllBtn {
        background-color: #fd7e14;
        color: white;
    }

    #deselectAllBtn:hover {
        background-color: #e66a00;
        transform: translateY(-1px);
    }

    .control-btn.export-btn {
        background-color: #217346;
        color: white;
    }

    .control-btn.export-btn:hover {
        background-color: #1d6f42;
        transform: translateY(-1px);
    }

    .control-btn:disabled {
        background-color: #ced4da;
        cursor: not-allowed;
        transform: none;
    }

    .filter-group {
        margin-bottom: 15px;
    }

    .filter-group label {
        display: block;
        margin-bottom: 5px;
        font-weight: 600;
        font-size: 0.9em;
        color: #343a40;
    }

    .filter-group input[type="text"],
    .filter-group input[type="date"],
    .filter-group select {
        width: 100%;
        padding: 8px;
        border: 1px solid #ced4da;
        border-radius: 4px;
        font-size: 0.85em;
        margin-bottom: 8px;
        box-sizing: border-box;
    }

    .filter-buttons {
        display: flex;
        gap: 8px;
        width: 100%;
    }

    .filter-buttons button {
        flex: 1;
        padding: 8px 12px;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-size: 0.9em;
        font-weight: 500;
        transition: all 0.2s ease;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
    }

    .filter-buttons .btn-icon {
        font-size: 1em;
        line-height: 1;
        font-weight: bold;
    }

    .filter-group button.apply-btn {
        background-color: #5cb85c;
        color: white;
    }

    .filter-group button.apply-btn:hover {
        background-color: #4cae4c;
        transform: translateY(-1px);
    }

    .filter-group button.cancel-btn {
        background-color: #95a5a6;
        color: white;
    }

    .filter-group button.cancel-btn:hover {
        background-color: #7f8c8d;
        transform: translateY(-1px);
    }

    #statusMessageContainer {
        display: flex;
        flex-direction: column;
        gap: 8px;
        margin-top: 10px;
        width: 100%;
    }

    .status-message {
        padding: 10px 15px;
        border-radius: 4px;
        font-size: 0.9em;
        display: none;
        margin-bottom: 20px;
    }

    .status-success {
        background-color: #d1e7dd;
        color: #0f5132;
        border: 1px solid #badbcc;
        display: block;
    }

    .status-error {
        background-color: #f8d7da;
        color: #842029;
        border: 1px solid #f5c2c7;
        display: block;
    }

    .status-info {
        background-color: #cff4fc;
        color: #055160;
        border: 1px solid #b6effb;
        display: block;
    }

    .video-card-list {
        display: flex;
        flex-direction: column;
        gap: 25px;
    }

    .video-card {
        background-color: #ffffff;
        border-radius: 8px;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        margin-bottom: 20px;
        overflow: hidden;
        transition: all 0.3s ease;
        position: relative;
    }

    .card-header {
        position: relative;
    }

    .card-checkbox-wrapper {
        position: absolute;
        top: 50%;
        left: 15px;
        transform: translateY(-50%);
        z-index: 10;
        padding: 5px;
        background-color: rgba(255, 255, 255, 0.9);
        border-radius: 4px;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .video-checkbox {
        width: 20px;
        height: 20px;
        cursor: pointer;
        accent-color: #007bff;
        pointer-events: auto;
        margin: 0;
    }

    .card-checkbox-wrapper:hover {
        z-index: 11;
    }

    .card-checkbox-wrapper:hover .video-checkbox {
        transform: scale(1.1);
        transition: transform 0.2s ease;
    }

    .checkbox-label {
        display: none;
    }

    .checkbox-controls {
        display: flex;
        gap: 10px;
        margin-bottom: 10px;
    }

    .checkbox-controls .control-btn {
        flex: 1;
        margin-bottom: 0;
    }

    .video-card.importance-s {
        background-color: #f3e5f5;
        border-left: 5px solid #9c27b0;
    }

    .video-card.importance-a {
        background-color: #d4edda;
        border-left: 5px solid #28a745;
    }

    .video-card.importance-b {
        background-color: #fff3cd;
        border-left: 5px solid #ffc107;
    }

    .video-card.importance-c {
        background-color: #ffe5d0;
        border-left: 5px solid #fd7e14;
    }

    .video-card.importance-n {
        background-color: #e9ecef;
        border-left: 5px solid #adb5bd;
    }

    .video-card.importance-unknown {
        background-color: #e2e3e5;
        border-left: 5px solid #6c757d;
    }

    .video-card:hover {
        box-shadow: 0 6px 16px rgba(0, 0, 0, 0.12);
    }

    .card-header {
        padding: 15px 20px;
        background-color: #ffffff;
        border-bottom: 1px solid #e0e0e0;
        cursor: pointer;
        display: flex;
        justify-content: space-between;
        align-items: center;
        transition: background-color 0.3s ease;
    }

    .card-title-section {
        display: flex;
        align-items: center;
        flex-grow: 1;
        overflow: hidden;
        margin-left: 50px;
    }

    .card-header .rating-badge {
        font-size: 1.2em;
        font-weight: bold;
        color: #fff;
        background-color: #6c757d;
        border-radius: 5px;
        padding: 5px 10px;
        margin-right: 15px;
        line-height: 1;
        flex-shrink: 0;
        min-width: 32px;
        text-align: center;
    }

    .card-header .rating-badge.rating-s {
        background-color: #9c27b0 !important;
        color: #fff !important;
    }

    .card-header .rating-badge.rating-a {
        background-color: #28a745 !important;
        color: #fff !important;
    }

    .card-header .rating-badge.rating-b {
        background-color: #ffc107 !important;
        color: #000 !important;
    }

    .card-header .rating-badge.rating-c {
        background-color: #fd7e14 !important;
        color: #fff !important;
    }

    .card-header .rating-badge.rating-n {
        background-color: #adb5bd !important;
        color: #fff !important;
    }

    .card-header .rating-badge.no-data {
        background-color: #6c757d !important;
        color: #fff !important;
        font-size: 1em;
        padding: 5px 8px;
    }

    .card-header .source-badge {
        font-size: 1.2em;
        font-weight: bold;
        color: #fff;
        background-color: #17a2b8;
        border-radius: 5px;
        padding: 5px 10px;
        margin-right: 15px;
        line-height: 1;
        flex-shrink: 0;
        min-width: 32px;
        text-align: center;
        text-transform: uppercase;
    }

    .card-header h2 {
        margin: 0;
        color: #0056b3;
        font-size: 1.7em;
        font-weight: 600;
        word-break: break-all;
        white-space: normal;
        overflow: visible;
        text-overflow: clip;
        margin-right: 10px;
        line-height: 1.2;
    }

    .card-short-summary {
        padding: 12px 20px;
        background-color: #f8f9fa;
        border-bottom: 1px solid #e0e0e0;
    }

    .card-short-summary .summary-content {
        margin: 0;
        color: #212529;
        font-size: 0.9em;
        line-height: 1.5;
        word-break: break-word;
    }

    .flag-icon {
        font-size: 1.6em;
        margin-left: 0;
        vertical-align: middle;
        flex-shrink: 0;
    }

    .expand-indicator {
        font-size: 1.2em;
        color: #007bff;
        margin-left: 15px;
        flex-shrink: 0;
        transition: transform 0.3s ease-in-out;
    }

    .card-identity-meta {
        padding: 8px 20px 10px 20px;
        background-color: #ffffff;
        border-bottom: 1px solid #e0e0e0;
        display: flex;
        flex-wrap: wrap;
        gap: 8px 20px;
        font-size: 0.85em;
        color: #495057;
    }

    .card-identity-meta p {
        margin: 0;
    }

    .card-summary-section {
        padding: 20px;
        display: flex;
        flex-wrap: nowrap;
        gap: 20px;
        border-bottom: 1px solid #e9ecef;
        align-items: flex-start;
    }

    .video-player-container {
        flex: 0 0 300px;
        min-width: 280px;
        background-color: #111;
        border-radius: 6px;
        overflow: hidden;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        position: relative;
    }

    .video-download-container {
        position: absolute;
        top: 10px;
        right: 10px;
        z-index: 10;
    }

    .video-download-btn {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 8px 12px;
        background-color: #007bff;
        color: white;
        text-decoration: none;
        border-radius: 5px;
        font-size: 0.85em;
        font-weight: 500;
        transition: all 0.2s ease;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
    }

    .video-download-btn:hover {
        background-color: #0056b3;
        transform: translateY(-1px);
        box-shadow: 0 3px 6px rgba(0, 0, 0, 0.3);
        color: white;
        text-decoration: none;
    }

    .video-download-btn .btn-icon {
        font-size: 1em;
        line-height: 1;
    }

    /* Responsive YouTube embed container (16:9 aspect ratio) */
    .video-player-container.has-youtube::before {
        content: "";
        display: block;
        padding-bottom: 56.25%; /* 16:9 aspect ratio */
    }

    .video-player-container iframe {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        display: block;
        border: none;
    }

    .video-player-container video {
        width: 100%;
        height: 100%;
        display: block;
    }

    .video-placeholder {
        width: 100%;
        height: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
        background-color: #e9ecef;
        color: #6c757d;
        font-style: italic;
        border-radius: 6px;
    }

    .summary-content-wrapper {
        flex-grow: 1;
        display: flex;
        flex-direction: column;
        gap: 10px;
        min-width: 250px;
    }

    .importance-section {
        background-color: #fff9e0;
        padding: 15px;
        border-radius: 6px;
        border: 1px solid #ffeeba;
    }

    .importance-section .factors-title,
    .importance-section .details-title {
        font-weight: bold;
        color: #495057;
        margin-top: 10px;
        margin-bottom: 5px;
        font-size: 1em;
    }

    .importance-section ul {
        list-style-type: "★ ";
        list-style-position: outside;
        padding-left: 30px;
        margin-left: 0;
        margin-bottom: 8px;
        overflow-x: visible;
    }

    .importance-section ul li {
        padding-left: 8px;
        margin-left: 0;
        margin-bottom: 5px;
    }

    .importance-section pre {
        background-color: #fffdf5;
        border-color: #ffefc6;
        max-height: 80px;
        overflow-y: auto;
        white-space: pre-wrap;
        word-break: break-all;
    }

    .status-block-summary {
        font-size: 0.85em;
        color: #6c757d;
        margin-top: 10px;
    }

    .status-block-summary p {
        margin: 5px 0;
        font-size: 0.9em;
        word-break: break-word;
    }

    .status-block-summary .label {
        font-weight: 600;
        color: #343a40;
        margin-right: 5px;
    }

    .status-value-error {
        color: #c82333;
        font-weight: bold;
    }

    .video-meta-footer {
        padding: 10px 20px;
        border-top: 1px solid #e9ecef;
        background-color: #fdfdff;
        display: flex;
        flex-wrap: wrap;
        gap: 10px 25px;
        justify-content: flex-start;
        font-size: 0.85em;
        color: #495057;
    }

    .video-meta-footer p {
        margin: 3px 0;
    }

    .card-details {
        padding: 20px;
        display: none;
        background-color: #ffffff;
        border-top: 1px solid #e9ecef;
    }

    .card-details.expanded {
        display: block;
    }

    .card-details h3 {
        margin-top: 18px;
        margin-bottom: 8px;
        color: #0056b3;
        font-size: 1.15em;
        border-bottom: 1px solid #dee2e6;
        padding-bottom: 5px;
        display: flex;
        align-items: center;
    }

    .card-details h3:first-child {
        margin-top: 0;
    }

    .card-details h3 .icon {
        margin-right: 8px;
    }

    .card-details pre {
        font-size: 0.9em;
        max-height: 180px;
        overflow-y: auto;
        white-space: pre-wrap !important;
        word-break: break-all;
        overflow-wrap: break-word;
        overflow-x: hidden;
        background-color: #f8f9fa;
        padding: 10px;
        border-radius: 4px;
        border: 1px solid #e0e0e0;
        margin: 5px 0;
    }

    .card-details ul {
        list-style-type: disc;
        list-style-position: outside;
        padding-left: 30px;
        margin-left: 0;
        margin-right: 0;
        max-height: 180px;
        overflow-y: auto;
        overflow-x: visible;
        word-break: break-word;
    }

    .card-details ul li {
        padding-left: 8px;
        margin-left: 0;
        margin-bottom: 8px;
        padding-right: 0;
    }

    .no-data {
        color: #868e96;
        font-style: italic;
    }

    .no-data.file-size-exceeded {
        color: #e67700;
        font-weight: 500;
        background-color: #fff3e0;
        padding: 8px 12px;
        border-radius: 4px;
        border-left: 3px solid #ff9800;
        font-style: normal;
    }

    .file-size-warning {
        color: #e67700;
        font-weight: 500;
    }

    .size-warning-badge {
        display: inline-block;
        background-color: #ff9800;
        color: white;
        padding: 2px 8px;
        border-radius: 12px;
        font-size: 0.75em;
        font-weight: 600;
        margin-left: 6px;
        vertical-align: middle;
    }

    .label {
        font-weight: bold;
    }

    .prompt-version-info {
        font-size: 0.8em;
        color: #6c757d;
    }

    .icon::before {
        margin-right: 6px;
        font-family: "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol";
    }

    .icon-id::before { content: "🆔"; }
    .icon-date::before { content: "📅"; }
    .icon-status::before { content: "📊"; }
    .icon-version::before { content: "🔢"; }
    .icon-prompt::before { content: "💬"; }
    .icon-size::before { content: "💾"; }
    .icon-error::before { content: "❗ "; color: #c82333; }
    .icon-duration::before { content: "⏱️"; }
    .icon-shotlist::before { content: "🎬"; }
    .icon-location::before { content: "📍"; }
    .icon-category::before { content: "🏷️"; }
    .icon-link::before { content: "🔗"; }
    .icon-summary::before { content: "📄 "; }
    .icon-bite::before { content: "🎤 "; }
    .icon-keywords::before { content: "🔑 "; }
    .icon-material::before { content: "📦 "; }
    .icon-news::before { content: "📰 "; }
    .icon-transcript::before { content: "📜 "; }
    .icon-translation::before { content: "🌐 "; }
    .icon-description::before { content: "🖼️ "; }
    .icon-rating::before { content: "⭐"; }

    .info-row {
        margin-bottom: 10px;
    }

    /* 在 card-identity-meta 中的 info-row 佔滿整行，固定在第二排 */
    .card-identity-meta .info-row {
        flex-basis: 100%;
        width: 100%;
    }

    .info-label {
        font-weight: bold;
    }

    .info-value {
        margin-left: 10px;
    }

    .main-content {
        flex-grow: 1;
        padding: 30px;
        overflow-y: auto;
        background-color: #f0f2f5;
    }

    .main-content h1 {
        margin: 0 0 25px 0;
        color: #2c3e50;
        font-size: 2.2em;
        font-weight: 600;
        padding-bottom: 15px;
        border-bottom: 2px solid #e0e0e0;
    }

    .pagination-wrapper {
        margin-top: 30px;
        padding: 20px 0;
        display: flex;
        justify-content: center;
        align-items: center;
    }

    .pagination {
        display: flex;
        list-style: none;
        padding: 0;
        margin: 0;
        gap: 5px;
    }

    .pagination li {
        margin: 0;
    }

    .pagination a,
    .pagination span {
        display: block;
        padding: 8px 12px;
        text-decoration: none;
        color: #007bff;
        border: 1px solid #dee2e6;
        border-radius: 4px;
        transition: all 0.2s ease;
    }

    .pagination a:hover {
        background-color: #e9ecef;
        border-color: #007bff;
    }

    .pagination .active span {
        background-color: #007bff;
        color: #fff;
        border-color: #007bff;
        cursor: default;
    }

    .pagination .disabled span {
        color: #6c757d;
        background-color: #fff;
        border-color: #dee2e6;
        cursor: not-allowed;
        opacity: 0.6;
    }

    .copyable-field {
        cursor: pointer;
        transition: all 0.2s ease;
        position: relative;
    }

    .copyable-field:hover {
        background-color: #f0f8ff;
        border-radius: 4px;
    }

    .copyable-field::after {
        content: '📋';
        opacity: 0;
        position: absolute;
        right: 5px;
        top: 5px;
        font-size: 12px;
        transition: opacity 0.2s ease;
        pointer-events: none;
    }

    .copyable-field:hover::after {
        opacity: 0.6;
    }

    /* 針對不同類型的元素調整樣式 */
    .card-header h2.copyable-field {
        padding: 5px 25px 5px 5px;
        border-radius: 4px;
        display: inline-block;
        margin: 0;
    }

    .card-header h2.copyable-field:hover {
        background-color: #e3f2fd;
    }

    .card-short-summary .summary-content.copyable-field {
        padding: 10px 25px 10px 10px;
    }

    .info-row .info-value.copyable-field {
        padding: 2px 20px 2px 5px;
        display: inline-block;
    }

    .importance-section ul.copyable-field,
    .importance-section pre.copyable-field {
        padding: 10px 25px 10px 30px;
    }

    .status-value-error.copyable-field {
        padding: 2px 20px 2px 5px;
        display: inline-block;
    }

    .video-meta-footer .copyable-field {
        padding: 2px 20px 2px 5px;
        display: inline-block;
    }

    .card-details pre.copyable-field,
    .card-details p.copyable-field {
        padding: 10px 25px 10px 10px;
    }

    .card-details ul.copyable-field {
        padding: 10px 25px 10px 30px;
    }
</style>

