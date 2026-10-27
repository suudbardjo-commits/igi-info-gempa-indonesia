// Uji beban: pembacaan publik (peta dan API GeoJSON) saat lonjakan pengunjung, misalnya sesudah gempa besar.
//
//   docker run --rm --network monitoringbencanaalam_sail -v "$PWD/loadtest:/s" grafana/k6 run /s/public-read.js
//   (lihat loadtest/README.md untuk menyiapkan instance uji dan variabel lingkungannya)

import http from 'k6/http';
import { check, sleep } from 'k6';

const BASE = __ENV.BASE_URL || 'http://laravel.test:8001';
const PEAK = Number(__ENV.PEAK_VUS || 60);

export const options = {
    scenarios: {
        lonjakan: {
            executor: 'ramping-vus',
            startVUs: 0,
            stages: [
                { duration: '10s', target: Math.round(PEAK / 3) },
                { duration: '20s', target: Math.round(PEAK / 3) },
                { duration: '10s', target: PEAK },
                { duration: '30s', target: PEAK },
                { duration: '10s', target: 0 },
            ],
        },
    },
    thresholds: {
        http_req_failed: ['rate<0.01'],
        // Server uji adalah artisan serve (bukan produksi); ambang ini patokan kegunaan, bukan target produksi.
        'http_req_duration{kind:api}': ['p(95)<1000'],
        'http_req_duration{kind:halaman}': ['p(95)<2000'],
    },
};

export function setup() {
    const res = http.get(`${BASE}/api/v1/disasters?limit=20`);
    const ids = res.json('features').map((f) => f.id);

    if (!ids.length) throw new Error('Tidak ada gempa di database; jalankan ingesti dulu.');

    return { ids };
}

export default function (data) {
    const roll = Math.random();
    let res;

    if (roll < 0.45) {
        res = http.get(`${BASE}/api/v1/disasters?hours=168&limit=50`, { tags: { name: 'daftar_gempa', kind: 'api' } });
    } else if (roll < 0.65) {
        res = http.get(`${BASE}/api/v1/camps`, { tags: { name: 'daftar_posko', kind: 'api' } });
    } else if (roll < 0.9) {
        const id = data.ids[Math.floor(Math.random() * data.ids.length)];
        res = http.get(`${BASE}/api/v1/disasters/${id}`, { tags: { name: 'rincian_gempa', kind: 'api' } });
    } else {
        res = http.get(`${BASE}/`, { tags: { name: 'halaman_peta', kind: 'halaman' } });
    }

    check(res, { 'status 200': (r) => r.status === 200 });
    sleep(0.5 + Math.random());
}
