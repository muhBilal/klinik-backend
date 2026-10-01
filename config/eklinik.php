<?php

/*
| Konfigurasi khusus Lefaklinik.
|
| `pengaturan` = definisi pengaturan klinik yang bisa diubah admin lewat API/UI (tabel `pengaturans`).
| Nilai di sini hanya DEFAULT; nilai tersimpan menimpa default. Aturan validasi harus berupa string/array
| string (tanpa closure/objek) agar `php artisan config:cache` tetap bisa dipakai.
| `publik` = boleh dibaca tanpa login lewat `GET /api/info` (nama klinik di halaman login, kop struk).
*/

return [

    'pengaturan' => [
        'klinik.nama' => ['default' => env('APP_NAME', 'Lefaklinik'), 'rules' => ['required', 'string', 'max:100'], 'publik' => true],
        'klinik.alamat' => ['default' => null, 'rules' => ['nullable', 'string', 'max:255'], 'publik' => true],
        'klinik.telepon' => ['default' => null, 'rules' => ['nullable', 'string', 'max:30'], 'publik' => true],
        'klinik.email' => ['default' => null, 'rules' => ['nullable', 'email', 'max:100'], 'publik' => true],
        'klinik.npwp' => ['default' => null, 'rules' => ['nullable', 'string', 'max:30'], 'publik' => true],

        'struk.catatan_kaki' => ['default' => 'Terima kasih atas kunjungan Anda.', 'rules' => ['nullable', 'string', 'max:255'], 'publik' => true],
        'cetak.lebar_struk' => ['default' => '80mm', 'rules' => ['required', 'in:58mm,80mm'], 'publik' => true],

        // Prefix nomor dokumen. Counter harian tetap per jenis dokumen, jadi mengganti prefix tidak mereset urutan.
        'penomoran.prefix_registrasi' => ['default' => 'REG', 'rules' => ['required', 'regex:/^[A-Z]{2,5}$/'], 'publik' => false],
        'penomoran.prefix_resep' => ['default' => 'RSP', 'rules' => ['required', 'regex:/^[A-Z]{2,5}$/'], 'publik' => false],
        'penomoran.prefix_tagihan' => ['default' => 'INV', 'rules' => ['required', 'regex:/^[A-Z]{2,5}$/'], 'publik' => false],
        'penomoran.prefix_booking' => ['default' => 'BOK', 'rules' => ['required', 'regex:/^[A-Z]{2,5}$/'], 'publik' => false],
        'penomoran.prefix_paket' => ['default' => 'PKT', 'rules' => ['required', 'regex:/^[A-Z]{2,5}$/'], 'publik' => false],

        // Pajak layanan (persen) yang ditambahkan ke tagihan baru. Tarif di-snapshot per tagihan.
        'keuangan.pajak_persen' => ['default' => 0, 'rules' => ['required', 'integer', 'between:0,100'], 'publik' => false],
        // Batas diskon maksimum per peran, {kode_peran: persen}. Peran tanpa entri = tidak dibatasi,
        // sehingga klinik yang belum mengatur batas tetap berjalan seperti sebelumnya (BL-02 bersifat opt-in).
        'keuangan.batas_diskon_persen' => ['default' => [], 'rules' => ['present', 'array'], 'item_rules' => ['integer', 'between:0,100'], 'publik' => false],
        // Stok BHP kurang saat pemeriksaan ditutup: true = tolak, false = tetap lanjut dan pemakaian
        // ditandai belum dipotong untuk diselesaikan lewat stok opname (IN-02).
        'inventori.blokir_bhp_stok_kurang' => ['default' => false, 'rules' => ['boolean'], 'publik' => false],

        // Kebijakan paket multi-sesi (TR-02; pertanyaan terbuka PRD → diatur tiap klinik). Paket yang belum dipakai selalu bisa
        // direfund penuh lewat refund tagihan (BL-06). Sisa paket yang sudah dipakai: refund prorata hanya bila diizinkan,
        // dipotong `potongan_refund_persen`. Pengalihan sisa sesi ke pasien lain hanya bila diizinkan.
        'paket.boleh_transfer' => ['default' => false, 'rules' => ['boolean'], 'publik' => false],
        'paket.refund_sisa' => ['default' => false, 'rules' => ['boolean'], 'publik' => false],
        'paket.potongan_refund_persen' => ['default' => 0, 'rules' => ['required', 'integer', 'between:0,100'], 'publik' => false],

        // Treatment ber-template consent wajib punya informed consent yang disetujui sebelum pemeriksaan ditutup (RM-03).
        'rme.wajib_informed_consent' => ['default' => true, 'rules' => ['boolean'], 'publik' => false],

        // Foto klinis (FT-04): unggah foto klinis butuh persetujuan foto pasien yang berlaku; naskahnya bisa diubah klinik.
        // Placeholder: {nama_pasien} {no_rm} {klinik} {tanggal} {tingkat} {pilihan} (daftar tingkat dengan tanda [x]).
        'foto.wajib_consent' => ['default' => true, 'rules' => ['boolean'], 'publik' => false],
        'foto.naskah_consent' => [
            'default' => 'Saya, penanda tangan di bawah ini, atas nama pasien {nama_pasien} (No. RM {no_rm}), menyetujui {klinik} '
                ."mengambil foto klinis wajah/bagian tubuh yang dirawat untuk dokumentasi rekam medis dan evaluasi hasil perawatan.\n\n"
                .'Foto disimpan terenkripsi, hanya dapat dibuka tenaga kesehatan yang berwenang, setiap aksesnya tercatat, dan foto '
                ."tidak disimpan di perangkat pribadi staf.\n\nPenggunaan foto yang saya izinkan:\n{pilihan}\n\n"
                .'Saya dapat mengubah atau mencabut persetujuan ini kapan saja. Pencabutan tidak menghapus foto yang sudah menjadi '
                .'bagian rekam medis, tetapi foto tidak lagi dipakai di luar keperluan klinis dan foto baru tidak akan diambil.'
                ."\n\n{tanggal}",
            'rules' => ['required', 'string', 'max:10000'],
            'publik' => false,
        ],

        // Jam operasional klinik (AD-04); dipakai sebagai batas wajar jadwal praktik & booking.
        'klinik.jam_buka' => ['default' => '08:00', 'rules' => ['required', 'date_format:H:i'], 'publik' => true],
        'klinik.jam_tutup' => ['default' => '21:00', 'rules' => ['required', 'date_format:H:i'], 'publik' => true],

        // Sesi berakhir bila token tidak dipakai selama N menit (PRD 7.2: 15 menit di perangkat bersama).
        'keamanan.idle_timeout_menit' => ['default' => 15, 'rules' => ['required', 'integer', 'between:5,480'], 'publik' => false],
        // Kode peran yang wajib mengaktifkan 2FA sebelum bisa memakai aplikasi (mis. ["admin", "dokter"]).
        'keamanan.wajib_2fa' => ['default' => [], 'rules' => ['present', 'array'], 'item_rules' => ['string', 'distinct', 'exists:perans,kode'], 'publik' => false],
    ],

    'berkas' => [
        // Ukuran maksimum unggahan (KB). Nginx & PHP di image Docker dibatasi 20 MB.
        'maks_kb' => (int) env('BERKAS_MAKS_KB', 10240),
        'mimes' => ['jpg', 'jpeg', 'png', 'webp', 'pdf'],
        // Masa berlaku tautan unduh bertanda tangan (menit).
        'tautan_menit' => (int) env('BERKAS_TAUTAN_MENIT', 5),
    ],

    'two_factor' => [
        // Nama penerbit yang tampil di aplikasi authenticator.
        'issuer' => env('TWO_FACTOR_ISSUER', env('APP_NAME', 'Lefaklinik')),
    ],

];
