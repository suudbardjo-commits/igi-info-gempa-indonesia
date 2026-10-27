// Membangun APK debug IGI. Cache Gradle diarahkan ke drive D karena drive C kecil.
//   npm run apk                                   -> memuat http://192.168.100.182:8000 (uji Wi-Fi)
//   $env:IGI_URL="https://igi.contoh.id"; npm run apk
import { spawnSync } from 'node:child_process';
import { copyFileSync, existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const env = {
    ...process.env,
    JAVA_HOME: process.env.JAVA_HOME || 'C:\\Program Files\\Microsoft\\jdk-17.0.19.10-hotspot',
    ANDROID_HOME: process.env.ANDROID_HOME || 'C:\\Users\\USER\\Android\\Sdk',
    GRADLE_USER_HOME: process.env.GRADLE_USER_HOME || 'D:\\Cache\\gradle',
};
env.ANDROID_SDK_ROOT = env.ANDROID_HOME;

function run(cmd, args, cwd) {
    console.log(`\n> ${cmd} ${args.join(' ')}`);
    const r = spawnSync(cmd, args, { cwd, env, stdio: 'inherit', shell: true });
    if (r.status !== 0) process.exit(r.status ?? 1);
}

run('npm', ['run', 'sync'], root);
// Jalur proyek memuat spasi, jadi perintah diberi tanda kutip (shell: true memecahnya pada spasi).
// versionCode harus naik setiap rilis agar Android menerimanya sebagai pembaruan. Menit sejak 1970 selalu naik dan muat di int 32-bit.
const versionCode = Math.floor(Date.now() / 60000);
const stamp = new Date().toISOString().slice(0, 16).replace(/[-:T]/g, '');
run(
    `"${join(root, 'android', 'gradlew.bat')}"`,
    ['--no-daemon', `-PigiVersionCode=${versionCode}`, `-PigiVersionName=1.0.${stamp}`, 'assembleDebug'],
    join(root, 'android'),
);
console.log(`\nVersi: 1.0.${stamp} (kode ${versionCode})`);

const built = join(root, 'android', 'app', 'build', 'outputs', 'apk', 'debug', 'app-debug.apk');

if (!existsSync(built)) {
    console.error('APK tidak ditemukan di', built);
    process.exit(1);
}

copyFileSync(built, join(root, 'igi-debug.apk'));
console.log('\nSelesai: igi-debug.apk');
