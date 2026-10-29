import type React from 'react';
import { BarlowCondensed_600SemiBold, BarlowCondensed_700Bold } from '@expo-google-fonts/barlow-condensed';
import { Barlow_400Regular, Barlow_500Medium, Barlow_600SemiBold, Barlow_700Bold } from '@expo-google-fonts/barlow';
import { useFonts } from 'expo-font';
import { StatusBar } from 'expo-status-bar';
import { useEffect, useRef, useState } from 'react';
import * as Updates from 'expo-updates';
import { Animated, AppState, Pressable, StyleSheet, useWindowDimensions, View } from 'react-native';
import { Text } from './src/Text';
import { SafeAreaProvider, SafeAreaView } from 'react-native-safe-area-context';
import { COLORS, loadServerUrl, serverOk } from './src/config';
import { tap } from './src/fx';
import ServerForm from './src/ServerForm';
import CuacaScreen from './src/screens/CuacaScreen';
import MapHomeScreen from './src/screens/MapHomeScreen';
import { Go } from './src/screens/SheetBody';
import InfoScreen from './src/screens/InfoScreen';
import PetugasScreen from './src/screens/PetugasScreen';
import WebScreen from './src/screens/WebScreen';

// Aplikasi native (React Native) yang HANYA menampilkan dan mengirim data lewat API Laravel (/api/v1). Tidak ada data
// atau logika bisnis di sini.
const TABS = [
    { key: 'peta', label: 'Peta' },
    { key: 'cuaca', label: 'Cuaca' },
    { key: 'info', label: 'Info' },
    { key: 'petugas', label: 'Petugas' },
] as const;
type Key = (typeof TABS)[number]['key'] | 'komando';

