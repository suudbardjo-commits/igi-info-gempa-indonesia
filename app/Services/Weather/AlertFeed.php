<?php

namespace App\Services\Weather;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Peringatan dini cuaca BMKG: daftar RSS + detail CAP. Tautan detail hanya diterima bila https, host www.bmkg.go.id,
 * tanpa port/userinfo, dan jalurnya cocok pola; dokumen yang bukan CAP sah dibuang. Hasil disimpan di cache; status
 * aktif/kedaluwarsa dihitung ulang tiap dibaca supaya peringatan lewat waktu tidak tampil sebagai aktif.
 */
class AlertFeed
{
    private const KEY = 'weather:alerts:store';

    public function __construct(private Upstream $upstream, private CapParser $cap) {}

    /**
     * @return array{ok: bool, checked_at: ?string, stale: bool, error: ?string, alerts: list<array<string, mixed>>}
     */
    public function current(): array
    {
        $store = Cache::get(self::KEY);
        $fresh = $store && CarbonImmutable::parse($store['checked_at'])->gt(now()->subSeconds(config('weather.ttl.alerts')));

        if (! $fresh) {
            $store = $this->refresh($store);
        }

        return [
            'ok' => $store !== null,
            'checked_at' => $store['checked_at'] ?? null,
            'stale' => (bool) ($store['stale'] ?? false),
            'error' => $store['error'] ?? null,
            'alerts' => $store ? $this->withState($store['alerts']) : [],
        ];
    }

    /** @param array<string, mixed>|null $previous */
    public function refresh(?array $previous = null): ?array
    {
        $lock = Cache::lock('lock:weather:alerts', 20);
        try {
            $lock->block(8);
            $again = Cache::get(self::KEY);
            if ($again && CarbonImmutable::parse($again['checked_at'])->gt(now()->subSeconds(config('weather.ttl.alerts')))) {
                return $again;
            }

            $store = ['checked_at' => now()->toIso8601String(), 'stale' => false, 'error' => null, 'alerts' => $this->fetchAll()];
            Cache::put(self::KEY, $store, 86400);
            $this->upstream->ok('bmkg-alerts');

            return $store;
        } catch (UpstreamException|\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            $previous ??= Cache::get(self::KEY);
            if (! $previous) {
                return null;
            }
            $reason = $e instanceof UpstreamException ? $e->reason : 'busy';

            return ['stale' => true, 'error' => $reason] + $previous;
        } finally {
            optional($lock)->release();
        }
    }

    /** @return list<array<string, mixed>> */
    private function fetchAll(): array
    {
        $rss = $this->upstream->get('bmkg', 'bmkg-alerts', config('weather.bmkg.alert_rss_url'));
        $links = $this->links($rss->body());

        $docs = [];
        $toFetch = [];
        foreach ($links as $url) {
            $cached = Cache::get('weather:cap:'.md5($url));
            $cached ? $docs[] = $cached : $toFetch[] = $url;
        }

        if ($toFetch) {
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn ($u) => $pool->as($u)->timeout(config('weather.timeout'))->accept('*/*')->withUserAgent(config('weather.user_agent'))->get($u),
                $toFetch,
            ));
            foreach ($toFetch as $url) {
                $r = $responses[$url] ?? null;
                if (! $r instanceof \Illuminate\Http\Client\Response || ! $r->successful() || strlen($r->body()) > config('weather.bmkg.alert_max_bytes')) {
                    Log::info('Detail CAP tidak terambil', ['status' => $r instanceof \Illuminate\Http\Client\Response ? $r->status() : 'error']);

                    continue;
                }
                $parsed = $this->cap->parse($r->body());
                if (! $parsed) {
                    Log::warning('Dokumen CAP ditolak (tidak sah atau berisi DOCTYPE)');

                    continue;
                }
                $parsed['source_url'] = $url;
                Cache::put('weather:cap:'.md5($url), $parsed, 86400);
                $docs[] = $parsed;
            }
        }

        return $this->reconcile($docs);
    }

    /** @return list<string> tautan CAP yang lolos validasi, tanpa duplikat */
    public function links(string $rssXml): array
    {
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $rssXml)) {
            return [];
        }
        $prev = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($rssXml, options: LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (! $xml || ! isset($xml->channel)) {
            throw new UpstreamException('malformed', 'Daftar peringatan BMKG tidak dapat dibaca.');
        }

        $out = [];
        foreach ($xml->channel->item as $item) {
            $url = trim((string) $item->link);
            if ($this->isAllowedCapUrl($url)) {
                $out[$url] = $url;
            }
            if (count($out) >= config('weather.bmkg.alert_max_items')) {
                break;
            }
        }

        return array_values($out);
    }

    public function isAllowedCapUrl(string $url): bool
    {
        $p = parse_url($url);

        return $p !== false
            && ($p['scheme'] ?? '') === 'https'
            && strtolower($p['host'] ?? '') === config('weather.bmkg.alert_host')
            && array_diff_key($p, array_flip(['scheme', 'host', 'path'])) === []
            && preg_match(config('weather.bmkg.alert_path'), $p['path'] ?? '') === 1;
    }

    /**
     * Hanya status Actual; Exercise/Test/Draft/System dibuang. Cancel menandai alert yang dirujuk batal; Update menggantikan yang dirujuk.
     *
     * @param  list<array<string, mixed>>  $docs
     * @return list<array<string, mixed>>
     */
    public function reconcile(array $docs): array
    {
        $byId = [];
        foreach ($docs as $d) {
            if (($d['status'] ?? '') !== 'Actual') {
                continue;
            }
            // Identifier sama: simpan yang terkirim paling akhir.
            if (! isset($byId[$d['identifier']]) || $d['sent'] > $byId[$d['identifier']]['sent']) {
                $byId[$d['identifier']] = $d;
            }
        }

        $cancelled = [];
        $superseded = [];
        foreach ($byId as $d) {
            foreach ($d['references'] as $ref) {
                if ($d['msg_type'] === 'Cancel') {
                    $cancelled[$ref] = true;
                } elseif ($d['msg_type'] === 'Update') {
                    $superseded[$ref] = true;
                }
            }
        }

        $out = [];
        foreach ($byId as $id => $d) {
            if (isset($superseded[$id]) || $d['msg_type'] === 'Cancel' || $d['msg_type'] === 'Ack' || $d['msg_type'] === 'Error') {
                continue;
            }
            $d['cancelled'] = isset($cancelled[$id]);
            $out[] = $d;
        }
        usort($out, fn ($a, $b) => strcmp($b['sent'], $a['sent']));

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $alerts
     * @return list<array<string, mixed>>
     */
    private function withState(array $alerts): array
    {
        $now = CarbonImmutable::now();

        return array_map(function ($a) use ($now) {
            $a['state'] = match (true) {
                $a['cancelled'] => 'cancelled',
                $a['expires'] && CarbonImmutable::parse($a['expires'])->lte($now) => 'expired',
                $a['effective'] && CarbonImmutable::parse($a['effective'])->gt($now) => 'upcoming',
                default => 'active',
            };

            return $a;
        }, $alerts);
    }
}
