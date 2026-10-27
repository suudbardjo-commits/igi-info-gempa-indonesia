import { computed, reactive, ref } from 'vue';

const FAV_KEY = 'igi:cuaca:favorit';
export const MAX_FAVORITES = 10;
export const MAX_COMPARE = 3;

async function getJson(url, signal) {
    let response;
    try {
        response = await fetch(url, { headers: { Accept: 'application/json' }, signal });
    } catch (e) {
        if (e.name === 'AbortError') throw e;
        const error = new Error(navigator.onLine === false ? 'Perangkat sedang luring.' : 'Tidak dapat terhubung ke server IGI.');
        error.code = 'network';
        throw error;
    }
    const body = await response.json().catch(() => null);

    if (!response.ok || !body || body.status === 'error') {
        const error = new Error(body?.error?.message ?? `HTTP ${response.status}`);
        error.code = body?.error?.code ?? `http_${response.status}`;
        throw error;
    }

    return body;
}

/** Kunci lokasi stabil; koordinat dibulatkan ke 2 desimal (~1 km), sama dengan yang dipakai server. */
export function locationKey(loc) {
    return loc.adm4 ? `adm4:${loc.adm4}` : `${Number(loc.latitude).toFixed(2)},${Number(loc.longitude).toFixed(2)}`;
}

function readFavorites() {
    try {
        const list = JSON.parse(localStorage.getItem(FAV_KEY) ?? '[]');

        return Array.isArray(list) ? list.slice(0, MAX_FAVORITES) : [];
    } catch {
        return [];
    }
}

/**
 * Cuaca banyak lokasi. Tiap lokasi punya status sendiri (idle/loading/ok/error), dan hasil permintaan lama diabaikan
 * bila lokasi yang sama sudah diminta ulang, jadi respons yang datang terlambat tidak menimpa yang lebih baru.
 */
