<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Place;
use App\Models\Tag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlaceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Place::query()
            ->where('user_id', $user->id)
            ->withCount('visits')
            ->withAvg('visits', 'rating')
            ->with([
                'latestVisit.tags',
                'latestVisit.photos',
            ]);

        if ($request->filled('is_wishlist')) {
            $isWishlist = filter_var($request->query('is_wishlist'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($isWishlist !== null) {
                $query->where('is_wishlist', $isWishlist);
            }
        }

        if ($request->filled('category')) {
            $query->where('category', $request->query('category'));
        }

        if ($request->filled('search')) {
            $term = trim((string) $request->query('search'));
            $like = '%' . $term . '%';
            $query->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)
                    ->orWhere('address', 'like', $like)
                    ->orWhere('category', 'like', $like)
                    ->orWhereHas('visits.dishes', function ($dq) use ($like) {
                        $dq->where('name', 'like', $like);
                    })
                    ->orWhereHas('visits.tags', function ($tq) use ($like) {
                        $tq->where('name', 'like', $like);
                    });
            });
        }

        if ($request->filled('tag')) {
            $tagName = trim((string) $request->query('tag'));
            $query->whereHas('visits.tags', function ($tq) use ($tagName) {
                $tq->where('name', $tagName);
            });
        }

        if ($request->filled('tag_id')) {
            $tagId = (int) $request->query('tag_id');
            $query->whereHas('visits.tags', function ($tq) use ($tagId) {
                $tq->where('tags.id', $tagId);
            });
        }

        if ($request->filled('min_rating') || $request->filled('max_rating')) {
            $minRating = $request->filled('min_rating') ? (int) $request->query('min_rating') : 1;
            $maxRating = $request->filled('max_rating') ? (int) $request->query('max_rating') : 5;
            $query->whereHas('visits', function ($vq) use ($minRating, $maxRating) {
                $vq->whereBetween('rating', [$minRating, $maxRating]);
            });
        }

        if ($request->filled('min_price') || $request->filled('max_price')) {
            $minPrice = $request->filled('min_price') ? (int) $request->query('min_price') : null;
            $maxPrice = $request->filled('max_price') ? (int) $request->query('max_price') : null;
            $query->whereHas('visits', function ($vq) use ($minPrice, $maxPrice) {
                if ($minPrice !== null) {
                    $vq->where('total_price', '>=', $minPrice);
                }
                if ($maxPrice !== null) {
                    $vq->where('total_price', '<=', $maxPrice);
                }
            });
        }

        $places = $query->orderByDesc('updated_at')->orderByDesc('id')->get();

        return response()->json([
            'data' => $places,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'category' => ['required', 'string', 'max:100'],
            'is_wishlist' => ['sometimes', 'boolean'],
        ], [
            'name.required' => 'Nama tempat wajib diisi.',
            'category.required' => 'Kategori tempat wajib diisi.',
        ]);

        $user = $request->user();
        $name = trim($validated['name']);
        $address = isset($validated['address']) && trim($validated['address']) !== ''
            ? trim($validated['address'])
            : null;

        $duplicate = Place::query()
            ->where('user_id', $user->id)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->where(function ($q) use ($address) {
                if ($address === null) {
                    $q->whereNull('address')->orWhere('address', '');
                } else {
                    $q->whereRaw('LOWER(address) = ?', [mb_strtolower($address)]);
                }
            })
            ->first();

        if ($duplicate) {
            return response()->json([
                'message' => 'Tempat dengan nama dan alamat yang sama sudah tercatat.',
                'duplicate' => true,
                'existing_place_id' => $duplicate->id,
            ], 409);
        }

        $place = Place::create([
            'user_id' => $user->id,
            'name' => $name,
            'address' => $address,
            'latitude' => $validated['latitude'] ?? null,
            'longitude' => $validated['longitude'] ?? null,
            'category' => trim($validated['category']),
            'is_wishlist' => $validated['is_wishlist'] ?? false,
        ]);

        $place->loadCount('visits')->loadAvg('visits', 'rating');

        return response()->json([
            'message' => 'Tempat berhasil ditambahkan.',
            'data' => $place,
        ], 201);
    }

    public function show(Request $request, Place $place): JsonResponse
    {
        abort_if($place->user_id !== $request->user()->id, 403, 'Akses ditolak.');

        $place->loadCount('visits')
            ->loadAvg('visits', 'rating')
            ->load([
                'visits.dishes.photos',
                'visits.photos',
                'visits.tags',
            ]);

        return response()->json([
            'data' => $place,
        ]);
    }

    public function update(Request $request, Place $place): JsonResponse
    {
        abort_if($place->user_id !== $request->user()->id, 403, 'Akses ditolak.');

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'category' => ['sometimes', 'required', 'string', 'max:100'],
            'is_wishlist' => ['sometimes', 'boolean'],
        ], [
            'name.required' => 'Nama tempat wajib diisi.',
            'category.required' => 'Kategori tempat wajib diisi.',
        ]);

        if (
            array_key_exists('is_wishlist', $validated)
            && $place->is_wishlist
            && $validated['is_wishlist'] === false
            && $place->visits()->count() === 0
        ) {
            return response()->json([
                'message' => 'Tempat di wishlist harus memiliki minimal satu kunjungan sebelum dipindahkan ke riwayat.',
            ], 422);
        }

        $nextName = array_key_exists('name', $validated) ? trim($validated['name']) : $place->name;
        $nextAddress = array_key_exists('address', $validated)
            ? (trim((string) $validated['address']) !== '' ? trim((string) $validated['address']) : null)
            : $place->address;

        $duplicate = Place::query()
            ->where('user_id', $request->user()->id)
            ->where('id', '!=', $place->id)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($nextName)])
            ->where(function ($q) use ($nextAddress) {
                if ($nextAddress === null) {
                    $q->whereNull('address')->orWhere('address', '');
                } else {
                    $q->whereRaw('LOWER(address) = ?', [mb_strtolower($nextAddress)]);
                }
            })
            ->first();

        if ($duplicate) {
            return response()->json([
                'message' => 'Tempat dengan nama dan alamat yang sama sudah tercatat.',
                'duplicate' => true,
                'existing_place_id' => $duplicate->id,
            ], 409);
        }

        $place->fill([
            'name' => $nextName,
            'address' => $nextAddress,
            'latitude' => array_key_exists('latitude', $validated) ? $validated['latitude'] : $place->latitude,
            'longitude' => array_key_exists('longitude', $validated) ? $validated['longitude'] : $place->longitude,
            'category' => array_key_exists('category', $validated) ? trim($validated['category']) : $place->category,
            'is_wishlist' => array_key_exists('is_wishlist', $validated) ? $validated['is_wishlist'] : $place->is_wishlist,
        ]);
        $place->save();

        $place->loadCount('visits')->loadAvg('visits', 'rating');

        return response()->json([
            'message' => 'Tempat berhasil diperbarui.',
            'data' => $place,
        ]);
    }

    public function destroy(Request $request, Place $place): JsonResponse
    {
        abort_if($place->user_id !== $request->user()->id, 403, 'Akses ditolak.');

        $place->delete();

        return response()->json([
            'message' => 'Tempat beserta seluruh riwayat kunjungan, menu, dan fotonya berhasil dihapus.',
        ]);
    }

    public function completeWishlist(Request $request, Place $place): JsonResponse
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
                $tagIds = [];
                foreach ($validated['tags'] as $tagName) {
                    $clean = mb_strtolower(trim($tagName));
                    if ($clean === '') {
                        continue;
                    }
                    $tag = Tag::firstOrCreate([
                        'user_id' => $request->user()->id,
                        'name' => $clean,
                    ]);
                    $tagIds[] = $tag->id;
                }
                $visit->tags()->sync($tagIds);
            }

            if ($place->is_wishlist) {
                $place->update(['is_wishlist' => false]);
            }

            return $visit;
        });

        $visit->load(['dishes.photos', 'photos', 'tags']);
        $place->refresh()->loadCount('visits')->loadAvg('visits', 'rating');

        return response()->json([
            'message' => 'Tempat berhasil dipindahkan ke riwayat dengan kunjungan pertama.',
            'data' => [
                'place' => $place,
                'visit' => $visit,
            ],
        ], 201);
    }
}

