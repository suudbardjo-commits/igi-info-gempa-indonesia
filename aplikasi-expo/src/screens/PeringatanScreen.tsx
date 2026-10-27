import { useCallback, useEffect, useState } from 'react';
import { FlatList, Linking, Pressable, StyleSheet, View } from 'react-native';
import { Text } from '../Text';
import { api, lalu, waktuWib } from '../api';
import { COLORS } from '../config';
import { Header, Loading, Notice, s } from '../ui';

const STATUS: Record<string, string> = { active: 'Aktif', upcoming: 'Akan berlaku', expired: 'Berakhir', cancelled: 'Dibatalkan' };

export default function PeringatanScreen() {
    const [data, setData] = useState<any>(null);
    const [error, setError] = useState<string | null>(null);
    const [loading, setLoading] = useState(true);
    const [open, setOpen] = useState<string | null>(null);

    const load = useCallback(async () => {
        setLoading(true);
        setError(null);
        try {
            setData(await api('weather-alerts?state=all'));
        } catch (e: any) {
            setError(e.message);
        } finally {
            setLoading(false);
        }
    }, []);
    useEffect(() => { load(); }, [load]);

    const active = (data?.alerts ?? []).filter((a: any) => a.state === 'active' || a.state === 'upcoming');
    const ended = (data?.alerts ?? []).filter((a: any) => a.state === 'expired' || a.state === 'cancelled');
    const rows = [...active.map((a: any) => ({ ...a, _h: 'a' })), ...ended.map((a: any) => ({ ...a, _h: 'e' }))];

    return (
        <View style={st.fill}>
            <Header title="Peringatan dini cuaca" subtitle={data ? `Diperiksa ${lalu(data.checked_at)} · BMKG` : 'Dari BMKG (CAP)'} />
            {error && <Notice onRetry={load}>{error} Ini bukan berarti tidak ada peringatan.</Notice>}
            {data?.is_stale && <Notice>Pembaruan terakhir gagal; ini data lama.</Notice>}
            <FlatList
                data={rows}
                keyExtractor={(a) => a.id}
                refreshing={loading}
                onRefresh={load}
                ListHeaderComponent={data ? <Text style={st.h}>Aktif ({active.length})</Text> : null}
                ListEmptyComponent={loading ? <Loading /> : data ? <Text style={st.empty}>Tidak ada peringatan aktif pada pemeriksaan ini.</Text> : null}
                renderItem={({ item, index }) => (
                    <View>
                        {item._h === 'e' && (index === 0 || rows[index - 1]._h === 'a') && <Text style={st.h}>Berakhir atau dibatalkan ({ended.length})</Text>}
                        <Pressable onPress={() => setOpen(open === item.id ? null : item.id)} accessibilityRole="button" accessibilityState={{ expanded: open === item.id }} style={[st.item, item._h === 'e' && { opacity: 0.7 }]}>
                            <Text style={st.val}>{item.title}</Text>
                            <Text style={s.muted}>{item.issuer} · {STATUS[item.state]} · s.d. {waktuWib(item.expires)}</Text>
                            {open === item.id && (
                                <View style={st.body}>
                                    <Text style={s.muted}>Area: {item.area}</Text>
                                    <Text style={st.text}>{item.description}</Text>
                                    {item.instruction ? <Text style={st.text}>{item.instruction}</Text> : null}
                                    {item.source_url ? <Text style={st.link} onPress={() => Linking.openURL(item.source_url)}>Dokumen CAP BMKG ↗</Text> : null}
                                </View>
                            )}
                        </Pressable>
                    </View>
                )}
                ListFooterComponent={<Text style={st.foot}>Peringatan dini cuaca: BMKG. Untuk arahan keselamatan ikuti BMKG, BPBD, dan BNPB.</Text>}
            />
        </View>
    );
}

const st = StyleSheet.create({
    fill: { flex: 1, backgroundColor: COLORS.bg },
    h: { fontSize: 13, fontWeight: '700', color: COLORS.navy, padding: 14, paddingBottom: 6 },
    item: { backgroundColor: COLORS.surface, borderBottomWidth: 1, borderBottomColor: COLORS.edge, padding: 14, gap: 3, minHeight: 56 },
    val: { color: COLORS.ink, fontSize: 14, fontWeight: '600' },
    body: { marginTop: 8, gap: 6 },
    text: { color: COLORS.ink, fontSize: 13, lineHeight: 20 },
    link: { color: COLORS.blue, textDecorationLine: 'underline', minHeight: 44, paddingTop: 8 },
    empty: { padding: 24, color: COLORS.muted },
    foot: { padding: 16, color: COLORS.muted, fontSize: 11, lineHeight: 17 },
});
