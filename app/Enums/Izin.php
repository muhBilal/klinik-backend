<?php

namespace App\Enums;

/**
 * Daftar izin (permission) RBAC. Peran (tabel `perans`) memegang sekumpulan izin; route memakai
 * middleware `izin:pasien.kelola` dan kode memakai `$user->punyaIzin(Izin::PasienKelola)`.
 *
 * Menambah izin baru: tambah case + label() + grup(), lalu berikan ke peran lewat migration/UI.
 */
enum Izin: string
{
    case PasienLihat = 'pasien.lihat';
    case PasienKelola = 'pasien.kelola';
    case PasienHapus = 'pasien.hapus';

    case KunjunganDaftar = 'kunjungan.daftar';

    case BookingLihat = 'booking.lihat';
    case BookingKelola = 'booking.kelola';
    case JadwalKelola = 'jadwal.kelola';

    case PemeriksaanPanggil = 'pemeriksaan.panggil';
    case PemeriksaanVital = 'pemeriksaan.vital';
    case PemeriksaanDokter = 'pemeriksaan.dokter';

    case RmeLihat = 'rme.lihat';
    case RmeTindakan = 'rme.tindakan';
    case RmeTerbatas = 'rme.terbatas';
    case BerkasKelola = 'berkas.kelola';

    case FarmasiResep = 'farmasi.resep';
    case FarmasiObat = 'farmasi.obat';
    case InventoriKelola = 'inventori.kelola';

    case KasirTagihan = 'kasir.tagihan';
    case KasirVoid = 'kasir.void';
    case KasirShift = 'kasir.shift';
    case KasirDiskon = 'kasir.diskon';
    case PromoKelola = 'promo.kelola';
    case KomisiKelola = 'komisi.kelola';
    case KomisiSetujui = 'komisi.setujui';
    case LaporanKeuangan = 'laporan.keuangan';

    case MasterKelola = 'master.kelola';
    case CabangKelola = 'cabang.kelola';
    case PenggunaKelola = 'pengguna.kelola';
    case PeranKelola = 'peran.kelola';
    case PengaturanKelola = 'pengaturan.kelola';
    case AuditLihat = 'audit.lihat';
    case IntegrasiKelola = 'integrasi.kelola';

    public function label(): string
    {
        return match ($this) {
            self::PasienLihat => 'Lihat & cari data identitas pasien',
            self::PasienKelola => 'Tambah & ubah data pasien',
            self::PasienHapus => 'Hapus data pasien',
            self::KunjunganDaftar => 'Daftarkan & batalkan kunjungan',
            self::BookingLihat => 'Lihat kalender & jadwal booking',
            self::BookingKelola => 'Buat, ubah, batalkan & check-in booking',
            self::JadwalKelola => 'Kelola jadwal praktik, cuti, ruang & alat',
            self::PemeriksaanPanggil => 'Panggil pasien dari antrian',
            self::PemeriksaanVital => 'Isi tanda vital & anamnesis',
            self::PemeriksaanDokter => 'Pemeriksaan dokter: SOAP, diagnosa, tindakan, resep (tercatat sebagai dokter)',
            self::RmeLihat => 'Lihat isi rekam medis & lampiran klinis',
            self::RmeTindakan => 'Catat tindakan & sesi paket, pesan paket, catatan tindakan (area, dosis, face chart, parameter alat) & informed consent',
            self::RmeTerbatas => 'Lihat rekam medis berakses terbatas (mis. IMS) yang tidak ditangani sendiri',
            self::BerkasKelola => 'Unggah & hapus lampiran klinis',
            self::FarmasiResep => 'Proses & serahkan resep',
            self::FarmasiObat => 'Kelola obat & stok',
            self::InventoriKelola => 'Penerimaan barang, batch, stok opname & pemakaian BHP',
            self::KasirTagihan => 'Tagihan & pembayaran',
            self::KasirVoid => 'Batalkan tagihan & refund pembayaran',
            self::KasirShift => 'Buka & tutup shift kas',
            self::KasirDiskon => 'Setujui diskon di atas batas peran kasir',
            self::PromoKelola => 'Kelola voucher & kode promo',
            self::KomisiKelola => 'Atur komisi per treatment (di master treatment), hitung rekap & penyesuaian komisi',
            self::KomisiSetujui => 'Setujui & kunci rekap komisi',
            self::LaporanKeuangan => 'Lihat pendapatan & laporan keuangan',
            self::MasterKelola => 'Kelola master poli, tindakan, ICD-10, hapus obat',
            self::CabangKelola => 'Kelola cabang klinik',
            self::PenggunaKelola => 'Kelola akun pengguna',
            self::PeranKelola => 'Kelola peran & izin',
            self::PengaturanKelola => 'Ubah pengaturan klinik',
            self::AuditLihat => 'Lihat audit log',
            self::IntegrasiKelola => 'Pantau & kirim ulang integrasi SATUSEHAT / WhatsApp',
        };
    }

    public function grup(): string
    {
        return match ($this) {
            self::PasienLihat, self::PasienKelola, self::PasienHapus, self::KunjunganDaftar => 'Pasien & Pendaftaran',
            self::BookingLihat, self::BookingKelola, self::JadwalKelola => 'Booking & Jadwal',
            self::PemeriksaanPanggil, self::PemeriksaanVital, self::PemeriksaanDokter => 'Pelayanan',
            self::RmeLihat, self::RmeTindakan, self::RmeTerbatas, self::BerkasKelola => 'Rekam Medis',
            self::FarmasiResep, self::FarmasiObat, self::InventoriKelola => 'Farmasi',
            self::KasirTagihan, self::KasirVoid, self::KasirShift, self::KasirDiskon, self::PromoKelola, self::KomisiKelola, self::KomisiSetujui, self::LaporanKeuangan => 'Keuangan',
            default => 'Administrasi',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
