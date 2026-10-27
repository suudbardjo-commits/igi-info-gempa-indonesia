<?php

use App\Models\ExternalNews;
use App\Services\ExternalNews\BmkgNowcastParser;
use App\Services\ExternalNews\BnpbListParser;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

function bmkgRss(array $items): string
{
    $xml = '';
    foreach ($items as $i) {
        $xml .= "<item><title>{$i['title']}</title><link>{$i['link']}</link>"
            ."<description>{$i['desc']}</description><guid isPermaLink=\"false\">{$i['guid']}</guid>"
            ."<pubDate>{$i['date']}</pubDate></item>";
    }

    return '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>Peringatan Dini Cuaca BMKG</title>'.$xml.'</channel></rss>';
}

function bmkgItem(array $over = []): array
{
    return $over + [
        'title' => 'Hujan Lebat disertai Petir di Sumatera Utara',
        'link' => 'https://www.bmkg.go.id/alerts/nowcast/id/CSU20261024003_alert.xml',
        'desc' => 'Hujan lebat disertai petir akan terjadi pada 24 October 2026, 14:50 WIB di sebagian wilayah Sumatera Utara, khususnya di AFULU, BADIRI, BAHOROK. Kondisi ini berpotensi menimbulkan banjir lokal.',
        'guid' => '2.49.0.1.360.0.2026.10.24.07.12.003',
        'date' => now()->subHour()->toRfc2822String(),
    ];
}

function bnpbHtml(array $cards): string
{
    $html = '<html><body><div class="row">';
    foreach ($cards as $c) {
        $html .= '<div class="col-md-4"><div class="block mb-4"><div class="img"><img src="/x.jpg" alt="'.$c['alt'].'"></div>'
            .'<div class="date mt-3"> '.$c['date'].' </div>'
            .'<div class="title"><a class="tracking" data-type="news" href="'.$c['href'].'">'.$c['text'].'</a></div></div></div>';
    }

    return $html.'</div></body></html>';
}

function bnpbCard(array $o = []): array
{
    $t = now()->subHours(3)->setTimezone('Asia/Jakarta');

    return $o + [
        'alt' => 'Tak Hanya di 6 Provinsi Prioritas, BNPB Kerahkan Dukungan Udara untuk Karhutla Jawa Timur',
        'text' => 'Tak Hanya di 6 Provinsi Prioritas, BNPB Kerahkan Dukungan Ud...',
        'date' => $t->format('j').' Okt '.$t->year.' | '.$t->format('H:i').' WIB',
        'href' => 'https://www.bnpb.go.id/berita/tak-hanya-di-6-provinsi-prioritas',
    ];
}

describe('penguraian', function () {
    it('membaca RSS BMKG dan meringkas ke kalimat pertama tanpa daftar kecamatan', function () {
        $items = (new BmkgNowcastParser)->parse(bmkgRss([bmkgItem()]));

        expect($items)->toHaveCount(1)
            ->and($items[0]['title'])->toBe('Hujan Lebat disertai Petir di Sumatera Utara')
            ->and($items[0]['summary'])->toBe('Hujan lebat disertai petir akan terjadi pada 24 October 2026, 14:50 WIB di sebagian wilayah Sumatera Utara.')
            ->and($items[0]['summary'])->not->toContain('AFULU')
            ->and($items[0]['external_id'])->toBe('2.49.0.1.360.0.2026.10.24.07.12.003');
    });

    it('melewati item RSS tanpa tanggal atau judul, dan menoleransi XML rusak', function () {
        $xml = bmkgRss([bmkgItem(['date' => 'bukan tanggal']), bmkgItem(['title' => '', 'guid' => 'g2'])]);

        expect((new BmkgNowcastParser)->parse($xml))->toBe([])
            ->and((new BmkgNowcastParser)->parse('<rss><channel><item>'))->toBe([])
            ->and((new BmkgNowcastParser)->parse(''))->toBe([]);
    });

    it('tidak mengambil entitas eksternal dari XML', function () {
        $xml = '<?xml version="1.0"?><!DOCTYPE r [<!ENTITY x SYSTEM "file:///etc/passwd">]><rss><channel><item><title>&x;</title>'
            .'<link>https://www.bmkg.go.id/a</link><guid>g</guid><pubDate>Sat, 24 Oct 2026 14:40:00 +0700</pubDate></item></channel></rss>';

        $items = (new BmkgNowcastParser)->parse($xml);

        expect(json_encode($items))->not->toContain('root:');
    });

    it('membaca daftar BNPB: judul lengkap dari alt gambar, tanggal WIB ke UTC', function () {
        $html = bnpbHtml([[
            'alt' => 'Tak Hanya di 6 Provinsi Prioritas, BNPB Kerahkan Dukungan Udara untuk Karhutla Jawa Timur',
            'text' => 'Tak Hanya di 6 Provinsi Prioritas, BNPB Kerahkan Dukungan Ud...',
            'date' => '23 Okt 2026 | 18:30 WIB',
            'href' => 'https://www.bnpb.go.id/berita/tak-hanya',
        ]]);

        $items = (new BnpbListParser)->parse($html);

        expect($items)->toHaveCount(1)
            ->and($items[0]['title'])->toBe('Tak Hanya di 6 Provinsi Prioritas, BNPB Kerahkan Dukungan Udara untuk Karhutla Jawa Timur')
            ->and($items[0]['published_at']->utc()->toIso8601String())->toBe('2026-10-23T11:30:00+00:00')
            ->and($items[0]['url'])->toBe('https://www.bnpb.go.id/berita/tak-hanya');
    });

    it('mengerti zona WITA/WIT dan bulan Mei/Agu, dan melewati kartu tanpa tanggal terbaca', function () {
        $html = bnpbHtml([
            ['alt' => 'A', 'text' => 'A', 'date' => '5 Agu 2026 | 09:00 WITA', 'href' => 'https://www.bnpb.go.id/berita/a'],
            ['alt' => 'B', 'text' => 'B', 'date' => 'kemarin', 'href' => 'https://www.bnpb.go.id/berita/b'],
        ]);

        $items = (new BnpbListParser)->parse($html);

        expect($items)->toHaveCount(1)->and($items[0]['published_at']->utc()->toIso8601String())->toBe('2026-08-05T01:00:00+00:00');
    });
});

