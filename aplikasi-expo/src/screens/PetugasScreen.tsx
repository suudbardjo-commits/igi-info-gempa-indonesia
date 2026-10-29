import * as SecureStore from 'expo-secure-store';
import { useCallback, useEffect, useState } from 'react';
import { Linking, Platform, RefreshControl, ScrollView, StyleSheet, TextInput, View } from 'react-native';
import { Text } from '../Text';
import { ApiError, angka, lalu } from '../api';
import { COLORS, SERVER_URL, magnitudeColor } from '../config';
import { PressableScale, Reveal, tapStrong } from '../fx';
import { Notice, s } from '../ui';

const TOKEN_KEY = 'igi.token';

/** Panggilan API terproteksi (token Sanctum). Token disimpan terenkripsi di perangkat (SecureStore). */
async function authed(token: string, method: string, path: string, body?: unknown) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 15000);
    try {
        const res = await fetch(`${SERVER_URL}/api/v1/${path}`, {
            method,
            headers: { Accept: 'application/json', 'ngrok-skip-browser-warning': '1', Authorization: `Bearer ${token}`, ...(body ? { 'Content-Type': 'application/json' } : {}) },
            body: body ? JSON.stringify(body) : undefined,
            signal: controller.signal,
        });
        const json = await res.json().catch(() => null);
        if (!res.ok) throw new ApiError(json?.message ?? json?.error?.message ?? `Server menjawab ${res.status}.`, `http_${res.status}`, res.status);
        return json;
    } catch (e: any) {
        if (e instanceof ApiError) throw e;
        throw new ApiError(e?.name === 'AbortError' ? 'Server terlalu lama menjawab.' : 'Tidak dapat terhubung ke server IGI.', 'network');
    } finally {
        clearTimeout(timer);
    }
}

const URGENCY: Record<string, { label: string; color: string }> = { critical: { label: 'Kritis', color: '#B71C1C' }, high: { label: 'Tinggi', color: '#D32F2F' }, medium: { label: 'Sedang', color: '#E65100' }, low: { label: 'Rendah', color: '#0B67B2' } };

