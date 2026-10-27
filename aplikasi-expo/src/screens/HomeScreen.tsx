import * as Location from 'expo-location';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { Image, Linking, Pressable, RefreshControl, ScrollView, StyleSheet, useWindowDimensions, View } from 'react-native';
import { Text } from '../Text';
import { angka, api, lalu, waktuWib } from '../api';
import { COLORS, SERVER_URL, magnitudeColor } from '../config';
import { CountUp, GrowBar, PressableScale, Reveal, Skeleton } from '../fx';
import ServerForm from '../ServerForm';
import { Kicker, Notice, Rule, Trace } from '../ui';
import { SavedPlace, TIPS, loadFavorites, weatherQuery } from '../weather';

export type Go = (tab: 'beranda' | 'pantauan' | 'cuaca' | 'info' | 'petugas', param?: string) => void;

const DARURAT: [string, string][] = [['Darurat', '112'], ['Basarnas', '115'], ['BNPB', '117']];
const HARI = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];

const wibDay = (iso: string) => new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Jakarta' }).format(new Date(iso));
const mag1 = (m: number | null | undefined) => (m == null ? '-' : Number(m).toLocaleString('id-ID', { minimumFractionDigits: 1, maximumFractionDigits: 1 }));

/** Beranda pemantau gempa (mode gelap, lihat DESIGN.md): angka magnitudo utama, jejak seismik 7 hari, panel instrumen. */
export default function HomeScreen({ go, onServerChanged }: { go: Go; onServerChanged: () => void }) {
    const { width } = useWindowDimensions();
    const [stats, setStats] = useState<any>(null);
    const [events, setEvents] = useState<any[] | null>(null);
    const [alerts, setAlerts] = useState<{ ok: boolean; count: number; first?: any } | null>(null);
    const [news, setNews] = useState<any[]>([]);
    const [error, setError] = useState<string | null>(null);
    const [loading, setLoading] = useState(true);
    const [loadedAt, setLoadedAt] = useState<string | null>(null);
    const [editing, setEditing] = useState(false);
    const [open, setOpen] = useState<string | null>(null);
    const [day, setDay] = useState<number | null>(null);
    const [tip, setTip] = useState(0);
    const [place, setPlace] = useState<SavedPlace | null>(null);
    const [wx, setWx] = useState<{ state: 'idle' | 'loading' | 'ok' | 'error'; data?: any; message?: string }>({ state: 'idle' });

    const load = useCallback(async () => {
        setLoading(true);
        setError(null);
        const results = await Promise.allSettled([api('stats'), api('disasters?limit=300&hours=168'), api('weather-alerts'), api('news?limit=3')]);
        const [s, d, a, n] = results;
        if (s.status === 'fulfilled') setStats(s.value);
        if (d.status === 'fulfilled') setEvents(d.value.features);
        setAlerts(a.status === 'fulfilled' ? { ok: true, count: a.value.count, first: a.value.alerts?.[0] } : { ok: false, count: 0 });
        if (n.status === 'fulfilled') setNews(n.value.items ?? []);
        if (s.status === 'rejected' && d.status === 'rejected') setError((s.reason as Error)?.message ?? 'Data belum dapat dimuat.');
        else setLoadedAt(new Date().toISOString());
        setLoading(false);
    }, []);

    const loadWeather = useCallback(async (p: SavedPlace) => {
        setPlace(p);
        setWx({ state: 'loading' });
        try {
            setWx({ state: 'ok', data: await api(`weather?${weatherQuery(p)}`) });
        } catch (e: any) {
            setWx({ state: 'error', message: e.message });
        }
    }, []);

    useEffect(() => {
        load();
        loadFavorites().then((f) => { if (f[0]) loadWeather(f[0]); });
    }, [load, loadWeather]);

    const useDevice = useCallback(async () => {
        setWx({ state: 'loading' });
        try {
            const perm = await Location.requestForegroundPermissionsAsync();
            if (perm.status !== 'granted') { setWx({ state: 'error', message: 'Izin lokasi ditolak. Anda tetap bisa mencari tempat di tab Cuaca.' }); return; }
            const pos = await Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.Low });
            // Dibulatkan ±1 km dan tidak disimpan.
            loadWeather({ name: 'Lokasi Anda (perkiraan)', admin: 'Dari izin lokasi', latitude: +pos.coords.latitude.toFixed(2), longitude: +pos.coords.longitude.toFixed(2) });
        } catch {
            setWx({ state: 'error', message: 'Lokasi perangkat tidak dapat ditentukan. Cari tempat di tab Cuaca.' });
        }
    }, [loadWeather]);

    const sorted = useMemo(() => [...(events ?? [])].sort((a, b) => +new Date(b.properties.occurred_at) - +new Date(a.properties.occurred_at)), [events]);
    const lead = sorted[0];
    const others = sorted.slice(1, 6);

    // Jejak 7 hari: 28 potongan 6 jam; tinggi = magnitudo terkuat di potongan itu (data nyata), kosong = tidak ada gempa.
    const trace = useMemo(() => {
        const bins = new Array(28).fill(0);
        const start = Date.now() - 7 * 86400000;
        for (const f of events ?? []) {
            const i = Math.floor((new Date(f.properties.occurred_at).getTime() - start) / (6 * 3600000));
            if (i >= 0 && i < 28) bins[i] = Math.max(bins[i], (f.properties.magnitude ?? 0) / 7);
        }
        return bins;
    }, [events]);

    const perDay = useMemo(() => {
        const out: { key: string; label: string; n: number; max: number }[] = [];
        for (let i = 6; i >= 0; i--) {
            const key = wibDay(new Date(Date.now() - i * 86400000).toISOString());
            out.push({ key, label: HARI[new Date(`${key}T12:00:00Z`).getUTCDay()], n: 0, max: 0 });
        }
        for (const f of events ?? []) {
            const d = out.find((x) => x.key === wibDay(f.properties.occurred_at));
            if (d) { d.n += 1; d.max = Math.max(d.max, f.properties.magnitude ?? 0); }
        }
        return out;
    }, [events]);
    const maxN = Math.max(1, ...perDay.map((d) => d.n));
    const lp = lead?.properties;
    const leadColor = magnitudeColor(lp?.magnitude);
    const c = wx.data?.current;
    const t = TIPS[tip];
    const host = SERVER_URL.replace(/^https?:\/\//, '');
    const wide = width >= 600;

    return (
        <ScrollView style={st.fill} contentContainerStyle={{ paddingBottom: 40 }} refreshControl={<RefreshControl refreshing={loading} onRefresh={load} tintColor={COLORS.accent} />}>
            <View style={st.top}>
                <View style={st.logoTile}><Image source={require('../../assets/igi-mark.png')} style={st.logo} resizeMode="contain" accessibilityLabel="Logo IGI" /></View>
                <View style={{ flex: 1 }}>
                    <Text style={st.brand}>IGI</Text>
                    <Text style={st.meta}>Info Gempa Indonesia</Text>
                </View>
                <Text style={st.meta}>{loadedAt ? `Dimuat ${waktuWib(loadedAt).replace(/^\d+ \w+ /, '')}` : 'Memuat'}</Text>
            </View>

            {error && <Notice onRetry={load}>{error}</Notice>}

            <View style={st.pad}>
                {!events && !error && <Skeleton style={{ height: 250 }} />}
                {events && !lead && <View style={st.panel}><Text style={st.body}>Tidak ada gempa tercatat dalam 7 hari terakhir. Tidak adanya catatan bukan penetapan bahwa suatu wilayah aman.</Text></View>}
                {lead && (
                    <Reveal>
                        <PressableScale onPress={() => go('pantauan', lead.id)} label={`Gempa terakhir magnitudo ${lp.magnitude} di ${lp.location}`} style={st.lead}>
                            <Kicker accent>Gempa terakhir tercatat</Kicker>
                            <View style={st.leadRow}>
                                <Text style={[st.bigMag, { color: leadColor, textShadowColor: leadColor, textShadowRadius: 18, textShadowOffset: { width: 0, height: 0 } }]} accessibilityLabel={`Magnitudo ${lp.magnitude}`}>{mag1(lp.magnitude)}</Text>
                                <Text style={st.leadUnit}>magnitudo</Text>
                            </View>
                            <Text style={st.headline}>{lp.location}</Text>
                            <Text style={st.deck}>{waktuWib(lp.occurred_at)}, {lalu(lp.occurred_at)}. {lp.depth_km != null ? `Kedalaman ${Math.round(lp.depth_km)} km.` : 'Kedalaman belum tersedia.'} Sumber {String(lp.source).toUpperCase()}.</Text>
                            <View style={{ marginTop: 14 }}>
                                <Trace values={trace} height={44} label="Jejak gempa 7 hari terakhir per 6 jam" />
                                <View style={st.traceFoot}><Text style={st.meta}>7 hari lalu</Text><Text style={st.meta}>sekarang</Text></View>
                            </View>
                            <Text style={st.link}>Lihat di peta</Text>
                        </PressableScale>
                    </Reveal>
                )}
            </View>

            {stats && (
                <View style={st.pad}>
                    <View style={[st.panel, st.figures]}>
                        {([['24 jam', stats.quakes_24h], ['7 hari', stats.quakes_7d], ['M 5+', stats.strong_7d], ['Posko', stats.camps_open]] as const).map(([k, v], i) => (
                            <PressableScale key={k} onPress={() => go('pantauan')} label={`${k}: ${v}`} style={[st.fig, i > 0 && st.figRule]}>
                                <CountUp value={Number(v)} style={st.figNum} />
                                <Text style={st.meta}>{k}</Text>
                            </PressableScale>
                        ))}
                    </View>
                    <Text style={[st.meta, { marginTop: 6 }]}>Gempa tercatat. M 5+ dihitung 7 hari terakhir; {Number(stats.occupants ?? 0).toLocaleString('id-ID')} orang tercatat di posko aktif.</Text>
                </View>
            )}

            <View style={[st.pad, wide && st.cols]}>
                <View style={[st.panel, st.colItem]}>
                    <Kicker>Cuaca</Kicker>
                    {wx.state === 'idle' && (
                        <>
                            <Text style={st.body}>Lihat cuaca di tempat Anda. Lokasi hanya dipakai saat tombol ditekan dan tidak disimpan.</Text>
                            <PressableScale onPress={useDevice} label="Pakai lokasi saya" style={st.linkBtn}><Text style={st.link}>Pakai lokasi saya</Text></PressableScale>
                            <PressableScale onPress={() => go('cuaca')} label="Cari tempat" style={st.linkBtn}><Text style={st.link}>Cari tempat lain</Text></PressableScale>
                        </>
                    )}
                    {wx.state === 'loading' && <Skeleton style={{ height: 80 }} />}
                    {wx.state === 'error' && (
                        <>
                            <Text style={[st.body, { color: COLORS.danger }]}>{wx.message}</Text>
                            <PressableScale onPress={useDevice} label="Coba lagi" style={st.linkBtn}><Text style={st.link}>Coba lagi</Text></PressableScale>
                        </>
                    )}
                    {wx.state === 'ok' && c && (
                        <PressableScale onPress={() => go('cuaca')} label="Buka cuaca lengkap">
                            <Text style={st.temp}>{angka(c.temperature, '°C', 0)}</Text>
                            <Text style={st.cond}>{c.condition ?? '-'}</Text>
                            <Text style={st.meta}>{place?.name} · hujan {angka(c.rain_probability, '%')} · angin {angka(c.wind_speed, 'km/jam', 0)}</Text>
                        </PressableScale>
                    )}
                </View>
                <View style={[st.panel, st.colItem, alerts?.ok && alerts.count > 0 && st.panelAlert]}>
                    <Kicker accent={!!alerts?.ok && alerts.count > 0}>Peringatan dini</Kicker>
                    {!alerts && <Skeleton style={{ height: 80 }} />}
                    {alerts && !alerts.ok && <Text style={st.body}>Belum dapat dicek. Ini bukan berarti tidak ada peringatan.</Text>}
                    {alerts?.ok && alerts.count === 0 && <Text style={st.body}>Tidak ada peringatan dini cuaca aktif pada pemeriksaan terakhir.</Text>}
                    {alerts?.ok && alerts.count > 0 && (
                        <PressableScale onPress={() => go('info', 'peringatan')} label="Buka peringatan dini">
                            <CountUp value={alerts.count} style={[st.temp, { color: COLORS.danger }]} />
                            <Text style={st.cond}>peringatan BMKG aktif</Text>
                            {alerts.first ? <Text style={st.meta} numberOfLines={2}>Terbaru: {alerts.first.title}</Text> : null}
                        </PressableScale>
                    )}
                </View>
            </View>

            {others.length > 0 && (
                <View style={st.pad}>
                    <View style={st.panel}>
                        <Kicker>Gempa lain pekan ini</Kicker>
                        {others.map((f, i) => {
                            const p = f.properties;
                            const isOpen = open === f.id;
                            return (
                                <View key={f.id}>
                                    <Pressable onPress={() => setOpen(isOpen ? null : f.id)} accessibilityRole="button" accessibilityState={{ expanded: isOpen }} style={st.itemHead}>
                                        <View style={[st.mark, { backgroundColor: magnitudeColor(p.magnitude) }]} />
                                        <Text style={st.itemMag}>{mag1(p.magnitude)}</Text>
                                        <View style={{ flex: 1 }}>
                                            <Text style={st.itemLoc} numberOfLines={isOpen ? 3 : 1}>{p.location}</Text>
                                            <Text style={st.meta}>{lalu(p.occurred_at)}</Text>
                                        </View>
                                        <Text style={st.meta}>{isOpen ? 'Tutup' : 'Rinci'}</Text>
                                    </Pressable>
                                    {isOpen && (
                                        <Reveal index={0} style={st.itemBody}>
                                            <Text style={st.body}>{waktuWib(p.occurred_at)}. {p.depth_km != null ? `Kedalaman ${Math.round(p.depth_km)} km.` : 'Kedalaman belum tersedia.'} Koordinat {f.geometry.coordinates[1].toFixed(2)}, {f.geometry.coordinates[0].toFixed(2)}. Sumber {String(p.source).toUpperCase()}.</Text>
                                            <PressableScale onPress={() => go('pantauan', f.id)} label="Lihat di peta" style={st.linkBtn}><Text style={st.link}>Lihat di peta</Text></PressableScale>
                                        </Reveal>
                                    )}
                                    {i < others.length - 1 && <Rule />}
                                </View>
                            );
                        })}
                    </View>
                </View>
            )}

            <View style={st.pad}>
                <View style={st.panel}>
                    <Kicker>Berapa gempa tercatat per hari?</Kicker>
                    <View style={st.chart}>
                        {perDay.map((d, i) => (
                            <PressableScale key={d.key} onPress={() => setDay(day === i ? null : i)} label={`${d.label}: ${d.n} gempa`} style={st.barCol}>
                                <Text style={[st.barN, day === i && { color: COLORS.accent, fontWeight: '700' }]}>{d.n}</Text>
                                <GrowBar index={i} height={(d.n / maxN) * 80} color={day === i ? COLORS.accent : magnitudeColor(d.max || 3)} />
                                <Text style={[st.meta, day === i && { color: COLORS.ink, fontWeight: '700' }]}>{d.label}</Text>
                            </PressableScale>
                        ))}
                    </View>
                    <Text style={st.meta}>{day == null ? 'Ketuk satu hari untuk rincian. Waktu WIB; warna batang mengikuti gempa terkuat hari itu.' : `${perDay[day].n} gempa tercatat${perDay[day].n ? `, terkuat M ${mag1(perDay[day].max)}` : ''} pada ${perDay[day].key}.`}</Text>
                </View>
            </View>

            <View style={st.pad}>
                <View style={st.panel}>
                    <Kicker>Menu</Kicker>
                    {([['pantauan', 'Pantauan', 'Peta, daftar gempa, dan posko'], ['cuaca', 'Cuaca', 'Cari tempat, favorit, bandingkan'], ['info', 'Peringatan dan berita', 'BMKG dan BNPB'], ['petugas', 'Petugas', 'Masuk dan lihat posko Anda']] as const).map(([k, title, sub], i, arr) => (
                        <View key={k}>
                            <PressableScale onPress={() => go(k)} label={title} style={st.index}>
                                <View style={{ flex: 1 }}>
                                    <Text style={st.indexTitle}>{title}</Text>
                                    <Text style={st.meta}>{sub}</Text>
                                </View>
                                <Text style={st.chev}>›</Text>
                            </PressableScale>
                            {i < arr.length - 1 && <Rule />}
                        </View>
                    ))}
                </View>
            </View>

            <View style={st.pad}>
                <Pressable onPress={() => setTip((tip + 1) % TIPS.length)} accessibilityRole="button" accessibilityLabel="Tips berikutnya" style={st.tip}>
                    <Kicker accent>Siaga</Kicker>
                    <Text style={st.tipTitle}>{t.title}</Text>
                    <Text style={st.body}>{t.body}</Text>
                    <Text style={st.meta}>Ketuk untuk tips berikutnya ({tip + 1} dari {TIPS.length})</Text>
                </Pressable>
            </View>

            {news.length > 0 && (
                <View style={st.pad}>
                    <View style={st.panel}>
                        <View style={st.rowBetween}><Kicker>Kabar dari lembaga</Kicker><Pressable onPress={() => go('info', 'berita')} accessibilityRole="button" style={st.linkBtn}><Text style={st.link}>Semua</Text></Pressable></View>
                        {news.map((n, i) => (
                            <View key={n.id}>
                                <PressableScale onPress={() => Linking.openURL(n.url)} label={n.title} style={st.news}>
                                    <Text style={st.meta}>{n.source_label}, {lalu(n.published_at)}</Text>
                                    <Text style={st.newsTitle} numberOfLines={3}>{n.title}</Text>
                                </PressableScale>
                                {i < news.length - 1 && <Rule />}
                            </View>
                        ))}
                    </View>
                </View>
            )}

            <View style={st.pad}>
                <Kicker>Nomor penting</Kicker>
                <View style={st.phones}>
                    {DARURAT.map(([k, n]) => (
                        <PressableScale key={n} onPress={() => Linking.openURL(`tel:${n}`)} label={`Telepon ${k} ${n}`} style={st.phone}>
                            <Text style={st.phoneNum}>{n}</Text>
                            <Text style={st.meta}>{k}</Text>
                        </PressableScale>
                    ))}
                </View>
            </View>

            <View style={st.pad}>
                <Text style={[st.meta, { marginTop: 18 }]}>Server: {host}</Text>
                <Pressable onPress={() => setEditing(!editing)} accessibilityRole="button" style={st.linkBtn}><Text style={st.link}>{editing ? 'Tutup' : 'Ubah alamat server'}</Text></Pressable>
                {editing && <ServerForm onSaved={onServerChanged} />}
                <Text style={st.footNote}>IGI bukan pengganti peringatan resmi. Untuk arahan evakuasi ikuti BMKG, BPBD, dan BNPB. Sumber: BMKG, USGS, BNPB, Open-Meteo.</Text>
            </View>
        </ScrollView>
    );
}

