<?php

namespace App\Services\ExternalNews;

use App\Models\ExternalNews;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ExternalNewsSync
{
    public function __construct(private BmkgNowcastParser $bmkg, private BnpbListParser $bnpb) {}

    /** @return int jumlah item yang tersimpan/diperbarui */
    public function sync(string $source): int
    {
        $config = config("disaster.news.sources.{$source}") ?? throw new RuntimeException("Sumber berita tidak dikenal: {$source}");

        // Firewall situs pemerintah kerap menolak header Accept: application/json; kirim */* seperti ingesti BMKG.
        $response = Http::timeout(config('disaster.news.timeout'))
            ->retry(2, 500, throw: false)
            ->accept('*/*')
            ->withUserAgent(config('disaster.http_user_agent'))
            ->get($config['url']);

        if (! $response->successful()) {
            Log::warning('Gagal mengambil berita eksternal', ['source' => $source, 'status' => $response->status()]);

            throw new RuntimeException("{$config['label']} membalas HTTP {$response->status()}");
        }

        $body = $response->body();

        if (strlen($body) > config('disaster.news.max_bytes')) {
            throw new RuntimeException("Respons {$config['label']} terlalu besar");
        }

        $items = match ($source) {
            'bmkg' => $this->bmkg->parse($body),
            'bnpb' => $this->bnpb->parse($body),
        };

        // Respons sukses tetapi tidak ada satu pun item terbaca berarti bentuk halaman/feed berubah. Dilaporkan sebagai
        // kegagalan supaya terlihat, bukan dianggap "memang tidak ada berita".
        if ($items === [] && $source === 'bnpb') {
            throw new RuntimeException('Tidak ada berita BNPB yang bisa dibaca; tampilan halaman sumber mungkin berubah');
        }

        $now = now();
        $stored = 0;

        foreach (array_slice($items, 0, config('disaster.news.max_items')) as $item) {
            if (! $this->isOwnUrl($item['url'], $config['host'])) {
                Log::warning('Tautan berita di luar host sumber ditolak', ['source' => $source, 'url' => $item['url']]);

                continue;
            }

            // Ke UTC dulu: Eloquent menulis "Y-m-d H:i:s" tanpa offset, sehingga waktu berzona (+0700, +0800) yang
            // langsung disimpan akan terbaca sebagai UTC dan meleset beberapa jam.
            $published = $item['published_at']->utc();

            ExternalNews::query()->updateOrCreate(
                ['source' => $source, 'external_id' => mb_substr($item['external_id'], 0, 300)],
                [
                    'title' => mb_substr($item['title'], 0, 300),
                    'summary' => $item['summary'] ? mb_substr($item['summary'], 0, 400) : null,
                    'url' => $item['url'],
                    // Sesuatu yang diterbitkan tidak mungkin lebih baru daripada saat kita mengambilnya; waktu di masa
                    // depan berarti feed salah melabeli zona.
                    'published_at' => $published->gt($now) ? $now : $published,
                    'fetched_at' => $now,
                ],
            );
            $stored++;
        }

        $this->prune($source, $config['retention_hours']);

        return $stored;
    }

    /** Tautan harus https dan berada di host sumbernya sendiri, supaya isi feed tidak bisa mengarahkan pembaca ke situs lain. */
    private function isOwnUrl(string $url, string $host): bool
    {
        $parts = parse_url($url);

        return ($parts['scheme'] ?? null) === 'https'
            && strtolower($parts['host'] ?? '') === $host
            && ! isset($parts['user'], $parts['pass']);
    }

    private function prune(string $source, int $retentionHours): void
    {
        // Disimpan 2x masa tayang lalu dibuang; berguna bila masa tayang dinaikkan kembali.
        ExternalNews::query()->where('source', $source)->where('published_at', '<', now()->subHours($retentionHours * 2))->delete();
    }
}
