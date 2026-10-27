<?php

namespace App\Services\Weather;

use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;

/**
 * Membaca satu dokumen CAP 1.2. Pengamanan: ukuran dibatasi pemanggil, DOCTYPE/ENTITY ditolak (tanpa XXE),
 * jaringan dimatikan (LIBXML_NONET), entitas tidak diperluas. Poligon CAP "lintang,bujur" diubah ke GeoJSON [bujur, lintang].
 */
class CapParser
{
    private const NS = 'urn:oasis:names:tc:emergency:cap:1.2';

    /** @return array<string, mixed>|null null bila dokumen tidak sah */
    public function parse(string $xml): ?array
    {
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) {
            return null;
        }

        $prev = libxml_use_internal_errors(true);
        $doc = new DOMDocument;
        $ok = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (! $ok) {
            return null;
        }

        $x = new DOMXPath($doc);
        $x->registerNamespace('c', self::NS);
        $v = fn (string $path, ?\DOMNode $ctx = null): ?string => ($n = $x->query($path, $ctx)->item(0)) ? trim($n->textContent) : null;

        $identifier = $v('/c:alert/c:identifier');
        $status = $v('/c:alert/c:status');
        $msgType = $v('/c:alert/c:msgType');
        $sent = $this->time($v('/c:alert/c:sent'));
        if (! $identifier || ! $status || ! $msgType || ! $sent || strlen($identifier) > 160) {
            return null;
        }

        $info = $x->query('/c:alert/c:info')->item(0);
        $area = $info ? $x->query('c:area', $info)->item(0) : null;
        $polygons = [];
        $invalid = 0;
        if ($info) {
            foreach ($x->query('c:area/c:polygon', $info) as $p) {
                $ring = $this->ring(trim($p->textContent));
                $ring ? $polygons[] = $ring : $invalid++;
            }
        }

        $areaDescs = [];
        if ($info) {
            foreach ($x->query('c:area/c:areaDesc', $info) as $a) {
                $areaDescs[] = trim($a->textContent);
            }
        }

        return [
            'identifier' => $identifier,
            'sender' => $v('/c:alert/c:sender'),
            'sent' => $sent,
            'status' => $status,
            'msg_type' => $msgType,
            'references' => $this->references($v('/c:alert/c:references')),
            'event' => $info ? $v('c:event', $info) : null,
            'urgency' => $info ? $v('c:urgency', $info) : null,
            'severity' => $info ? $v('c:severity', $info) : null,
            'certainty' => $info ? $v('c:certainty', $info) : null,
            'effective' => $info ? $this->time($v('c:effective', $info) ?? $v('c:onset', $info)) : null,
            'expires' => $info ? $this->time($v('c:expires', $info)) : null,
            'issuer' => $info ? ($v('c:senderName', $info) ?? $v('/c:alert/c:sender')) : null,
            'headline' => $info ? $v('c:headline', $info) : null,
            'description' => $info ? $v('c:description', $info) : null,
            'instruction' => $info ? $v('c:instruction', $info) : null,
            'web' => $info ? $this->safeHttps($v('c:web', $info)) : null,
            'area' => $areaDescs ? implode('; ', array_unique($areaDescs)) : ($area ? null : null),
            'polygons' => $polygons,
            'invalid_polygons' => $invalid,
        ];
    }

    /** "lat,lon lat,lon ..." menjadi cincin GeoJSON [[lon,lat],...] tertutup, atau null bila tidak sah. */
    private function ring(string $text): ?array
    {
        $pts = [];
        foreach (preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY) as $pair) {
            $parts = explode(',', $pair);
            if (count($parts) !== 2 || ! is_numeric($parts[0]) || ! is_numeric($parts[1])) {
                return null;
            }
            [$lat, $lon] = [(float) $parts[0], (float) $parts[1]];
            if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
                return null;
            }
            $pts[] = [$lon, $lat];
        }
        if (count($pts) < 4) {
            return null;
        }
        if ($pts[0] !== end($pts)) {
            $pts[] = $pts[0];
        }

        return $pts;
    }

    /** "pengirim,identifier,waktu" dipisah spasi; hanya identifier yang dipakai. */
    private function references(?string $text): array
    {
        if (! $text) {
            return [];
        }
        $ids = [];
        foreach (preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY) as $ref) {
            $p = explode(',', $ref);
            if (count($p) >= 2) {
                $ids[] = $p[1];
            }
        }

        return $ids;
    }

    private function time(?string $t): ?string
    {
        if (! $t) {
            return null;
        }
        try {
            return CarbonImmutable::parse($t)->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }

    private function safeHttps(?string $u): ?string
    {
        $p = $u ? parse_url($u) : false;

        return ($p && ($p['scheme'] ?? '') === 'https' && ! isset($p['user']) && ! isset($p['pass']) && str_ends_with(strtolower($p['host'] ?? ''), '.bmkg.go.id')) ? $u : null;
    }
}
