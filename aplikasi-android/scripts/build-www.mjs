// Membuat isi folder www: halaman cadangan yang tampil saat server IGI tidak terjangkau. Alamat server diambil dari
// capacitor.config.js (variabel IGI_URL), supaya tombol "Coba lagi" menuju alamat yang sama dengan yang dimuat aplikasi.
import { mkdirSync, writeFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const config = createRequire(import.meta.url)(join(root, 'capacitor.config.js'));
const url = config.server.url;
const host = new URL(url).host;

mkdirSync(join(root, 'www'), { recursive: true });

const page = (title, body) => `<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0A1630">
<title>${title}</title>
<style>
  :root { color-scheme: dark; }
  * { box-sizing: border-box; }
  body { margin: 0; min-height: 100dvh; display: grid; align-content: center; gap: 0; padding: 28px 24px; background: #0A1630; color: #fff;
         font: 16px/1.55 system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; }
  svg { width: 56px; height: 56px; }
  h1 { margin: 20px 0 8px; font-size: 1.9rem; line-height: 1.1; font-weight: 800; letter-spacing: .01em; }
  p { margin: 0 0 12px; max-width: 34rem; color: rgba(255,255,255,.78); }
  a.btn { display: inline-flex; align-items: center; min-height: 48px; margin-top: 8px; padding: 0 20px; border-radius: 8px; background: #fff; color: #0A1630;
          font-weight: 700; text-decoration: none; }
  a.btn:focus-visible { outline: 3px solid #6FE0EE; outline-offset: 3px; }
  small { display: block; margin-top: 22px; color: rgba(255,255,255,.62); }
  code { color: #6FE0EE; }
</style>
</head>
<body>
<svg viewBox="0 0 64 64" role="img" aria-label="IGI"><rect width="64" height="64" rx="15" fill="#14264f"/><circle cx="32" cy="32" r="22" fill="none" stroke="#6fe0ee" stroke-opacity=".28" stroke-width="2"/><circle cx="32" cy="32" r="14" fill="none" stroke="#6fe0ee" stroke-opacity=".55" stroke-width="2"/><path d="M6 33h13l4-5 5 17 6-30 5 22 3-4h16" fill="none" stroke="#6fe0ee" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
${body}
</body>
</html>
`;

writeFileSync(
    join(root, 'www', 'error.html'),
    page(
        'IGI tidak terhubung',
        `<h1>IGI belum bisa dibuka</h1>
<p>Aplikasi tidak menjangkau server IGI. Periksa bahwa HP tersambung ke jaringan yang benar dan server sedang berjalan.</p>
<p>Laporan yang sudah Anda catat di halaman Lapangan tetap tersimpan di HP ini dan terkirim sendiri setelah tersambung kembali.</p>
<a class="btn" href="${url}">Coba lagi</a>
<small>Server: <code>${host}</code></small>`,
    ),
);

// Berkas awal yang dibutuhkan Capacitor; aplikasi langsung menuju server, halaman ini hanya cadangan.
writeFileSync(join(root, 'www', 'index.html'), page('IGI', `<h1>IGI</h1><p>Membuka Info Gempa Indonesia…</p><a class="btn" href="${url}">Buka IGI</a>`));

console.log(`www dibuat untuk ${url}`);
