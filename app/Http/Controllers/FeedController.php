<?php

namespace App\Http\Controllers;

use App\Models\DisasterEvent;
use Illuminate\Http\Response;

/** Atom feed gempa terbaru, untuk pembaca RSS dan layanan lain yang ingin memantau IGI. */
class FeedController extends Controller
{
    public function __invoke(): Response
    {
        $events = DisasterEvent::query()->publicFeed(hours: 168, limit: 50)->get();
        $e = fn (?string $s) => htmlspecialchars((string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $updated = ($events->first()?->occurred_at ?? now())->toAtomString();
        $self = url('/feed.xml');

        $entries = $events->map(function (DisasterEvent $ev) use ($e) {
            $mag = $ev->magnitude !== null ? 'M '.number_format($ev->magnitude, 1, ',', '') : 'Gempa';
            $depth = $ev->depth_km !== null ? ', kedalaman '.round($ev->depth_km).' km' : '';
            $link = url('/berita/'.$ev->id);

            return '<entry><title>'.$e("{$mag} di {$ev->location_name}").'</title>'
                .'<id>urn:igi:gempa:'.$e($ev->id).'</id>'
                .'<link rel="alternate" type="text/html" href="'.$e($link).'"/>'
                .'<updated>'.$ev->occurred_at->toAtomString().'</updated>'
                .'<summary>'.$e("Tercatat {$ev->occurred_at->setTimezone('Asia/Jakarta')->format('d-m-Y H:i')} WIB{$depth}. Sumber: ".strtoupper($ev->source->value).'.').'</summary>'
                .'</entry>';
        })->implode('');

        $xml = '<?xml version="1.0" encoding="UTF-8"?><feed xmlns="http://www.w3.org/2005/Atom" xml:lang="id">'
            .'<title>IGI, Info Gempa Indonesia</title><subtitle>Gempa terbaru di Indonesia dari BMKG dan USGS</subtitle>'
            .'<id>urn:igi:feed:gempa</id><updated>'.$updated.'</updated>'
            .'<link rel="self" type="application/atom+xml" href="'.$e($self).'"/><link rel="alternate" type="text/html" href="'.$e(url('/')).'"/>'
            .$entries.'</feed>';

        return response($xml, 200, ['Content-Type' => 'application/atom+xml; charset=UTF-8'])->setPublic()->setMaxAge(60);
    }
}