export function useWeather() {
    const states = reactive({});
    const tokens = {};
    const controllers = {};

    async function load(loc, { force = false } = {}) {
        const key = locationKey(loc);
        if (!force && states[key]?.status === 'ok' && Date.now() - states[key].loadedAt < 60_000) return;

        controllers[key]?.abort();
        const controller = (controllers[key] = new AbortController());
        const token = (tokens[key] = (tokens[key] ?? 0) + 1);
        states[key] = { ...(states[key] ?? {}), status: 'loading', error: null };

        const query = new URLSearchParams();
        if (loc.adm4) query.set('adm4', loc.adm4);
        else {
            query.set('lat', Number(loc.latitude).toFixed(2));
            query.set('lon', Number(loc.longitude).toFixed(2));
        }
        if (loc.name) query.set('name', loc.name.slice(0, 120));

        try {
            const data = await getJson(`/api/v1/weather?${query}`, controller.signal);
            if (token === tokens[key]) states[key] = { status: 'ok', data, error: null, loadedAt: Date.now(), alerts: states[key]?.alerts };
        } catch (e) {
            if (e.name === 'AbortError' || token !== tokens[key]) return;
            // Data sebelumnya (bila ada) tetap dipertahankan dan ditandai; galat ditampilkan terpisah.
            states[key] = { ...(states[key] ?? {}), status: 'error', error: { code: e.code ?? 'network', message: navigator.onLine === false ? 'Perangkat sedang luring.' : e.message } };
        }
    }

    async function loadAlerts(loc) {
        const key = locationKey(loc);
        const query = new URLSearchParams({ lat: Number(loc.latitude).toFixed(2), lon: Number(loc.longitude).toFixed(2), geometry: '1' });
        if (loc.province) query.set('province', loc.province);
        const token = tokens[`a:${key}`] = (tokens[`a:${key}`] ?? 0) + 1;

        try {
            const data = await getJson(`/api/v1/weather-alerts?${query}`);
            if (token === tokens[`a:${key}`]) states[key] = { ...(states[key] ?? { status: 'idle' }), alerts: { ok: true, data } };
        } catch (e) {
            if (token === tokens[`a:${key}`]) states[key] = { ...(states[key] ?? { status: 'idle' }), alerts: { ok: false, message: e.message } };
        }
    }

    // Pencarian nama tempat dengan jeda 300 ms; permintaan sebelumnya dibatalkan.
    const results = ref([]);
    const searching = ref(false);
    const searchError = ref(null);
    const searched = ref('');
    let timer, searchController, searchToken = 0;

    function search(text) {
        clearTimeout(timer);
        searchController?.abort();
        const q = text.trim();
        searchError.value = null;
        if (q.length < 2) {
            results.value = [];
            searching.value = false;
            searched.value = '';

            return;
        }
        searching.value = true;
        const token = ++searchToken;
        timer = setTimeout(async () => {
            searchController = new AbortController();
            try {
                const data = await getJson(`/api/v1/locations?q=${encodeURIComponent(q)}`, searchController.signal);
                if (token !== searchToken) return;
                results.value = data.results;
                searched.value = q;
            } catch (e) {
                if (e.name === 'AbortError' || token !== searchToken) return;
                results.value = [];
                searchError.value = e.message;
            } finally {
                if (token === searchToken) searching.value = false;
            }
        }, 300);
    }

    // Favorit: disimpan di perangkat saja.
    const favorites = ref(readFavorites());
    const favoriteError = ref(null);

    function persist() {
        try {
            localStorage.setItem(FAV_KEY, JSON.stringify(favorites.value));
            favoriteError.value = null;
        } catch {
            favoriteError.value = 'Favorit tidak dapat disimpan di perangkat ini; hanya berlaku selama halaman terbuka.';
        }
    }

    const isFavorite = (loc) => favorites.value.some((f) => locationKey(f) === locationKey(loc));

    function addFavorite(loc) {
        if (isFavorite(loc)) return true;
        if (favorites.value.length >= MAX_FAVORITES) {
            favoriteError.value = `Favorit penuh (maksimal ${MAX_FAVORITES}). Hapus satu untuk menambah yang baru.`;

            return false;
        }
        favorites.value = [...favorites.value, { name: loc.name, admin: loc.admin ?? '', province: loc.province ?? null, latitude: Number(Number(loc.latitude).toFixed(2)), longitude: Number(Number(loc.longitude).toFixed(2)), adm4: loc.adm4 ?? null, default: false }];
        persist();

        return true;
    }

    const removeFavorite = (loc) => { favorites.value = favorites.value.filter((f) => locationKey(f) !== locationKey(loc)); persist(); };
    const renameFavorite = (loc, name) => { favorites.value = favorites.value.map((f) => (locationKey(f) === locationKey(loc) ? { ...f, name: name.trim().slice(0, 60) || f.name } : f)); persist(); };
    const setDefault = (loc) => { favorites.value = favorites.value.map((f) => ({ ...f, default: locationKey(f) === locationKey(loc) && !f.default })); persist(); };
    const moveFavorite = (loc, dir) => {
        const list = [...favorites.value];
        const i = list.findIndex((f) => locationKey(f) === locationKey(loc));
        const j = i + dir;
        if (i < 0 || j < 0 || j >= list.length) return;
        [list[i], list[j]] = [list[j], list[i]];
        favorites.value = list;
        persist();
    };
    const sortFavorites = () => { favorites.value = [...favorites.value].sort((a, b) => a.name.localeCompare(b.name, 'id')); persist(); };

    const defaultFavorite = computed(() => favorites.value.find((f) => f.default) ?? null);

    return {
        states, load, loadAlerts,
        results, searching, searchError, searched, search,
        favorites, favoriteError, isFavorite, addFavorite, removeFavorite, renameFavorite, setDefault, moveFavorite, sortFavorites, defaultFavorite,
    };
}

/** "11.00 WIB" untuk waktu ISO di zona lokasi (bukan zona perangkat). */
export function formatLocalTime(iso, timezone, label) {
    if (!iso) return '—';
    try {
        const t = new Intl.DateTimeFormat('id-ID', { hour: '2-digit', minute: '2-digit', timeZone: timezone, hour12: false }).format(new Date(iso));

        return `${t.replace(':', '.')} ${label ?? ''}`.trim();
    } catch {
        return '—';
    }
}

export function formatLocalDay(iso, timezone, options = { weekday: 'short', day: 'numeric', month: 'short' }) {
    if (!iso) return '—';
    try {
        return new Intl.DateTimeFormat('id-ID', { ...options, timeZone: timezone }).format(new Date(iso));
    } catch {
        return '—';
    }
}

/** Jarak lingkaran besar dalam km. */
export function distanceKm(lat1, lon1, lat2, lon2) {
    const r = Math.PI / 180;
    const a = Math.sin(((lat2 - lat1) * r) / 2) ** 2 + Math.cos(lat1 * r) * Math.cos(lat2 * r) * Math.sin(((lon2 - lon1) * r) / 2) ** 2;

    return 2 * 6371 * Math.asin(Math.sqrt(a));
}
