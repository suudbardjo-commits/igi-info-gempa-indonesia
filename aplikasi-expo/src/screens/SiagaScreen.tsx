import AsyncStorage from '@react-native-async-storage/async-storage';
import { useEffect, useState } from 'react';
import { Linking, ScrollView, StyleSheet, View } from 'react-native';
import { Text } from '../Text';
import { COLORS } from '../config';
import { PressableScale, Reveal, tap } from '../fx';
import { Header, Kicker } from '../ui';

const KEY = 'igi:siaga-tas';

const NOMOR = [
    { n: '112', l: 'Darurat umum' },
    { n: '115', l: 'Basarnas' },
    { n: '117', l: 'BNPB' },
    { n: '119', l: 'Ambulans' },
    { n: '110', l: 'Polisi' },
    { n: '113', l: 'Pemadam' },
];

const TAS = [
    'Air minum untuk 3 hari',
    'Makanan tahan lama dan siap makan',
    'Senter dan baterai cadangan',
    'Peluit untuk memberi tanda',
    'Obat pribadi dan P3K',
    'Salinan dokumen penting (KTP, KK, ijazah)',
    'Uang tunai secukupnya',
    'Power bank dan kabel pengisi daya',
    'Radio kecil bertenaga baterai',
    'Pakaian ganti dan selimut',
    'Masker dan perlengkapan kebersihan',
    'Daftar nomor keluarga yang dituliskan di kertas',
];

const LANGKAH = [
    { t: 'Turun', d: 'Segera rendahkan tubuh ke lantai agar tidak terjatuh.' },
    { t: 'Lindungi', d: 'Berlindung di bawah meja kokoh dan lindungi kepala serta leher.' },
    { t: 'Bertahan', d: 'Tetap di tempat sampai guncangan berhenti. Jangan berlari keluar saat bergoyang.' },
    { t: 'Setelah itu', d: 'Menjauh dari bangunan rusak. Bila di pesisir dan guncangan kuat atau lama, segera menuju tempat tinggi dan ikuti arahan BMKG dan BPBD.' },
];

/** Panduan siaga: nomor darurat sekali ketuk, langkah saat gempa, dan daftar periksa tas siaga yang tersimpan di ponsel. */
export default function SiagaScreen() {
    const [done, setDone] = useState<Record<number, boolean>>({});

    useEffect(() => {
        AsyncStorage.getItem(KEY).then((v) => { if (v) setDone(JSON.parse(v)); }).catch(() => undefined);
    }, []);

    function toggle(i: number) {
        tap();
        const next = { ...done, [i]: !done[i] };
        setDone(next);
        AsyncStorage.setItem(KEY, JSON.stringify(next)).catch(() => undefined);
    }

    const count = TAS.filter((_, i) => done[i]).length;

    return (
        <View style={st.fill}>
            <Header title="Siaga gempa" subtitle="Panduan singkat, nomor darurat, dan tas siaga" />
            <ScrollView contentContainerStyle={st.body}>
                <Reveal index={0}>
                    <View style={st.card}>
                        <Kicker accent>Nomor penting</Kicker>
                        <View style={st.phones}>
                            {NOMOR.map((x) => (
                                <PressableScale key={x.n} onPress={() => Linking.openURL(`tel:${x.n}`).catch(() => undefined)} label={`Telepon ${x.l} ${x.n}`} style={st.phone}>
                                    <Text style={st.num}>{x.n}</Text>
                                    <Text style={st.meta}>{x.l}</Text>
                                </PressableScale>
                            ))}
                        </View>
                        <Text style={st.meta}>Ketuk untuk menelepon. Pakai hanya untuk keadaan darurat.</Text>
                    </View>
                </Reveal>

                <Reveal index={1}>
                    <View style={st.card}>
                        <Kicker accent>Saat gempa terjadi</Kicker>
                        {LANGKAH.map((x, i) => (
                            <View key={x.t} style={st.step}>
                                <View style={st.badge}><Text style={st.badgeText}>{i + 1}</Text></View>
                                <View style={st.fill}><Text style={st.stepT}>{x.t}</Text><Text style={st.p}>{x.d}</Text></View>
                            </View>
                        ))}
                    </View>
                </Reveal>

                <Reveal index={2}>
                    <View style={st.card}>
                        <Kicker accent>Tas siaga ({count} dari {TAS.length})</Kicker>
                        <View style={st.track} accessibilityRole="progressbar" accessibilityValue={{ min: 0, max: TAS.length, now: count }}>
                            <View style={[st.fillBar, { width: `${(count / TAS.length) * 100}%` }]} />
                        </View>
                        {TAS.map((x, i) => (
                            <PressableScale key={x} onPress={() => toggle(i)} haptic={false} label={`${x}, ${done[i] ? 'sudah' : 'belum'} disiapkan`} style={st.item}>
                                <View style={[st.box, done[i] && st.boxOn]}>{done[i] && <Text style={st.tick}>✓</Text>}</View>
                                <Text style={[st.p, st.fill, done[i] && st.struck]}>{x}</Text>
                            </PressableScale>
                        ))}
                        <Text style={st.meta}>Centang disimpan di ponsel ini saja.</Text>
                    </View>
                </Reveal>
            </ScrollView>
        </View>
    );
}

const st = StyleSheet.create({
    fill: { flex: 1, backgroundColor: COLORS.bg },
    body: { padding: 12, gap: 10 },
    card: { backgroundColor: COLORS.surface, borderWidth: 1, borderColor: COLORS.edge, borderRadius: 8, padding: 14, gap: 8 },
    phones: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
    phone: { width: '31%', minHeight: 72, alignItems: 'center', justifyContent: 'center', borderWidth: 1, borderColor: COLORS.edge, borderRadius: 8, backgroundColor: COLORS.surface },
    num: { fontFamily: 'head', fontSize: 30, fontWeight: '700', color: COLORS.danger },
    meta: { fontSize: 13, color: COLORS.muted, lineHeight: 19 },
    p: { fontSize: 16, lineHeight: 23, color: COLORS.ink },
    step: { flexDirection: 'row', gap: 10, alignItems: 'flex-start' },
    badge: { width: 28, height: 28, borderRadius: 14, backgroundColor: COLORS.accent, alignItems: 'center', justifyContent: 'center', marginTop: 2 },
    badgeText: { color: '#fff', fontWeight: '700' },
    stepT: { fontFamily: 'head', fontSize: 21, fontWeight: '700', color: COLORS.ink },
    track: { height: 8, borderRadius: 4, backgroundColor: COLORS.edge, overflow: 'hidden' },
    fillBar: { height: 8, backgroundColor: COLORS.accent },
    item: { flexDirection: 'row', alignItems: 'center', gap: 10, minHeight: 46 },
    box: { width: 26, height: 26, borderRadius: 6, borderWidth: 2, borderColor: COLORS.accent, alignItems: 'center', justifyContent: 'center' },
    boxOn: { backgroundColor: COLORS.accent },
    tick: { color: '#fff', fontWeight: '700', fontSize: 16 },
    struck: { color: COLORS.muted, textDecorationLine: 'line-through' },
});
