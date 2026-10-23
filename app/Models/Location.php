<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class Location extends Model
{
    protected $fillable = [
        'psgc_code',
        'barangay',
        'city_municipality',
        'province',
        'full_address',
        'is_city',
    ];

    protected function casts(): array
    {
        return [
            'is_city' => 'boolean',
        ];
    }

    public function getLabelAttribute(): string
    {
        return $this->full_address;
    }

    public function scopeSearch(Builder $query, string $term, int $limit = 20): Collection
    {
        $term = trim($term);

        if ($term === '') {
            return collect();
        }

        $needle = '%'.strtoupper($term).'%';
        $starts = strtoupper($term).'%';

        return $query
            ->where(function (Builder $inner) use ($needle) {
                $inner->whereRaw('UPPER(barangay) LIKE ?', [$needle])
                    ->orWhereRaw('UPPER(city_municipality) LIKE ?', [$needle])
                    ->orWhereRaw('UPPER(full_address) LIKE ?', [$needle]);
            })
            ->orderByRaw('CASE WHEN UPPER(barangay) LIKE ? THEN 0 ELSE 1 END', [$starts])
            ->orderBy('barangay')
            ->orderBy('city_municipality')
            ->limit($limit)
            ->get();
    }
}
