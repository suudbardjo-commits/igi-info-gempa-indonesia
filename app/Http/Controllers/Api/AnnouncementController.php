<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class AnnouncementController extends Controller
{
    /** Publik: pengumuman yang sedang berlaku. */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Announcement::query()->active()->limit(20)->get()->map->toPublicArray()->values(),
        ]);
    }

    /** Konsol: semua pengumuman termasuk yang sudah kedaluwarsa, untuk dikelola. */
    public function manage(): JsonResponse
    {
        Gate::authorize('announcement.manage');

        return response()->json([
            'data' => Announcement::query()->orderByDesc('published_at')->limit(100)->get()->map(
                fn (Announcement $a) => $a->toPublicArray() + ['active' => ! $a->expires_at || $a->expires_at->isFuture()],
            )->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('announcement.manage');

        $data = $request->validate([
            'title' => ['required', 'string', 'min:5', 'max:140'],
            'body' => ['required', 'string', 'min:10', 'max:2000'],
            'level' => ['required', Rule::in(Announcement::LEVELS)],
            'expires_in_hours' => ['nullable', 'integer', 'min:1', 'max:720'],
        ]);

        $announcement = Announcement::create([
            'user_id' => $request->user()->id,
            'title' => $data['title'],
            'body' => $data['body'],
            'level' => $data['level'],
            'published_at' => now(),
            'expires_at' => isset($data['expires_in_hours']) ? now()->addHours($data['expires_in_hours']) : null,
        ]);

        return response()->json($announcement->toPublicArray(), 201);
    }

    public function destroy(Announcement $announcement): JsonResponse
    {
        Gate::authorize('announcement.manage');

        $announcement->delete();

        return response()->json(null, 204);
    }
}
