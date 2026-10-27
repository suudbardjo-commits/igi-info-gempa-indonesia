<?php

namespace App\Services\ExternalNews;

use App\Models\ExternalNews;

/** Bahan tampilan berita lembaga resmi untuk beranda dan halaman Berita. */
class OfficialNewsFeed
{
    /** Terakhir berhasil diambil lebih lama dari ini = tandai mungkin tidak mutakhir. */
    private const STALE_AFTER_HOURS = 6;

    /** @return array{items: list<array>, sources: list<array>} */
    public function forPage(int $limit): array
    {
        $enabled = collect(config('disaster.news.sources'))->filter(fn ($s) => $s['enabled']);

        // Jatah per sumber: BMKG menerbitkan peringatan tiap beberapa menit dan akan menenggelamkan BNPB kalau
        // daftarnya digabung begitu saja lalu dipotong.
        $each = max(1, (int) ceil($limit / max(1, $enabled->count())));

        $items = $enabled->keys()
            ->flatMap(fn ($key) => ExternalNews::query()->where('source', $key)->visible()->limit($each)->get())
            ->sortByDesc('published_at')
            ->map->toPublicArray()
            ->values()
            ->all();

        $fetched = ExternalNews::query()->selectRaw('source, max(fetched_at) as at')->groupBy('source')->pluck('at', 'source');

        $sources = $enabled->map(function ($s, $key) use ($fetched) {
            $at = isset($fetched[$key]) ? now()->parse($fetched[$key]) : null;

            return [
                'key' => $key,
                'label' => $s['label'],
                'kind' => $s['kind'],
                'home' => $s['home'],
                'updated_at' => $at?->toIso8601String(),
                'stale' => ! $at || $at->lt(now()->subHours(self::STALE_AFTER_HOURS)),
            ];
        })->values()->all();

        return ['items' => $items, 'sources' => $sources];
    }
}
