<?php

declare(strict_types=1);

namespace App\Enums;

enum AnalysisStatus: string
{
    case PENDING = 'pending';
    case METADATA_EXTRACTING = 'metadata_extracting';
    case METADATA_EXTRACTED = 'metadata_extracted';
    case TXT_ANALYSIS_FAILED = 'txt_analysis_failed';
    case PROCESSING = 'processing';
    case VIDEO_ANALYSIS_FAILED = 'video_analysis_failed';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
}

