import * as Location from 'expo-location';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { ScrollView, Share, StyleSheet, View } from 'react-native';
import { Text } from '../Text';
import { api, lalu, waktuWib } from '../api';
import { COLORS, magnitudeColor } from '../config';
import { tap } from '../fx';
import { Chip, Header, Kicker, Loading, Notice } from '../ui';
import { PressableScale, Reveal } from '../fx';
import type { Feature } from '../data';

const RADIUS = [{ v: 100, l: '100 km' }, { v: 300, l: '300 km' }, { v: 1000, l: '1.000 km' }];
const ARAH = ['utara', 'timur laut', 'timur', 'tenggara', 'selatan', 'barat daya', 'barat', 'barat laut'];

function toRad(d: number) { return (d * Math.PI) / 180; }
function jarakKm(lat1: number, lon1: number, lat2: number, lon2: number) {
    const a = Math.sin(toRad(lat2 - lat1) / 2) ** 2 + Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.sin(toRad(lon2 - lon1) / 2) ** 2;
    return 6371 * 2 * Math.asin(Math.min(1, Math.sqrt(a)));
}
function arah(lat1: number, lon1: number, lat2: number, lon2: number) {
    const y = Math.sin(toRad(lon2 - lon1)) * Math.cos(toRad(lat2));
    const x = Math.cos(toRad(lat1)) * Math.sin(toRad(lat2)) - Math.sin(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.cos(toRad(lon2 - lon1));
    const b = ((Math.atan2(y, x) * 180) / Math.PI + 360) % 360;
    return ARAH[Math.round(b / 45) % 8];
}

/** Gempa 7 hari terakhir diurutkan dari yang paling dekat dengan posisi pengguna. Lokasi hanya diminta saat tombol ditekan dan tidak disimpan. */
export default function TerdekatScreen() {
    const [quakes, setQuakes] = useState<Feature[]>([]);
    const [error, setError] = useState<string | null>(null);
    const [loading, setLoading] = useState(true);
    const [me, setMe] = useState<{ lat: number; lon: number } | null>(null);
    const [locState, setLocState] = useState<'idle' | 'loading' | 'denied' | 'error'>('idle');
    const [radius, setRadius] = useState(300);

    const load = useCallback(async () => {
        setLoading(true);
        setError(null);
        try {
            const r = await api<{ features: Feature[] }>('disasters?hours=168&limit=300');
            setQuakes(r.features);
        } catch (e: any) {
            setError(e.message);
        } finally {
            setLoading(false);
        }
    }, []);
    useEffect(() => { load(); }, [load]);

    async function locate() {
        tap();
        setLocState('loading');
        try {
            const perm = await Location.requestForegroundPermissionsAsync();
            if (perm.status !== 'granted') { setLocState('denied'); return; }
            const pos = await Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.Balanced });
            setMe({ lat: pos.coords.latitude, lon: pos.coords.longitude });
            setLocState('idle');
        } catch {
            setLocState('error');
        }
    }

    const rows = useMemo(() => {
        if (!me) return [];
        return quakes
            .map((f) => {
                const [lon, lat] = f.geometry.coordinates;
                return { f, km: jarakKm(me.lat, me.lon, lat, lon), dir: arah(me.lat, me.lon, lat, lon) };
            })
            .filter((r) => r.km <= radius)
            .sort((a, b) => a.km - b.km);
    }, [quakes, me, radius]);

    const strongest = rows.reduce((m, r) => Math.max(m, r.f.properties.magnitude ?? 0), 0);

    async function bagikan(r: (typeof rows)[number]) {
        tap();
        const p = r.f.properties;
        try {
            await Share.share({ message: `Gempa M ${Number(p.magnitude).toFixed(1).replace('.', ',')} ${p.location}, ${waktuWib(p.occurred_at)}. Sumber ${String(p.source).toUpperCase()}.` });
        } catch { /* dibatalkan */ }
    }

    return (
        <View style={st.fill}>
            <Header title="Gempa terdekat" subtitle="7 hari terakhir, diurutkan dari yang paling dekat dengan Anda" />
            {error && <Notice onRetry={load}>{error}</Notice>}
            <ScrollView contentContainerStyle={st.body}>
                {!me ? (
                    <View style={st.card}>
                        <Kicker accent>Lokasi Anda</Kicker>
                        <Text style={st.p}>Izinkan lokasi untuk melihat gempa di sekitar Anda. Lokasi hanya dipakai saat tombol ditekan dan tidak disimpan.</Text>
                        <PressableScale onPress={locate} label="Pakai lokasi saya" style={st.btn}><Text style={st.btnText}>{locState === 'loading' ? 'Mencari lokasi…' : 'Pakai lokasi saya'}</Text></PressableScale>
                        {locState === 'denied' && <Text style={st.err}>Izin lokasi ditolak. Aktifkan izin lokasi di pengaturan ponsel lalu coba lagi.</Text>}
                        {locState === 'error' && <Text style={st.err}>Lokasi perangkat tidak dapat ditentukan. Coba lagi di tempat terbuka.</Text>}
                    </View>
                ) : (
                    <>
                        <View style={st.chips}>{RADIUS.map((o) => <Chip key={o.v} label={`Dalam ${o.l}`} active={radius === o.v} onPress={() => setRadius(o.v)} />)}</View>
                        <View style={st.card}>
                            <Kicker accent>Ringkasan</Kicker>
                            <Text style={st.big}>{rows.length} gempa</Text>
                            <Text style={st.meta}>{rows.length ? `dalam ${radius.toLocaleString('id-ID')} km dari Anda, terkuat M ${strongest.toFixed(1).replace('.', ',')}` : `tercatat dalam ${radius.toLocaleString('id-ID')} km dari Anda selama 7 hari. Tidak adanya catatan bukan penetapan bahwa wilayah aman.`}</Text>
                        </View>
                        {loading && <Loading />}
                        {rows.slice(0, 30).map((r, i) => (
                            <Reveal key={r.f.id} index={Math.min(i, 8)}>
                                <PressableScale onPress={() => bagikan(r)} label={`Bagikan gempa ${r.f.properties.location}`} style={st.row}>
                                    <View style={[st.bar, { backgroundColor: magnitudeColor(r.f.properties.magnitude) }]} />
                                    <Text style={[st.mag, { color: magnitudeColor(r.f.properties.magnitude) }]}>{Number(r.f.properties.magnitude).toFixed(1).replace('.', ',')}</Text>
                                    <View style={st.fill}>
                                        <Text style={st.loc} numberOfLines={2}>{r.f.properties.location}</Text>
                                        <Text style={st.meta}>{Math.round(r.km).toLocaleString('id-ID')} km ke arah {r.dir} · {lalu(r.f.properties.occurred_at)}</Text>
                                    </View>
                                </PressableScale>
                            </Reveal>
                        ))}
                        {rows.length > 0 && <Text style={st.hint}>Ketuk satu gempa untuk membagikannya.</Text>}
                        <PressableScale onPress={() => { setMe(null); setLocState('idle'); }} label="Hapus lokasi dari layar" style={st.link}><Text style={st.linkText}>Lupakan lokasi saya</Text></PressableScale>
                    </>
                )}
            </ScrollView>
        </View>
    );
}