export default function PetugasScreen() {
    const [token, setToken] = useState<string | null>(null);
    const [ready, setReady] = useState(false);
    const [user, setUser] = useState<any>(null);
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [data, setData] = useState<{ overview?: any; summary?: any; demands?: any[] }>({});
    const [loading, setLoading] = useState(false);

    const logout = useCallback(async (t: string | null, silent = false) => {
        if (t && !silent) { try { await authed(t, 'DELETE', 'auth/token'); } catch { /* token tetap dihapus di perangkat */ } }
        try { await SecureStore.deleteItemAsync(TOKEN_KEY); } catch { /* tidak ada */ }
        setToken(null); setUser(null); setData({});
    }, []);

    const load = useCallback(async (t: string) => {
        setLoading(true);
        setError(null);
        try {
            const me = await authed(t, 'GET', 'auth/me');
            setUser(me);
            if (me.must_change_password) { setLoading(false); return; }
            const [overview, summary, demands] = await Promise.all([
                authed(t, 'GET', 'me/overview').catch(() => null),
                authed(t, 'GET', 'logistics/summary').catch(() => null), // 403 untuk peran tanpa akses logistik
                authed(t, 'GET', 'demands?status=open').catch(() => null),
            ]);
            setData({ overview, summary, demands: demands?.data ?? [] });
        } catch (e: any) {
            if (e.status === 401) { await logout(t, true); setError('Sesi berakhir. Silakan masuk lagi.'); } else setError(e.message);
        } finally {
            setLoading(false);
        }
    }, [logout]);

    useEffect(() => {
        SecureStore.getItemAsync(TOKEN_KEY).then((t) => { setReady(true); if (t) { setToken(t); load(t); } }).catch(() => setReady(true));
    }, [load]);

    const login = async () => {
        setBusy(true);
        setError(null);
        try {
            const res = await fetch(`${SERVER_URL}/api/v1/auth/token`, {
                method: 'POST',
                headers: { Accept: 'application/json', 'ngrok-skip-browser-warning': '1', 'Content-Type': 'application/json' },
                body: JSON.stringify({ email: email.trim(), password, device_name: `Aplikasi IGI (${Platform.OS})`.slice(0, 100) }),
            });
            const json = await res.json().catch(() => null);
            if (!res.ok) throw new Error(json?.errors?.email?.[0] ?? json?.message ?? (res.status === 429 ? 'Terlalu banyak percobaan. Tunggu sebentar.' : `Server menjawab ${res.status}.`));
            await SecureStore.setItemAsync(TOKEN_KEY, json.token);
            setPassword('');
            tapStrong();
            setToken(json.token);
            load(json.token);
        } catch (e: any) {
            setError(e.message === 'Network request failed' ? 'Tidak dapat terhubung ke server IGI.' : e.message);
        } finally {
            setBusy(false);
        }
    };

    if (!ready) return <View style={st.fill} />;

    if (!token) {
        return (
            <ScrollView style={st.fill} contentContainerStyle={st.pad} keyboardShouldPersistTaps="handled">
                <Reveal><Text style={st.title}>Area petugas</Text><Text style={st.sub}>Untuk relawan, koordinator posko, pengelola gudang, dan operator BPBD.</Text></Reveal>
                <Reveal index={1} style={st.card}>
                    <Text style={st.label}>Email</Text>
                    <TextInput placeholderTextColor={COLORS.muted} keyboardAppearance="dark" value={email} onChangeText={setEmail} autoCapitalize="none" autoCorrect={false} keyboardType="email-address" style={st.input} accessibilityLabel="Email" />
                    <Text style={st.label}>Kata sandi</Text>
                    <TextInput placeholderTextColor={COLORS.muted} keyboardAppearance="dark" value={password} onChangeText={setPassword} secureTextEntry style={st.input} accessibilityLabel="Kata sandi" onSubmitEditing={login} />
                    {error && <Text style={st.err} accessibilityRole="alert">{error}</Text>}
                    <PressableScale onPress={login} disabled={busy || !email || !password} style={[st.btn, (busy || !email || !password) && { opacity: 0.55 }]} label="Masuk"><Text style={st.btnText}>{busy ? 'Memeriksa…' : 'Masuk'}</Text></PressableScale>
                </Reveal>
                <Text style={st.note}>Belum punya akun? Daftar dari situs; akun baru aktif setelah disetujui operator BPBD.</Text>
                <PressableScale onPress={() => Linking.openURL(`${SERVER_URL}/daftar`)} style={st.linkBtn} label="Daftar di situs"><Text style={st.link}>Daftar di situs ↗</Text></PressableScale>
            </ScrollView>
        );
    }

    const { overview, summary, demands } = data;
    return (
        <ScrollView style={st.fill} contentContainerStyle={st.pad} refreshControl={<RefreshControl refreshing={loading} onRefresh={() => token && load(token)} tintColor={COLORS.navy} />}>
            <Reveal>
                <View style={st.who}>
                    <View style={st.avatar}><Text style={st.avatarText}>{(user?.name ?? '?').slice(0, 1).toUpperCase()}</Text></View>
                    <View style={{ flex: 1 }}>
                        <Text style={st.title}>{user?.name ?? 'Petugas'}</Text>
                        <Text style={st.sub}>{(user?.roles ?? []).join(', ') || 'petugas'}</Text>
                    </View>
                    <PressableScale onPress={() => logout(token)} style={st.out} label="Keluar"><Text style={st.outText}>Keluar</Text></PressableScale>
                </View>
            </Reveal>
            {error && <Notice onRetry={() => token && load(token)}>{error}</Notice>}
            {user?.must_change_password && (
                <Notice>Kata sandi Anda sementara. Ganti dulu di situs (menu Akun) sebelum memakai fitur lain.</Notice>
            )}

            {summary && (
                <Reveal index={1}>
                    <Text style={st.h}>Ringkasan logistik</Text>
                    <View style={st.tiles}>
                        {([['Permintaan terbuka', summary.demands_open, '#0B67B2'], ['Kritis', summary.demands_critical, '#B71C1C'], ['Pengiriman aktif', summary.dispatches_active, '#2E7D32'], ['Segera kedaluwarsa', summary.batches_expiring, '#E65100']] as const).map(([k, v, c]) => (
                            <View key={k} style={[st.tile, { borderTopColor: c }]}><Text style={[st.tileVal, { color: c }]}>{angka(v)}</Text><Text style={st.tileKey}>{k}</Text></View>
                        ))}
                    </View>
                </Reveal>
            )}

            {overview && (
                <Reveal index={2}>
                    <Text style={st.h}>Posko Anda ({overview.camps.length})</Text>
                    {overview.camps.length === 0 && <Text style={st.muted}>Belum ada posko yang ditugaskan ke akun ini.</Text>}
                    {overview.camps.map((c: any) => {
                        const pct = c.capacity_people ? Math.min(100, (c.current_occupants / c.capacity_people) * 100) : 0;
                        return (
                            <View key={c.id} style={st.row}>
                                <View style={st.rowHead}><Text style={st.val}>{c.name}</Text><Text style={st.badge}>{c.role}</Text></View>
                                <Text style={st.muted}>{c.code} · {c.current_occupants} / {c.capacity_people} orang tercatat</Text>
                                <View style={st.bar}><View style={[st.barFill, { width: `${pct}%`, backgroundColor: pct > 90 ? '#B71C1C' : COLORS.accent }]} /></View>
                            </View>
                        );
                    })}
                    {overview.alerts.length > 0 && (
                        <>
                            <Text style={st.h}>Peringatan gempa untuk posko Anda (72 jam)</Text>
                            {overview.alerts.map((a: any) => (
                                <View key={`${a.disaster.id}-${a.camp.id}`} style={[st.row, { borderLeftWidth: 4, borderLeftColor: magnitudeColor(a.disaster.magnitude) }]}>
                                    <Text style={st.val}>M {a.disaster.magnitude} · {a.disaster.location}</Text>
                                    <Text style={st.muted}>{a.camp.name} · {a.distance_km.toFixed(1)} km dari pusat gempa · {lalu(a.disaster.occurred_at)}</Text>
                                </View>
                            ))}
                        </>
                    )}
                </Reveal>
            )}

            {demands && demands.length > 0 && (
                <Reveal index={3}>
                    <Text style={st.h}>Permintaan terbuka ({demands.length})</Text>
                    {demands.slice(0, 20).map((d: any) => {
                        const u = URGENCY[d.urgency_level] ?? URGENCY.low;
                        return (
                            <View key={d.id} style={st.row}>
                                <View style={st.rowHead}><Text style={st.val}>{d.item?.name}</Text><Text style={[st.badge, { backgroundColor: `${u.color}22`, color: u.color }]}>{u.label}</Text></View>
                                <Text style={st.muted}>{d.camp?.name} · sisa {angka(d.quantity_remaining)} dari {angka(d.quantity_requested)} {d.item?.unit}</Text>
                            </View>
                        );
                    })}
                </Reveal>
            )}

            <Reveal index={4}>
                <Text style={st.h}>Fitur lengkap di situs</Text>
                <Text style={st.muted}>Laporan lapangan offline, pengajuan kebutuhan, pengiriman, dan stok gudang tersedia di situs IGI.</Text>
                <PressableScale onPress={() => Linking.openURL(`${SERVER_URL}/lapangan`)} style={st.linkBtn} label="Laporan lapangan"><Text style={st.link}>Buka laporan lapangan ↗</Text></PressableScale>
                <PressableScale onPress={() => Linking.openURL(`${SERVER_URL}/logistik`)} style={st.linkBtn} label="Konsol logistik"><Text style={st.link}>Buka konsol logistik ↗</Text></PressableScale>
            </Reveal>
        </ScrollView>
    );
}

