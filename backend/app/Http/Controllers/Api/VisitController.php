<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Place;
use App\Models\Tag;
use App\Models\Visit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VisitController extends Controller
{
    public function store(Request $request, Place $place): JsonResponse
    {
        abort_if($place->user_id !== $request->user()->id, 403, 'Akses ditolak.');

        $validated = $request->validate([
            'visited_at' => ['required', 'date'],
            'rating' => ['required', 'integer', 'between:1,5'],
            'total_price' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string'],
            'companions' => ['nullable', 'string', 'max:255'],
            'dishes' => ['sometimes', 'array'],
            'dishes.*.name' => ['required_with:dishes', 'string', 'max:255'],
            'dishes.*.price' => ['nullable', 'integer', 'min:0'],
            'dishes.*.rating' => ['nullable', 'integer', 'between:1,5'],
            'dishes.*.is_favorite' => ['sometimes', 'boolean'],
            'tags' => ['sometimes', 'array'],
            'tags.*' => ['string', 'max:100'],
        ], [
            'visited_at.required' => 'Tanggal kunjungan wajib diisi.',
            'rating.required' => 'Rating wajib diisi.',
            'rating.between' => 'Rating harus di antara 1 sampai 5.',
            'total_price.min' => 'Total harga tidak boleh negatif.',
        ]);

        $visit = DB::transaction(function () use ($request, $place, $validated) {
            $visit = $place->visits()->create([
                'user_id' => $request->user()->id,
                'visited_at' => $validated['visited_at'],
                'rating' => $validated['rating'],
                'total_price' => $validated['total_price'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'companions' => $validated['companions'] ?? null,
            ]);

            if (! empty($validated['dishes'])) {
                foreach ($validated['dishes'] as $dishData) {
                    $visit->dishes()->create([
                        'name' => trim($dishData['name']),
                        'price' => $dishData['price'] ?? null,
                        'rating' => $dishData['rating'] ?? null,
                        'is_favorite' => $dishData['is_favorite'] ?? false,
                    ]);
                }
            }

            if (! empty($validated['tags'])) {
                $this->syncTags($visit, $request->user()->id, $validated['tags']);
            }

            if ($place->is_wishlist) {
                $place->update(['is_wishlist' => false]);
            }

            return $visit;
        });

        $visit->load(['dishes.photos', 'photos', 'tags']);

        return response()->json([
            'message' => 'Kunjungan berhasil dicatat.',
            'data' => $visit,
        ], 201);
    }

    public function show(Request $request, Visit $visit): JsonResponse
    {
        abort_if($visit->user_id !== $request->user()->id, 403, 'Akses ditolak.');

        $visit->load(['place', 'dishes.photos', 'photos', 'tags']);

        return response()->json([
            'data' => $visit,
        ]);
    }

    public function update(Request $request, Visit $visit): JsonResponse
    {
        abort_if($visit->user_id !== $request->user()->id, 403, 'Akses ditolak.');

        $validated = $request->validate([
            'visited_at' => ['sometimes', 'required', 'date'],
            'rating' => ['sometimes', 'required', 'integer', 'between:1,5'],
            'total_price' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string'],
            'companions' => ['nullable', 'string', 'max:255'],
            'tags' => ['sometimes', 'array'],
            'tags.*' => ['string', 'max:100'],
        ], [
            'rating.between' => 'Rating harus di antara 1 sampai 5.',
            'total_price.min' => 'Total harga tidak boleh negatif.',
        ]);

        DB::transaction(function () use ($request, $visit, $validated) {
            $visit->fill(collect($validated)->except('tags')->toArray());
            $visit->save();

            if (array_key_exists('tags', $validated)) {
                $this->syncTags($visit, $request->user()->id, $validated['tags'] ?? []);
            }
        });

        $visit->load(['dishes.photos', 'photos', 'tags']);

        return response()->json([
            'message' => 'Kunjungan berhasil diperbarui.',
            'data' => $visit,
        ]);
    }

    public function destroy(Request $request, Visit $visit): JsonResponse
    {
        abort_if($visit->user_id !== $request->user()->id, 403, 'Akses ditolak.');

        $visit->delete();

        return response()->json([
            'message' => 'Kunjungan berhasil dihapus.',
        ]);
    }

    /**
     * @param array<int, string> $tagNames
     */
    private function syncTags(Visit $visit, int $userId, array $tagNames): void
    {
        $tagIds = [];
        foreach ($tagNames as $tagName) {
            $clean = mb_strtolower(trim($tagName));
            if ($clean === '') {
                continue;
            }
            $tag = Tag::firstOrCreate([
                'user_id' => $userId,
                'name' => $clean,
            ]);
            $tagIds[] = $tag->id;
        }
        $visit->tags()->sync(array_values(array_unique($tagIds)));
    }
}