export default function App() {
    const { width } = useWindowDimensions();
    const [ready, setReady] = useState(false);
    // Huruf dimuat sebelum layar tampil; bila gagal, aplikasi tetap jalan dengan huruf bawaan.
    const [fontsLoaded, fontError] = useFonts({ BarlowCondensed_600SemiBold, BarlowCondensed_700Bold, Barlow_400Regular, Barlow_500Medium, Barlow_600SemiBold, Barlow_700Bold });
    const [version, setVersion] = useState(0); // naik saat alamat server diganti: semua layar dimuat ulang
    const [tab, setTab] = useState<Key>('peta');
    const [param, setParam] = useState<Record<string, string | undefined>>({});
    const [seen, setSeen] = useState<Set<Key>>(new Set<Key>(['peta']));
    const slide = useRef(new Animated.Value(0)).current;

    useEffect(() => { loadServerUrl().then(() => setReady(true)); }, []);
    // Pembaruan (termasuk alamat server baru) diambil saat aplikasi dibuka dan saat kembali ke layar, lalu langsung dipakai.
    useEffect(() => {
        if (__DEV__ || !Updates.isEnabled) return undefined;
        let busy = false;
        const check = async () => {
            if (busy) return;
            busy = true;
            try {
                const r = await Updates.checkForUpdateAsync();
                if (r.isAvailable) {
                    await Updates.fetchUpdateAsync();
                    await Updates.reloadAsync();
                }
            } catch { /* tanpa internet: coba lagi nanti */ } finally { busy = false; }
        };
        check();
        const sub = AppState.addEventListener('change', (s) => { if (s === 'active') check(); });
        return () => sub.remove();
    }, []);
    useEffect(() => {
        Animated.spring(slide, { toValue: TABS.findIndex((t) => t.key === tab), useNativeDriver: true, speed: 18, bounciness: 8 }).start();
    }, [tab, slide]);

    if (!ready || !(fontsLoaded || fontError)) return <View style={styles.fill} />;

    if (!serverOk()) {
        return (
            <SafeAreaProvider>
                <SafeAreaView style={styles.center}>
                    <Text style={styles.title}>Sambungkan ke server IGI</Text>
                    <ServerForm intro="Masukkan alamat server IGI yang diberikan pengelola (harus https)." onSaved={() => setVersion((v) => v + 1)} />
                </SafeAreaView>
            </SafeAreaProvider>
        );
    }

    const go: Go = (k, p) => { setTab(k); setParam((o) => ({ ...o, [k]: p })); setSeen((prev) => new Set(prev).add(k)); };
    const changed = () => { setSeen(new Set(['peta'])); setTab('peta'); setParam({}); setVersion((v) => v + 1); };
    const screens: Record<Key, React.ReactElement> = {
        peta: <MapHomeScreen go={go} onServerChanged={changed} />,
        cuaca: <CuacaScreen />,
        info: <InfoScreen initial={param.info} />,
        petugas: <PetugasScreen />,
        // Halaman web /komando (peta penuh, gelombang P/S) dibuka di dalam aplikasi; tidak ada di bilah tab.
        komando: <WebScreen path="/komando" />,
    };
    const tabW = width / TABS.length;

    return (
        <SafeAreaProvider key={version}>
            <SafeAreaView style={styles.root} edges={['top']}>
                <StatusBar style="light" />
                {tab === 'komando' && (
                    <Pressable onPress={() => { tap(); go('peta', undefined); }} accessibilityRole="button" accessibilityLabel="Kembali ke aplikasi" style={styles.backBar}>
                        <Text style={styles.backText}>← Kembali</Text>
                    </Pressable>
                )}
                <View style={styles.fill}>
                    {([...TABS.map((t) => t.key), 'komando'] as Key[]).map((k) => seen.has(k) && (
                        <View key={k} style={[styles.fill, tab !== k && styles.hidden]} pointerEvents={tab === k ? 'auto' : 'none'}>
                            {screens[k]}
                        </View>
                    ))}
                </View>
                <SafeAreaView edges={['bottom']} style={styles.barWrap}>
                <View style={styles.bar} accessibilityRole="tablist">
                    <Animated.View style={[styles.indicator, { width: tabW, transform: [{ translateX: Animated.multiply(slide, tabW) }] }]} />
                    {TABS.map((t) => (
                        <Pressable key={t.key} onPress={() => { tap(); go(t.key, undefined); }} accessibilityRole="tab" accessibilityState={{ selected: tab === t.key }} accessibilityLabel={t.label} style={styles.tab}>
                            <Text style={[styles.label, tab === t.key && styles.on]}>{t.label}</Text>
                        </Pressable>
                    ))}
                </View>
                </SafeAreaView>
            </SafeAreaView>
        </SafeAreaProvider>
    );
}

const styles = StyleSheet.create({
    root: { flex: 1, backgroundColor: COLORS.header },
    barWrap: { backgroundColor: COLORS.surface },
    fill: { flex: 1, backgroundColor: COLORS.bg },
    hidden: { display: 'none' },
    backBar: { minHeight: 44, justifyContent: 'center', paddingHorizontal: 16, backgroundColor: COLORS.surface, borderBottomWidth: 1, borderBottomColor: COLORS.edge },
    backText: { color: COLORS.accent, fontSize: 15, fontWeight: '700' },
    bar: { flexDirection: 'row', borderTopWidth: 1, borderTopColor: COLORS.edge, backgroundColor: COLORS.surface },
    indicator: { position: 'absolute', top: 0, left: 0, height: 3, backgroundColor: COLORS.accent },
    tab: { flex: 1, minHeight: 54, alignItems: 'center', justifyContent: 'center', gap: 1 },
    icon: { fontSize: 20 },
    dim: { opacity: 0.55 },
    label: { fontSize: 14, color: COLORS.muted },
    on: { color: COLORS.accent, fontWeight: '700' },
    center: { flex: 1, padding: 24, backgroundColor: COLORS.paper, justifyContent: 'center', gap: 12 },
    title: { fontFamily: 'head', fontSize: 32, fontWeight: '700', color: COLORS.ink, marginBottom: 6 },
});
