import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import * as Location from 'expo-location';
import { FlatList, Linking, Pressable, ScrollView, Share, StyleSheet, View } from 'react-native';
import { Text } from '../Text';
import { PressableScale, Reveal, tap } from '../fx';
import { WebView } from 'react-native-webview';
import { api, lalu, waktuWib } from '../api';
import { COLORS, SERVER_URL, magnitudeColor } from '../config';
import { Chip, Header, Loading, Notice, s } from '../ui';

type Feature = { id: string; geometry: { coordinates: [number, number] }; properties: any };

const WAKTU = [{ v: 24, l: '24 jam' }, { v: 168, l: '7 hari' }, { v: 720, l: '30 hari' }];
const MAG = [{ v: 0, l: 'Semua' }, { v: 4.5, l: 'M 4,5+' }, { v: 5, l: 'M 5+' }, { v: 6, l: 'M 6+' }];

// Peta Leaflet di dalam WebView. Data dikirim lewat postMessage; ketukan penanda dikirim balik. Teks lokasi tidak
// pernah disisipkan sebagai HTML (hanya lewat tooltip textContent).
const MAP_HTML = `<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"><style>html,body,#m{height:100%;margin:0}#e{position:absolute;z-index:999;left:8px;right:8px;top:8px;background:#fff8e9;border:1px solid #e6c997;border-radius:4px;padding:8px;font:13px sans-serif;display:none}</style></head>
<body><div id="m"></div><div id="e">Peta dasar belum termuat. Daftar kejadian tetap bisa dipakai.</div>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script><script>
var map=L.map('m',{zoomControl:true}).fitBounds([[-11.5,94.5],[6.5,141.5]]);
L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/Canvas/World_Light_Gray_Base/MapServer/tile/{z}/{y}/{x}',{maxZoom:16,attribution:'Peta: Esri, HERE, Garmin, © OpenStreetMap'}).on('tileerror',function(){document.getElementById('e').style.display='block'}).addTo(map);
var layer=L.layerGroup().addTo(map);
function col(m){return m>=6.5?'#ff4d7a':m>=5.5?'#ff6b4a':m>=4.5?'#ff9f43':'#f2c94c'}
function draw(d){layer.clearLayers();(d.events||[]).forEach(function(f){var c=f.geometry.coordinates;var m=f.properties.magnitude||0;
var k=L.circleMarker([c[1],c[0]],{radius:5+m*1.4,color:COLORS.surface,weight:1.5,fillColor:col(m),fillOpacity:.85}).addTo(layer);
var t=document.createElement('span');t.textContent='M '+m+' · '+(f.properties.location||'');k.bindTooltip(t);
k.on('click',function(){window.ReactNativeWebView.postMessage(JSON.stringify({type:'event',id:f.id}))})});
(d.camps||[]).forEach(function(f){var c=f.geometry.coordinates;L.marker([c[1],c[0]],{icon:L.divIcon({className:'',html:'<div style="width:12px;height:12px;background:#0f766e;border:2px solid #fff"></div>',iconSize:[12,12]})}).addTo(layer)
.bindTooltip((function(){var t=document.createElement('span');t.textContent=f.properties.name;return t})())})}
function onMsg(e){try{draw(JSON.parse(e.data))}catch(x){}}
document.addEventListener('message',onMsg);window.addEventListener('message',onMsg);
window.ReactNativeWebView.postMessage(JSON.stringify({type:'ready'}));
</script></body></html>`;

function km(lat1: number, lon1: number, lat2: number, lon2: number) {
    const r = Math.PI / 180;
    const a = Math.sin(((lat2 - lat1) * r) / 2) ** 2 + Math.cos(lat1 * r) * Math.cos(lat2 * r) * Math.sin(((lon2 - lon1) * r) / 2) ** 2;
    return 2 * 6371 * Math.asin(Math.sqrt(a));
}

