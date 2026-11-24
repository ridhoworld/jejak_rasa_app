<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TagController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tags = Tag::query()
            ->where('user_id', $request->user()->id)
            ->withCount('visits')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $tags,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
        ], [
            'name.required' => 'Nama tag wajib diisi.',
        ]);

        $clean = mb_strtolower(trim($validated['name']));

        $tag = Tag::firstOrCreate([
            'user_id' => $request->user()->id,
            'name' => $clean,
        ]);

        return response()->json([
            'message' => 'Tag berhasil disimpan.',
            'data' => $tag,
        ], 201);
    }

    public function destroy(Request $request, Tag $tag): JsonResponse
    {
        abort_if($tag->user_id !== $request->user()->id, 403, 'Akses ditolak.');

        $tag->delete();

        return response()->json([
            'message' => 'Tag berhasil dihapus.',
        ]);
    }
}

