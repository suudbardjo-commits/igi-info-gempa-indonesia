import * as Location from 'expo-location';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { Linking, Pressable, Share, StyleSheet, View } from 'react-native';
import { Text } from '../Text';
import { angka, api, lalu, waktuWib } from '../api';
import { COLORS, SERVER_URL, magnitudeColor } from '../config';
import { Dashboard, Feature, MAG, WAKTU } from '../data';
import { CountUp, GrowBar, PressableScale, Reveal, Skeleton } from '../fx';
import ServerForm from '../ServerForm';
import { Chip, Kicker, Notice, Rule } from '../ui';
import { SavedPlace, TIPS, loadFavorites, weatherQuery } from '../weather';

export type Go = (tab: 'peta' | 'cuaca' | 'info' | 'petugas' | 'komando', param?: string) => void;

const DARURAT: [string, string][] = [['Darurat', '112'], ['Basarnas', '115'], ['BNPB', '117']];
const HARI = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];
const wibDay = (iso: string) => new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Jakarta' }).format(new Date(iso));
export const mag1 = (m: number | null | undefined) => (m == null ? '-' : Number(m).toLocaleString('id-ID', { minimumFractionDigits: 1, maximumFractionDigits: 1 }));

type Props = { d: Dashboard; selected: Feature | null; onSelect: (id: string) => void; onClear: () => void; go: Go; onServerChanged: () => void };

