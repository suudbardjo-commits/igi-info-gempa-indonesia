import { StatusBar } from 'expo-status-bar';
import { useCallback, useEffect, useRef, useState } from 'react';
import { ActivityIndicator, BackHandler, Linking, Platform, Pressable, StyleSheet, View } from 'react-native';
import { Text } from '../Text';
import { SafeAreaProvider, SafeAreaView } from 'react-native-safe-area-context';
import { WebView, WebViewNavigation } from 'react-native-webview';

// Alamat server IGI. Diisi lewat EXPO_PUBLIC_IGI_URL (lihat eas.json); tidak ada rahasia di sini, hanya alamat publik.
import { SERVER_URL } from '../config';
const NAVY = '#17334C';

// Situs lain yang boleh dibuka di dalam aplikasi (tautan berita/video sumber resmi). Selebihnya dibuka di peramban.
const INSIDE_HOSTS = ['www.youtube-nocookie.com', 'www.youtube.com'];

function hostOf(url: string): string {
    try {
        return new URL(url).host;
    } catch {
        return '';
    }
}

export default function WebScreen() {
    const web = useRef<WebView>(null);
    const [canGoBack, setCanGoBack] = useState(false);
    const [failure, setFailure] = useState<string | null>(null);
    const [loading, setLoading] = useState(true);

    const serverOk = true;
    const serverHost = hostOf(SERVER_URL);

    // Tombol kembali Android: mundur di dalam situs dulu, baru keluar aplikasi.
    useEffect(() => {
        if (Platform.OS !== 'android') return;
        const sub = BackHandler.addEventListener('hardwareBackPress', () => {
            if (canGoBack) {
                web.current?.goBack();
                return true;
            }
            return false;
        });
        return () => sub.remove();
    }, [canGoBack]);

    const retry = useCallback(() => {
        setFailure(null);
        setLoading(true);
        web.current?.reload();
    }, []);

    const shouldLoad = useCallback(
        (request: { url: string }) => {
            const url = request.url;
            if (url.startsWith('about:') || url.startsWith('blob:') || url.startsWith('data:')) return true;
            // Telepon dan surel dibuka aplikasi bawaan HP.
            if (/^(tel|mailto):/i.test(url)) {
                Linking.openURL(url).catch(() => undefined);
                return false;
            }
            const host = hostOf(url);
            // Unduhan CSV diserahkan ke peramban HP (WebView Android tidak mengelola unduhan sendiri).
            if (host === serverHost && url.includes('/api/v1/disasters-export')) {
                Linking.openURL(url).catch(() => undefined);
                return false;
            }
            if (host === serverHost || INSIDE_HOSTS.includes(host)) return true;
            if (url.startsWith('https://')) Linking.openURL(url).catch(() => undefined);
            return false;
        },
        [serverHost],
    );

    if (!serverOk) {
        return (
            <SafeAreaProvider>
                <View style={styles.center}>
                    <Text style={styles.title}>Alamat server belum diatur</Text>
                    <Text style={styles.body}>
                        Aplikasi ini memuat situs IGI dari server. Untuk build rilis, alamat harus berupa https
                        (atur EXPO_PUBLIC_IGI_URL di eas.json).
                    </Text>
                </View>
            </SafeAreaProvider>
        );
    }

    return (
        <SafeAreaProvider>
            <SafeAreaView style={styles.fill} edges={[]}>
                <StatusBar style="dark" />
                <WebView
                    ref={web}
                    source={{ uri: SERVER_URL }}
                    style={styles.fill}
                    originWhitelist={['https://*', 'http://*', 'tel:*', 'mailto:*']}
                    onShouldStartLoadWithRequest={shouldLoad}
                    onNavigationStateChange={(s: WebViewNavigation) => setCanGoBack(s.canGoBack)}
                    onLoadStart={() => setLoading(true)}
                    onLoadEnd={() => setLoading(false)}
                    onLoad={() => setFailure(null)}
                    onError={() => setFailure('Server IGI tidak dapat dijangkau.')}
                    onHttpError={(e) => {
                        if (e.nativeEvent.statusCode >= 500) setFailure(`Server IGI sedang bermasalah (${e.nativeEvent.statusCode}).`);
                    }}
                    geolocationEnabled
                    setSupportMultipleWindows={false}
                    pullToRefreshEnabled
                    allowsBackForwardNavigationGestures
                    mixedContentMode="never"
                    javaScriptEnabled
                    domStorageEnabled
                    userAgent={undefined}
                    applicationNameForUserAgent="IGI-App/1.0"
                />
                {loading && !failure && (
                    <View style={styles.loading} pointerEvents="none">
                        <ActivityIndicator color={NAVY} />
                    </View>
                )}
                {failure && (
                    <View style={[styles.center, styles.cover]}>
                        <Text style={styles.title}>Tidak bisa membuka IGI</Text>
                        <Text style={styles.body}>{failure} Periksa koneksi internet Anda, lalu coba lagi.</Text>
                        <Pressable style={styles.button} onPress={retry} accessibilityRole="button" accessibilityLabel="Coba lagi">
                            <Text style={styles.buttonText}>Coba lagi</Text>
                        </Pressable>
                        <Text style={styles.note}>Untuk keadaan darurat hubungi 112.</Text>
                    </View>
                )}
            </SafeAreaView>
        </SafeAreaProvider>
    );
}

const styles = StyleSheet.create({
    fill: { flex: 1, backgroundColor: '#FFFFFF' },
    cover: { position: 'absolute', top: 0, left: 0, right: 0, bottom: 0, backgroundColor: '#FFFFFF' },
    center: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: 28, backgroundColor: '#FFFFFF' },
    loading: { position: 'absolute', top: 12, alignSelf: 'center' },
    title: { fontSize: 20, fontWeight: '600', color: NAVY, marginBottom: 10, textAlign: 'center' },
    body: { fontSize: 15, lineHeight: 22, color: '#4A5C6A', textAlign: 'center', marginBottom: 22 },
    button: { minHeight: 48, paddingHorizontal: 28, borderRadius: 6, backgroundColor: NAVY, alignItems: 'center', justifyContent: 'center' },
    buttonText: { color: '#FFFFFF', fontSize: 16, fontWeight: '600' },
    note: { marginTop: 24, fontSize: 13, color: '#6B7B88' },
});