describe('sinkronisasi', function () {
    it('menyimpan berita kedua sumber, dan tidak menggandakannya saat diambil lagi', function () {
        Http::fake([
            'www.bmkg.go.id/*' => Http::response(bmkgRss([bmkgItem()])),
            'www.bnpb.go.id/*' => Http::response(bnpbHtml([bnpbCard()])),
        ]);

        $this->artisan('news:fetch')->assertSuccessful();
        $this->artisan('news:fetch')->assertSuccessful();

        expect(ExternalNews::where('source', 'bmkg')->count())->toBe(1)
            ->and(ExternalNews::where('source', 'bnpb')->count())->toBe(1);
    });

    it('menyimpan waktu terbit berzona sebagai instan yang benar (bukan jam dinding yang dianggap UTC)', function () {
        // 15:38 WITA = 07:38 UTC; 18:30 WIB = 11:30 UTC.
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-24 10:00:00 UTC'));
        Http::fake([
            'www.bmkg.go.id/*' => Http::response(bmkgRss([bmkgItem(['date' => 'Sat, 24 Oct 2026 15:38:00 +0800'])])),
            'www.bnpb.go.id/*' => Http::response(bnpbHtml([bnpbCard(['date' => '23 Okt 2026 | 18:30 WIB'])])),
        ]);

        $this->artisan('news:fetch')->assertSuccessful();

        expect(ExternalNews::where('source', 'bmkg')->value('published_at'))->toEqual(\Carbon\CarbonImmutable::parse('2026-10-24 07:38:00 UTC'))
            ->and(ExternalNews::where('source', 'bnpb')->value('published_at'))->toEqual(\Carbon\CarbonImmutable::parse('2026-10-23 11:30:00 UTC'));
    });

    it('tidak membiarkan waktu terbit di masa depan (label zona feed yang salah)', function () {
        Http::fake(['www.bmkg.go.id/*' => Http::response(bmkgRss([bmkgItem(['date' => now()->addHours(8)->toRfc2822String()])]))]);

        $this->artisan('news:fetch --source=bmkg')->assertSuccessful();

        expect(ExternalNews::first()->published_at->lte(now()))->toBeTrue();
    });

    it('menolak tautan di luar host sumbernya dan skema selain https', function () {
        Http::fake(['www.bmkg.go.id/*' => Http::response(bmkgRss([
            bmkgItem(['guid' => 'ok']),
            bmkgItem(['guid' => 'jahat1', 'link' => 'https://situs-lain.example/phish']),
            bmkgItem(['guid' => 'jahat2', 'link' => 'javascript:alert(1)']),
            bmkgItem(['guid' => 'jahat3', 'link' => 'http://www.bmkg.go.id/a']),
            bmkgItem(['guid' => 'jahat4', 'link' => 'https://www.bmkg.go.id.situs-lain.example/a']),
        ]))]);

        $this->artisan('news:fetch --source=bmkg')->assertSuccessful();

        expect(ExternalNews::pluck('external_id')->all())->toBe(['ok']);
    });

    it('satu sumber gagal tidak menghapus berita lama dan tidak menghentikan sumber lain', function () {
        ExternalNews::create(['source' => 'bmkg', 'external_id' => 'lama', 'title' => 'Peringatan lama', 'url' => 'https://www.bmkg.go.id/a', 'published_at' => now()->subHour(), 'fetched_at' => now()->subHour()]);
        Http::fake([
            'www.bmkg.go.id/*' => Http::response('Forbidden', 403),
            'www.bnpb.go.id/*' => Http::response(bnpbHtml([bnpbCard()])),
        ]);

        $this->artisan('news:fetch')->assertSuccessful();

        expect(ExternalNews::where('source', 'bmkg')->pluck('title')->all())->toBe(['Peringatan lama'])
            ->and(ExternalNews::where('source', 'bnpb')->count())->toBe(1);
    });

    it('menganggap halaman BNPB tanpa satu berita terbaca sebagai kegagalan, bukan "tidak ada berita"', function () {
        Http::fake(['www.bnpb.go.id/*' => Http::response('<html><body>desain baru</body></html>')]);

        $this->artisan('news:fetch --source=bnpb')->assertFailed();
    });

    it('semua sumber gagal = perintah gagal; sumber dimatikan = dilewati', function () {
        Http::fake(['*' => Http::response('x', 500)]);
        $this->artisan('news:fetch')->assertFailed();

        config(['disaster.news.sources.bmkg.enabled' => false, 'disaster.news.sources.bnpb.enabled' => false]);
        $this->artisan('news:fetch')->assertSuccessful();
    });

    it('membuang berita yang jauh melewati masa tayang', function () {
        ExternalNews::create(['source' => 'bnpb', 'external_id' => 'purba', 'title' => 'Berita purba', 'url' => 'https://www.bnpb.go.id/berita/purba', 'published_at' => now()->subDays(90), 'fetched_at' => now()->subDays(90)]);
        Http::fake(['www.bnpb.go.id/*' => Http::response(bnpbHtml([bnpbCard()]))]);

        $this->artisan('news:fetch --source=bnpb')->assertSuccessful();

        expect(ExternalNews::where('external_id', 'purba')->exists())->toBeFalse();
    });
});