/** Isi lembar bawah halaman Peta: rincian terpilih, saringan, angka, cuaca, daftar, grafik, menu, tips, berita, nomor penting. */
export default function SheetBody({ d, selected, onSelect, onClear, go, onServerChanged }: Props) {
    const [day, setDay] = useState<number | null>(null);
    const [tip, setTip] = useState(0);
    const [showAll, setShowAll] = useState(false);
    const [editing, setEditing] = useState(false);
    const [place, setPlace] = useState<SavedPlace | null>(null);
    const [wx, setWx] = useState<{ state: 'idle' | 'loading' | 'ok' | 'error'; data?: any; message?: string }>({ state: 'idle' });

    const loadWeather = useCallback(async (p: SavedPlace) => {
        setPlace(p);
        setWx({ state: 'loading' });
        try {
            setWx({ state: 'ok', data: await api(`weather?${weatherQuery(p)}`) });
        } catch (e: any) {
            setWx({ state: 'error', message: e.message });
        }
    }, []);
    useEffect(() => { loadFavorites().then((f) => { if (f[0]) loadWeather(f[0]); }); }, [loadWeather]);

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

    const sorted = useMemo(() => [...(d.events ?? [])].sort((a, b) => +new Date(b.properties.occurred_at) - +new Date(a.properties.occurred_at)), [d.events]);
    const list = showAll ? sorted : sorted.slice(0, 8);

    const perDay = useMemo(() => {
        const out: { key: string; label: string; n: number; max: number }[] = [];
        for (let i = 6; i >= 0; i--) {
            const key = wibDay(new Date(Date.now() - i * 86400000).toISOString());
            out.push({ key, label: HARI[new Date(`${key}T12:00:00Z`).getUTCDay()], n: 0, max: 0 });
        }
        for (const f of d.events ?? []) {
            const x = out.find((o) => o.key === wibDay(f.properties.occurred_at));
            if (x) { x.n += 1; x.max = Math.max(x.max, f.properties.magnitude ?? 0); }
        }
        return out;
    }, [d.events]);
    const maxN = Math.max(1, ...perDay.map((x) => x.n));
    const c = wx.data?.current;
    const t = TIPS[tip];
    const host = SERVER_URL.replace(/^https?:\/\//, '');
    const sp = selected?.properties;

    return (
        <View>
            {d.error && <Notice onRetry={d.reload}>{d.error} Data sebelumnya tetap ditampilkan.</Notice>}

            {selected && sp && (
                <Reveal key={selected.id} style={st.block}>
                    <View style={st.rowBetween}>
                        <Kicker accent>Gempa terpilih</Kicker>
                        <Pressable onPress={onClear} accessibilityRole="button" style={st.linkBtn}><Text style={st.link}>Tutup</Text></Pressable>
                    </View>
                    <View style={st.selTop}>
                        <Text style={[st.selMag, { color: magnitudeColor(sp.magnitude) }]}>{mag1(sp.magnitude)}</Text>
                        <View style={{ flex: 1 }}>
                            <Text style={st.selLoc}>{sp.location}</Text>
                            <Text style={st.meta}>{waktuWib(sp.occurred_at)}, {lalu(sp.occurred_at)}</Text>
                        </View>
                    </View>
                    <View style={st.facts}>
                        <View style={st.fact}><Text style={st.meta}>Kedalaman</Text><Text style={st.factV}>{sp.depth_km != null ? `${Math.round(sp.depth_km)} km` : '-'}</Text></View>
                        <View style={st.fact}><Text style={st.meta}>Koordinat</Text><Text style={st.factV}>{selected.geometry.coordinates[1].toFixed(2)}, {selected.geometry.coordinates[0].toFixed(2)}</Text></View>
                        <View style={st.fact}><Text style={st.meta}>Sumber</Text><Text style={st.factV}>{String(sp.source).toUpperCase()}</Text></View>
                    </View>
                    <Text style={st.body}>{sp.tsunami_potential ?? 'Informasi tsunami tidak tersedia pada catatan ini. Periksa pembaruan resmi BMKG.'}</Text>
                    <View style={st.actions}>
                        <PressableScale onPress={() => Share.share({ message: `Gempa M ${mag1(sp.magnitude)} ${sp.location} (${waktuWib(sp.occurred_at)}). Sumber ${String(sp.source).toUpperCase()}. ${SERVER_URL}/berita/${selected.id}` }).catch(() => undefined)} label="Bagikan" style={st.btnGhost}><Text style={st.btnGhostText}>Bagikan</Text></PressableScale>
                        <PressableScale onPress={() => go('komando')} label="Simulasi gelombang" style={st.btn}><Text style={st.btnText}>Simulasi gelombang</Text></PressableScale>
                    </View>
                    <Rule />
                </Reveal>
            )}

            <View style={st.block}>
                <Kicker>Saringan peta dan daftar</Kicker>
                <View style={st.chips}>
                    {WAKTU.map((o) => <Chip key={o.v} label={o.l} active={d.hours === o.v} onPress={() => d.setHours(o.v)} />)}
                </View>
                <View style={st.chips}>
                    {MAG.map((o) => <Chip key={o.v} label={o.l} active={d.minMag === o.v} onPress={() => d.setMinMag(o.v)} />)}
                </View>
                <Text style={st.meta}>{d.loading ? 'Memuat…' : `${(d.events ?? []).length} gempa tercatat sesuai saringan. Data dimuat ${d.loadedAt ? lalu(d.loadedAt) : '-'}.`}</Text>
            </View>

            {d.stats && (
                <View style={st.block}>
                    <View style={[st.panel, st.figures]}>
                        {([['24 jam', d.stats.quakes_24h], ['7 hari', d.stats.quakes_7d], ['M 5+', d.stats.strong_7d], ['Posko', d.stats.camps_open]] as const).map(([k, v], i) => (
                            <View key={k} style={[st.fig, i > 0 && st.figRule]}>
                                <CountUp value={Number(v)} style={st.figNum} />
                                <Text style={st.meta}>{k}</Text>
                            </View>
                        ))}
                    </View>
                    <Text style={[st.meta, { marginTop: 6 }]}>Gempa tercatat. M 5+ dihitung 7 hari terakhir; {Number(d.stats.occupants ?? 0).toLocaleString('id-ID')} orang tercatat di posko aktif.</Text>
                </View>
            )}

            <View style={st.block}>
                <View style={[st.panel, d.alerts?.ok && d.alerts.count > 0 && st.panelAlert]}>
                    <Kicker accent={!!d.alerts?.ok && d.alerts.count > 0}>Peringatan dini cuaca</Kicker>
                    {!d.alerts && <Skeleton style={{ height: 40 }} />}
                    {d.alerts && !d.alerts.ok && <Text style={st.body}>Belum dapat dicek. Ini bukan berarti tidak ada peringatan.</Text>}
                    {d.alerts?.ok && d.alerts.count === 0 && <Text style={st.body}>Tidak ada peringatan dini cuaca aktif pada pemeriksaan terakhir.</Text>}
                    {d.alerts?.ok && d.alerts.count > 0 && (
                        <PressableScale onPress={() => go('info', 'peringatan')} label="Buka peringatan dini">
                            <Text style={st.alertNum}>{d.alerts.count} peringatan BMKG aktif</Text>
                            {d.alerts.first ? <Text style={st.meta} numberOfLines={2}>Terbaru: {d.alerts.first.title}</Text> : null}
                            <Text style={st.link}>Lihat semua</Text>
                        </PressableScale>
                    )}
                </View>
            </View>

            <View style={st.block}>
                <View style={st.panel}>
                    <Kicker>Cuaca</Kicker>
                    {wx.state === 'idle' && (
                        <>
                            <Text style={st.body}>Lihat cuaca di tempat Anda. Lokasi hanya dipakai saat tombol ditekan dan tidak disimpan.</Text>
                            <PressableScale onPress={useDevice} label="Pakai lokasi saya" style={st.linkBtn}><Text style={st.link}>Pakai lokasi saya</Text></PressableScale>
                            <PressableScale onPress={() => go('cuaca')} label="Cari tempat" style={st.linkBtn}><Text style={st.link}>Cari tempat lain</Text></PressableScale>
                        </>
                    )}
                    {wx.state === 'loading' && <Skeleton style={{ height: 70 }} />}
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
            </View>

            <View style={st.block}>
                <View style={st.panel}>
                    <Kicker>Daftar gempa</Kicker>
                    {!d.events && <Skeleton style={{ height: 120 }} />}
                    {d.events && sorted.length === 0 && <Text style={st.body}>Tidak ada gempa pada saringan ini. Tidak adanya catatan bukan penetapan bahwa suatu wilayah aman.</Text>}
                    {list.map((f, i) => {
                        const p = f.properties;
                        return (
                            <View key={f.id}>
                                <Pressable onPress={() => onSelect(f.id)} accessibilityRole="button" accessibilityState={{ selected: selected?.id === f.id }} style={({ pressed }) => [st.item, (pressed || selected?.id === f.id) && { backgroundColor: COLORS.accentSoft }]}>
                                    <View style={[st.mark, { backgroundColor: magnitudeColor(p.magnitude) }]} />
                                    <Text style={st.itemMag}>{mag1(p.magnitude)}</Text>
                                    <View style={{ flex: 1 }}>
                                        <Text style={st.itemLoc} numberOfLines={2}>{p.location}</Text>
                                        <Text style={st.meta}>{lalu(p.occurred_at)} · {p.depth_km != null ? `${Math.round(p.depth_km)} km` : 'kedalaman -'} · {String(p.source).toUpperCase()}</Text>
                                    </View>
                                </Pressable>
                                {i < list.length - 1 && <Rule />}
                            </View>
                        );
                    })}
                    {sorted.length > 8 && (
                        <Pressable onPress={() => setShowAll(!showAll)} accessibilityRole="button" style={st.linkBtn}><Text style={st.link}>{showAll ? 'Tampilkan lebih sedikit' : `Tampilkan semua (${sorted.length})`}</Text></Pressable>
                    )}
                    <Pressable onPress={() => Linking.openURL(`${SERVER_URL}/api/v1/disasters-export?hours=${d.hours}${d.minMag > 0 ? `&min_magnitude=${d.minMag}` : ''}`)} accessibilityRole="link" style={st.linkBtn}><Text style={st.link}>Unduh CSV sesuai saringan</Text></Pressable>
                </View>
            </View>

            <View style={st.block}>
                <View style={st.panel}>
                    <Kicker>Berapa gempa tercatat per hari?</Kicker>
                    <View style={st.chart}>
                        {perDay.map((x, i) => (
                            <PressableScale key={x.key} onPress={() => setDay(day === i ? null : i)} label={`${x.label}: ${x.n} gempa`} style={st.barCol}>
                                <Text style={[st.barN, day === i && { color: COLORS.accent, fontWeight: '700' }]}>{x.n}</Text>
                                <GrowBar index={i} height={(x.n / maxN) * 70} color={day === i ? COLORS.accent : magnitudeColor(x.max || 3)} />
                                <Text style={[st.meta, day === i && { color: COLORS.ink, fontWeight: '700' }]}>{x.label}</Text>
                            </PressableScale>
                        ))}
                    </View>
                    <Text style={st.meta}>{day == null ? 'Ketuk satu hari untuk rincian. Waktu WIB; sesuai saringan di atas.' : `${perDay[day].n} gempa tercatat${perDay[day].n ? `, terkuat M ${mag1(perDay[day].max)}` : ''} pada ${perDay[day].key}.`}</Text>
                </View>
            </View>

            <View style={st.block}>
                <View style={st.panel}>
                    <Kicker>Menu</Kicker>
                    {([['komando', 'Pusat komando', 'Gelombang P dan S, jarak ke Anda'], ['cuaca', 'Cuaca wilayah', 'Cari tempat, favorit, bandingkan'], ['info', 'Peringatan dan berita', 'BMKG dan BNPB'], ['petugas', 'Area petugas', 'Masuk dan lihat posko Anda']] as const).map(([k, title, sub], i, arr) => (
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

            <View style={st.block}>
                <Pressable onPress={() => setTip((tip + 1) % TIPS.length)} accessibilityRole="button" accessibilityLabel="Tips berikutnya" style={st.tip}>
                    <Kicker accent>Siaga</Kicker>
                    <Text style={st.tipTitle}>{t.title}</Text>
                    <Text style={st.body}>{t.body}</Text>
                    <Text style={st.meta}>Ketuk untuk tips berikutnya ({tip + 1} dari {TIPS.length})</Text>
                </Pressable>
            </View>

            {d.news.length > 0 && (
                <View style={st.block}>
                    <View style={st.panel}>
                        <View style={st.rowBetween}><Kicker>Kabar dari lembaga</Kicker><Pressable onPress={() => go('info', 'berita')} accessibilityRole="button" style={st.linkBtn}><Text style={st.link}>Semua</Text></Pressable></View>
                        {d.news.map((n, i) => (
                            <View key={n.id}>
                                <PressableScale onPress={() => Linking.openURL(n.url)} label={n.title} style={st.news}>
                                    <Text style={st.meta}>{n.source_label}, {lalu(n.published_at)}</Text>
                                    <Text style={st.newsTitle} numberOfLines={3}>{n.title}</Text>
                                </PressableScale>
                                {i < d.news.length - 1 && <Rule />}
                            </View>
                        ))}
                    </View>
                </View>
            )}

            <View style={st.block}>
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

            <View style={st.block}>
                <Text style={st.meta}>Server: {host}</Text>
                <Pressable onPress={() => setEditing(!editing)} accessibilityRole="button" style={st.linkBtn}><Text style={st.link}>{editing ? 'Tutup' : 'Ubah alamat server'}</Text></Pressable>
                {editing && <ServerForm onSaved={onServerChanged} />}
                <Text style={st.foot}>IGI bukan pengganti peringatan resmi. Untuk arahan evakuasi ikuti BMKG, BPBD, dan BNPB. Sumber: BMKG, USGS, BNPB, Open-Meteo.</Text>
            </View>
        </View>
    );
}

const st = StyleSheet.create({
    block: { paddingHorizontal: 16, marginTop: 14 },
    panel: { backgroundColor: COLORS.surface, borderRadius: 8, padding: 14, borderWidth: 1, borderColor: COLORS.edge },
    panelAlert: { borderColor: COLORS.orange },
    meta: { fontSize: 13, color: COLORS.muted, lineHeight: 18 },
    body: { fontSize: 16, color: COLORS.ink, lineHeight: 24 },
    link: { fontSize: 16, color: COLORS.accent, fontWeight: '700' },
    linkBtn: { minHeight: 44, justifyContent: 'center' },
    rowBetween: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
    chips: { flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginVertical: 4 },
    selTop: { flexDirection: 'row', alignItems: 'center', gap: 12, marginTop: 4 },
    selMag: { fontFamily: 'head', fontSize: 64, fontWeight: '700', lineHeight: 68 },
    selLoc: { fontFamily: 'head', fontSize: 22, fontWeight: '700', color: COLORS.ink, lineHeight: 26 },
    facts: { flexDirection: 'row', gap: 12, marginVertical: 10 },
    fact: { flex: 1 },
    factV: { fontSize: 15, fontWeight: '600', color: COLORS.ink },
    actions: { flexDirection: 'row', gap: 10, marginVertical: 10, flexWrap: 'wrap' },
    btn: { backgroundColor: COLORS.accent, borderRadius: 8, paddingHorizontal: 16, minHeight: 46, justifyContent: 'center' },
    btnText: { color: '#FFFFFF', fontWeight: '700', fontSize: 15 },
    btnGhost: { borderColor: COLORS.edge, borderWidth: 1, borderRadius: 8, paddingHorizontal: 16, minHeight: 46, justifyContent: 'center', backgroundColor: COLORS.surface },
    btnGhostText: { color: COLORS.accent, fontWeight: '700', fontSize: 15 },
    figures: { flexDirection: 'row', paddingVertical: 10, paddingHorizontal: 4 },
    fig: { flex: 1, paddingHorizontal: 10 },
    figRule: { borderLeftWidth: 1, borderLeftColor: COLORS.edge },
    figNum: { fontFamily: 'head', fontSize: 34, fontWeight: '700', color: COLORS.ink, lineHeight: 38 },
    alertNum: { fontFamily: 'head', fontSize: 24, fontWeight: '700', color: COLORS.orange },
    temp: { fontFamily: 'head', fontSize: 46, fontWeight: '700', color: COLORS.ink, lineHeight: 50 },
    cond: { fontSize: 17, fontWeight: '600', color: COLORS.ink },
    item: { flexDirection: 'row', alignItems: 'center', gap: 10, minHeight: 56, paddingVertical: 8 },
    mark: { width: 4, alignSelf: 'stretch', borderRadius: 2 },
    itemMag: { fontFamily: 'head', fontSize: 28, fontWeight: '700', color: COLORS.ink, minWidth: 46 },
    itemLoc: { fontSize: 15, color: COLORS.ink, fontWeight: '600' },
    chart: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'flex-end', height: 120, paddingTop: 10 },
    barCol: { alignItems: 'center', justifyContent: 'flex-end', gap: 4, minWidth: 38, height: 120 },
    barN: { fontSize: 13, color: COLORS.muted },
    index: { flexDirection: 'row', alignItems: 'center', minHeight: 62, paddingVertical: 8 },
    indexTitle: { fontFamily: 'head', fontSize: 22, fontWeight: '700', color: COLORS.ink },
    chev: { fontSize: 26, color: COLORS.accent },
    tip: { backgroundColor: COLORS.accentSoft, borderRadius: 8, padding: 14, gap: 6, borderWidth: 1, borderColor: COLORS.accent },
    tipTitle: { fontFamily: 'head', fontSize: 22, fontWeight: '700', color: COLORS.ink, lineHeight: 26 },
    news: { paddingVertical: 12, gap: 3 },
    newsTitle: { fontSize: 16, fontWeight: '600', color: COLORS.ink, lineHeight: 23 },
    phones: { flexDirection: 'row', gap: 10, marginTop: 6 },
    phone: { flex: 1, minHeight: 78, borderRadius: 8, backgroundColor: COLORS.surface, borderWidth: 1, borderColor: COLORS.edge, alignItems: 'center', justifyContent: 'center' },
    phoneNum: { fontFamily: 'head', fontSize: 34, fontWeight: '700', color: COLORS.danger },
    foot: { color: COLORS.muted, fontSize: 13, lineHeight: 19, marginTop: 10 },
});
