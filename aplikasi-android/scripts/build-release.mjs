// Membangun APK rilis (untuk dibagikan) dan AAB (untuk Play Store), ditandatangani kunci rilis IGI.
//   $env:IGI_URL="https://igi.contoh.id"; npm run apk:release
// Wajib https: aplikasi hanya membungkus situs, jadi alamatnya harus bisa dijangkau siapa pun yang memasangnya.
import { spawnSync } from 'node:child_process';
import { copyFileSync, existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const url = process.env.IGI_URL || '';

if (!/^https:\/\/[a-z0-9.-]+\.[a-z]{2,}(:\d+)?\/?$/i.test(url)) {
    console.error('IGI_URL wajib berupa alamat https publik, contoh: $env:IGI_URL="https://igi.contoh.id"');
    console.error('Alamat Wi-Fi/IP lokal tidak bisa dibuka orang lain, jadi build rilis menolaknya.');
    process.exit(2);
}

const propsFile = process.env.IGI_SIGNING_PROPS || 'D:\\Cache\\kunci-apk\\igi-release.properties';
if (!existsSync(propsFile)) {
    console.error(`Berkas kunci rilis tidak ada: ${propsFile}`);
    process.exit(2);
}

const sdk = process.env.ANDROID_HOME || 'C:\\Users\\USER\\Android\\Sdk';
const env = {
    ...process.env,
    IGI_URL: url,
    IGI_SIGNING_PROPS: propsFile,
    JAVA_HOME: process.env.JAVA_HOME || 'C:\\Program Files\\Microsoft\\jdk-17.0.19.10-hotspot',
    ANDROID_HOME: sdk,
    ANDROID_SDK_ROOT: sdk,
    GRADLE_USER_HOME: process.env.GRADLE_USER_HOME || 'D:\\Cache\\gradle',
};

function run(cmd, args, cwd) {
    console.log(`\n> ${cmd} ${args.join(' ')}`);
    const r = spawnSync(cmd, args, { cwd, env, stdio: 'inherit', shell: true });
    if (r.status !== 0) process.exit(r.status ?? 1);
}

run('npm', ['run', 'sync'], root);
const versionCode = Math.floor(Date.now() / 60000);
const stamp = new Date().toISOString().slice(0, 16).replace(/[-:T]/g, '');
run(
    `"${join(root, 'android', 'gradlew.bat')}"`,
    ['--no-daemon', `-PigiVersionCode=${versionCode}`, `-PigiVersionName=1.0.${stamp}`, 'assembleRelease', 'bundleRelease'],
    join(root, 'android'),
);

const apk = join(root, 'android', 'app', 'build', 'outputs', 'apk', 'release', 'app-release.apk');
const aab = join(root, 'android', 'app', 'build', 'outputs', 'bundle', 'release', 'app-release.aab');
if (!existsSync(apk)) {
    console.error('APK rilis tidak ditemukan (kemungkinan tidak bertanda tangan):', apk);
    process.exit(1);
}
copyFileSync(apk, join(root, 'igi-release.apk'));
if (existsSync(aab)) copyFileSync(aab, join(root, 'igi-release.aab'));
console.log(`\nVersi 1.0.${stamp} (kode ${versionCode}), memuat ${url}\nSelesai: igi-release.apk${existsSync(aab) ? ' dan igi-release.aab' : ''}`);