const st = StyleSheet.create({
    fill: { flex: 1, backgroundColor: COLORS.bg },
    pad: { padding: 16, paddingBottom: 40 },
    title: { fontFamily: 'head', fontSize: 28, fontWeight: '800', color: COLORS.ink },
    sub: { color: COLORS.muted, fontSize: 13, lineHeight: 19, marginTop: 2, marginBottom: 10 },
    card: { backgroundColor: COLORS.surface, borderRadius: 8, padding: 16, gap: 8 },
    label: { fontSize: 13, fontWeight: '700', color: COLORS.ink },
    input: { fontFamily: 'Barlow_400Regular', borderWidth: 1, borderColor: COLORS.edge, borderRadius: 8, paddingHorizontal: 12, minHeight: 48, fontSize: 15, color: COLORS.ink },
    err: { color: COLORS.danger, fontSize: 13 },
    btn: { backgroundColor: COLORS.navy, borderRadius: 8, minHeight: 50, alignItems: 'center', justifyContent: 'center', marginTop: 6 },
    btnText: { color: COLORS.surface, fontSize: 16, fontWeight: '700' },
    note: { color: COLORS.muted, fontSize: 12, lineHeight: 18, marginTop: 14 },
    linkBtn: { minHeight: 44, justifyContent: 'center' },
    link: { color: COLORS.blue, fontSize: 14, fontWeight: '600' },
    who: { flexDirection: 'row', alignItems: 'center', gap: 12 },
    avatar: { width: 48, height: 48, borderRadius: 8, backgroundColor: COLORS.teal, alignItems: 'center', justifyContent: 'center' },
    avatarText: { color: COLORS.surface, fontSize: 20, fontWeight: '800' },
    out: { borderColor: COLORS.edge, borderWidth: 1, borderRadius: 8, paddingHorizontal: 14, minHeight: 40, justifyContent: 'center' },
    outText: { color: COLORS.blue, fontWeight: '700' },
    h: { fontSize: 15, fontWeight: '800', color: COLORS.navy, marginTop: 18, marginBottom: 8 },
    tiles: { flexDirection: 'row', flexWrap: 'wrap', marginHorizontal: -4 },
    tile: { width: '47%', margin: '1.5%', backgroundColor: COLORS.surface, borderRadius: 8, padding: 12, borderTopWidth: 4 },
    tileVal: { fontSize: 28, fontWeight: '800' },
    tileKey: { fontSize: 12, color: COLORS.ink, fontWeight: '600' },
    row: { backgroundColor: COLORS.surface, borderRadius: 8, padding: 12, marginBottom: 8, gap: 4 },
    rowHead: { flexDirection: 'row', justifyContent: 'space-between', gap: 8, alignItems: 'center' },
    val: { color: COLORS.ink, fontSize: 14, fontWeight: '700', flexShrink: 1 },
    badge: { fontSize: 11, fontWeight: '700', color: COLORS.blue, backgroundColor: COLORS.accentSoft, borderRadius: 8, paddingHorizontal: 7, paddingVertical: 2, overflow: 'hidden' },
    muted: { color: COLORS.muted, fontSize: 12, lineHeight: 18 },
    bar: { height: 5, backgroundColor: COLORS.edge, borderRadius: 8, marginTop: 4, overflow: 'hidden' },
    barFill: { height: 5, borderRadius: 8 },
});
