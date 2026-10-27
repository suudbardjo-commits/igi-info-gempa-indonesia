import { StyleSheet, Text as RNText, TextProps } from 'react-native';

// Barlow Condensed untuk judul dan angka (fontFamily: 'head'), Barlow untuk isi. Bobot diterjemahkan ke berkas huruf yang tepat.
const BODY: Record<string, string> = { '400': 'Barlow_400Regular', '500': 'Barlow_500Medium', '600': 'Barlow_600SemiBold', '700': 'Barlow_700Bold', '800': 'Barlow_700Bold', '900': 'Barlow_700Bold' };
const HEAD: Record<string, string> = { '400': 'BarlowCondensed_600SemiBold', '600': 'BarlowCondensed_600SemiBold', '700': 'BarlowCondensed_700Bold', '800': 'BarlowCondensed_700Bold', '900': 'BarlowCondensed_700Bold' };

export function Text({ style, ...rest }: TextProps) {
    const flat = (StyleSheet.flatten(style) ?? {}) as { fontFamily?: string; fontWeight?: string | number };
    const w = String(flat.fontWeight ?? '400');
    const family = flat.fontFamily === 'head' ? HEAD[w] ?? HEAD['700'] : BODY[w] ?? BODY['400'];
    return <RNText {...rest} style={[style, { fontFamily: family, fontWeight: 'normal' }]} />;
}
