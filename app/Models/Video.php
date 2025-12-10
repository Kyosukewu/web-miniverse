<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AnalysisStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Video extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'videos';

    /** @var bool */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'source_name',
        'source_id',
        'nas_path',
        'xml_file_version',
        'mp4_file_version',
        'title',
        'fetched_at',
        'published_at',
        'duration_secs',
        'shotlist_content',
        'view_link',
        'subjects',
        'location',
        'restrictions',
        'tran_restrictions',
        'prompt_version',
        'analysis_status',
        'analyzed_at',
        'source_metadata',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'fetched_at' => 'datetime',
        'published_at' => 'datetime',
        'analyzed_at' => 'datetime',
        'subjects' => 'array',
        'source_metadata' => 'array',
        'analysis_status' => AnalysisStatus::class,
    ];

    /**
     * Get the analysis result for the video.
     *
     * @return HasOne<AnalysisResult>
     */
    public function analysisResult(): HasOne
    {
        return $this->hasOne(AnalysisResult::class, 'video_id');
    }
}

