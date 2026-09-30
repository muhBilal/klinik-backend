# F0-03 — Audit Log & Soft Delete

**PRD:** AD-03 (audit log akses & perubahan RME dan transaksi keuangan), 7.1 (Permenkes 24/2022: RME tidak dihapus, koreksi
dengan jejak; UU PDP: jejak akses data pasien), 7.2 Audit · **Fase:** 0 · **Status:** selesai

## Tabel `audit_logs` (append-only)

| Kolom | Isi |
|-------|-----|
| `user_id` | pelaku (null = sistem/seeder, atau login gagal dengan email tak dikenal) |
| `cabang_id` | cabang data yang diubah, atau cabang aktif untuk aksi akses |
| `aksi` | lihat tabel aksi di bawah |
| `tipe`, `subjek_id` | jenis data (`Str::snake(class_basename)`, mis. `pemeriksaan_diagnosa`) + id |
| `pasien_id` | pasien pemilik data → jejak "siapa mengakses/mengubah data pasien X" |
| `label` | teks ringkas (No. RM · nama, no. registrasi, email, ...) |
| `perubahan` | JSON `{ kolom: { lama, baru } }` |
| `ip_address`, `user_agent`, `created_at` | konteks request |

Tanpa foreign key (baris audit tidak ikut berubah/terhapus). Model `AuditLog` melempar `LogicException` saat `updating`/`deleting`;
tidak ada endpoint ubah/hapus. Retensi mengikuti masa simpan RME (≥ 25 tahun) — jangan membuat job pembersihan audit.

## Aksi yang tercatat

| Aksi | Sumber |
|------|--------|
| `buat`, `ubah`, `hapus`, `pulihkan` | otomatis dari trait `Auditable` (event model) |
| `lihat` | buka detail kunjungan dengan rekam medis, detail pasien, riwayat rekam medis pasien |
| `akses_berkas`, `unduh_berkas` | minta tautan berkas, unduh isi berkas |
| `login`, `login_gagal`, `logout` | AuthController (login gagal juga untuk kode 2FA salah) |
| `2fa_aktif`, `2fa_nonaktif`, `2fa_kode_baru`, `2fa_kode_pemulihan`, `ubah_password` | ProfilController / TwoFactorService |
| `ubah_izin` | `Peran::aturIzin` (daftar izin lama & baru) |
| `ubah` tipe `pengaturan` | `PengaturanService::simpan` (kunci yang berubah) |

## Trait `Auditable`

```php
use App\Models\Concerns\Auditable;

class Pasien extends Model
{
    use Auditable;

    public function auditLabel(): ?string { return "{$this->no_rm} · {$this->nama}"; }
    public function auditPasienId(): ?int { return $this->id; }
    public function auditAbaikan(): array { return []; }   // kolom tambahan yang tidak dicatat
}
```

- `buat`: nilai baru (kolom non-null). `ubah`: hanya kolom yang berubah; bila hanya kolom yang diabaikan berubah → tidak dicatat.
  `hapus` permanen: salinan nilai terakhir; soft delete: tanpa rincian (data masih ada).
- Selalu diabaikan: `created_at`, `updated_at`, `deleted_at`, `password`, `remember_token`, `two_factor_*`.
  `Obat` mengabaikan `stok` (sudah di kartu stok); `Berkas` mengabaikan `path`, `checksum`.
- `pasien_id` model turunan kunjungan memakai `AuditService::pasienDariKunjungan()` (cache per request).
- **Hanya event model yang tercatat.** Query massal (`->where()->update()`, `$relasi()->delete()`) tidak memicu event —
  karena itu `PemeriksaanService` menghapus diagnosa/tindakan/item resep lama dengan `->get()->each->delete()` dan
  `TagihanService::bayar` memakai `$tagihan->kunjungan->update(...)`.

Model yang diaudit: Cabang, Peran, User, Poli, Pasien, Icd10, Tindakan, Obat, Kunjungan, Pemeriksaan, PemeriksaanDiagnosa,
KunjunganTindakan, Resep, ResepItem, Tagihan, TagihanItem, Berkas.

## Mencatat akses manual

```php
app(AuditService::class)->catat('lihat', 'kunjungan', $kunjungan->id, [
    'pasien_id' => $kunjungan->pasien_id,
    'label' => "Rekam medis {$kunjungan->no_registrasi}",
]);
```
Opsi: `user_id` (default user login), `cabang_id` (default cabang aktif), `pasien_id`, `label`, `perubahan`.

## Soft delete

Migration `2026_09_30_100005`: `deleted_at` pada `pasiens`, `users`, `obats`, `tindakans`, `polis` (+ `cabangs`, `berkas` sejak dibuat).
Semua endpoint DELETE master kini soft delete; aturan "tidak boleh dihapus bila sudah dipakai" tetap berlaku. Relasi ke pengguna/
pasien/poli/obat/tindakan memakai `withTrashed()` agar riwayat tetap terbaca. Belum ada endpoint pulihkan (restore) — bila dibutuhkan,
`restore()` otomatis tercatat `pulihkan`.

## API

`GET /audit-logs` (filter `aksi`, `tipe`, `subjek_id`, `user_id`, `pasien_id`, `cabang_id`, `dari`, `sampai`, `q`),
`GET /audit-logs/{id}` (dengan `perubahan`). Izin `audit.lihat` (admin, manajer bawaan).

## Frontend

- `views/admin/AuditLogView.vue`: filter aksi/data/tanggal/label, badge warna per aksi, modal detail (tabel kolom sebelum/sesudah).
- `PasienDetail` → tombol **Jejak Akses** (`/admin/audit?pasien_id=`) untuk pemegang `audit.lihat` — mendukung permintaan
  pasien atas riwayat akses datanya (UU PDP).

## Test

`tests/Feature/AuditLogTest.php` — nilai lama/baru, tidak ada baris untuk simpan tanpa perubahan, akses RME dicatat hanya untuk
pemegang `rme.lihat`, penggantian diagnosa tercatat `hapus`, login/login gagal, password tidak pernah tercatat, audit tidak bisa
diubah/dihapus, endpoint dibatasi izin, soft delete pasien.
