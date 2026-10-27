/** Jarak garis lurus di permukaan bumi (haversine), dalam km. Titik berbentuk { lat, lng }. */
export function haversineKm(a, b) {
    const R = 6371;
    const rad = (d) => (d * Math.PI) / 180;
    const dLat = rad(b.lat - a.lat);
    const dLng = rad(b.lng - a.lng);
    const h = Math.sin(dLat / 2) ** 2 + Math.cos(rad(a.lat)) * Math.cos(rad(b.lat)) * Math.sin(dLng / 2) ** 2;

    return 2 * R * Math.asin(Math.sqrt(h));
}

/** Titik { lat, lng } dari fitur GeoJSON (koordinat GeoJSON berurutan [lng, lat]). */
export function pointOf(feature) {
    const [lng, lat] = feature.geometry.coordinates;

    return { lat, lng };
}
