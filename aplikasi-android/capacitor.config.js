// Alamat server IGI yang dimuat aplikasi. Ganti lewat variabel lingkungan IGI_URL saat menjalankan `npm run sync`:
//   $env:IGI_URL = "https://igi.contoh.id"; npm run sync
// Bawaan: alamat PC pengembang di jaringan Wi-Fi yang sama (hanya untuk uji).
const url = process.env.IGI_URL || 'http://192.168.100.182:8000';
const secure = url.startsWith('https://');

/** @type {import('@capacitor/cli').CapacitorConfig} */
module.exports = {
    appId: 'id.igi.gempa',
    appName: 'IGI',
    webDir: 'www',
    backgroundColor: '#0A1630',
    server: {
        url,
        // Lalu lintas http polos hanya diizinkan untuk uji lewat Wi-Fi. Build rilis wajib memakai alamat https,
        // dan opsi ini otomatis mati.
        cleartext: !secure,
        // Bila server tidak terjangkau, tampilkan halaman cadangan di dalam aplikasi (bukan layar galat peramban).
        errorPath: 'error.html',
        allowNavigation: [new URL(url).host, 'www.bmkg.go.id', 'www.bnpb.go.id', 'www.youtube-nocookie.com', 'www.youtube.com'],
    },
    android: {
        allowMixedContent: false,
    },
};
