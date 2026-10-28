import { ReactNode, forwardRef, useEffect, useImperativeHandle, useMemo, useRef, useState } from 'react';
import { Animated, PanResponder, Pressable, StyleSheet, View } from 'react-native';
import { COLORS } from './config';
import { tap } from './fx';

type Props = {
    /** Tinggi area yang tersedia (px). */
    containerHeight: number;
    /** Dipanggil dengan tinggi sheet yang tampak (px) setelah berhenti di satu titik snap. */
    onSettled: (visible: number, index: number) => void;
    /** Tombol melayang yang naik-turun bersama sheet (mis. pusatkan peta). */
    floating?: ReactNode;
    /** Bagian yang selalu tampak di titik Peek (di bawah pegangan). */
    peek: ReactNode;
    /** Isi lengkap, digulir saat sheet dinaikkan. */
    children: ReactNode;
};

const PEEK_DEFAULT = 176;

/**
 * Lembar bawah dengan tiga titik snap: Peek (ringkasan), Half (50%), Full (90%). Diseret lewat pegangan dan bagian Peek;
 * isi hanya bisa digulir saat sheet dinaikkan, supaya gulir dan seret tidak berebut.
 */
export type SheetHandle = { snapTo: (index: number) => void };

const Sheet = forwardRef<SheetHandle, Props>(function Sheet({ containerHeight: H, onSettled, floating, peek, children }, ref) {
    // Tinggi titik Peek = tinggi pegangan + ringkasan (diukur), supaya isi lembar tidak mengintip di bawahnya.
    const [PEEK, setPeek] = useState(PEEK_DEFAULT);
    const full = Math.max(PEEK + 120, Math.round(H * 0.9));
    const snaps = useMemo(() => [PEEK, Math.round(H * 0.5), full], [H, full, PEEK]);
    const [index, setIndex] = useState(0);
    const y = useRef(new Animated.Value(full - PEEK_DEFAULT)).current; // translateY: full - tinggi tampak
    const visible = useRef(PEEK_DEFAULT);
    const start = useRef(PEEK_DEFAULT);

    const go = (i: number, animate = true) => {
        const to = Math.max(0, Math.min(2, i));
        setIndex(to);
        visible.current = snaps[to];
        const target = full - snaps[to];
        if (animate) Animated.spring(y, { toValue: target, useNativeDriver: false, bounciness: 3, speed: 16 }).start();
        else y.setValue(target);
        onSettled(snaps[to], to);
    };

    useImperativeHandle(ref, () => ({ snapTo: (i: number) => go(i) }));

    // Ukuran area berubah (rotasi, papan ketik): pertahankan titik snap sekarang.
    useEffect(() => { go(index, false); /* eslint-disable-next-line react-hooks/exhaustive-deps */ }, [H, PEEK]);

    const pan = useMemo(() => PanResponder.create({
        onStartShouldSetPanResponder: () => false,
        onMoveShouldSetPanResponder: (_, g) => Math.abs(g.dy) > 6 && Math.abs(g.dy) > Math.abs(g.dx),
        onPanResponderGrant: () => { y.stopAnimation(); start.current = visible.current; },
        onPanResponderMove: (_, g) => {
            const v = Math.max(snaps[0] * 0.9, Math.min(full, start.current - g.dy));
            visible.current = v;
            y.setValue(full - v);
        },
        onPanResponderRelease: (_, g) => {
            // Lemparan cepat melompat satu titik; selain itu ke titik terdekat.
            let to: number;
            if (Math.abs(g.vy) > 0.7) to = index + (g.vy < 0 ? 1 : -1);
            else to = snaps.reduce((b, s, i) => (Math.abs(s - visible.current) < Math.abs(snaps[b] - visible.current) ? i : b), 0);
            go(to);
        },
    }), [snaps, full, index]); // eslint-disable-line react-hooks/exhaustive-deps

    const fabY = y.interpolate({ inputRange: [0, full], outputRange: [-full - 12, -12], extrapolate: 'clamp' });

    return (
        <>
            {floating ? <Animated.View style={[st.floating, { transform: [{ translateY: fabY }] }]} pointerEvents="box-none">{floating}</Animated.View> : null}
            <Animated.View style={[st.sheet, { height: full, transform: [{ translateY: y }] }]} accessibilityLabel="Panel data gempa">
                <View {...pan.panHandlers} onLayout={(e) => { const h = Math.round(e.nativeEvent.layout.height); if (h > 60 && Math.abs(h - PEEK) > 2) setPeek(h); }}>
                    <Pressable
                        onPress={() => { tap(); go(index === 0 ? 1 : index === 1 ? 2 : 0); }}
                        accessibilityRole="adjustable"
                        accessibilityLabel={`Panel data. Titik ${['peek', 'half', 'full'][index]}. Ketuk untuk memperbesar.`}
                        accessibilityActions={[{ name: 'increment' }, { name: 'decrement' }]}
                        onAccessibilityAction={(e) => go(e.nativeEvent.actionName === 'increment' ? index + 1 : index - 1)}
                        style={st.handle}
                    >
                        <View style={st.grip} />
                    </Pressable>
                    <View style={st.peek}>{peek}</View>
                </View>
                <Animated.ScrollView scrollEnabled={index > 0} nestedScrollEnabled showsVerticalScrollIndicator={index > 0} contentContainerStyle={st.body}>
                    {children}
                </Animated.ScrollView>
            </Animated.View>
        </>
    );
});

export default Sheet;

const st = StyleSheet.create({
    sheet: { position: 'absolute', left: 0, right: 0, bottom: 0, backgroundColor: COLORS.bg, borderTopLeftRadius: 12, borderTopRightRadius: 12, borderWidth: 1, borderBottomWidth: 0, borderColor: COLORS.edge, overflow: 'hidden' },
    handle: { height: 28, alignItems: 'center', justifyContent: 'center', backgroundColor: COLORS.surface },
    grip: { width: 40, height: 4, borderRadius: 2, backgroundColor: COLORS.edge },
    peek: { backgroundColor: COLORS.surface, paddingHorizontal: 16, paddingBottom: 10, borderBottomWidth: 1, borderBottomColor: COLORS.edge },
    body: { paddingBottom: 40 },
    floating: { position: 'absolute', right: 12, bottom: 0 },
});
