import * as Location from 'expo-location';
import { useCallback, useEffect, useRef, useState } from 'react';
import { FlatList, ScrollView, Share, StyleSheet, TextInput, View } from 'react-native';
import { Text } from '../Text';
import { angka, api, lalu, waktuLokal } from '../api';
import { COLORS, SERVER_URL } from '../config';
import { PressableScale, Reveal, Skeleton, tapStrong } from '../fx';
import { Chip, DoubleRule, Kicker, Loading, Notice, s } from '../ui';
import { MAX_FAV, SavedPlace, loadFavorites, placeKey, saveFavorites, weatherQuery } from '../weather';

const LEVELS = ['Provinsi', 'Kabupaten/kota', 'Kecamatan', 'Desa/kelurahan'];
const MAX_CMP = 3;
type WState = { status: 'loading' | 'ok' | 'error'; data?: any; message?: string };

export default function CuacaScreen() {
    const [q, setQ] = useState('');
    const [results, setResults] = useState<any[]>([]);
    const [searching, setSearching] = useState(false);
    const [searched, setSearched] = useState('');
    const [searchError, setSearchError] = useState<string | null>(null);
    const [loc, setLoc] = useState<SavedPlace | null>(null);
    const [store, setStore] = useState<Record<string, WState>>({});
    const [alerts, setAlerts] = useState<{ ok: boolean; items: any[]; checked?: string; message?: string } | null>(null);
    const [tab, setTab] = useState<'jam' | 'hari' | 'banding'>('jam');
    const [pick, setPick] = useState<{ path: { code: string; name: string }[]; options: any[]; busy: boolean } | null>(null);
    const [favs, setFavs] = useState<SavedPlace[]>([]);
    const [cmp, setCmp] = useState<SavedPlace[]>([]);
    const [note, setNote] = useState<string | null>(null);
    const timer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);
    const searchToken = useRef(0);
    const tokens = useRef<Record<string, number>>({});

    useEffect(() => { loadFavorites().then((f) => { setFavs(f); if (f[0]) choose(f[0]); }); /* eslint-disable-next-line react-hooks/exhaustive-deps */ }, []);

    // Pencarian nama tempat dengan jeda 300 ms; hasil yang datang terlambat diabaikan.
    useEffect(() => {
        clearTimeout(timer.current);
        const text = q.trim();
        setSearchError(null);
        if (text.length < 2) { setResults([]); setSearched(''); return; }
        const mine = ++searchToken.current;
        setSearching(true);
        timer.current = setTimeout(async () => {
            try {
                const r = await api(`locations?q=${encodeURIComponent(text)}`);
                if (mine !== searchToken.current) return;
                setResults(r.results);
                setSearched(text);
            } catch (e: any) {
                if (mine === searchToken.current) { setResults([]); setSearchError(e.message); }
            } finally {
                if (mine === searchToken.current) setSearching(false);
            }
        }, 300);
        return () => clearTimeout(timer.current);
    }, [q]);

    const fetchWeather = useCallback(async (p: SavedPlace) => {
        const key = placeKey(p);
        const mine = (tokens.current[key] = (tokens.current[key] ?? 0) + 1);
        setStore((o) => ({ ...o, [key]: { ...(o[key] ?? {}), status: 'loading' } }));
        try {
            const d = await api(`weather?${weatherQuery(p)}`);
            if (mine === tokens.current[key]) setStore((o) => ({ ...o, [key]: { status: 'ok', data: d } }));
            return d;
        } catch (e: any) {
            // Data sebelumnya tetap dipertahankan bila ada.
            if (mine === tokens.current[key]) setStore((o) => ({ ...o, [key]: { ...(o[key] ?? {}), status: 'error', message: e.message } }));
        }
    }, []);

    const choose = useCallback(async (p: SavedPlace) => {
        setLoc(p); setQ(''); setResults([]); setPick(null); setAlerts(null); setNote(null); setTab('jam');
        const d = await fetchWeather(p);
        const lat = d?.location?.latitude ?? p.latitude;
        const lon = d?.location?.longitude ?? p.longitude;
        const prov = d?.location?.province ?? p.province;
        if (d && p.adm4) setLoc((cur) => (cur && placeKey(cur) === placeKey(p) ? { ...cur, name: d.location?.name ?? cur.name, admin: d.location?.admin ?? cur.admin, province: prov ?? cur.province } : cur));
        if (lat == null || lon == null) return;
        api(`weather-alerts?lat=${Number(lat).toFixed(2)}&lon=${Number(lon).toFixed(2)}${prov ? `&province=${encodeURIComponent(prov)}` : ''}`)
            .then((a) => setAlerts({ ok: true, items: a.alerts, checked: a.checked_at }))
            .catch((e) => setAlerts({ ok: false, items: [], message: e.message }));
    }, [fetchWeather]);

    const useDevice = useCallback(async () => {
        setNote(null);
        try {
            const perm = await Location.requestForegroundPermissionsAsync();
            if (perm.status !== 'granted') { setNote('Izin lokasi ditolak. Anda tetap bisa mencari tempat atau memilih per wilayah.'); return; }
            const pos = await Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.Low });
            // Dibulatkan ±1 km dan tidak disimpan kecuali Anda menjadikannya favorit.
            choose({ name: 'Lokasi Anda (perkiraan)', admin: 'Dari izin lokasi', latitude: +pos.coords.latitude.toFixed(2), longitude: +pos.coords.longitude.toFixed(2) });
        } catch {
            setNote('Lokasi perangkat tidak dapat ditentukan. Cari nama tempat sebagai gantinya.');
        }
    }, [choose]);

    // Pemilih bertingkat dari katalog kode wilayah Kemendagri di server.
    const loadLevel = useCallback(async (path: { code: string; name: string }[]) => {
        setPick({ path, options: [], busy: true });
        try {
            const r = await api(`areas${path.length ? `?parent=${path[path.length - 1].code}` : ''}`);
            setPick({ path, options: r.areas, busy: false });
        } catch (e: any) {
            setPick({ path, options: [], busy: false });
            setNote(e.message);
        }
    }, []);
    const onPick = (a: any) => {
        if (!pick) return;
        const path = [...pick.path, { code: a.code, name: a.name }];
        if (path.length < 4) { loadLevel(path); return; }
        choose({ name: a.name, admin: path.slice(0, 3).reverse().map((p) => p.name).join(', '), province: path[0].name, adm4: a.code });
    };

    const isFav = (p: SavedPlace) => favs.some((f) => placeKey(f) === placeKey(p));
    const toggleFav = (p: SavedPlace) => {
        tapStrong();
        if (isFav(p)) { const n = favs.filter((f) => placeKey(f) !== placeKey(p)); setFavs(n); saveFavorites(n); return; }
        if (favs.length >= MAX_FAV) { setNote(`Favorit penuh (maksimal ${MAX_FAV}). Hapus satu untuk menambah.`); return; }
        const n = [...favs, p]; setFavs(n); saveFavorites(n);
    };
    const toggleCmp = (p: SavedPlace) => {
        if (cmp.some((c) => placeKey(c) === placeKey(p))) { setCmp(cmp.filter((c) => placeKey(c) !== placeKey(p))); return; }
        if (cmp.length >= MAX_CMP) { setNote(`Perbandingan maksimal ${MAX_CMP} lokasi.`); return; }
        setCmp([...cmp, p]);
        if (!store[placeKey(p)]?.data) fetchWeather(p);
    };

    const key = loc ? placeKey(loc) : null;
    const cur = key ? store[key] : null;
    const data = cur?.data;
    const tz = data?.timezone ?? 'Asia/Jakarta';
    const tzl = data?.tz_label ?? 'WIB';
    const c = data?.current;
    const nextHours = (data?.hourly ?? []).filter((h: any) => new Date(h.time).getTime() >= Date.now() - 3600000).slice(0, data?.provider === 'bmkg' ? 12 : 24);
    const shareWx = () => data && Share.share({ message: `Cuaca ${loc?.name}: ${angka(c?.temperature, '°C', 0)}, ${c?.condition ?? '-'}. Peluang hujan ${angka(c?.rain_probability, '%')}. Sumber: ${data.provider === 'bmkg' ? 'BMKG' : 'Open-Meteo'}. ${SERVER_URL}/cuaca` }).catch(() => undefined);

    return (
        <View style={st.fill}>
            <ScrollView contentContainerStyle={st.pad} keyboardShouldPersistTaps="handled">
                <Text style={st.title}>Cuaca</Text>
                <TextInput placeholderTextColor={COLORS.muted} keyboardAppearance="dark" value={q} onChangeText={setQ} placeholder="Cari kota, kabupaten, atau tempat" style={st.input} autoCorrect={false} accessibilityLabel="Cari kota, kabupaten, atau tempat" returnKeyType="search" />
                {searching && <Text style={s.muted}>Mencari…</Text>}
                {searchError && <Text style={st.err}>{searchError}</Text>}
                {searched && !searching && !results.length && !searchError && <Text style={s.muted}>Tidak ada tempat bernama “{searched}” di Indonesia. Periksa ejaan.</Text>}
                {results.map((r) => (
                    <PressableScale key={r.id} style={st.result} onPress={() => choose({ name: r.name, admin: r.admin, province: r.province, latitude: r.latitude, longitude: r.longitude })} label={`${r.name}, ${r.admin}`}>
                        <Text style={st.val}>{r.name}</Text>
                        <Text style={s.muted}>{r.admin || 'Indonesia'} · {r.latitude.toFixed(2)}, {r.longitude.toFixed(2)}</Text>
                    </PressableScale>
                ))}

                <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={st.chips}>
                    <Chip label="Lokasi saya" active={false} onPress={useDevice} />
                    <Chip label={pick ? 'Tutup pilihan wilayah' : 'Pilih per wilayah'} active={!!pick} onPress={() => (pick ? setPick(null) : loadLevel([]))} />
                    {favs.map((f) => <Chip key={placeKey(f)} label={f.name} active={!!loc && placeKey(loc) === placeKey(f)} onPress={() => choose(f)} />)}
                </ScrollView>
                {favs.length === 0 && <Text style={s.muted}>Simpan tempat dengan tombol Simpan favorit agar muncul di sini dan di Beranda. Favorit hanya disimpan di HP ini.</Text>}
                {note && <Notice tone="info">{note}</Notice>}

                {pick && (
                    <View style={st.pickBox}>
                        <Text style={st.h3}>{LEVELS[pick.path.length]}</Text>
                        {pick.path.length > 0 && (
                            <PressableScale onPress={() => (pick.path.length > 1 ? loadLevel(pick.path.slice(0, -1)) : loadLevel([]))} style={st.linkBtn} label="Kembali">
                                <Text style={st.link}>← {pick.path.map((p) => p.name).join(' › ')}</Text>
                            </PressableScale>
                        )}
                        {pick.busy ? <Loading /> : (
                            <FlatList scrollEnabled={false} data={pick.options} keyExtractor={(a) => a.code} renderItem={({ item }) => (
                                <PressableScale style={st.result} onPress={() => onPick(item)} label={item.name}><Text style={st.val}>{item.name}</Text></PressableScale>
                            )} />
                        )}
                    </View>
                )}

                {loc && cur?.status === 'loading' && !data && <Skeleton style={{ height: 190, marginTop: 14 }} />}
                {cur?.status === 'error' && <Notice onRetry={() => fetchWeather(loc!)}>{cur.message}{data ? ' Data sebelumnya tetap ditampilkan.' : ''}</Notice>}

                {loc && data && c && (
                    <Reveal key={key ?? ''}>
                        <View style={st.hero}>
                            <Kicker accent>{data.provider === 'bmkg' ? 'Prakiraan resmi BMKG' : 'Estimasi cuaca saat ini'}</Kicker>
                            <Text style={st.heroName}>{data.location?.name ?? loc.name}</Text>
                            <Text style={st.heroAdmin}>{data.location?.admin ?? loc.admin} · {tzl}</Text>
                            <View style={st.heroMain}>
                                <Text style={st.heroTemp}>{angka(c.temperature, '°', 0)}</Text>
                                <View style={{ flex: 1, paddingBottom: 12 }}>
                                    <Text style={st.heroCond}>{c.condition ?? '-'}</Text>
                                    <Text style={st.heroMeta}>{waktuLokal(c.time, tz, tzl)}</Text>
                                </View>
                            </View>
                            <View style={st.heroActions}>
                                <PressableScale onPress={() => toggleFav(loc)} style={st.act} label={isFav(loc) ? 'Hapus favorit' : 'Simpan favorit'}><Text style={st.actText}>{isFav(loc) ? 'Tersimpan' : 'Simpan favorit'}</Text></PressableScale>
                                <PressableScale onPress={() => toggleCmp(loc)} style={[st.act, cmp.some((x) => placeKey(x) === placeKey(loc)) && st.actOn]} label="Bandingkan"><Text style={st.actText}>Bandingkan</Text></PressableScale>
                                <PressableScale onPress={shareWx} style={st.act} label="Bagikan"><Text style={st.actText}>Bagikan</Text></PressableScale>
                            </View>
                            <View style={{ marginTop: 10 }}><DoubleRule /></View>
                        </View>

                        {alerts && !alerts.ok && <Notice>Peringatan dini BMKG belum dapat dicek ({alerts.message}). Ini bukan berarti tidak ada peringatan.</Notice>}
                        {alerts?.ok && alerts.items.length > 0 && (
                            <View style={st.alert}>
                                <Text style={st.val}>{alerts.items.length} peringatan dini BMKG untuk lokasi ini</Text>
                                {alerts.items.map((a) => (
                                    <View key={a.id} style={{ marginTop: 6 }}>
                                        <Text style={st.val}>{a.title}</Text>
                                        <Text style={s.muted}>{a.coverage === 'polygon' ? 'Titik ini berada di dalam area peringatan.' : 'Mencakup sebagian wilayah provinsi ini (tingkat provinsi).'} Berlaku sampai {waktuLokal(a.expires, tz, tzl)}.</Text>
                                    </View>
                                ))}
                            </View>
                        )}
                        {alerts?.ok && alerts.items.length === 0 && <Text style={st.quiet}>Tidak ada peringatan dini BMKG aktif untuk lokasi ini · diperiksa {lalu(alerts.checked)}. Tidak adanya peringatan bukan jaminan kondisi aman.</Text>}

                        <View style={st.grid}>
                            {([['', 'Terasa seperti', angka(c.feels_like, '°C', 0)], ['', 'Kelembapan', angka(c.humidity, '%')], ['', 'Angin', angka(c.wind_speed, 'km/jam', 0)], ['', 'Peluang hujan', angka(c.rain_probability, '%')], ['', 'Curah hujan', angka(c.precipitation, 'mm', 1)], ['', 'Indeks UV', angka(c.uv, '', 1)], ['', 'Terbit', waktuLokal(data.daily?.[0]?.sunrise, tz, tzl)], ['', 'Terbenam', waktuLokal(data.daily?.[0]?.sunset, tz, tzl)]] as const).filter(([, , v]) => v !== '-').map(([ic, k, v]) => (
                                <View key={k} style={st.cell}><Text style={s.muted}>{k}</Text><Text style={st.val}>{v}</Text></View>
                            ))}
                        </View>
                        <Text style={st.prov}>Sumber: {data.provider === 'bmkg' ? 'BMKG' : 'Open-Meteo'} · diambil {lalu(data.fetched_at)}{data.issued_at ? '' : ' · waktu terbit tidak diberikan penyedia'}</Text>
                        {data.fallback && <Notice>{data.fallback.note}</Notice>}
                        {data.is_stale && <Notice>Data ini sudah {Math.round(data.cache_age_seconds / 60)} menit dan penyedia belum menjawab pembaruan.</Notice>}

                        <View style={st.chips2}>
                            <Chip label="Per jam" active={tab === 'jam'} onPress={() => setTab('jam')} />
                            <Chip label="7 hari" active={tab === 'hari'} onPress={() => setTab('hari')} />
                            <Chip label={`Bandingkan (${cmp.length})`} active={tab === 'banding'} onPress={() => setTab('banding')} />
                        </View>

                        {tab === 'jam' && (
                            <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ gap: 8, paddingVertical: 4 }}>
                                {nextHours.map((h: any, i: number) => {
                                    return (
                                        <View key={h.time} style={[st.hour, i === 0 && st.hourNow]}>
                                            <Text style={st.hourT}>{i === 0 ? 'Kini' : waktuLokal(h.time, tz, tzl).replace(` ${tzl}`, '')}</Text>
                                            <Text style={st.val}>{angka(h.temperature, '°', 0)}</Text>
                                            <Text style={st.hourR}>{h.rain_probability != null ? `hujan ${angka(h.rain_probability, '%')}` : `${angka(h.precipitation, 'mm', 1)}`}</Text>
                                        </View>
                                    );
                                })}
                            </ScrollView>
                        )}
                        {tab === 'hari' && (data.daily ?? []).map((d: any) => {
                            return (
                                <View key={d.date} style={st.day}>
                                    <Text style={[st.val, { width: 92 }]}>{new Intl.DateTimeFormat('id-ID', { weekday: 'short', day: 'numeric', month: 'short', timeZone: 'UTC' }).format(new Date(`${d.date}T12:00:00Z`))}</Text>
                                    <Text style={[s.muted, { flex: 1 }]} numberOfLines={1}>{d.condition ?? '-'}</Text>
                                    <Text style={st.val}>{angka(d.temp_min, '°')} / {angka(d.temp_max, '°')}</Text>
                                </View>
                            );
                        })}
                        {tab === 'banding' && (
                            <View>
                                {cmp.length === 0 && <Text style={s.muted}>Tekan Bandingkan pada lokasi (maksimal {MAX_CMP}) untuk membandingkannya di sini. Tiap kolom memakai zona waktunya sendiri.</Text>}
                                {cmp.length > 0 && (
                                    <ScrollView horizontal>
                                        <View>
                                            <View style={st.cmpRow}>
                                                <Text style={[st.cmpK]} />
                                                {cmp.map((p) => (
                                                    <PressableScale key={placeKey(p)} onPress={() => toggleCmp(p)} style={st.cmpH} label={`Keluarkan ${p.name}`}>
                                                        <Text style={st.val} numberOfLines={1}>{p.name}</Text>
                                                        <Text style={s.muted}>{store[placeKey(p)]?.data ? `${store[placeKey(p)].data.tz_label} · ${store[placeKey(p)].data.provider === 'bmkg' ? 'BMKG' : 'Open-Meteo'}` : ''}</Text>
                                                    </PressableScale>
                                                ))}
                                            </View>
                                            {([['Kondisi', (x: any) => x.condition ?? '-'], ['Suhu', (x: any) => angka(x.temperature, '°C', 0)], ['Terasa', (x: any) => angka(x.feels_like, '°C', 0)], ['Lembap', (x: any) => angka(x.humidity, '%')], ['Angin', (x: any) => angka(x.wind_speed, 'km/jam', 0)], ['Peluang hujan', (x: any) => angka(x.rain_probability, '%')]] as const).map(([k, fn]) => (
                                                <View key={k} style={st.cmpRow}>
                                                    <Text style={st.cmpK}>{k}</Text>
                                                    {cmp.map((p) => { const w = store[placeKey(p)]; return <Text key={placeKey(p)} style={st.cmpV}>{w?.data ? fn(w.data.current) : w?.status === 'error' ? 'gagal' : '…'}</Text>; })}
                                                </View>
                                            ))}
                                        </View>
                                    </ScrollView>
                                )}
                            </View>
                        )}
                        <Text style={st.foot}>{data.attribution}</Text>
                    </Reveal>
                )}
                {!loc && !pick && <Text style={st.empty}>Pilih lokasi untuk melihat prakiraan: cari nama tempat, pakai lokasi Anda, atau pilih per wilayah. Data dari BMKG dan Open-Meteo; waktu dalam zona waktu lokasi (WIB/WITA/WIT).</Text>}
            </ScrollView>
        </View>
    );
}

