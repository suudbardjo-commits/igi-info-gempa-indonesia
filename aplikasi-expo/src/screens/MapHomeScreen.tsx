import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Image, Pressable, StyleSheet, View } from 'react-native';
import { Text } from '../Text';
import { lalu, waktuWib } from '../api';
import { COLORS, magnitudeColor } from '../config';
import { useDashboard } from '../data';
import QuakeMap from '../QuakeMap';
import Sheet, { SheetHandle } from '../Sheet';
import SheetBody, { Go, mag1 } from './SheetBody';

/**
 * Beranda berorientasi peta: pita judul biru di atas, peta Leaflet memenuhi layar, dan lembar bawah yang bisa ditarik
 * (Peek, Half, Full) berisi rincian, saringan, daftar, cuaca, dan menu. Memilih gempa (peta atau daftar) memusatkan peta
 * di area yang tidak tertutup lembar.
 */
export default function MapHomeScreen({ go, onServerChanged }: { go: Go; onServerChanged: () => void }) {
    const d = useDashboard();
    const sheet = useRef<SheetHandle>(null);
    const [H, setH] = useState(0);
    const [inset, setInset] = useState(168);
    const [selectedId, setSelectedId] = useState<string | null>(null);
    const [recenter, setRecenter] = useState(0);

    const sorted = useMemo(() => [...(d.events ?? [])].sort((a, b) => +new Date(b.properties.occurred_at) - +new Date(a.properties.occurred_at)), [d.events]);
    const latest = sorted[0] ?? null;
    const selected = useMemo(() => (selectedId ? sorted.find((f) => f.id === selectedId) ?? null : null), [selectedId, sorted]);
    const shown = selected ?? latest;

    // Gempa terakhir dipilih otomatis saat data pertama tiba, supaya peta langsung memusatkan dan menandai titiknya.
    useEffect(() => { if (!selectedId && latest) setSelectedId(latest.id); }, [latest, selectedId]);

    const select = useCallback((id: string) => {
        setSelectedId(id);
        // Dari Peek, naikkan ke Half agar rincian terlihat; dari Full, turunkan ke Half agar peta terlihat.
        sheet.current?.snapTo(1);
    }, []);

    const p = shown?.properties;

    return (
        <View style={st.fill}>
            <View style={st.top}>
                <View style={st.logoTile}><Image source={require('../../assets/igi-mark.png')} style={st.logo} resizeMode="contain" accessibilityLabel="Logo IGI" /></View>
                <View style={{ flex: 1 }}>
                    <Text style={st.brand}>IGI</Text>
                    <Text style={st.brandSub}>Info Gempa Indonesia</Text>
                </View>
                <Pressable onPress={d.reload} accessibilityRole="button" accessibilityLabel="Muat ulang data" disabled={d.loading} style={st.refresh}>
                    <Text style={st.refreshText}>{d.loading ? 'Memuat' : 'Muat ulang'}</Text>
                </Pressable>
            </View>

            <View style={st.stage} onLayout={(e) => setH(Math.round(e.nativeEvent.layout.height))}>
                <QuakeMap events={d.events ?? []} camps={d.camps} selectedId={selectedId} bottomInset={inset} recenterKey={recenter} onSelect={select} />

                {H > 0 && (
                    <Sheet
                        ref={sheet}
                        containerHeight={H}
                        onSettled={(v) => setInset(v)}
                        floating={(
                            <Pressable onPress={() => { if (!selectedId && latest) setSelectedId(latest.id); setRecenter((k) => k + 1); }} accessibilityRole="button" accessibilityLabel="Pusatkan peta ke gempa terpilih" style={st.fab}>
                                <View style={st.crossH} /><View style={st.crossV} /><View style={st.crossDot} />
                            </Pressable>
                        )}
                        peek={(
                            <View>
                                {!shown ? (
                                    <Text style={st.peekMuted}>{d.error ? 'Data gempa belum dapat dimuat.' : 'Memuat data gempa…'}</Text>
                                ) : (
                                    <>
                                        <Text style={st.kicker}>{selected && selected.id !== latest?.id ? 'GEMPA TERPILIH' : 'GEMPA TERAKHIR TERCATAT'}</Text>
                                        <View style={st.peekRow}>
                                            <Text style={[st.peekMag, { color: magnitudeColor(p.magnitude) }]}>{mag1(p.magnitude)}</Text>
                                            <View style={{ flex: 1 }}>
                                                <Text style={st.peekLoc} numberOfLines={2}>{p.location}</Text>
                                                <Text style={st.peekMeta} numberOfLines={1}>{waktuWib(p.occurred_at)} · {lalu(p.occurred_at)}</Text>
                                            </View>
                                        </View>
                                    </>
                                )}
                                <Text style={st.hint}>Tarik ke atas untuk rincian, daftar, dan cuaca</Text>
                            </View>
                        )}
                    >
                        <SheetBody d={d} selected={selected} onSelect={select} onClear={() => setSelectedId(latest?.id ?? null)} go={go} onServerChanged={onServerChanged} />
                    </Sheet>
                )}
            </View>
        </View>
    );
}

const st = StyleSheet.create({
    fill: { flex: 1, backgroundColor: COLORS.bg },
    top: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingHorizontal: 16, paddingTop: 8, paddingBottom: 10, backgroundColor: COLORS.header },
    logoTile: { width: 40, height: 40, borderRadius: 8, backgroundColor: '#FFFFFF', alignItems: 'center', justifyContent: 'center' },
    logo: { width: 26, height: 28 },
    brand: { fontFamily: 'head', fontSize: 26, fontWeight: '700', color: '#FFFFFF', lineHeight: 28 },
    brandSub: { fontSize: 12, color: '#D6E6F5' },
    refresh: { minHeight: 40, paddingHorizontal: 12, justifyContent: 'center', borderWidth: 1, borderColor: '#8DB9E3', borderRadius: 8 },
    refreshText: { color: '#FFFFFF', fontSize: 14, fontWeight: '600' },
    stage: { flex: 1, overflow: 'hidden' },
    kicker: { fontSize: 11, letterSpacing: 1, color: COLORS.accent, fontWeight: '700', marginBottom: 2 },
    peekRow: { flexDirection: 'row', alignItems: 'center', gap: 12 },
    peekMag: { fontFamily: 'head', fontSize: 52, fontWeight: '700', lineHeight: 54 },
    peekLoc: { fontFamily: 'head', fontSize: 20, fontWeight: '700', color: COLORS.ink, lineHeight: 23 },
    peekMeta: { fontSize: 13, color: COLORS.muted },
    peekMuted: { fontSize: 15, color: COLORS.muted, paddingVertical: 12 },
    hint: { fontSize: 12, color: COLORS.muted, marginTop: 4 },
    fab: { width: 44, height: 44, borderRadius: 8, backgroundColor: COLORS.surface, borderWidth: 1, borderColor: COLORS.edge, alignItems: 'center', justifyContent: 'center' },
    crossH: { position: 'absolute', width: 22, height: 2, backgroundColor: COLORS.accent },
    crossV: { position: 'absolute', width: 2, height: 22, backgroundColor: COLORS.accent },
    crossDot: { width: 10, height: 10, borderRadius: 5, borderWidth: 2, borderColor: COLORS.accent, backgroundColor: COLORS.surface },
});