const st = StyleSheet.create({
    fill: { flex: 1, backgroundColor: COLORS.bg },
    body: { padding: 12, gap: 10 },
    card: { backgroundColor: COLORS.surface, borderWidth: 1, borderColor: COLORS.edge, borderRadius: 8, padding: 14, gap: 6 },
    p: { fontSize: 16, lineHeight: 23, color: COLORS.ink },
    btn: { minHeight: 48, borderRadius: 8, backgroundColor: COLORS.accent, alignItems: 'center', justifyContent: 'center', marginTop: 6 },
    btnText: { color: '#fff', fontWeight: '700', fontSize: 16 },
    err: { color: COLORS.danger, fontSize: 14 },
    chips: { flexDirection: 'row', gap: 8, flexWrap: 'wrap' },
    big: { fontFamily: 'head', fontSize: 34, fontWeight: '700', color: COLORS.ink },
    meta: { fontSize: 13, color: COLORS.muted, lineHeight: 19 },
    row: { flexDirection: 'row', alignItems: 'center', gap: 10, backgroundColor: COLORS.surface, borderWidth: 1, borderColor: COLORS.edge, borderRadius: 8, padding: 10, minHeight: 64 },
    bar: { alignSelf: 'stretch', width: 4, borderRadius: 2 },
    mag: { fontFamily: 'head', fontSize: 28, fontWeight: '700', minWidth: 48 },
    loc: { fontSize: 15, fontWeight: '600', color: COLORS.ink },
    hint: { fontSize: 13, color: COLORS.muted, textAlign: 'center' },
    link: { minHeight: 44, alignItems: 'center', justifyContent: 'center' },
    linkText: { color: COLORS.accent, fontWeight: '700' },
});
