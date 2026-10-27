/**
 * UUID versi 7 (RFC 9562): 48 bit waktu milidetik, sisanya acak. Dibuat di perangkat saat laporan dicatat,
 * jadi server bisa mengenali pengiriman ulang dan perangkat tidak perlu menunggu server untuk memberi ID.
 * Urut menurut waktu, sehingga ramah indeks database.
 */
export function uuid7() {
    const bytes = new Uint8Array(16);
    crypto.getRandomValues(bytes);

    const ms = BigInt(Date.now());
    for (let i = 0; i < 6; i++) {
        bytes[i] = Number((ms >> BigInt(8 * (5 - i))) & 0xffn);
    }

    bytes[6] = (bytes[6] & 0x0f) | 0x70; // versi 7
    bytes[8] = (bytes[8] & 0x3f) | 0x80; // varian RFC

    const hex = [...bytes].map((b) => b.toString(16).padStart(2, '0')).join('');

    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}
