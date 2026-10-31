import { useCallback, useEffect, useState } from 'react';
import { ScrollView, StyleSheet, View } from 'react-native';
import { Text } from '../Text';
import { api, lalu } from '../api';
import { COLORS } from '../config';
import { Header, Kicker, Loading, Notice } from '../ui';

type Sumber = { key: string; name: string; last_ok: string | null; last_failure: unknown; status: 'healthy' | 'late' | 'degraded' | 'unknown' | string };

const STATUS: Record<string, { teks: string; warna: string }> = {
    healthy: { teks: 'Normal', warna: '#2E7D32' },
    late: { teks: 'Terlambat', warna: COLORS.orange },
    degraded: { teks: 'Bermasalah', warna: COLORS.danger },
};

const JENIS: { judul: string; isi: string }[] = [
    { judul: 'Resmi BMKG', isi: 'Gempa terkini dan dirasakan, serta peringatan dini cuaca. Diteruskan apa adanya dari data.bmkg.go.id tanpa mengubah angka.' },
    { judul: 'Prakiraan cuaca', isi: 'Titik lokasi yang dikenali sebagai desa atau kelurahan memakai prakiraan resmi BMKG. Bila tidak dikenali atau BMKG gagal, dipakai estimasi model Open-Meteo dan diberi label sendiri.' },
    { judul: 'Belum diverifikasi', isi: 'Gempa USGS melengkapi yang belum ada di BMKG dan selalu ditandai sumbernya. Di Pusat Komando, gempa EMSC ditandai menunggu validasi BMKG. Laporan warga tidak diverifikasi.' },
    { judul: 'Perkiraan sendiri', isi: 'Estimasi guncangan (MMI) di lokasi Anda dihitung dengan rumus kasar. Itu perkiraan, bukan hasil pengukuran, dan bukan pengganti peringatan resmi.' },
];

/** Kejelasan sumber data: status tiap penyedia saat ini dan pembeda data resmi, cadangan, dan perkiraan. */
export default function SumberScreen() {
    const [rows, setRows] = useState<Sumber[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);

    const load = useCallback(async () => {
        setLoading(true);
        setError(null);
        try {
            setRows((await api<{ sources: Sumber[] }>('sources/status')).sources ?? []);
        } catch (e: any) {
            setError(e.message);
        } finally {
            setLoading(false);
        }
    }, []);
    useEffect(() => { load(); }, [load]);

    return (
        <View style={st.fill}>
            <Header title="Sumber data" subtitle="Dari mana informasi ini berasal dan seberapa sehat sumbernya" />
            {error && <Notice onRetry={load}>{error}</Notice>}
            <ScrollView contentContainerStyle={st.body}>
                {JENIS.map((j) => (
                    <View key={j.judul} style={st.card}>
                        <Kicker accent>{j.judul}</Kicker>
                        <Text style={st.p}>{j.isi}</Text>
                    </View>
                ))}
                <Kicker>Status sumber saat ini</Kicker>
                {loading && <Loading />}
                {rows.map((r) => {
                    const s = STATUS[r.status] ?? { teks: 'Belum ada data', warna: COLORS.muted };
                    return (
                        <View key={r.key} style={st.row}>
                            <View style={st.fill}>
                                <Text style={st.name}>{r.name}</Text>
                                <Text style={st.meta}>{r.last_ok ? `Terakhir berhasil ${lalu(r.last_ok)}` : 'Belum pernah berhasil'}</Text>
                            </View>
                            <Text style={[st.badge, { color: s.warna, borderColor: s.warna }]}>{s.teks}</Text>
                        </View>
                    );
                })}
                <Text style={st.hint}>Aplikasi ini menyajikan ulang informasi dari lembaga resmi dan bukan alat pendeteksi gempa. Untuk keadaan darurat, ikuti arahan BMKG dan BPBD setempat.</Text>
            </ScrollView>
        </View>
    );
}

const st = StyleSheet.create({
    fill: { flex: 1, backgroundColor: COLORS.bg },
    body: { padding: 12, gap: 10, paddingBottom: 32 },
    card: { backgroundColor: COLORS.surface, borderWidth: 1, borderColor: COLORS.edge, borderRadius: 8, padding: 14, gap: 4 },
    p: { fontSize: 15, lineHeight: 22, color: COLORS.ink },
    row: { flexDirection: 'row', alignItems: 'center', gap: 10, backgroundColor: COLORS.surface, borderWidth: 1, borderColor: COLORS.edge, borderRadius: 8, padding: 12, minHeight: 56 },
    name: { fontSize: 15, fontWeight: '600', color: COLORS.ink },
    meta: { fontSize: 13, color: COLORS.muted },
    badge: { fontSize: 12, fontWeight: '700', borderWidth: 1, borderRadius: 999, paddingHorizontal: 10, paddingVertical: 4 },
    hint: { fontSize: 13, color: COLORS.muted, lineHeight: 19, marginTop: 6 },
});
