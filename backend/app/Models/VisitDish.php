<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class VisitDish extends Model
{
    use HasFactory;

    protected $fillable = [
        'visit_id',
        'name',
        'price',
        'rating',
        'is_favorite',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'rating' => 'integer',
            'is_favorite' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (VisitDish $dish): void {
            foreach ($dish->photos()->pluck('path') as $path) {
                if ($path && Storage::disk('public')->exists($path)) {
                    Storage::disk('public')->delete($path);
                }
            }
        });
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(Photo::class, 'visit_dish_id')->orderBy('id');
    }
}

