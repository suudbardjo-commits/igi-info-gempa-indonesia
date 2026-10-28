import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import * as Location from 'expo-location';
import { Platform, StyleSheet, View } from 'react-native';
import { WebView } from 'react-native-webview';
import { SERVER_URL } from './config';
import { MAP_HTML } from './mapHtml';
import type { Feature } from './data';


type Props = { events: Feature[]; camps: Feature[]; selectedId: string | null; bottomInset: number; recenterKey: number; onSelect: (id: string) => void };

/** Peta penuh. Mengirim data ke peta bila siap, memusatkan pada gempa terpilih di area yang tidak tertutup sheet. */
export default function QuakeMap({ events, camps, selectedId, bottomInset, recenterKey, onSelect }: Props) {
    const web = useRef<WebView>(null);
    const frame = useRef<any>(null);
    const [ready, setReady] = useState(false);
    const insetRef = useRef(bottomInset);
    insetRef.current = bottomInset;
    const isWeb = Platform.OS === 'web';

    const post = useCallback((msg: object) => {
        const text = JSON.stringify(msg);
        if (isWeb) frame.current?.contentWindow?.postMessage(text, '*');
        else web.current?.postMessage(text);
    }, [isWeb]);

    const handle = useCallback(async (raw: string) => {
        let m: any;
        try { m = JSON.parse(raw); } catch { return; }
        if (m.type === 'ready') setReady(true);
        else if (m.type === 'event') onSelect(m.id);
        else if (m.type === 'locate') {
            // Lokasi hanya diminta saat tombol ditekan; hanya dikirim ke peta, tidak disimpan.
            try {
                const perm = await Location.requestForegroundPermissionsAsync();
                if (perm.status !== 'granted') { post({ type: 'me-error', message: 'Izin lokasi ditolak. Aktifkan izin lokasi lalu coba lagi.' }); return; }
                const pos = await Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.Balanced });
                post({ type: 'me', lat: pos.coords.latitude, lng: pos.coords.longitude, acc: pos.coords.accuracy ?? 0, bottom: insetRef.current });
            } catch {
                post({ type: 'me-error', message: 'Lokasi perangkat tidak dapat ditentukan.' });
            }
        }
        else if (m.type === 'need-faults') {
            try {
                const r = await fetch(`${SERVER_URL}/data/sesar-indonesia.geojson`);
                if (!r.ok) throw new Error('http');
                post({ type: 'faults', data: await r.json() });
            } catch {
                post({ type: 'faults-error' });
            }
        }
    }, [onSelect, post]);

    // Pratinjau web: pesan dari <iframe> milik komponen ini saja.
    useEffect(() => {
        if (!isWeb) return;
        const fn = (e: MessageEvent) => { if (e.source === frame.current?.contentWindow && typeof e.data === 'string') handle(e.data); };
        window.addEventListener('message', fn);
        return () => window.removeEventListener('message', fn);
    }, [isWeb, handle]);

    useEffect(() => { if (ready) post({ type: 'data', events, camps }); }, [ready, events, camps, post]);
    useEffect(() => { if (ready && selectedId) post({ type: 'select', id: selectedId, bottom: bottomInset, mag: events.find((f) => f.id === selectedId)?.properties.magnitude ?? 0 }); }, [ready, selectedId, bottomInset, recenterKey, post, events]);

    const html = useMemo(() => MAP_HTML, []);

    return (
        <View style={st.fill}>
            {isWeb ? (
                // eslint-disable-next-line @typescript-eslint/no-explicit-any
                (require('react') as any).createElement('iframe', { ref: frame, srcDoc: html, title: 'Peta gempa', style: { border: 0, width: '100%', height: '100%' } })
            ) : (
                <WebView
                    ref={web}
                    originWhitelist={['*']}
                    source={{ html, baseUrl: 'https://localhost' }}
                    style={st.fill}
                    onMessage={(e) => handle(e.nativeEvent.data)}
                    javaScriptEnabled
                    domStorageEnabled
                    setSupportMultipleWindows={false}
                />
            )}
        </View>
    );
}

const st = StyleSheet.create({ fill: { flex: 1, backgroundColor: '#dfe6ee' } });
