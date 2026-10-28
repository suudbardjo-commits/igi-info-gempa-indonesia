import { useCallback, useEffect, useRef, useState } from 'react';
import { api } from './api';

export type Feature = { id: string; geometry: { coordinates: [number, number] }; properties: any };

export const WAKTU = [{ v: 24, l: '24 jam' }, { v: 168, l: '7 hari' }, { v: 720, l: '30 hari' }];
export const MAG = [{ v: 0, l: 'Semua' }, { v: 4.5, l: 'M 4,5+' }, { v: 5, l: 'M 5+' }, { v: 6, l: 'M 6+' }];

/**
 * Data beranda dari API Laravel: gempa (dengan saringan waktu dan magnitudo), posko, statistik, peringatan, dan berita.
 * Hanya hasil permintaan terakhir yang dipakai, supaya saringan yang diganti cepat tidak saling menimpa.
 */
export function useDashboard() {
    const [hours, setHours] = useState(168);
    const [minMag, setMinMag] = useState(0);
    const [events, setEvents] = useState<Feature[] | null>(null);
    const [camps, setCamps] = useState<Feature[]>([]);
    const [stats, setStats] = useState<any>(null);
    const [alerts, setAlerts] = useState<{ ok: boolean; count: number; first?: any } | null>(null);
    const [news, setNews] = useState<any[]>([]);
    const [error, setError] = useState<string | null>(null);
    const [loading, setLoading] = useState(true);
    const [loadedAt, setLoadedAt] = useState<string | null>(null);
    const token = useRef(0);

    const load = useCallback(async () => {
        const mine = ++token.current;
        setLoading(true);
        setError(null);
        const q = new URLSearchParams({ hours: String(hours), limit: '300' });
        if (minMag > 0) q.set('min_magnitude', String(minMag));
        const results = await Promise.allSettled([api(`disasters?${q}`), api('camps'), api('stats'), api('weather-alerts'), api('news?limit=3')]);
        if (mine !== token.current) return;
        const [e, c, s, a, n] = results;
        if (e.status === 'fulfilled') setEvents(e.value.features);
        if (c.status === 'fulfilled') setCamps(c.value.features);
        if (s.status === 'fulfilled') setStats(s.value);
        setAlerts(a.status === 'fulfilled' ? { ok: true, count: a.value.count, first: a.value.alerts?.[0] } : { ok: false, count: 0 });
        if (n.status === 'fulfilled') setNews(n.value.items ?? []);
        if (e.status === 'rejected') setError((e.reason as Error)?.message ?? 'Data gempa belum dapat dimuat.');
        else setLoadedAt(new Date().toISOString());
        setLoading(false);
    }, [hours, minMag]);

    useEffect(() => { load(); }, [load]);

    return { hours, setHours, minMag, setMinMag, events, camps, stats, alerts, news, error, loading, loadedAt, reload: load };
}

export type Dashboard = ReturnType<typeof useDashboard>;
