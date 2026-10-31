import { useEffect, useState } from 'react';
import { ScrollView, StyleSheet, View } from 'react-native';
import { COLORS } from '../config';
import { Chip } from '../ui';
import BeritaScreen from './BeritaScreen';
import PeringatanScreen from './PeringatanScreen';
import SiagaScreen from './SiagaScreen';
import SumberScreen from './SumberScreen';
import TerdekatScreen from './TerdekatScreen';

type Part = 'peringatan' | 'berita' | 'terdekat' | 'siaga' | 'sumber';
const PARTS: { key: Part; label: string }[] = [
    { key: 'peringatan', label: 'Peringatan dini' },
    { key: 'terdekat', label: 'Gempa terdekat' },
    { key: 'siaga', label: 'Siaga' },
    { key: 'berita', label: 'Berita' },
    { key: 'sumber', label: 'Sumber data' },
];

/** Peringatan dini, gempa terdekat, panduan siaga, dan berita dalam satu tab dengan pengalih. */
export default function InfoScreen({ initial }: { initial?: string }) {
    const [part, setPart] = useState<Part>(PARTS.some((p) => p.key === initial) ? (initial as Part) : 'peringatan');
    useEffect(() => { if (PARTS.some((p) => p.key === initial)) setPart(initial as Part); }, [initial]);
    return (
        <View style={st.fill}>
            <View style={st.segWrap}>
                <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={st.seg}>
                    {PARTS.map((p) => <Chip key={p.key} label={p.label} active={part === p.key} onPress={() => setPart(p.key)} />)}
                </ScrollView>
            </View>
            <View style={st.fill}>
                {part === 'peringatan' && <PeringatanScreen />}
                {part === 'terdekat' && <TerdekatScreen />}
                {part === 'siaga' && <SiagaScreen />}
                {part === 'berita' && <BeritaScreen />}
                {part === 'sumber' && <SumberScreen />}
            </View>
        </View>
    );
}

const st = StyleSheet.create({
    fill: { flex: 1, backgroundColor: COLORS.bg },
    segWrap: { backgroundColor: COLORS.surface, borderBottomWidth: 1, borderBottomColor: COLORS.edge },
    seg: { flexDirection: 'row', gap: 8, padding: 10 },
});