export default function PantauanScreen({ focusId }: { focusId?: string }) {
    const [hours, setHours] = useState(168);
    const [mag, setMag] = useState(0);
    const [view, setView] = useState<'daftar' | 'peta' | 'posko'>('daftar');
    const [events, setEvents] = useState<Feature[]>([]);
    const [camps, setCamps] = useState<Feature[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [loadedAt, setLoadedAt] = useState<string | null>(null);
    const [selected, setSelected] = useState<Feature | null>(null);
    const [detail, setDetail] = useState<any>(null);
    const [mapReady, setMapReady] = useState(false);
    const [me, setMe] = useState<{ lat: number; lon: number } | null>(null);
    const [near, setNear] = useState(false);
    const [locMsg, setLocMsg] = useState<string | null>(null);
    const map = useRef<WebView>(null);
    const token = useRef(0);

    const load = useCallback(async () => {
        const mine = ++token.current;
        setLoading(true);
        setError(null);
        try {
            const q = new URLSearchParams({ hours: String(hours), limit: '300' });
            if (mag > 0) q.set('min_magnitude', String(mag));
            const [e, c] = await Promise.all([api(`disasters?${q}`), api('camps')]);
            if (mine !== token.current) return; // jawaban lama diabaikan
            setEvents(e.features);
            setCamps(c.features);
            setLoadedAt(new Date().toISOString());
        } catch (err: any) {
            if (mine === token.current) setError(err.message);
        } finally {
            if (mine === token.current) setLoading(false);
        }
    }, [hours, mag]);

    useEffect(() => { load(); }, [load]);

    useEffect(() => {
        if (mapReady) map.current?.postMessage(JSON.stringify({ events, camps }));
    }, [mapReady, events, camps]);

    const open = useCallback(async (f: Feature) => {
        setSelected(f);
        setDetail(null);
        try {
            setDetail(await api(`disasters/${f.id}`));
        } catch {
            setDetail({ failed: true });
        }
    }, []);

    useEffect(() => {
        if (!focusId) return;
        const f = events.find((x) => x.id === focusId);
        if (f) open(f);
    }, [focusId, events, open]);

    const toggleNear = useCallback(async () => {
        if (near) { setNear(false); return; }
        setLocMsg(null);
        try {
            const perm = await Location.requestForegroundPermissionsAsync();
            if (perm.status !== 'granted') { setLocMsg('Izin lokasi ditolak, jadi urutan "Dekat saya" tidak bisa dipakai.'); return; }
            const pos = await Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.Low });
            setMe({ lat: +pos.coords.latitude.toFixed(2), lon: +pos.coords.longitude.toFixed(2) }); // dibulatkan, tidak disimpan
            setNear(true);
        } catch {
            setLocMsg('Lokasi perangkat tidak dapat ditentukan.');
        }
    }, [near]);

    const distOf = useCallback((f: Feature) => (me ? km(me.lat, me.lon, f.geometry.coordinates[1], f.geometry.coordinates[0]) : null), [me]);
    const sorted = useMemo(() => {
        const list = [...events];
        if (near && me) return list.sort((a, b) => (distOf(a) ?? 0) - (distOf(b) ?? 0));
        return list.sort((a, b) => +new Date(b.properties.occurred_at) - +new Date(a.properties.occurred_at));
    }, [events, near, me, distOf]);

    const exportCsv = () => {
        const q = new URLSearchParams({ hours: String(hours) });
        if (mag > 0) q.set('min_magnitude', String(mag));
        Linking.openURL(`${SERVER_URL}/api/v1/disasters-export?${q}`);
    };
    const share = (f: Feature) => Share.share({ message: `Gempa M ${f.properties.magnitude} ${f.properties.location} (${waktuWib(f.properties.occurred_at)}). Sumber: ${String(f.properties.source).toUpperCase()}. ${SERVER_URL}/berita/${f.id}` }).catch(() => undefined);

    if (selected) {
        const p = selected.properties;
        return (
            <View style={st.fill}>
                <Header title={`M ${p.magnitude ?? '-'}`} subtitle={p.location} />
                <ScrollView contentContainerStyle={st.pad}>
                    <Pressable onPress={() => setSelected(null)} accessibilityRole="button" style={st.back}><Text style={st.link}>← Kembali ke daftar</Text></Pressable>
                    <Text style={[st.big, { color: magnitudeColor(p.magnitude) }]}>M {p.magnitude ?? '-'}</Text>
                    <Text style={st.h2}>{p.location}</Text>
                    <Text style={s.muted}>{waktuWib(p.occurred_at)} · {lalu(p.occurred_at)}</Text>
                    {[['Kedalaman', p.depth_km != null ? `${Math.round(p.depth_km)} km` : 'Belum tersedia'], ['Koordinat', `${selected.geometry.coordinates[1].toFixed(2)}, ${selected.geometry.coordinates[0].toFixed(2)}`], ['Sumber', String(p.source).toUpperCase()]].map(([k, v]) => (
                        <View key={k} style={st.row}><Text style={s.muted}>{k}</Text><Text style={st.val}>{v}</Text></View>
                    ))}
                    <Notice tone="info">{p.tsunami_potential ?? 'Informasi tsunami tidak tersedia pada catatan ini. Periksa pembaruan resmi BMKG.'}</Notice>
                    <Text style={st.h3}>Posko dalam radius perkiraan</Text>
                    <Text style={s.muted}>Radius {p.impact_radius_km ?? '-'} km; bukan peta guncangan resmi.</Text>
                    {!detail ? <Loading /> : detail.failed ? <Text style={s.muted}>Rincian posko belum termuat.</Text>
                        : detail.affected_camps?.length ? detail.affected_camps.map((c: any) => (
                            <View key={c.id} style={st.item}><Text style={st.val}>{c.name}</Text><Text style={s.muted}>{Number(c.distance_km).toFixed(1)} km · {c.current_occupants} orang tercatat</Text></View>
                        )) : <Text style={s.muted}>Tidak ada posko terdaftar dalam radius ini.</Text>}
                    <View style={st.actions}><PressableScale onPress={() => share(selected)} style={st.actBtn} label="Bagikan"><Text style={st.actText}>Bagikan</Text></PressableScale><PressableScale onPress={() => Linking.openURL(`${SERVER_URL}/berita/${selected.id}`)} style={st.actBtn} label="Halaman kejadian"><Text style={st.actText}>Halaman kejadian ↗</Text></PressableScale></View>
                </ScrollView>
            </View>
        );
    }

    return (
        <View style={st.fill}>
            <Header title="Pantauan" subtitle={loadedAt ? `Data dimuat ${lalu(loadedAt)}` : 'Gempa dan posko pengungsian'} />
            <View style={st.filters}>
                <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={st.chips}>
                    {WAKTU.map((o) => <Chip key={o.v} label={o.l} active={hours === o.v} onPress={() => setHours(o.v)} />)}
                    <View style={st.sep} />
                    {MAG.map((o) => <Chip key={o.v} label={o.l} active={mag === o.v} onPress={() => setMag(o.v)} />)}
                    <View style={st.sep} />
                    <Chip label={near ? 'Dekat saya ' : 'Dekat saya'} active={near} onPress={toggleNear} />
                </ScrollView>
                <View style={st.tabs}>
                    {(['daftar', 'peta', 'posko'] as const).map((t) => (
                        <Pressable key={t} onPress={() => setView(t)} accessibilityRole="tab" accessibilityState={{ selected: view === t }} style={[st.tab, view === t && st.tabOn]}>
                            <Text style={[st.tabText, view === t && st.tabTextOn]}>{t === 'daftar' ? `Kejadian ${events.length}` : t === 'peta' ? 'Peta' : `Posko ${camps.length}`}</Text>
                        </Pressable>
                    ))}
                </View>
            </View>
            {error && <Notice onRetry={load}>{error} Data sebelumnya tetap ditampilkan.</Notice>}
            {locMsg && <Notice tone="info">{locMsg}</Notice>}
            {view === 'peta' ? (
                <WebView
                    ref={map}
                    originWhitelist={['*']}
                    source={{ html: MAP_HTML, baseUrl: 'https://localhost' }}
                    style={st.fill}
                    onMessage={(e) => {
                        try {
                            const m = JSON.parse(e.nativeEvent.data);
                            if (m.type === 'ready') setMapReady(true);
                            if (m.type === 'event') { const f = events.find((x) => x.id === m.id); if (f) open(f); }
                        } catch { /* pesan tidak dikenal */ }
                    }}
                />
            ) : view === 'posko' ? (
                <FlatList
                    data={camps}
                    keyExtractor={(c) => c.id}
                    refreshing={loading}
                    onRefresh={load}
                    ListEmptyComponent={loading ? <Loading /> : <Text style={st.empty}>Belum ada posko terdaftar.</Text>}
                    ListFooterComponent={<Text style={st.foot}>Jumlah penghuni adalah data tercatat, bukan jaminan ketersediaan tempat.</Text>}
                    renderItem={({ item }) => (
                        <View style={st.item}>
                            <Text style={st.val}>{item.properties.name}</Text>
                            <Text style={s.muted}>{item.properties.current_occupants} / {item.properties.capacity_people} orang tercatat · {item.properties.code}</Text>
                            <View style={st.bar}><View style={[st.barFill, { width: `${Math.min(100, item.properties.capacity_people ? (item.properties.current_occupants / item.properties.capacity_people) * 100 : 0)}%` }]} /></View>
                        </View>
                    )}
                />
            ) : (
                <FlatList
                    data={sorted}
                    keyExtractor={(f) => f.id}
                    refreshing={loading}
                    onRefresh={load}
                    ListEmptyComponent={loading ? <Loading /> : <Text style={st.empty}>Tidak ada kejadian pada filter ini. Tidak adanya catatan bukan penetapan bahwa suatu wilayah aman.</Text>}
                    ListFooterComponent={<View><Pressable onPress={exportCsv} accessibilityRole="button" style={st.back}><Text style={[st.link, { paddingHorizontal: 16 }]}>Unduh CSV sesuai filter ↗</Text></Pressable><Text style={st.foot}>Sumber: BMKG dan USGS. Menampilkan hingga 300 catatan.</Text></View>}
                    renderItem={({ item }) => (
                        <Pressable onPress={() => { tap(); open(item); }} accessibilityRole="button" style={({ pressed }) => [st.event, pressed && { backgroundColor: COLORS.paperDeep }]}>
                            <View style={{ width: 8, alignSelf: 'stretch', backgroundColor: magnitudeColor(item.properties.magnitude) }} />
                            <Text style={st.mag}>{item.properties.magnitude ?? '-'}</Text>
                            <View style={{ flex: 1 }}>
                                <Text style={st.val} numberOfLines={2}>{item.properties.location}</Text>
                                <Text style={s.muted}>{waktuWib(item.properties.occurred_at)} · {String(item.properties.source).toUpperCase()} · {item.properties.depth_km != null ? `${Math.round(item.properties.depth_km)} km` : 'kedalaman -'}{near && distOf(item) != null ? ` · ${Math.round(distOf(item)!)} km dari Anda` : ''}</Text>
                            </View>
                        </Pressable>
                    )}
                />
            )}
        </View>
    );
}

