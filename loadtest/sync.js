// Uji beban: sinkronisasi banyak perangkat lapangan sekaligus (misalnya sinyal baru pulih di seluruh wilayah).
// Sebagian operasi sengaja dikirim ulang untuk membuktikan idempotensi tetap benar di bawah beban.
//
// Siapkan data dulu: php artisan loadtest:prepare --users=30 --camps=10

import http from 'k6/http';
import { check, fail, sleep } from 'k6';
import { Counter } from 'k6/metrics';

const BASE = __ENV.BASE_URL || 'http://laravel.test:8001';
const USERS = Number(__ENV.USERS || 30);
const BATCH = Number(__ENV.BATCH || 20);
const PEAK = Number(__ENV.PEAK_VUS || 30);

const opsKirim = new Counter('ops_dikirim');
const opsKirimUlang = new Counter('ops_dikirim_ulang');
const hasilApplied = new Counter('hasil_applied');
const hasilStale = new Counter('hasil_applied_stale');
const hasilDuplicate = new Counter('hasil_duplicate');
const hasilRetry = new Counter('hasil_retry');
const hasilRejected = new Counter('hasil_rejected');

export const options = {
    scenarios: {
        sinkron: {
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
        'http_req_duration{name:sinkron}': ['p(95)<2500'],
        // Tidak boleh ada operasi yang ditolak atau perlu diulang pada data yang sah.
        hasil_retry: ['count==0'],
        hasil_rejected: ['count==0'],
    },
};

const json = { 'Content-Type': 'application/json', Accept: 'application/json' };
const auth = (token) => ({ headers: { ...json, Authorization: `Bearer ${token}` } });

function uuid4() {
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
        const r = (Math.random() * 16) | 0;

        return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
    });
}

export function setup() {
    const users = [];

    for (let i = 1; i <= USERS; i++) {
        const login = http.post(`${BASE}/api/v1/auth/token`, JSON.stringify({ email: `loadtest${i}@bencana.test`, password: 'password', device_name: 'k6' }), { headers: json });
        if (login.status !== 200) fail(`login loadtest${i} gagal (${login.status}); jalankan loadtest:prepare dan naikkan RATE_LIMIT_LOGIN`);

        const token = login.json('token');
        const overview = http.get(`${BASE}/api/v1/field/overview`, auth(token));

        users.push({ token, camps: overview.json('camps').map((c) => c.id) });
    }

    return { users };
}

// Status per pekerja virtual (modul dimuat sekali per VU).
let sent = [];

export default function (data) {
    const me = data.users[(__VU - 1) % data.users.length];
    const ops = [];
    let replays = 0;

    for (let i = 0; i < BATCH; i++) {
        // ~15% operasi adalah pengiriman ulang persis dari yang pernah dikirim (jaringan putus sebelum jawaban sampai).
        if (sent.length > 10 && Math.random() < 0.15) {
            ops.push(sent[Math.floor(Math.random() * sent.length)]);
            replays++;
            continue;
        }

        const camp = me.camps[Math.floor(Math.random() * me.camps.length)];
        const recordedAt = new Date(Date.now() - Math.floor(Math.random() * 3600 * 1000)).toISOString();
        const op =
            Math.random() < 0.8
                ? { id: uuid4(), type: 'occupancy_report', recorded_at: recordedAt, payload: { camp_id: camp, occupants: Math.floor(Math.random() * 480) } }
                : { id: uuid4(), type: 'condition_report', recorded_at: recordedAt, payload: { camp_id: camp, severity: Math.random() < 0.7 ? 'aman' : 'waspada', note: 'uji beban' } };

        ops.push(op);
        sent.push(op);
    }

    const res = http.post(`${BASE}/api/v1/sync/field-logs`, JSON.stringify({ operations: ops }), { ...auth(me.token), tags: { name: 'sinkron' } });

    const ok = check(res, {
        'status 200': (r) => r.status === 200,
        'satu hasil per operasi': (r) => r.status === 200 && r.json('results').length === ops.length,
    });

    opsKirim.add(ops.length);
    opsKirimUlang.add(replays);

    if (ok) {
        for (const r of res.json('results')) {
            if (r.status === 'applied') hasilApplied.add(1);
            else if (r.status === 'applied_stale') hasilStale.add(1);
            else if (r.status === 'duplicate') hasilDuplicate.add(1);
            else if (r.status === 'retry') hasilRetry.add(1);
            else hasilRejected.add(1);
        }
    }

    sleep(0.5);
}
