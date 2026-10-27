import * as Haptics from 'expo-haptics';
import { ReactNode, useEffect, useRef, useState } from 'react';
import { AccessibilityInfo, Animated, Easing, Pressable, StyleProp, StyleSheet, TextStyle, View, ViewStyle } from 'react-native';
import { Text } from './Text';

/** Getaran ringan saat menyentuh kontrol; diabaikan bila perangkat tidak mendukung. */
export const tap = () => { Haptics.selectionAsync().catch(() => undefined); };
export const tapStrong = () => { Haptics.impactAsync(Haptics.ImpactFeedbackStyle.Medium).catch(() => undefined); };

let reduceMotion = false;
AccessibilityInfo.isReduceMotionEnabled().then((v) => { reduceMotion = v; }).catch(() => undefined);
const dur = (ms: number) => (reduceMotion ? 0 : ms);

const INNER_KEYS = new Set(['padding', 'paddingHorizontal', 'paddingVertical', 'paddingTop', 'paddingBottom', 'paddingLeft', 'paddingRight', 'flexDirection', 'alignItems', 'justifyContent', 'gap', 'flexWrap', 'minHeight', 'minWidth']);

/** Tombol yang mengecil sedikit saat ditekan dan memberi getaran. Ukuran/latar di luar, tata letak isi di dalam. */
export function PressableScale({ children, onPress, style, label, haptic = true, disabled }: { children: ReactNode; onPress?: () => void; style?: StyleProp<ViewStyle>; label?: string; haptic?: boolean; disabled?: boolean }) {
    const scale = useRef(new Animated.Value(1)).current;
    const to = (v: number) => Animated.spring(scale, { toValue: v, useNativeDriver: true, speed: 40, bounciness: reduceMotion ? 0 : 6 }).start();
    const flat = (StyleSheet.flatten(style) ?? {}) as Record<string, unknown>;
    const outer: Record<string, unknown> = {};
    const inner: Record<string, unknown> = {};
    for (const k of Object.keys(flat)) (INNER_KEYS.has(k) ? inner : outer)[k] = flat[k];
    return (
        <Animated.View style={[outer as ViewStyle, { transform: [{ scale }] }]}>
            <Pressable
                disabled={disabled}
                accessibilityRole="button"
                accessibilityLabel={label}
                onPressIn={() => to(0.96)}
                onPressOut={() => to(1)}
                onPress={() => { if (haptic) tap(); onPress?.(); }}
                style={[inner as ViewStyle, { flex: outer.flex ? 1 : undefined }]}
            >
                {children}
            </Pressable>
        </Animated.View>
    );
}

/** Muncul dengan geser dan pudar; `index` memberi jeda berurutan. */
export function Reveal({ children, index = 0, style }: { children: ReactNode; index?: number; style?: StyleProp<ViewStyle> }) {
    const v = useRef(new Animated.Value(0)).current;
    useEffect(() => {
        Animated.timing(v, { toValue: 1, duration: dur(420), delay: dur(index * 70), easing: Easing.out(Easing.cubic), useNativeDriver: true }).start();
    }, [v, index]);
    return (
        <Animated.View style={[style, { opacity: v, transform: [{ translateY: v.interpolate({ inputRange: [0, 1], outputRange: [reduceMotion ? 0 : 18, 0] }) }] }]}>{children}</Animated.View>
    );
}

/** Angka yang menghitung naik dari 0 ke nilai akhir. */
export function CountUp({ value, style, digits = 0 }: { value: number; style?: StyleProp<TextStyle>; digits?: number }) {
    const [shown, setShown] = useState(0);
    useEffect(() => {
        if (reduceMotion) { setShown(value); return; }
        const start = Date.now();
        const total = 900;
        const id = setInterval(() => {
            const t = Math.min(1, (Date.now() - start) / total);
            setShown(value * (1 - Math.pow(1 - t, 3)));
            if (t >= 1) clearInterval(id);
        }, 33);
        return () => clearInterval(id);
    }, [value]);
    return <Text style={style}>{shown.toLocaleString('id-ID', { maximumFractionDigits: digits, minimumFractionDigits: digits })}</Text>;
}

/** Titik berdenyut penanda data langsung. */
export function PulseDot({ color = '#2FB67C', size = 10 }: { color?: string; size?: number }) {
    const v = useRef(new Animated.Value(0)).current;
    useEffect(() => {
        if (reduceMotion) return;
        const loop = Animated.loop(Animated.timing(v, { toValue: 1, duration: 1600, easing: Easing.out(Easing.quad), useNativeDriver: true }));
        loop.start();
        return () => loop.stop();
    }, [v]);
    return (
        <View style={{ width: size * 2.4, height: size * 2.4, alignItems: 'center', justifyContent: 'center' }} accessibilityElementsHidden importantForAccessibility="no-hide-descendants">
            <Animated.View style={{ position: 'absolute', width: size, height: size, borderRadius: size, backgroundColor: color, opacity: v.interpolate({ inputRange: [0, 1], outputRange: [0.5, 0] }), transform: [{ scale: v.interpolate({ inputRange: [0, 1], outputRange: [1, 2.4] }) }] }} />
            <View style={{ width: size, height: size, borderRadius: size, backgroundColor: color }} />
        </View>
    );
}

/** Bilah yang tumbuh dari bawah. */
export function GrowBar({ height, color, width = 22, index = 0 }: { height: number; color: string; width?: number; index?: number }) {
    const v = useRef(new Animated.Value(0)).current;
    useEffect(() => {
        Animated.timing(v, { toValue: 1, duration: dur(600), delay: dur(index * 60), easing: Easing.out(Easing.cubic), useNativeDriver: false }).start();
    }, [v, index]);
    return <Animated.View style={{ width, borderRadius: 4, backgroundColor: color, height: v.interpolate({ inputRange: [0, 1], outputRange: [2, Math.max(2, height)] }) }} />;
}

/** Kerangka abu berkilau selagi data dimuat. */
export function Skeleton({ style }: { style?: StyleProp<ViewStyle> }) {
    const v = useRef(new Animated.Value(0.4)).current;
    useEffect(() => {
        if (reduceMotion) return;
        const loop = Animated.loop(Animated.sequence([Animated.timing(v, { toValue: 1, duration: 700, useNativeDriver: true }), Animated.timing(v, { toValue: 0.4, duration: 700, useNativeDriver: true })]));
        loop.start();
        return () => loop.stop();
    }, [v]);
    return <Animated.View style={[{ backgroundColor: '#DCE4EA', borderRadius: 8, opacity: v }, style]} />;
}
