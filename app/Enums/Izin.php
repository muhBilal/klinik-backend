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

    case PemeriksaanPanggil = 'pemeriksaan.panggil';
    case PemeriksaanVital = 'pemeriksaan.vital';
    case PemeriksaanDokter = 'pemeriksaan.dokter';

    case RmeLihat = 'rme.lihat';
    case BerkasKelola = 'berkas.kelola';

    case FarmasiResep = 'farmasi.resep';
    case FarmasiObat = 'farmasi.obat';

    case KasirTagihan = 'kasir.tagihan';
    case LaporanKeuangan = 'laporan.keuangan';

    case MasterKelola = 'master.kelola';
    case CabangKelola = 'cabang.kelola';
    case PenggunaKelola = 'pengguna.kelola';
    case PeranKelola = 'peran.kelola';
    case PengaturanKelola = 'pengaturan.kelola';
    case AuditLihat = 'audit.lihat';

    public function label(): string
    {
        return match ($this) {
            self::PasienLihat => 'Lihat & cari data identitas pasien',
            self::PasienKelola => 'Tambah & ubah data pasien',
            self::PasienHapus => 'Hapus data pasien',
            self::KunjunganDaftar => 'Daftarkan & batalkan kunjungan',
            self::PemeriksaanPanggil => 'Panggil pasien dari antrian',
            self::PemeriksaanVital => 'Isi tanda vital & anamnesis',
            self::PemeriksaanDokter => 'Pemeriksaan dokter: SOAP, diagnosa, tindakan, resep (tercatat sebagai dokter)',
            self::RmeLihat => 'Lihat isi rekam medis & lampiran klinis',
            self::BerkasKelola => 'Unggah & hapus lampiran klinis',
            self::FarmasiResep => 'Proses & serahkan resep',
            self::FarmasiObat => 'Kelola obat & stok',
            self::KasirTagihan => 'Tagihan & pembayaran',
            self::LaporanKeuangan => 'Lihat pendapatan & laporan keuangan',
            self::MasterKelola => 'Kelola master poli, tindakan, ICD-10, hapus obat',
            self::CabangKelola => 'Kelola cabang klinik',
            self::PenggunaKelola => 'Kelola akun pengguna',
            self::PeranKelola => 'Kelola peran & izin',
            self::PengaturanKelola => 'Ubah pengaturan klinik',
            self::AuditLihat => 'Lihat audit log',
        };
    }

    public function grup(): string
    {
        return match ($this) {
            self::PasienLihat, self::PasienKelola, self::PasienHapus, self::KunjunganDaftar => 'Pasien & Pendaftaran',
            self::PemeriksaanPanggil, self::PemeriksaanVital, self::PemeriksaanDokter => 'Pelayanan',
            self::RmeLihat, self::BerkasKelola => 'Rekam Medis',
            self::FarmasiResep, self::FarmasiObat => 'Farmasi',
            self::KasirTagihan, self::LaporanKeuangan => 'Keuangan',
            default => 'Administrasi',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
