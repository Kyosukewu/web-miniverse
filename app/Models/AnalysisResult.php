<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnalysisResult extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'analysis_results';

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = true;

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'video_id';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'video_id',
        'transcript',
        'translation',
        'short_summary',
        'bulleted_summary',
        'bites',
        'mentioned_locations',
        'importance_score',
        'importance_rating',
        'material_type',
        'related_news',
        'visual_description',
        'topics',
        'keywords',
        'error_message',
        'prompt_version',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'bites' => 'array',
        'mentioned_locations' => 'array',
        'importance_score' => 'array',
        'related_news' => 'array',
        'topics' => 'array',
        'keywords' => 'array',
    ];

    /**
     * Get the video that owns the analysis result.
     *
     * @return BelongsTo<Video, AnalysisResult>
     */
    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class, 'video_id');
    }

    /**
     * Get overall rating (numeric 1-5) from importance_rating or importance_score.
     *
     * @return int|null
     */
    public function getOverallRatingAttribute(): ?int
    {
        // First try to get from importance_rating column
        if (null !== $this->importance_rating) {
            return $this->importance_rating;
        }

        // Fallback to importance_score JSON for backward compatibility
        if (null !== $this->importance_score) {
            $importanceScore = is_array($this->importance_score) 
                ? $this->importance_score 
                : json_decode($this->importance_score, true);
            
            $oldRating = $importanceScore['overall_rating'] ?? null;
            
            if (is_int($oldRating) && $oldRating >= 1 && $oldRating <= 5) {
                return $oldRating;
            }
            
            // Handle legacy string format (S/A/B/C/N) - deprecated
            if (is_string($oldRating)) {
                return match (strtoupper(trim($oldRating))) {
                    'S' => 5,
                    'A' => 4,
                    'B' => 3,
                    'C' => 2,
                    'N' => 1,
                    default => null,
                };
            }
        }

        return null;
    }

    /**
     * Get overall rating letter (S/A/B/C/N) for display.
     *
     * @return string
     */
    public function getOverallRatingLetterAttribute(): string
    {
        $rating = $this->overall_rating;
        
        if (null === $rating) {
            return '';
        }

        return match ($rating) {
            5 => 'S',
            4 => 'A',
            3 => 'B',
            2 => 'C',
            1 => 'N',
            default => '',
        };
    }
}

