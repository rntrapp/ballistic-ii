<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

final class CognitiveProfile extends Model
{
    /** @use HasFactory<\Database\Factories\CognitiveProfileFactory> */
    use HasFactory;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'user_id',
        'dominant_period_seconds',
        'phase_anchor_at',
        'amplitude',
        'confidence',
        'sample_count',
        'computed_at',
    ];

    protected $casts = [
        'dominant_period_seconds' => 'float',
        'amplitude' => 'float',
        'confidence' => 'float',
        'sample_count' => 'integer',
        'phase_anchor_at' => 'datetime:Y-m-d H:i:s.u',
        'computed_at' => 'datetime',
    ];

    #[\Override]
    protected static function boot(): void
    {
        parent::boot();

        self::creating(function ($model): void {
            if (empty($model->id)) {
                $model->id = Str::uuid();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
