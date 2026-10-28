// Uji unit pustaka pusat komando: geometri, fisika gelombang, parsing data BMKG, dan perhitungan kamera.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { bearingDeg, compass8, haversineKm, offsetCenterPx, parseCoordinates } from '../../resources/js/command/lib/geo.js';
import { arrivalSeconds, feltRadiusKm, magnitudeColor, parseDepthKm, parseMmi, proximityBadge, surfaceRadiusKm, VP, VS } from '../../resources/js/command/lib/seismic.js';
import { mergeQuakes, normalizeQuake, parseFeed } from '../../resources/js/command/lib/bmkg.js';
import { fmtDuration } from '../../resources/js/command/lib/format.js';

test('haversine: Jakarta ke Surabaya sekitar 665 km; titik sama = 0', () => {
    const d = haversineKm(-6.2088, 106.8456, -7.2575, 112.7521);
    assert.ok(d > 655 && d < 675, `jarak ${d}`);
    assert.equal(haversineKm(1, 2, 1, 2), 0);
});

test('bearing dan 8 arah mata angin', () => {
    assert.ok(Math.abs(bearingDeg(0, 0, 1, 0) - 0) < 0.01); // utara
    assert.ok(Math.abs(bearingDeg(0, 0, 0, 1) - 90) < 0.01); // timur
    assert.ok(Math.abs(bearingDeg(0, 0, -1, 0) - 180) < 0.01); // selatan
    assert.ok(Math.abs(bearingDeg(0, 0, 0, -1) - 270) < 0.01); // barat
    assert.equal(compass8(0).singkat, 'U');
    assert.equal(compass8(44).singkat, 'TL');
    assert.equal(compass8(135).nama, 'Tenggara');
    assert.equal(compass8(359).singkat, 'U');
    assert.equal(compass8(292).singkat, 'B'); // 292 -> sektor Barat (270..292.5 dibulatkan ke 270)
    assert.equal(compass8(300).singkat, 'BL');
});

test('koordinat BMKG "lat,lng" diurai; format salah ditolak', () => {
    assert.deepEqual(parseCoordinates('5.59,125.27'), { lat: 5.59, lng: 125.27 });
    assert.deepEqual(parseCoordinates('-8.2, 114.3'), { lat: -8.2, lng: 114.3 });
    for (const bad of ['', 'a,b', '1,2,3', '95,10', '10,200', null, undefined, 5]) assert.equal(parseCoordinates(bad), null, String(bad));
});

test('rumus gelombang permukaan: R = sqrt((v t)^2 - h^2)', () => {
    const h = 30;
    assert.equal(surfaceRadiusKm(VP, 0, h), 0);
    assert.equal(surfaceRadiusKm(VP, h / VP, h), 0); // tepat di episentrum
    assert.equal(surfaceRadiusKm(VP, 4, 0), 24); // h=0: R = v t
    const r = surfaceRadiusKm(VS, 20, 40); // v t = 70 km
    assert.ok(Math.abs(r - Math.sqrt(70 ** 2 - 40 ** 2)) < 1e-9);
    assert.ok(surfaceRadiusKm(VP, 10, h) > surfaceRadiusKm(VS, 10, h)); // P lebih cepat dari S
});

test('waktu tiba memakai jarak hiposentral', () => {
    assert.ok(Math.abs(arrivalSeconds(VS, 120, 50) - Math.hypot(120, 50) / 3.5) < 1e-9);
    // Saat ETA habis, radius permukaan S sama dengan jarak episentral.
    const t = arrivalSeconds(VS, 120, 50);
    assert.ok(Math.abs(surfaceRadiusKm(VS, t, 50) - 120) < 1e-6);
});

test('warna magnitudo: <5 hijau/kuning, 5-6 oranye, >6 merah', () => {
    assert.equal(magnitudeColor(3.2), '#22C55E');
    assert.equal(magnitudeColor(4.6), '#EAB308');
    assert.equal(magnitudeColor(5.0), '#F97316');
    assert.equal(magnitudeColor(6.0), '#F97316');
    assert.equal(magnitudeColor(6.1), '#EF4444');
    assert.equal(magnitudeColor(null), '#9CA3AF');
});

