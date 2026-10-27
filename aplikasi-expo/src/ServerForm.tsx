import { useState } from 'react';
import { Pressable, StyleSheet, TextInput, View } from 'react-native';
import { Text } from './Text';
import { COLORS, SERVER_URL, saveServerUrl } from './config';

/** Formulir alamat server. Alamat divalidasi (https) dan diuji ke /api/v1/ping sebelum disimpan. */
export default function ServerForm({ onSaved, intro }: { onSaved: () => void; intro?: string }) {
    const [text, setText] = useState(SERVER_URL);
    const [busy, setBusy] = useState(false);
    const [msg, setMsg] = useState<string | null>(null);

    const save = async () => {
        setBusy(true);
        setMsg(null);
        const r = await saveServerUrl(text);
        setBusy(false);
        if (r.ok) onSaved();
        else setMsg(r.message ?? 'Gagal menyimpan.');
    };

    return (
        <View style={st.box}>
            {intro ? <Text style={st.intro}>{intro}</Text> : null}
            <Text style={st.label}>Alamat server IGI</Text>
            <TextInput placeholderTextColor={COLORS.muted} keyboardAppearance="dark" value={text} onChangeText={setText} autoCapitalize="none" autoCorrect={false} keyboardType="url" placeholder="https://igi.contoh.id" style={st.input} accessibilityLabel="Alamat server IGI" />
            {msg ? <Text style={st.err} accessibilityRole="alert">{msg}</Text> : null}
            <Pressable onPress={save} disabled={busy} accessibilityRole="button" style={[st.btn, busy && { opacity: 0.6 }]}>
                <Text style={st.btnText}>{busy ? 'Memeriksa…' : 'Simpan dan sambungkan'}</Text>
            </Pressable>
        </View>
    );
}

const st = StyleSheet.create({
    box: { gap: 8 },
    intro: { color: COLORS.muted, fontSize: 14, lineHeight: 21, marginBottom: 6 },
    label: { color: COLORS.ink, fontSize: 13, fontWeight: '600' },
    input: { borderWidth: 1, borderColor: COLORS.edge, borderRadius: 4, paddingHorizontal: 12, minHeight: 46, fontSize: 15, color: COLORS.ink, backgroundColor: COLORS.surface },
    err: { color: COLORS.danger, fontSize: 13 },
    btn: { minHeight: 48, borderRadius: 4, backgroundColor: COLORS.navy, alignItems: 'center', justifyContent: 'center', marginTop: 4 },
    btnText: { color: COLORS.surface, fontSize: 15, fontWeight: '600' },
});
