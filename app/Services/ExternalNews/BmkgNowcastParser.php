<?php

namespace App\Services\ExternalNews;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;

/** RSS "Peringatan Dini Cuaca" BMKG. Murni mengurai teks; tidak melakukan akses jaringan. */
class BmkgNowcastParser
{
    /** @return list<array{external_id: string, title: string, summary: ?string, url: string, published_at: CarbonImmutable}> */
    public function parse(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);

        try {
            // LIBXML_NONET: tidak boleh mengambil sumber daya eksternal lewat jaringan saat mengurai.
            $feed = simplexml_load_string($xml, options: LIBXML_NONET | LIBXML_NOCDATA);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($feed === false || ! isset($feed->channel)) {
            return [];
        }

        $items = [];

        foreach ($feed->channel->item as $item) {
            try {
                $published = CarbonImmutable::parse((string) $item->pubDate);
            } catch (Throwable) {
                continue;
            }

            $title = trim((string) $item->title);
            $url = trim((string) $item->link);
            $id = trim((string) $item->guid) ?: $url;

            if ($title === '' || $url === '' || $id === '') {
                continue;
            }

            $items[] = [
                'external_id' => $id,
                'title' => $title,
                'summary' => $this->summarize((string) $item->description),
                'url' => $url,
                'published_at' => $published,
            ];
        }

        return $items;
    }

    /** Kalimat pertama saja: daftar kecamatan yang menyusulnya panjang dan ada di sumber. */
    private function summarize(string $description): ?string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($description)));

        if ($text === '') {
            return null;
        }

        $head = Str::before($text, ', khususnya');

        if ($head !== $text) {
            return rtrim($head, '. ').'.';
        }

        return Str::limit($text, 280, '…', preserveWords: true);
    }
}