describe('tampilan', function () {
    it('halaman Berita dan Beranda memuat kabar resmi dengan sumber dan tautan, tanpa peringatan cuaca basi', function () {
        $mk = fn ($source, $id, $hoursAgo, $host) => ExternalNews::create([
            'source' => $source, 'external_id' => $id, 'title' => "Judul {$id}", 'summary' => 'Ringkasan.',
            'url' => "https://{$host}/{$id}", 'published_at' => now()->subHours($hoursAgo), 'fetched_at' => now()->subMinutes(5),
        ]);
        $mk('bmkg', 'segar', 2, 'www.bmkg.go.id');
        $mk('bmkg', 'basi', 30, 'www.bmkg.go.id');
        $mk('bnpb', 'berita-lama-tapi-berlaku', 24 * 10, 'www.bnpb.go.id');

        $this->get('/berita')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Berita/Index')
            ->has('official.items', 2)
            ->where('official.items.0.source_label', 'BMKG')
            ->where('official.items.0.url', 'https://www.bmkg.go.id/segar')
            ->where('official.items.1.source', 'bnpb')
            ->has('official.sources', 2)
            ->where('official.sources.0.stale', false));

        $this->get('/')->assertInertia(fn (Assert $p) => $p->has('official.items', 2));
    });

    it('membagi jatah per sumber supaya satu sumber yang ramai tidak menenggelamkan yang lain', function () {
        foreach (range(1, 30) as $i) {
            ExternalNews::create(['source' => 'bmkg', 'external_id' => "b{$i}", 'title' => "Cuaca {$i}", 'url' => "https://www.bmkg.go.id/{$i}", 'published_at' => now()->subMinutes($i), 'fetched_at' => now()]);
        }
        ExternalNews::create(['source' => 'bnpb', 'external_id' => 'n1', 'title' => 'Berita BNPB', 'url' => 'https://www.bnpb.go.id/berita/n1', 'published_at' => now()->subHours(20), 'fetched_at' => now()]);

        $this->get('/')->assertInertia(fn (Assert $p) => $p->has('official.items', 3));
        $this->get('/berita')->assertInertia(fn (Assert $p) => $p
            ->has('official.items', 13)
            ->where('official.items', fn ($items) => collect($items)->pluck('source')->contains('bnpb')));
    });

    it('menandai sumber yang lama tidak berhasil diambil sebagai mungkin basi', function () {
        ExternalNews::create(['source' => 'bnpb', 'external_id' => 'x', 'title' => 'T', 'url' => 'https://www.bnpb.go.id/x', 'published_at' => now()->subDay(), 'fetched_at' => now()->subHours(9)]);

        $this->get('/berita')->assertInertia(fn (Assert $p) => $p
            ->where('official.sources.0.key', 'bmkg')->where('official.sources.0.stale', true)
            ->where('official.sources.1.key', 'bnpb')->where('official.sources.1.stale', true));
    });

    it('mengganti filter gempa (reload parsial) tidak menghitung ulang kabar resmi', function () {
        $version = $this->get('/berita')->viewData('page')['version'];

        $this->get('/berita?min_magnitude=5', ['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'Berita/Index', 'X-Inertia-Partial-Data' => 'events,pagination,filters', 'X-Inertia-Version' => $version])
            ->assertOk()->assertJsonMissingPath('props.official');
    });
});
