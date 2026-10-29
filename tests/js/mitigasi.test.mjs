// Uji pustaka mitigasi pusat komando: ingest multi-sumber, estimasi MMI, buletin suara, dan laporan warga.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { alertLevelFor, buildBulletin, tsunamiDanger } from '../../resources/js/command/lib/alarm.js';
import { COOLDOWN_MS, buildReport, clusterReports, cooldownLeft, flushQueue, queueSize, submitReport } from '../../resources/js/command/lib/dyfi.js';
import { backoffMs, inIndonesia, matchOfficial, normalizeEmsc, normalizeUsgs, sameEvent } from '../../resources/js/command/lib/ingest.js';
import { describeMmi, estimateLocalMmi, hypocentralKm, mmiValue } from '../../resources/js/command/lib/mmi.js';

const memory = () => {
    const m = new Map();

    return { getItem: (k) => m.get(k) ?? null, setItem: (k, v) => m.set(k, String(v)), removeItem: (k) => m.delete(k) };
};

const emscMsg = (over = {}, action = 'create') => ({ action, data: { type: 'Feature', id: '20261029_0000123', geometry: { type: 'Point', coordinates: [125.27, 5.59, -10] }, properties: { time: '2026-10-29T03:00:00.0Z', lat: 5.59, lon: 125.27, depth: 10, mag: 5.4, flynn_region: 'MINDANAO, PHILIPPINES', ...over } } });

test('inIndonesia memakai kotak -11..6 lintang dan 95..141 bujur', () => {
    assert.equal(inIndonesia(-6.2, 106.8), true);
    assert.equal(inIndonesia(7, 120), false);
    assert.equal(inIndonesia(-12, 120), false);
    assert.equal(inIndonesia(0, 94), false);
    assert.equal(inIndonesia(NaN, 100), false);
});

test('EMSC: pesan create di Indonesia menjadi kejadian pending; luar Indonesia dan pesan rusak ditolak', () => {
    const q = normalizeEmsc(emscMsg({ lat: -2.1, lon: 120.3, flynn_region: 'SULAWESI, INDONESIA' }));
    assert.equal(q.source, 'emsc');
    assert.equal(q.validation, 'pending');
    assert.equal(q.mag, 5.4);
    assert.equal(q.depthKm, 10);
    assert.equal(q.lat, -2.1);
    assert.equal(normalizeEmsc(emscMsg({ lat: 35, lon: 139 })), null); // Jepang
    assert.equal(normalizeEmsc(emscMsg({}, 'delete')), null);
    assert.equal(normalizeEmsc('bukan json'), null);
    assert.equal(normalizeEmsc(JSON.stringify(emscMsg({ lat: -2.1, lon: 120.3 }))).action, 'create');
    assert.equal(normalizeEmsc(emscMsg({ lat: -2.1, lon: 120.3, mag: 'x' })), null);
});

test('USGS: hanya fitur di kotak Indonesia, terbaru dahulu, tanpa entri rusak', () => {
    const f = (id, lon, lat, mag, time, depth = 10) => ({ id, properties: { mag, time, place: `tempat ${id}` }, geometry: { coordinates: [lon, lat, depth] } });
    const list = normalizeUsgs({ features: [f('a', 120, -2, 4.5, 1000), f('b', -120, 35, 5, 2000), f('c', 110, -7, 4.8, 3000), f('d', 110, -7, null, 4000)] });
    assert.deepEqual(list.map((q) => q.key), ['usgs:c', 'usgs:a']);
    assert.equal(list[0].validation, 'fallback');
    assert.equal(normalizeUsgs(null).length, 0);
});

test('pencocokan EMSC dengan data resmi: waktu +/- 3 menit dan jarak <= 150 km', () => {
    const base = { time: new Date('2026-10-29T03:00:00Z'), lat: 5.5, lng: 125.2 };
    const near = { ...base, time: new Date('2026-10-29T03:01:30Z'), lat: 5.6, lng: 125.3, key: 'bmkg:1' };
    const farTime = { ...base, time: new Date('2026-10-29T03:20:00Z'), key: 'bmkg:2' };
    const farPlace = { ...base, lat: 0, lng: 120, key: 'bmkg:3' };
    assert.equal(sameEvent(base, near), true);
    assert.equal(sameEvent(base, farTime), false);
    assert.equal(sameEvent(base, farPlace), false);
    const e1 = { ...base, key: 'emsc:1' };
    const e2 = { ...base, lat: -3, lng: 130, key: 'emsc:2' };
    const { pending, matched } = matchOfficial([e1, e2], [near, farTime]);
    assert.deepEqual(pending.map((p) => p.key), ['emsc:2']);
    assert.equal(matched.get('emsc:1').key, 'bmkg:1');
});

test('backoff sambung ulang: 2, 4, 8 detik, maksimum 60 detik', () => {
    assert.deepEqual([0, 1, 2, 3].map(backoffMs), [2000, 4000, 8000, 16000]);
    assert.equal(backoffMs(20), 60_000);
});

test('hiposentrum dan MMI: makin jauh makin kecil, makin kuat makin besar, tak sah menjadi null', () => {
    assert.equal(hypocentralKm(30, 40), 50);
    const near = mmiValue(6, 10, 20);
    const far = mmiValue(6, 10, 200);
    assert.ok(near > far);
    assert.ok(mmiValue(7, 10, 50) > mmiValue(5, 10, 50));
    assert.ok(mmiValue(5, 10, 50) >= 5 && mmiValue(5, 10, 50) < 6.5, `M5 di 50 km: ${mmiValue(5, 10, 50)}`);
    assert.equal(mmiValue(3, 10, 400), 1); // jauh sekali: dasar skala
    assert.ok(mmiValue(4, 5, 0) <= 6.5); // dekat sumber dibatasi menurut magnitudo
    assert.equal(mmiValue(NaN, 10, 10), null);
    assert.equal(mmiValue(5, 10, -1), null);
    assert.ok(mmiValue(5, null, 50) > 0); // kedalaman tak ada dianggap 10 km
});