const st = StyleSheet.create({
    fill: { flex: 1, backgroundColor: COLORS.bg },
    pad: { padding: 16, paddingBottom: 40 },
    filters: { backgroundColor: COLORS.surface, borderBottomWidth: 1, borderBottomColor: COLORS.edge },
    chips: { gap: 8, padding: 12 },
    sep: { width: 1, backgroundColor: COLORS.edge, marginHorizontal: 4 },
    tabs: { flexDirection: 'row' },
    tab: { flex: 1, minHeight: 44, alignItems: 'center', justifyContent: 'center', borderBottomWidth: 2, borderBottomColor: 'transparent' },
    tabOn: { borderBottomColor: COLORS.blue },
    tabText: { color: COLORS.muted, fontSize: 13 },
    tabTextOn: { color: COLORS.blue, fontWeight: '700' },
    event: { flexDirection: 'row', gap: 12, alignItems: 'center', paddingRight: 14, backgroundColor: COLORS.paper, borderBottomWidth: 1, borderBottomColor: COLORS.edge, minHeight: 64 },
    mag: { fontFamily: 'head', fontSize: 26, fontWeight: '800', color: COLORS.ink, minWidth: 48 },
    val: { color: COLORS.ink, fontSize: 14, fontWeight: '600' },
    item: { padding: 14, backgroundColor: COLORS.surface, borderBottomWidth: 1, borderBottomColor: COLORS.edge, gap: 4 },
    bar: { height: 3, backgroundColor: COLORS.edge, marginTop: 6 },
    barFill: { height: 3, backgroundColor: COLORS.accent },
    empty: { padding: 24, color: COLORS.muted, lineHeight: 20 },
    foot: { padding: 16, color: COLORS.muted, fontSize: 11, lineHeight: 17 },
    back: { minHeight: 44, justifyContent: 'center' },
    actions: { flexDirection: 'row', gap: 10, marginTop: 16, flexWrap: 'wrap' },
    actBtn: { borderColor: COLORS.edge, borderWidth: 1, borderRadius: 8, paddingHorizontal: 14, minHeight: 46, justifyContent: 'center', backgroundColor: COLORS.surface },
    actText: { color: COLORS.blue, fontWeight: '700', fontSize: 14 },
    link: { color: COLORS.blue, fontSize: 14 },
    big: { fontSize: 40, fontWeight: '600', marginTop: 4 },
    h2: { fontSize: 18, fontWeight: '600', color: COLORS.navy, marginVertical: 6 },
    h3: { fontSize: 14, fontWeight: '700', color: COLORS.navy, marginTop: 16 },
    row: { flexDirection: 'row', justifyContent: 'space-between', paddingVertical: 8, borderBottomWidth: 1, borderBottomColor: COLORS.edge },
});
