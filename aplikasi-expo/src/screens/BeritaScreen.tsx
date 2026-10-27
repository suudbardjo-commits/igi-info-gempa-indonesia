import { useCallback, useEffect, useState } from 'react';
import { FlatList, Linking, Pressable, StyleSheet, View } from 'react-native';
import { Text } from '../Text';
import { api, lalu } from '../api';
import { COLORS } from '../config';
import { Header, Loading, Notice, s } from '../ui';

export default function BeritaScreen() {
    const [news, setNews] = useState<any>(null);
    const [ann, setAnn] = useState<any[]>([]);
    const [src, setSrc] = useState<any[]>([]);
    const [error, setError] = useState<string | null>(null);
    const [loading, setLoading] = useState(true);

    const load = useCallback(async () => {
        setLoading(true);
        setError(null);
        try {
            const [n, a, so] = await Promise.all([api('news?limit=30'), api('announcements'), api('sources/status')]);
            setNews(n);
            setAnn(a.data ?? []);
            setSrc(so.sources ?? []);
        } catch (e: any) {
            setError(e.message);
        } finally {
            setLoading(false);
        }
    }, []);
    useEffect(() => { load(); }, [load]);

    const STAT: Record<string, string> = { healthy: 'Normal', late: 'Terlambat', failed: 'Gagal', unknown: 'Belum diketahui' };

    return (
        <View style={st.fill}>
            <Header title="Berita dan informasi" subtitle="Kabar dari BMKG dan BNPB, dengan tautan ke sumbernya" />
            {error && <Notice onRetry={load}>{error}</Notice>}
            <FlatList
                data={news?.items ?? []}
                keyExtractor={(n) => n.id}
                refreshing={loading}
                onRefresh={load}
                ListHeaderComponent={
                    <View>
                        {ann.map((a) => (
                            <View key={a.id} style={st.ann}><Text style={st.val}>{a.title}</Text><Text style={st.text}>{a.body}</Text></View>
                        ))}
                    </View>
                }
                ListEmptyComponent={loading ? <Loading /> : <Text style={st.empty}>Belum ada berita yang berhasil diambil.</Text>}
                renderItem={({ item }) => (
                    <Pressable onPress={() => Linking.openURL(item.url)} accessibilityRole="link" style={st.item}>
                        <Text style={s.muted}>{item.source_label} · {item.kind} · {lalu(item.published_at)}</Text>
                        <Text style={st.val}>{item.title}</Text>
                        {item.summary ? <Text style={st.text} numberOfLines={3}>{item.summary}</Text> : null}
                        <Text style={st.link}>Baca di situs sumber ↗</Text>
                    </Pressable>
                )}
                ListFooterComponent={
                    <View style={st.srcBox}>
                        <Text style={st.h}>Status sumber data</Text>
                        {src.map((x) => (
                            <View key={x.key} style={st.srcRow}><Text style={[st.text, { flex: 1 }]}>{x.name}</Text><Text style={[st.badge, x.status === 'failed' && { color: COLORS.danger }, x.status === 'late' && { color: '#FF9F43' }]}>{STAT[x.status]}</Text></View>
                        ))}
                    </View>
                }
            />
        </View>
    );
}

const st = StyleSheet.create({
    fill: { flex: 1, backgroundColor: COLORS.bg },
    item: { backgroundColor: COLORS.surface, borderBottomWidth: 1, borderBottomColor: COLORS.edge, padding: 14, gap: 5 },
    ann: { backgroundColor: COLORS.raised, borderLeftWidth: 3, borderLeftColor: COLORS.accent, padding: 12, margin: 12, gap: 4 },
    val: { color: COLORS.ink, fontSize: 14, fontWeight: '600', lineHeight: 20 },
    text: { color: COLORS.ink, fontSize: 13, lineHeight: 19 },
    link: { color: COLORS.blue, fontSize: 13, minHeight: 32, paddingTop: 4 },
    empty: { padding: 24, color: COLORS.muted },
    srcBox: { padding: 16 },
    h: { fontSize: 13, fontWeight: '700', color: COLORS.navy, marginBottom: 6 },
    srcRow: { flexDirection: 'row', paddingVertical: 8, borderBottomWidth: 1, borderBottomColor: COLORS.edge },
    badge: { fontSize: 12, fontWeight: '600', color: COLORS.accent },
});
