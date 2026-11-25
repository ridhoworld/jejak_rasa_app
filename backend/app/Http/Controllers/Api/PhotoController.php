<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Photo;
use App\Models\Visit;
use App\Models\VisitDish;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PhotoController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Photo::query()
            ->where('user_id', $request->user()->id)
            ->with([
                'visit:id,place_id,visited_at,rating',
                'visit.place:id,name,category',
                'dish:id,visit_id,name,price,rating',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($request->filled('place_id')) {
            $placeId = (int) $request->query('place_id');
            $query->whereHas('visit', fn ($q) => $q->where('place_id', $placeId));
        }

        return response()->json([
            'data' => $query->get(),
        ]);
    }

    public function store(Request $request, Visit $visit): JsonResponse
    {
        abort_if($visit->user_id !== $request->user()->id, 403, 'Akses ditolak.');

        $validated = $request->validate([
            'photo' => ['required', 'image', 'max:5120'],
            'visit_dish_id' => ['nullable', 'integer', 'exists:visit_dishes,id'],
            'caption' => ['nullable', 'string', 'max:255'],
        ], [
            'photo.required' => 'File foto wajib diunggah.',
            'photo.image' => 'File harus berupa gambar.',
            'photo.max' => 'Ukuran foto maksimal 5 MB.',
        ]);

        $dishId = $validated['visit_dish_id'] ?? null;
        if ($dishId !== null) {
            $dish = VisitDish::find($dishId);
            if (! $dish || $dish->visit_id !== $visit->id) {
                return response()->json([
                    'message' => 'Menu tidak ditemukan pada kunjungan ini.',
                ], 422);
            }
        }

        $path = $request->file('photo')->store('photos/' . $request->user()->id, 'public');

        $photo = Photo::create([
            'user_id' => $request->user()->id,
            'visit_id' => $visit->id,
            'visit_dish_id' => $dishId,
            'path' => $path,
            'caption' => $validated['caption'] ?? null,
        ]);

        return response()->json([
            'message' => 'Foto berhasil diunggah.',
            'data' => $photo,
        ], 201);
    }

    public function destroy(Request $request, Photo $photo): JsonResponse
    {
        abort_if($photo->user_id !== $request->user()->id, 403, 'Akses ditolak.');

        $photo->delete();

        return response()->json([
            'message' => 'Foto berhasil dihapus.',
        ]);
    }
}

