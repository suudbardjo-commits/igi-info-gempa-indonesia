import { useEffect, useState } from 'react';
import { StyleSheet, View } from 'react-native';
import { COLORS } from '../config';
import { Chip } from '../ui';
import BeritaScreen from './BeritaScreen';
import PeringatanScreen from './PeringatanScreen';

/** Peringatan dini dan berita dalam satu tab dengan pengalih. */
export default function InfoScreen({ initial }: { initial?: string }) {
    const [part, setPart] = useState<'peringatan' | 'berita'>(initial === 'berita' ? 'berita' : 'peringatan');
    useEffect(() => { if (initial === 'berita' || initial === 'peringatan') setPart(initial); }, [initial]);
    return (
        <View style={st.fill}>
            <View style={st.seg}>
                <Chip label="Peringatan dini" active={part === 'peringatan'} onPress={() => setPart('peringatan')} />
                <Chip label="Berita" active={part === 'berita'} onPress={() => setPart('berita')} />
            </View>
            <View style={st.fill}>{part === 'peringatan' ? <PeringatanScreen /> : <BeritaScreen />}</View>
        </View>
    );
}

const st = StyleSheet.create({
    fill: { flex: 1, backgroundColor: COLORS.bg },
    seg: { flexDirection: 'row', gap: 8, padding: 10, backgroundColor: COLORS.surface, borderBottomWidth: 1, borderBottomColor: COLORS.edge },
});
