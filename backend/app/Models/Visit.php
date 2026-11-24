<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Visit extends Model
{
    use HasFactory;

    protected $fillable = [
        'place_id',
        'user_id',
        'visited_at',
        'rating',
        'total_price',
        'notes',
        'companions',
    ];

    protected function casts(): array
    {
        return [
            'visited_at' => 'date:Y-m-d',
            'rating' => 'integer',
            'total_price' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (Visit $visit): void {
            foreach ($visit->photos()->pluck('path') as $path) {
                if ($path && Storage::disk('public')->exists($path)) {
                    Storage::disk('public')->delete($path);
                }
            }
        });
    }

    public function place(): BelongsTo
    {
        return $this->belongsTo(Place::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function dishes(): HasMany
    {
        return $this->hasMany(VisitDish::class)->orderBy('id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(Photo::class)->orderBy('id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'visit_tags');
    }
}

