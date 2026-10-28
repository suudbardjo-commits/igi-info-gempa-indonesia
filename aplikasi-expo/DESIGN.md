# DESIGN.md: arah visual aplikasi IGI

Arah ditentukan pemilik. Versi 3 (28 Okt 2026): tema gelap ditolak ("tidak suka yang biru tua"), diganti **tema terang bergaya aplikasi bencana BMKG**. Aturan penyaring: antislop.

**Design Read:** aplikasi informasi kebencanaan publik untuk warga segala usia, mirip InfoBMKG: latar terang, satu warna utama, dial ENERGY 1 / RHYTHM 2 / MOTION 1.

## Suasana
Resmi dan mudah dibaca di luar ruangan: pita judul biru BMKG di atas, kartu putih dengan garis tipis, angka magnitudo besar sebagai tokoh utama. Alasan: orang membuka aplikasi ini saat genting dan di bawah sinar matahari; kontras tinggi dan kebiasaan visual aplikasi BMKG mengurangi waktu mengenali informasi.

## Warna (1 utama + 2 status)
- Latar `#F2F5F9`, permukaan `#FFFFFF`, garis `#D5DEE8`
- Teks `#1B2733` (kontras 14:1), teks sekunder `#566677` (kontras 5,6:1)
- Utama: biru BMKG `#0B67B2` (tab aktif, tautan, tombol) dan `#0B5CA5` untuk pita judul
- Status: oranye `#E65100` (perhatian), merah `#C62828` (bahaya)
- Skala magnitudo (data): hijau `#2E7D32` (<4), amber `#B7791F` (4-4,5), oranye `#E65100` (4,5-5,5), merah `#D32F2F` (5,5-6,5), merah tua `#B71C1C` (>=6,5)

## Huruf
Barlow Condensed untuk judul dan angka (huruf rambu: sempit, tegas), Barlow untuk isi.

## Motif identitas
Jejak seismik (garis vertikal dari data nyata per 6 jam) pada kartu gempa terakhir. Sudut 8 px, tanpa bayangan, gradasi, kaca, atau emoji.

## Gerak (MOTION 1)
Angka menghitung naik sekali, jejak dan batang tumbuh sekali, baris membuka rincian saat diketuk. Mati bila HP memakai "kurangi gerakan".

## Aturan teks
Tanpa tanda pisah panjang. Nilai kosong ditulis `-`. Angka dan klaim hanya dari data server.

## Tata letak (v3.1, 28 Okt 2026)
Berorientasi peta: pita judul biru di atas, peta Leaflet memenuhi layar, dan lembar bawah yang bisa ditarik (Peek, Half, Full) memuat gempa terpilih, saringan, angka, peringatan, cuaca, daftar, grafik, menu, tips, berita, dan nomor penting. Bilah tab: Peta, Cuaca, Info, Petugas. Alasan: orang pertama-tama ingin tahu di mana gempa terjadi, jadi peta adalah beranda, bukan salah satu tab.
