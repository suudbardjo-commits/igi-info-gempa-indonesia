import type React from 'react';
import { BarlowCondensed_600SemiBold, BarlowCondensed_700Bold } from '@expo-google-fonts/barlow-condensed';
import { Barlow_400Regular, Barlow_500Medium, Barlow_600SemiBold, Barlow_700Bold } from '@expo-google-fonts/barlow';
import { useFonts } from 'expo-font';
import { StatusBar } from 'expo-status-bar';
import { useEffect, useRef, useState } from 'react';
import { Animated, Pressable, StyleSheet, useWindowDimensions, View } from 'react-native';
import { Text } from './src/Text';
import { SafeAreaProvider, SafeAreaView } from 'react-native-safe-area-context';
import { COLORS, loadServerUrl, serverOk } from './src/config';
import { tap } from './src/fx';
import ServerForm from './src/ServerForm';
import CuacaScreen from './src/screens/CuacaScreen';
import HomeScreen, { Go } from './src/screens/HomeScreen';
import InfoScreen from './src/screens/InfoScreen';
import PantauanScreen from './src/screens/PantauanScreen';
import PetugasScreen from './src/screens/PetugasScreen';

// Aplikasi native (React Native) yang HANYA menampilkan dan mengirim data lewat API Laravel (/api/v1). Tidak ada data
// atau logika bisnis di sini.
const TABS = [
    { key: 'beranda', label: 'Beranda' },
    { key: 'pantauan', label: 'Pantauan' },
    { key: 'cuaca', label: 'Cuaca' },
    { key: 'info', label: 'Info' },
    { key: 'petugas', label: 'Petugas' },
] as const;
type Key = (typeof TABS)[number]['key'];

export default function App() {
    const { width } = useWindowDimensions();
    const [ready, setReady] = useState(false);
    // Huruf dimuat sebelum layar tampil; bila gagal, aplikasi tetap jalan dengan huruf bawaan.
    const [fontsLoaded, fontError] = useFonts({ BarlowCondensed_600SemiBold, BarlowCondensed_700Bold, Barlow_400Regular, Barlow_500Medium, Barlow_600SemiBold, Barlow_700Bold });
    const [version, setVersion] = useState(0); // naik saat alamat server diganti: semua layar dimuat ulang
    const [tab, setTab] = useState<Key>('beranda');
    const [param, setParam] = useState<Record<string, string | undefined>>({});
    const [seen, setSeen] = useState<Set<Key>>(new Set(['beranda']));
    const slide = useRef(new Animated.Value(0)).current;

    useEffect(() => { loadServerUrl().then(() => setReady(true)); }, []);
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
    const changed = () => { setSeen(new Set(['beranda'])); setTab('beranda'); setParam({}); setVersion((v) => v + 1); };
    const screens: Record<Key, React.ReactElement> = {
        beranda: <HomeScreen go={go} onServerChanged={changed} />,
        pantauan: <PantauanScreen focusId={param.pantauan} />,
        cuaca: <CuacaScreen />,
        info: <InfoScreen initial={param.info} />,
        petugas: <PetugasScreen />,
    };
    const tabW = width / TABS.length;

    return (
        <SafeAreaProvider key={version}>
            <SafeAreaView style={styles.fill} edges={['top', 'bottom']}>
                <StatusBar style="light" />
                <View style={styles.fill}>
                    {TABS.map((t) => seen.has(t.key) && (
                        <View key={t.key} style={[styles.fill, tab !== t.key && styles.hidden]} pointerEvents={tab === t.key ? 'auto' : 'none'}>
                            {screens[t.key]}
                        </View>
                    ))}
                </View>
                <View style={styles.bar} accessibilityRole="tablist">
                    <Animated.View style={[styles.indicator, { width: tabW, transform: [{ translateX: Animated.multiply(slide, tabW) }] }]} />
                    {TABS.map((t) => (
                        <Pressable key={t.key} onPress={() => { tap(); go(t.key, undefined); }} accessibilityRole="tab" accessibilityState={{ selected: tab === t.key }} accessibilityLabel={t.label} style={styles.tab}>
                            <Text style={[styles.label, tab === t.key && styles.on]}>{t.label}</Text>
                        </Pressable>
                    ))}
                </View>
            </SafeAreaView>
        </SafeAreaProvider>
    );
}

const styles = StyleSheet.create({
    fill: { flex: 1, backgroundColor: COLORS.paper },
    hidden: { display: 'none' },
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
