<?php

namespace App\Services\ExternalNews;

use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Daftar berita di halaman BNPB (tidak ada RSS yang terbuka). Hanya judul, tanggal, dan tautan yang diambil; gambar dan
 * isi artikel tidak disalin. Murni mengurai teks; tidak melakukan akses jaringan.
 */
class BnpbListParser
{
    private const MONTHS = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'mei' => 5, 'jun' => 6, 'jul' => 7, 'agu' => 8, 'sep' => 9, 'okt' => 10, 'nov' => 11, 'des' => 12];

    private const ZONES = ['WIB' => '+07:00', 'WITA' => '+08:00', 'WIT' => '+09:00'];

    /** @return list<array{external_id: string, title: string, summary: ?string, url: string, published_at: CarbonImmutable}> */
    public function parse(string $html): array
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($dom);
        $items = [];

        foreach ($xpath->query('//a[@data-type="news"][@href]') as $link) {
            /** @var DOMElement $link */
            $url = trim($link->getAttribute('href'));
            $card = $xpath->query('ancestor::div[contains(concat(" ", normalize-space(@class), " "), " block ")][1]', $link)->item(0);

            if (! $card) {
                continue;
            }

            // Teks tautan dipotong dengan "..."; teks lengkapnya ada di atribut alt gambar kartu.
            $alt = $xpath->query('.//img/@alt', $card)->item(0)?->nodeValue;
            $title = $this->clean($alt ?: $link->textContent);
            $date = $this->parseDate($this->clean($xpath->query('.//*[contains(@class,"date")]', $card)->item(0)?->textContent ?? ''));

            if ($title === '' || $url === '' || ! $date) {
                continue;
            }

            $items[] = ['external_id' => $url, 'title' => $title, 'summary' => null, 'url' => $url, 'published_at' => $date];
        }

        return $items;
    }

    private function clean(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    /** "23 Okt 2026 | 18:30 WIB" */
    private function parseDate(string $text): ?CarbonImmutable
    {
        if (! preg_match('/(\d{1,2})\s+([A-Za-z]{3,})\s+(\d{4})\D+(\d{1,2}):(\d{2})\s*(WIB|WITA|WIT)?/u', $text, $m)) {
            return null;
        }

        $month = self::MONTHS[strtolower(substr($m[2], 0, 3))] ?? null;

        if (! $month) {
            return null;
        }

        return CarbonImmutable::create((int) $m[3], $month, (int) $m[1], (int) $m[4], (int) $m[5], 0, self::ZONES[$m[6] ?? 'WIB']);
    }
}
