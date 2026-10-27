import { SOURCE_LABEL, formatMagnitude, hasTsunamiPotential } from './quake';

/** Klasifikasi kekuatan yang lazim dipakai untuk menjelaskan magnitudo kepada awam. */
export function strengthLabel(mag) {
    const m = mag ?? 0;

    if (m < 3) return 'Gempa mikro';
    if (m < 4) return 'Gempa kecil';
    if (m < 5) return 'Gempa ringan';
    if (m < 6) return 'Gempa sedang';
    if (m < 7) return 'Gempa kuat';
    if (m < 8) return 'Gempa besar';

    return 'Gempa sangat besar';
}

/** Judul buletin dibentuk dari data yang tercatat; tidak ada klaim di luar angka sumber. */
export function headline(props) {
    return `${strengthLabel(props.magnitude)} M ${formatMagnitude(props.magnitude)} di ${props.location}`;
}

const dateFmt = new Intl.DateTimeFormat('id-ID', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
    timeZone: 'Asia/Jakarta',
});

export function formatFullWib(iso) {
    return `${dateFmt.format(new Date(iso)).replace('.', ':')} WIB`;
}

/** Satu-dua kalimat ringkas untuk kartu berita. */
export function summary(props) {
    const depth = props.depth_km != null ? `, kedalaman ${Math.round(props.depth_km)} km` : '';
    const tsunami = hasTsunamiPotential(props.tsunami_potential)
        ? ' Sumber menyebut berpotensi tsunami; ikuti arahan resmi BMKG dan BPBD.'
        : props.tsunami_potential
          ? ' Tidak berpotensi tsunami menurut sumber.'
          : '';

    return `Tercatat ${formatFullWib(props.occurred_at)}${depth}. Dilaporkan oleh ${SOURCE_LABEL[props.source] ?? props.source}.${tsunami}`;
}

export const LEVEL = {
    info: { label: 'Info', box: 'border-tsunami bg-tsunami/5', chip: 'bg-tsunami text-white' },
    penting: { label: 'Penting', box: 'border-quake-2 bg-quake-2/10', chip: 'bg-quake-2 text-ink' },
    darurat: { label: 'Darurat', box: 'border-quake-4 bg-quake-4/10', chip: 'bg-quake-4 text-white' },
};
