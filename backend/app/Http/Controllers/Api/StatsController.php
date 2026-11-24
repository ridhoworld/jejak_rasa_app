<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Place;
use App\Models\Visit;
use App\Models\VisitDish;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class StatsController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $totalPlaces = Place::where('user_id', $userId)->where('is_wishlist', false)->count();
        $totalWishlist = Place::where('user_id', $userId)->where('is_wishlist', true)->count();

        $visits = Visit::where('user_id', $userId)
            ->orderByDesc('visited_at')
            ->get(['id', 'place_id', 'visited_at', 'rating', 'total_price']);

        $totalVisits = $visits->count();
        $totalSpent = (int) $visits->sum(fn ($v) => (int) ($v->total_price ?? 0));
        $avgRating = $totalVisits > 0 ? round((float) $visits->avg('rating'), 2) : null;

        $categoryRows = Place::where('user_id', $userId)
            ->where('is_wishlist', false)
            ->selectRaw('category, COUNT(*) as count')
            ->groupBy('category')
            ->orderByDesc('count')
            ->get();

        $favoriteCategory = $categoryRows->first()
            ? [
                'category' => $categoryRows->first()->category,
                'count' => (int) $categoryRows->first()->count,
            ]
            : null;

        // Monthly spending for the last 6 months (compatible with both MySQL & SQLite)
        $monthlyMap = [];
        for ($i = 5; $i >= 0; $i--) {
            $ym = Carbon::now()->subMonths($i)->format('Y-m');
            $monthlyMap[$ym] = [
                'month' => $ym,
                'total_spent' => 0,
                'visits_count' => 0,
            ];
        }

        foreach ($visits as $visit) {
            $ym = Carbon::parse($visit->visited_at)->format('Y-m');
            if (isset($monthlyMap[$ym])) {
                $monthlyMap[$ym]['total_spent'] += (int) ($visit->total_price ?? 0);
                $monthlyMap[$ym]['visits_count'] += 1;
            }
        }

        $favoriteDishes = VisitDish::query()
            ->where('is_favorite', true)
            ->whereHas('visit', fn ($q) => $q->where('user_id', $userId))
            ->with('visit.place:id,name,category')
            ->orderByDesc('rating')
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(fn (VisitDish $dish) => [
                'id' => $dish->id,
                'name' => $dish->name,
                'price' => $dish->price,
                'rating' => $dish->rating,
                'place_id' => $dish->visit?->place?->id,
                'place_name' => $dish->visit?->place?->name,
            ]);

        return response()->json([
            'data' => [
                'total_places' => $totalPlaces,
                'total_wishlist' => $totalWishlist,
                'total_visits' => $totalVisits,
                'total_spent' => $totalSpent,
                'avg_rating' => $avgRating,
                'favorite_category' => $favoriteCategory,
                'category_breakdown' => $categoryRows->map(fn ($r) => [
                    'category' => $r->category,
                    'count' => (int) $r->count,
                ])->values(),
                'monthly_spending' => array_values($monthlyMap),
                'favorite_dishes' => $favoriteDishes->values(),
            ],
        ]);
    }

    public function randomWishlist(Request $request): JsonResponse
    {
        $place = Place::query()
            ->where('user_id', $request->user()->id)
            ->where('is_wishlist', true)
            ->inRandomOrder()
            ->first();

        if (! $place) {
            return response()->json([
                'message' => 'Wishlist masih kosong. Tambahkan tempat ke wishlist terlebih dahulu.',
            ], 404);
        }

        return response()->json([
            'data' => $place,
        ]);
    }

    public function exportCsv(Request $request): Response
    {
        $places = Place::query()
            ->where('user_id', $request->user()->id)
            ->with(['visits.dishes', 'visits.tags'])
            ->orderBy('name')
            ->get();

        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, [
            'Nama Tempat',
            'Kategori',
            'Alamat',
            'Status',
            'Tanggal Kunjungan',
            'Rating',
            'Total Harga',
            'Teman',
            'Menu',
            'Tag',
            'Catatan',
        ]);

        foreach ($places as $place) {
            $status = $place->is_wishlist ? 'Wishlist' : 'Dikunjungi';
            if ($place->visits->isEmpty()) {
                fputcsv($handle, [
                    $place->name,
                    $place->category,
                    $place->address ?? '',
                    $status,
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                ]);
                continue;
            }

            foreach ($place->visits as $visit) {
                $dishes = $visit->dishes
                    ->map(fn ($d) => $d->name . ($d->price !== null ? ' (Rp' . $d->price . ')' : ''))
                    ->implode('; ');
                $tags = $visit->tags->pluck('name')->implode(', ');

                fputcsv($handle, [
                    $place->name,
                    $place->category,
                    $place->address ?? '',
                    $status,
                    $visit->visited_at ? Carbon::parse($visit->visited_at)->format('Y-m-d') : '',
                    $visit->rating,
                    $visit->total_price ?? '',
                    $visit->companions ?? '',
                    $dishes,
                    $tags,
                    $visit->notes ?? '',
                ]);
            }
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="jejak_rasa_export.csv"',
        ]);
    }
}

