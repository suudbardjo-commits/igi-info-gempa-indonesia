import { SERVER_URL } from './config';

export class ApiError extends Error {
    constructor(message: string, public code: string, public status = 0) {
        super(message);
    }
}

/** GET JSON dari API Laravel (/api/v1). Batas waktu 15 detik; galat jaringan diberi pesan berbahasa Indonesia. */
export async function api<T = any>(path: string, signal?: AbortSignal): Promise<T> {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 15000);
    signal?.addEventListener('abort', () => controller.abort());
    try {
        const res = await fetch(`${SERVER_URL}/api/v1/${path}`, { headers: { Accept: 'application/json' }, signal: controller.signal });
        const body = await res.json().catch(() => null);
        if (!res.ok || (body && body.status === 'error')) {
            throw new ApiError(body?.error?.message ?? `Server menjawab ${res.status}.`, body?.error?.code ?? `http_${res.status}`, res.status);
        }
        return body as T;
    } catch (e: any) {
        if (e instanceof ApiError) throw e;
        if (signal?.aborted) throw e;
        throw new ApiError(e?.name === 'AbortError' ? 'Server terlalu lama menjawab.' : 'Tidak dapat terhubung ke server IGI.', 'network');
    } finally {
        clearTimeout(timer);
    }
}

export const waktuWib = (iso?: string | null) =>
    iso ? new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit', timeZone: 'Asia/Jakarta' }).format(new Date(iso)).replace(':', '.') + ' WIB' : '-';

export function waktuLokal(iso: string | null | undefined, timeZone: string, label: string) {
    if (!iso) return '-';
    try {
        return new Intl.DateTimeFormat('id-ID', { hour: '2-digit', minute: '2-digit', timeZone, hour12: false }).format(new Date(iso)).replace(':', '.') + ' ' + label;
    } catch {
        return '-';
    }
}

export const angka = (x: number | null | undefined, unit = '', d = 0) =>
    x == null ? '-' : `${Number(x).toLocaleString('id-ID', { maximumFractionDigits: d })}${unit ? ` ${unit}` : ''}`;

export function lalu(iso?: string | null): string {
    if (!iso) return '-';
    const s = Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000);
    if (s < 90) return 'baru saja';
    if (s < 5400) return `${Math.round(s / 60)} menit lalu`;
    if (s < 129600) return `${Math.round(s / 3600)} jam lalu`;
    return `${Math.round(s / 86400)} hari lalu`;
}
