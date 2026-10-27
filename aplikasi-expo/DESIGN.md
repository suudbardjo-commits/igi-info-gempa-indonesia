# DESIGN.md: arah visual aplikasi IGI

Arah ditentukan pemilik. Versi 2 (27 Okt 2026): gaya surat kabar ditolak pemilik ("kurang cocok"), diganti **mode gelap pemantau gempa**. Aturan penyaring: antislop (dipakai selama pengerjaan).

**Design Read:** aplikasi pemantau gempa untuk warga dan petugas, bergaya layar seismograf gelap, dial ENERGY 2 / RHYTHM 3 / MOTION 1.

## Suasana
Ruang pemantau: latar biru-hitam seperti layar seismograf, angka magnitudo sebagai tokoh utama, data digambar sebagai jejak (trace). Alasan: produknya memang alat memantau; gelap nyaman dipakai malam saat gempa sering dicek, dan angka berwarna terbaca tegas di atasnya.

## Warna (3 inti + 1 aksen)
- Latar `#0B1622`, permukaan `#12263A`, permukaan terangkat `#183049`, garis `#24405C` (biru laut gelap)
- Teks `#E6EEF5` (kontras 14:1), teks sekunder `#8FA6BA` (kontras 7:1 di atas latar)
- Aksen tunggal: teal `#2FD1C3`, hanya untuk tab aktif, pilihan terpilih, dan jejak seismik
- Skala magnitudo (data, bukan hiasan, versi terang untuk latar gelap): kuning `#F2C94C`, oranye `#FF9F43`, merah `#FF6B4A`, merah muda tua `#FF4D7A`

## Huruf
- Judul dan angka: Barlow Condensed (semi-tebal dan tebal). Alasan: huruf rambu jalan yang sempit dan tegas, angka besar muat dan terbaca seperti panel instrumen.
- Isi: Barlow. Alasan: satu keluarga dengan judul, jelas di ukuran kecil.

## Motif identitas
Jejak seismik: deretan garis vertikal tipis yang tingginya dari data nyata (jumlah dan kekuatan gempa per jam). Dipakai sebagai kepala halaman dan grafik. Sudut kartu 10 px (bukan pil), tanpa kaca, bayangan, atau gradasi. Satu-satunya cahaya (glow) ada pada angka magnitudo gempa terakhir.

## Gerak (MOTION 1)
Angka menghitung naik sekali, jejak tumbuh sekali, baris membuka rincian saat diketuk. Tanpa gerak berulang. Mati bila HP memakai "kurangi gerakan".

## Aturan teks
Tanpa tanda pisah panjang, tanpa emoji. Nilai kosong ditulis `-`. Angka dan klaim hanya dari data server.
