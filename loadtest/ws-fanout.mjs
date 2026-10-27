// Uji beban: siaran gempa ke banyak klien WebSocket sekaligus (Reverb). Membuka N koneksi sebagai peramban yang
// membuka peta publik, memicu satu gempa, lalu mengukur berapa lama tiap klien menerimanya.
//
//   docker compose exec -u sail laravel.test node loadtest/ws-fanout.mjs --clients=300
//
// Memakai WebSocket bawaan Node 22+ dan protokol Pusher (yang dipakai Reverb).

import { spawn } from 'node:child_process';
import { readFileSync } from 'node:fs';

const arg = (name, fallback) => (process.argv.find((a) => a.startsWith(`--${name}=`)) ?? `--${name}=${fallback}`).split('=')[1];

const CLIENTS = Number(arg('clients', 300));
const HOST = arg('host', 'reverb');
const PORT = arg('port', '8080');
const KEY = arg('key', (readFileSync('.env', 'utf8').match(/^REVERB_APP_KEY=(.+)$/m) ?? [])[1]?.trim());
const CHANNEL = 'public-disasters';
const EVENT = 'earthquake.detected';

if (!KEY) throw new Error('REVERB_APP_KEY tidak ditemukan; berikan --key=...');

const sockets = [];
const subscribed = new Set();
const received = new Map(); // indeks klien => waktu terima (ms, performance.now)
let connectErrors = 0;

const pct = (sorted, p) => sorted[Math.min(sorted.length - 1, Math.ceil((p / 100) * sorted.length) - 1)];

function open(index) {
    return new Promise((resolve) => {
        const ws = new WebSocket(`ws://${HOST}:${PORT}/app/${KEY}?protocol=7&client=loadtest&version=1.0&flash=false`);
        sockets.push(ws);

        ws.addEventListener('message', (event) => {
            const msg = JSON.parse(event.data);

            if (msg.event === 'pusher:connection_established') {
                ws.send(JSON.stringify({ event: 'pusher:subscribe', data: { channel: CHANNEL } }));
            } else if (msg.event === 'pusher_internal:subscription_succeeded') {
                subscribed.add(index);
                resolve();
            } else if (msg.event === 'pusher:ping') {
                ws.send(JSON.stringify({ event: 'pusher:pong', data: {} }));
            } else if (msg.event === EVENT && !received.has(index)) {
                received.set(index, performance.now());
            }
        });

        ws.addEventListener('error', () => {
            connectErrors++;
            resolve();
        });
    });
}

console.log(`Membuka ${CLIENTS} koneksi ke ws://${HOST}:${PORT} ...`);
const connectStart = performance.now();

// Dibuka bertahap supaya tidak membanjiri server pada detik pertama.
for (let i = 0; i < CLIENTS; i += 50) {
    await Promise.all(Array.from({ length: Math.min(50, CLIENTS - i) }, (_, k) => open(i + k)));
}

await new Promise((r) => setTimeout(r, 1000));
console.log(`Terhubung dan berlangganan: ${subscribed.size}/${CLIENTS} dalam ${((performance.now() - connectStart) / 1000).toFixed(1)} dtk (galat koneksi: ${connectErrors})`);

if (subscribed.size === 0) process.exit(1);

// Picu satu gempa nyata lewat perintah simulasi, sehingga seluruh jalur (rekam, antrean, siaran, Reverb) terukur.
const t0 = performance.now();
const child = spawn('php', ['artisan', 'quake:simulate', '--mag=6.0', '--lat=-8.1', '--lng=113.2', '--name=LT fanout']);
const exitTime = await new Promise((resolve) => child.on('close', () => resolve(performance.now())));
console.log(`Perintah simulasi selesai ${((exitTime - t0) / 1000).toFixed(2)} dtk setelah dipicu`);

// Tunggu penerimaan sampai semua klien menerima atau 30 detik.
const deadline = performance.now() + 30_000;
while (received.size < subscribed.size && performance.now() < deadline) await new Promise((r) => setTimeout(r, 100));

const latencies = [...received.values()].map((t) => t - t0).sort((a, b) => a - b);

console.log('\nHasil siaran:');
console.log(`  diterima : ${received.size}/${subscribed.size} klien`);

if (latencies.length) {
    console.log(`  waktu sejak dipicu (termasuk start perintah PHP ~1 dtk): p50 ${pct(latencies, 50).toFixed(0)} ms, p95 ${pct(latencies, 95).toFixed(0)} ms, maks ${latencies.at(-1).toFixed(0)} ms`);
    console.log(`  selisih penerima tercepat ke terlambat (sebaran fan-out): ${(latencies.at(-1) - latencies[0]).toFixed(0)} ms`);
}

for (const ws of sockets) ws.close();

process.exit(received.size === subscribed.size ? 0 : 1);