const st = StyleSheet.create({
    fill: { flex: 1, backgroundColor: COLORS.bg },
    pad: { padding: 14, paddingBottom: 40 },
    title: { fontFamily: 'head', fontSize: 30, fontWeight: '800', color: COLORS.ink, marginBottom: 8 },
    input: { backgroundColor: COLORS.white, borderWidth: 1, borderColor: COLORS.ink, paddingHorizontal: 14, minHeight: 50, fontSize: 16, color: COLORS.ink, fontFamily: 'Barlow_400Regular' },
    result: { borderBottomWidth: 1, borderBottomColor: COLORS.edge, padding: 12, minHeight: 48, justifyContent: 'center' },
    val: { color: COLORS.ink, fontSize: 14, fontWeight: '700' },
    err: { color: COLORS.danger, fontSize: 12, marginVertical: 6 },
    chips: { gap: 8, paddingVertical: 12 },
    chips2: { flexDirection: 'row', gap: 8, marginVertical: 14, flexWrap: 'wrap' },
    linkBtn: { minHeight: 44, justifyContent: 'center' },
    link: { color: COLORS.blue, fontSize: 14 },
    pickBox: { borderTopWidth: 2, borderTopColor: COLORS.ink, paddingVertical: 8, marginBottom: 8 },
    h3: { fontSize: 14, fontWeight: '800', color: COLORS.navy },
    hero: { paddingTop: 14, gap: 2 },
    heroName: { fontFamily: 'head', fontSize: 30, fontWeight: '800', color: COLORS.ink, lineHeight: 36 },
    heroAdmin: { color: COLORS.muted, fontSize: 13 },
    heroMain: { flexDirection: 'row', alignItems: 'flex-end', gap: 12 },
    heroTemp: { fontFamily: 'head', fontSize: 84, fontWeight: '800', color: COLORS.accent, lineHeight: 90 },
    heroCond: { fontFamily: 'head', fontSize: 22, fontWeight: '700', color: COLORS.ink },
    heroMeta: { color: COLORS.muted, fontSize: 12 },
    heroActions: { flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginTop: 6 },
    act: { minHeight: 44, paddingHorizontal: 12, justifyContent: 'center', borderBottomWidth: 2, borderBottomColor: COLORS.edge },
    actOn: { borderBottomColor: COLORS.accent },
    actText: { color: COLORS.ink, fontWeight: '700', fontSize: 14 },
    alert: { backgroundColor: COLORS.accentSoft, borderTopColor: COLORS.accent, borderTopWidth: 3, padding: 12, marginTop: 12 },
    quiet: { color: COLORS.muted, fontSize: 12, lineHeight: 18, marginTop: 12 },
    grid: { flexDirection: 'row', flexWrap: 'wrap', marginTop: 12, borderTopWidth: 2, borderTopColor: COLORS.ink },
    cell: { width: '50%', padding: 8, borderBottomWidth: 1, borderBottomColor: COLORS.edge },
    cellIcon: { fontSize: 22 },
    prov: { color: COLORS.muted, fontSize: 11, marginTop: 8 },
    hour: { width: 76, borderTopWidth: 2, borderTopColor: COLORS.edge, alignItems: 'center', paddingVertical: 10, gap: 3 },
    hourNow: { borderTopColor: COLORS.accent },
    hourT: { fontSize: 12, color: COLORS.muted },
    hourG: { fontSize: 24 },
    hourR: { fontSize: 11, color: COLORS.muted },
    day: { flexDirection: 'row', alignItems: 'center', gap: 8, borderBottomWidth: 1, borderBottomColor: COLORS.edge, minHeight: 50 },
    cmpRow: { flexDirection: 'row', borderBottomWidth: 1, borderBottomColor: COLORS.edge, backgroundColor: COLORS.surface },
    cmpK: { width: 96, padding: 10, color: COLORS.muted, fontSize: 12, fontWeight: '600' },
    cmpH: { width: 130, padding: 10 },
    cmpV: { width: 130, padding: 10, color: COLORS.ink, fontSize: 13, fontWeight: '600' },
    foot: { color: COLORS.muted, fontSize: 11, lineHeight: 17, marginTop: 14 },
    empty: { color: COLORS.muted, lineHeight: 20, marginTop: 24 },
});