test('deskripsi MMI: angka Romawi, label, dan saran tindakan terisi untuk seluruh skala', () => {
    for (let v = 1; v <= 12; v++) {
        const d = describeMmi(v);
        assert.ok(d.roman && d.label && d.body && d.action, `MMI ${v}`);
    }
    assert.equal(describeMmi(1).roman, 'I');
    assert.equal(describeMmi(5.4).roman, 'V');
    assert.equal(describeMmi(12).roman, 'XII');
    assert.equal(describeMmi(40).roman, 'XII');
    assert.equal(describeMmi(NaN), null);
    const est = estimateLocalMmi({ magnitude: 6.2, depthKm: 20, distanceKm: 40 });
    assert.ok(est.roman && est.hypoKm > 40);
});

test('tingkat sirene: M >= 5 = warning, tsunami = danger, M < 5 tanpa sirene', () => {
    assert.equal(alertLevelFor({ mag: 4.9, tsunami: null }), null);
    assert.equal(alertLevelFor({ mag: 5, tsunami: 'Tidak berpotensi tsunami' }), 'warning');
    assert.equal(alertLevelFor({ mag: 4.2, tsunami: 'Berpotensi tsunami' }), 'danger');
    assert.equal(alertLevelFor(null), null);
    assert.equal(tsunamiDanger('Tidak berpotensi tsunami'), false);
    assert.equal(tsunamiDanger('Gempa ini berpotensi tsunami untuk diteruskan pada masyarakat'), true);
});

test('buletin suara memuat magnitudo (koma), wilayah, status tsunami, dan anjuran aman', () => {
    const t = buildBulletin({ mag: 5.4, region: 'Pusat gempa di laut 40 km Barat Daya Poso', tsunami: 'Tidak berpotensi tsunami' });
    assert.match(t, /magnitudo 5,4/);
    assert.match(t, /Barat Daya Poso/);
    assert.match(t, /Tidak berpotensi tsunami\./);
    assert.match(t, /tempat aman/);
    assert.match(buildBulletin({ mag: 5, region: 'X', tsunami: null }), /menunggu informasi resmi/);
    assert.match(buildBulletin({ mag: 7, region: 'X', tsunami: 'Berpotensi tsunami' }), /Perhatian, Berpotensi tsunami/);
});

test('laporan DYFI: koordinat dibulatkan 2 desimal, tingkat tak sah ditolak', () => {
    const now = new Date('2026-10-29T03:00:00Z');
    const r = buildReport('strong', { lat: -6.123456, lng: 106.987654 }, { now, quakeKey: 'bmkg:1' });
    assert.deepEqual(r, { level: 'strong', lat: -6.12, lng: 106.99, felt_at: now.toISOString(), quake_key: 'bmkg:1' });
    assert.equal(buildReport('parah', { lat: 1, lng: 1 }), null);
    assert.equal(buildReport('none', null), null);
    assert.equal(buildReport('none', { lat: NaN, lng: 1 }), null);
});

test('laporan DYFI: kegagalan jaringan masuk antrean lalu terkirim saat kembali daring', async () => {
    const storage = memory();
    const payload = buildReport('light', { lat: -6, lng: 106 });
    const down = async () => { throw new Error('offline'); };
    assert.equal(await submitReport(payload, { fetchFn: down, storage, now: 1000 }), 'antre');
    assert.equal(queueSize(storage), 1);
    assert.ok(cooldownLeft(storage, 1000 + 1000) > 0);
    assert.equal(cooldownLeft(storage, 1000 + COOLDOWN_MS + 1), 0);
    assert.equal(await flushQueue({ fetchFn: down, storage }), 0);
    assert.equal(queueSize(storage), 1);
    const up = async () => ({ ok: true, status: 201 });
    assert.equal(await flushQueue({ fetchFn: up, storage }), 1);
    assert.equal(queueSize(storage), 0);
});

test('laporan DYFI: ditolak server (422/429) tidak masuk antrean', async () => {
    const storage = memory();
    const payload = buildReport('none', { lat: -6, lng: 106 });
    assert.equal(await submitReport(payload, { fetchFn: async () => ({ ok: false, status: 429 }), storage }), 'ditolak');
    assert.equal(queueSize(storage), 0);
    assert.equal(await submitReport(payload, { fetchFn: async () => ({ ok: true, status: 201 }), storage, now: 5 }), 'terkirim');
});

test('pengelompokan laporan: sel 0,1 derajat, warna = tingkat tertinggi, jumlah benar', () => {
    const pts = [
        { level: 'none', lat: -6.21, lng: 106.81 },
        { level: 'strong', lat: -6.22, lng: 106.82 },
        { level: 'light', lat: -6.23, lng: 106.83 },
        { level: 'light', lat: -8.5, lng: 115.2 },
        { level: 'tak-dikenal', lat: 1, lng: 1 },
        { level: 'none', lat: NaN, lng: 1 },
    ];
    const cl = clusterReports(pts).sort((a, b) => b.count - a.count);
    assert.equal(cl.length, 2);
    assert.equal(cl[0].count, 3);
    assert.equal(cl[0].level, 'strong');
    assert.deepEqual(cl[0].counts, { none: 1, light: 1, strong: 1 });
    assert.equal(cl[1].level, 'light');
    assert.deepEqual(clusterReports(null), []);
});
