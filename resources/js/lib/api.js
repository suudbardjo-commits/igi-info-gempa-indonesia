/**
 * Klien untuk API logistik. Konsol memakai cookie sesi (Sanctum stateful), jadi setiap
 * permintaan yang mengubah data menyertakan token CSRF dari cookie XSRF-TOKEN.
 */
export class ApiError extends Error {
    constructor(status, data) {
        super(ApiError.messageFor(status, data));
        this.status = status;
        this.data = data;
    }

    static messageFor(status, data) {
        if (status === 403) return 'Anda tidak berhak melakukan ini.';
        if (status === 419) return 'Sesi sudah kedaluwarsa. Muat ulang halaman lalu coba lagi.';
        if (status === 429) return 'Terlalu banyak permintaan. Tunggu sebentar lalu coba lagi.';
        if (status >= 500) return 'Server sedang bermasalah. Coba lagi sebentar lagi.';

        return data?.message ?? 'Terjadi kesalahan.';
    }

    /** Pesan galat per isian dari validasi Laravel (422). */
    get fieldErrors() {
        return this.data?.errors ?? {};
    }
}

function xsrfToken() {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/**
 * @param {object} options
 * @param {boolean} options.redirectOn401 kembali ke halaman masuk bila sesi habis. Mesin sinkronisasi mematikannya:
 *   data yang belum terkirim harus tetap aman di perangkat, bukan terputus oleh pengalihan halaman.
 */
export async function api(path, { method = 'GET', body, query, redirectOn401 = true } = {}) {
    const url = new URL(`/api/v1${path}`, window.location.origin);

    for (const [key, value] of Object.entries(query ?? {})) {
        if (value !== null && value !== undefined && value !== '') url.searchParams.set(key, value);
    }

    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
            ...(body ? { 'Content-Type': 'application/json' } : {}),
        },
        body: body ? JSON.stringify(body) : undefined,
    });

    // Sesi habis: kembali ke halaman masuk, bukan menampilkan galat mentah.
    if (response.status === 401) {
        if (redirectOn401) window.location.href = '/masuk';
        throw new ApiError(401, { message: 'Sesi berakhir. Silakan masuk lagi.' });
    }

    const data = await response.json().catch(() => null);

    if (!response.ok) throw new ApiError(response.status, data);

    return data;
}

/** Pesan galat yang ramah untuk ditampilkan ke pengguna; galat validasi menampilkan isian pertama yang salah. */
export function errorMessage(error) {
    if (error instanceof ApiError) {
        const first = Object.values(error.fieldErrors)[0];

        return first?.[0] ?? error.message;
    }

    return 'Tidak bisa terhubung ke server. Periksa koneksi Anda.';
}
