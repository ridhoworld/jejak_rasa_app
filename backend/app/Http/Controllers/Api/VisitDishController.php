<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Visit;
use App\Models\VisitDish;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VisitDishController extends Controller
{
    public function store(Request $request, Visit $visit): JsonResponse
    {
        abort_if($visit->user_id !== $request->user()->id, 403, 'Akses ditolak.');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'price' => ['nullable', 'integer', 'min:0'],
            'rating' => ['nullable', 'integer', 'between:1,5'],
            'is_favorite' => ['sometimes', 'boolean'],
        ], [
            'name.required' => 'Nama menu wajib diisi.',
            'price.min' => 'Harga menu tidak boleh negatif.',
            'rating.between' => 'Rating menu harus di antara 1 sampai 5.',
        ]);

        $dish = $visit->dishes()->create([
            'name' => trim($validated['name']),
            'price' => $validated['price'] ?? null,
            'rating' => $validated['rating'] ?? null,
            'is_favorite' => $validated['is_favorite'] ?? false,
        ]);

        $dish->load('photos');

        return response()->json([
            'message' => 'Menu berhasil ditambahkan.',
            'data' => $dish,
        ], 201);
    }

    public function update(Request $request, VisitDish $dish): JsonResponse
    {
        abort_if($dish->visit->user_id !== $request->user()->id, 403, 'Akses ditolak.');

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'price' => ['nullable', 'integer', 'min:0'],
            'rating' => ['nullable', 'integer', 'between:1,5'],
            'is_favorite' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('name', $validated)) {
            $validated['name'] = trim($validated['name']);
        }

        $dish->update($validated);
        $dish->load('photos');

        return response()->json([
            'message' => 'Menu berhasil diperbarui.',
            'data' => $dish,
        ]);
    }

    public function destroy(Request $request, VisitDish $dish): JsonResponse
    {
        abort_if($dish->visit->user_id !== $request->user()->id, 403, 'Akses ditolak.');

        $dish->delete();

        return response()->json([
            'message' => 'Menu berhasil dihapus.',
        ]);
    }
}

