import { ReactNode, useEffect, useRef } from 'react';
import { ActivityIndicator, Animated, Easing, Pressable, StyleSheet, View } from 'react-native';
import { Text } from './Text';
import { COLORS } from './config';

/**
 * Jejak seismik: deretan garis vertikal yang tingginya berasal dari data (nilai 0..1). Motif identitas IGI (DESIGN.md).
 * Tumbuh satu kali saat tampil.
 */
export function Trace({ values, height = 34, color = COLORS.accent, label }: { values: number[]; height?: number; color?: string; label?: string }) {
    const v = useRef(new Animated.Value(0)).current;
    useEffect(() => {
        Animated.timing(v, { toValue: 1, duration: 700, easing: Easing.out(Easing.cubic), useNativeDriver: false }).start();
    }, [v]);
    return (
        <View accessible accessibilityRole="image" accessibilityLabel={label ?? 'Jejak aktivitas'} style={{ height, flexDirection: 'row', alignItems: 'center', gap: 2 }}>
            {values.map((x, i) => (
                <Animated.View key={i} style={{ flex: 1, minWidth: 2, borderRadius: 1, backgroundColor: color, opacity: 0.35 + 0.65 * Math.min(1, x), height: v.interpolate({ inputRange: [0, 1], outputRange: [2, Math.max(3, Math.min(1, x) * height)] }) }} />
            ))}
        </View>
    );
}

/** Garis tipis pemisah. */
export const Rule = ({ thick }: { thick?: boolean }) => <View style={{ height: thick ? 2 : 1, backgroundColor: COLORS.edge }} />;

/** Garis aksen pendek di bawah judul (nama lama dipertahankan agar layar lain tetap jalan). */
export function DoubleRule() {
    return <View style={{ height: 3, width: 44, backgroundColor: COLORS.accent, borderRadius: 2 }} />;
}

/** Label rubrik kecil di atas judul bagian. */
export function Kicker({ children, accent }: { children: ReactNode; accent?: boolean }) {
    return <Text style={[s.kicker, accent && { color: COLORS.accent }]}>{String(children).toUpperCase()}</Text>;
}

export function Notice({ tone = 'warn', children, onRetry }: { tone?: 'warn' | 'info'; children: ReactNode; onRetry?: () => void }) {
    return (
        <View style={[s.notice, tone === 'info' && s.info]} accessibilityRole="alert">
            <Text style={s.noticeText}>{children}</Text>
            {onRetry && (
                <Pressable onPress={onRetry} accessibilityRole="button" style={s.retry}>
                    <Text style={s.retryText}>Coba lagi</Text>
                </Pressable>
            )}
        </View>
    );
}

export function Loading({ text = 'Memuat…' }: { text?: string }) {
    return (
        <View style={s.loading}>
            <ActivityIndicator color={COLORS.accent} />
            <Text style={s.muted}>{text}</Text>
        </View>
    );
}

export function Chip({ label, active, onPress }: { label: string; active: boolean; onPress: () => void }) {
    return (
        <Pressable onPress={onPress} accessibilityRole="button" accessibilityState={{ selected: active }} style={[s.chip, active && s.chipOn]}>
            <Text style={[s.chipText, active && s.chipTextOn]}>{label}</Text>
        </Pressable>
    );
}

/** Kepala halaman: judul sempit tebal, keterangan, garis aksen pendek. */
export function Header({ title, subtitle }: { title: string; subtitle?: string }) {
    return (
        <View style={s.header}>
            <Text style={s.title} accessibilityRole="header">{title}</Text>
            {subtitle ? <Text style={s.muted}>{subtitle}</Text> : null}
            <View style={{ marginTop: 10 }}><DoubleRule /></View>
        </View>
    );
}

export const s = StyleSheet.create({
    kicker: { fontSize: 12, letterSpacing: 1, color: COLORS.muted, fontWeight: '600', marginTop: 12, marginBottom: 4 },
    notice: { backgroundColor: COLORS.warn, borderColor: COLORS.warnEdge, borderWidth: 1, borderRadius: 10, padding: 12, margin: 12 },
    info: { backgroundColor: COLORS.raised, borderColor: COLORS.edge },
    noticeText: { color: COLORS.ink, fontSize: 15, lineHeight: 22 },
    retry: { marginTop: 8, minHeight: 44, justifyContent: 'center' },
    retryText: { color: COLORS.accent, fontWeight: '700', textDecorationLine: 'underline' },
    loading: { padding: 24, alignItems: 'center', gap: 8 },
    muted: { color: COLORS.muted, fontSize: 14, lineHeight: 20 },
    chip: { minHeight: 40, paddingHorizontal: 14, borderRadius: 10, borderWidth: 1, borderColor: COLORS.edge, justifyContent: 'center' },
    chipOn: { borderColor: COLORS.accent, backgroundColor: COLORS.accentSoft },
    chipText: { color: COLORS.muted, fontSize: 15 },
    chipTextOn: { color: COLORS.accent, fontWeight: '700' },
    header: { paddingHorizontal: 16, paddingTop: 14, paddingBottom: 10, backgroundColor: COLORS.bg },
    title: { fontFamily: 'head', fontSize: 34, fontWeight: '700', color: COLORS.ink, lineHeight: 38 },
});