test('badge kedekatan: bahaya (<100 km dan M>=5), terasa, di luar dampak', () => {
    assert.equal(proximityBadge(60, 5.4).kode, 'bahaya');
    assert.equal(proximityBadge(60, 4.9).kode, 'terasa'); // dalam radius terasa M4.9 (~180 km) tetapi bukan bahaya
    assert.equal(proximityBadge(150, 5.4).kode, 'terasa');
    assert.equal(proximityBadge(900, 5.4).kode, 'luar');
    assert.equal(proximityBadge(80, 3.0).kode, 'luar'); // M3 hanya ~25 km
    assert.ok(feltRadiusKm(6) > feltRadiusKm(5));
});

test('MMI dan kedalaman', () => {
    assert.equal(parseMmi('III Tomohon'), 'III');
    assert.equal(parseMmi('II-III Manado'), 'II-III');
    assert.equal(parseMmi('-'), null);
    assert.equal(parseMmi(undefined), null);
    assert.equal(parseDepthKm('10 km'), 10);
    assert.equal(parseDepthKm('abc'), null);
});

const raw = { DateTime: '2026-10-28T01:11:54+00:00', Coordinates: '5.59,125.27', Magnitude: '5.4', Kedalaman: '10 km', Wilayah: '221 km BaratLaut PULAUKARATUNG-SULUT', Potensi: 'Tidak berpotensi tsunami', Dirasakan: 'III Tomohon', Shakemap: '20261028081433.mmi.jpg', Lintang: '99 LS', Bujur: '1 BB' };

test('normalisasi BMKG: koordinat dari field Coordinates, bukan teks Lintang/Bujur; Shakemap URL', () => {
    const q = normalizeQuake(raw, 'terbaru');
    assert.equal(q.lat, 5.59);
    assert.equal(q.lng, 125.27);
    assert.equal(q.mag, 5.4);
    assert.equal(q.depthKm, 10);
    assert.equal(q.mmi, 'III');
    assert.equal(q.shakemapUrl, 'https://data.bmkg.go.id/DataMKG/TEWS/20261028081433.mmi.jpg');
    assert.equal(normalizeQuake({ ...raw, Coordinates: 'x' }, 'terbaru'), null);
    assert.equal(normalizeQuake({ ...raw, Magnitude: 'tinggi' }, 'terbaru'), null);
    assert.equal(normalizeQuake({ ...raw, DateTime: 'bukan tanggal' }, 'terbaru'), null);
    assert.equal(normalizeQuake({ ...raw, Dirasakan: '-' }, 'terbaru').felt, null);
});

test('parseFeed menerima objek tunggal dan larik; entri rusak dibuang; gabungan tanpa duplikat', () => {
    assert.equal(parseFeed({ Infogempa: { gempa: raw } }, 'terbaru').length, 1);
    assert.equal(parseFeed({ Infogempa: { gempa: [raw, { ...raw, Coordinates: 'zz' }] } }, 'terkini').length, 1);
    assert.equal(parseFeed({}, 'terkini').length, 0);
    const a = normalizeQuake({ ...raw, Shakemap: undefined, Dirasakan: '-' }, 'terkini');
    const b = normalizeQuake(raw, 'terbaru');
    const m = mergeQuakes([a], [b]);
    assert.equal(m.length, 1);
    assert.equal(m[0].shakemapUrl !== null, true); // data terlengkap digabung
});

test('kamera offset: titik jatuh di tengah area yang tidak tertutup', () => {
    // Ponsel 400x800, ticker 32 di atas, sheet 220 di bawah: tengah area kosong y = 32 + (800-32-220)/2 = 306.
    const o = offsetCenterPx({ x: 400, y: 800 }, { top: 32, bottom: 220 });
    assert.equal(o.dx, 0);
    assert.equal(o.dy, 400 - 306);
    // Desktop 1200x700, panel 372 di kiri: tengah x = 372 + (1200-372)/2 = 786.
    const d = offsetCenterPx({ x: 1200, y: 700 }, { top: 32, left: 372 });
    assert.equal(d.dx, 600 - 786);
    assert.equal(d.dy, 350 - (32 + (700 - 32) / 2));
    assert.deepEqual(offsetCenterPx({ x: 100, y: 100 }, {}), { dx: 0, dy: 0 });
});

test('format durasi', () => {
    assert.equal(fmtDuration(75), '01:15');
    assert.equal(fmtDuration(3725), '1:02:05');
    assert.equal(fmtDuration(-5), '00:00');
    assert.equal(fmtDuration(null), '--:--');
});