const st = StyleSheet.create({
    fill: { flex: 1, backgroundColor: COLORS.bg },
    pad: { paddingHorizontal: 16, marginTop: 14 },
    top: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingHorizontal: 16, paddingTop: 16 },
    logoTile: { width: 44, height: 44, borderRadius: 10, backgroundColor: '#F4F7FA', alignItems: 'center', justifyContent: 'center' },
    logo: { width: 28, height: 30 },
    brand: { fontFamily: 'head', fontSize: 30, fontWeight: '700', color: COLORS.ink, lineHeight: 32 },
    meta: { fontSize: 13, color: COLORS.muted, lineHeight: 18 },
    body: { fontSize: 16, color: COLORS.ink, lineHeight: 24 },
    link: { fontSize: 16, color: COLORS.accent, fontWeight: '700' },
    linkBtn: { minHeight: 44, justifyContent: 'center' },
    panel: { backgroundColor: COLORS.surface, borderRadius: 10, padding: 14, borderWidth: 1, borderColor: COLORS.edge },
    panelAlert: { borderColor: COLORS.danger },
    lead: { backgroundColor: COLORS.surface, borderRadius: 10, padding: 16, borderWidth: 1, borderColor: COLORS.edge, gap: 4 },
    leadRow: { flexDirection: 'row', alignItems: 'flex-end', gap: 10 },
    bigMag: { fontFamily: 'head', fontSize: 120, fontWeight: '700', lineHeight: 120 },
    leadUnit: { fontSize: 15, color: COLORS.muted, paddingBottom: 22 },
    headline: { fontFamily: 'head', fontSize: 28, fontWeight: '700', color: COLORS.ink, lineHeight: 32 },
    deck: { fontSize: 15, color: COLORS.muted, lineHeight: 22, marginTop: 2 },
    traceFoot: { flexDirection: 'row', justifyContent: 'space-between', marginTop: 4 },
    figures: { flexDirection: 'row', paddingVertical: 10, paddingHorizontal: 4 },
    fig: { flex: 1, paddingHorizontal: 10 },
    figRule: { borderLeftWidth: 1, borderLeftColor: COLORS.edge },
    figNum: { fontFamily: 'head', fontSize: 38, fontWeight: '700', color: COLORS.ink, lineHeight: 42 },
    cols: { flexDirection: 'row', gap: 12 },
    colItem: { flex: undefined, marginBottom: 0 },
    temp: { fontFamily: 'head', fontSize: 52, fontWeight: '700', color: COLORS.ink, lineHeight: 56 },
    cond: { fontSize: 17, fontWeight: '600', color: COLORS.ink },
    itemHead: { flexDirection: 'row', alignItems: 'center', gap: 10, minHeight: 56, paddingVertical: 8 },
    mark: { width: 4, alignSelf: 'stretch', borderRadius: 2 },
    itemMag: { fontFamily: 'head', fontSize: 28, fontWeight: '700', color: COLORS.ink, minWidth: 46 },
    itemLoc: { fontSize: 16, color: COLORS.ink, fontWeight: '600' },
    itemBody: { paddingBottom: 10, gap: 2 },
    chart: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'flex-end', height: 132, paddingTop: 10 },
    barCol: { alignItems: 'center', justifyContent: 'flex-end', gap: 4, minWidth: 38, height: 132 },
    barN: { fontSize: 13, color: COLORS.muted },
    index: { flexDirection: 'row', alignItems: 'center', minHeight: 62, paddingVertical: 8 },
    indexTitle: { fontFamily: 'head', fontSize: 24, fontWeight: '700', color: COLORS.ink },
    chev: { fontSize: 26, color: COLORS.accent },
    tip: { backgroundColor: COLORS.accentSoft, borderRadius: 10, padding: 14, gap: 6, borderWidth: 1, borderColor: COLORS.accent },
    tipTitle: { fontFamily: 'head', fontSize: 24, fontWeight: '700', color: COLORS.ink, lineHeight: 28 },
    rowBetween: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
    news: { paddingVertical: 12, gap: 3 },
    newsTitle: { fontSize: 16, fontWeight: '600', color: COLORS.ink, lineHeight: 23 },
    phones: { flexDirection: 'row', gap: 10, marginTop: 6 },
    phone: { flex: 1, minHeight: 78, borderRadius: 10, backgroundColor: COLORS.surface, borderWidth: 1, borderColor: COLORS.edge, alignItems: 'center', justifyContent: 'center' },
    phoneNum: { fontFamily: 'head', fontSize: 36, fontWeight: '700', color: COLORS.danger },
    footNote: { color: COLORS.muted, fontSize: 13, lineHeight: 19, marginTop: 10 },
});
