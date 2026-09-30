<?php

use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BerkasController;
use App\Http\Controllers\Api\CabangController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\Icd10Controller;
use App\Http\Controllers\Api\KategoriTindakanController;
use App\Http\Controllers\Api\KunjunganController;
use App\Http\Controllers\Api\ObatController;
use App\Http\Controllers\Api\PasienController;
use App\Http\Controllers\Api\PemeriksaanController;
use App\Http\Controllers\Api\PengaturanController;
use App\Http\Controllers\Api\PeranController;
use App\Http\Controllers\Api\PoliController;
use App\Http\Controllers\Api\ProfilController;
use App\Http\Controllers\Api\ResepController;
use App\Http\Controllers\Api\TagihanController;
use App\Http\Controllers\Api\TindakanController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

/*
| Hak akses memakai izin RBAC (App\Enums\Izin), bukan kode peran:
|   ->middleware('izin:pasien.kelola')        satu izin
|   ->middleware('izin:pemeriksaan.vital,x')  salah satu dari beberapa izin
| Peran berakses penuh (administrator) selalu lolos. Peta peran bawaan -> izin: migration 2026_09_30_100002.
|
| Middleware grup terautentikasi:
|   cabang   = tentukan cabang aktif (header X-Cabang-Id untuk user lintas cabang)
|   wajib2fa = peran di pengaturan keamanan.wajib_2fa harus mengaktifkan 2FA dulu
*/

// Publik
Route::get('info', [PengaturanController::class, 'info']);
Route::post('login', [AuthController::class, 'login'])->middleware('throttle:10,1');
Route::post('login/2fa', [AuthController::class, 'login2fa'])->middleware('throttle:6,1');
Route::get('berkas/{berkas}/unduh', [BerkasController::class, 'unduh'])->name('berkas.unduh')->middleware('signed');

Route::middleware(['auth:sanctum', 'cabang'])->group(function () {
    // Profil & keamanan akun (tetap bisa diakses user yang wajib 2FA tetapi belum mengaktifkannya)
    Route::get('me', [AuthController::class, 'me']);
    Route::post('logout', [AuthController::class, 'logout']);
    Route::put('me/password', [ProfilController::class, 'ubahPassword']);
    Route::post('me/2fa', [ProfilController::class, 'mulai2fa']);
    Route::post('me/2fa/konfirmasi', [ProfilController::class, 'konfirmasi2fa']);
    Route::post('me/2fa/kode-pemulihan', [ProfilController::class, 'kodePemulihanBaru']);
    Route::delete('me/2fa', [ProfilController::class, 'nonaktifkan2fa']);

    Route::middleware('wajib2fa')->group(function () {
        Route::get('dashboard', DashboardController::class);

        // Data referensi (read-only untuk semua pengguna)
        Route::get('cabangs', [CabangController::class, 'index']);
        Route::get('polis', [PoliController::class, 'index']);
        Route::get('polis/{poli}', [PoliController::class, 'show']);
        Route::get('dokters', [UserController::class, 'dokter']);
        Route::get('icd10s', [Icd10Controller::class, 'index']);
        Route::get('tindakans', [TindakanController::class, 'index']);
        Route::get('kategori-tindakans', [KategoriTindakanController::class, 'index']);
        Route::get('obats', [ObatController::class, 'index']);
        Route::get('obats/{obat}', [ObatController::class, 'show']);

        // Pasien (master pusat, lintas cabang)
        Route::middleware('izin:pasien.lihat')->group(function () {
            Route::get('pasiens', [PasienController::class, 'index']);
            Route::get('pasiens/{pasien}', [PasienController::class, 'show']);
        });
        Route::get('pasiens/{pasien}/riwayat', [KunjunganController::class, 'riwayat'])->middleware('izin:rme.lihat');
        Route::middleware('izin:pasien.kelola')->group(function () {
            Route::post('pasiens', [PasienController::class, 'store']);
            Route::put('pasiens/{pasien}', [PasienController::class, 'update']);
        });
        Route::delete('pasiens/{pasien}', [PasienController::class, 'destroy'])->middleware('izin:pasien.hapus');

        // Kunjungan & antrian (cabang aktif). Detail tanpa izin rme.lihat hanya berisi data administrasi.
        Route::get('kunjungans', [KunjunganController::class, 'index']);
        Route::get('kunjungans/{kunjungan}', [KunjunganController::class, 'show'])->whereNumber('kunjungan');
        Route::middleware('izin:kunjungan.daftar')->group(function () {
            Route::post('kunjungans', [KunjunganController::class, 'store']);
            Route::post('kunjungans/{kunjungan}/batal', [KunjunganController::class, 'batal']);
        });

        // Pemeriksaan
        Route::post('kunjungans/{kunjungan}/panggil', [KunjunganController::class, 'panggil'])->middleware('izin:pemeriksaan.panggil');
        Route::put('kunjungans/{kunjungan}/pemeriksaan', [PemeriksaanController::class, 'update'])->middleware('izin:pemeriksaan.vital,pemeriksaan.dokter');
        Route::post('kunjungans/{kunjungan}/selesai', [PemeriksaanController::class, 'selesai'])->middleware('izin:pemeriksaan.dokter');

        // Lampiran klinis terenkripsi
        Route::middleware('izin:rme.lihat')->group(function () {
            Route::get('berkas', [BerkasController::class, 'index']);
            Route::get('berkas/{berkas}/tautan', [BerkasController::class, 'tautan']);
        });
        Route::middleware('izin:berkas.kelola')->group(function () {
            Route::post('berkas', [BerkasController::class, 'store']);
            Route::delete('berkas/{berkas}', [BerkasController::class, 'destroy']);
        });

        // Farmasi
        Route::middleware('izin:farmasi.resep')->group(function () {
            Route::get('reseps', [ResepController::class, 'index']);
            Route::get('reseps/{resep}', [ResepController::class, 'show']);
            Route::post('reseps/{resep}/serahkan', [ResepController::class, 'serahkan']);
        });
        Route::middleware('izin:farmasi.obat')->group(function () {
            Route::post('obats', [ObatController::class, 'store']);
            Route::put('obats/{obat}', [ObatController::class, 'update']);
            Route::get('obats/{obat}/mutasi', [ObatController::class, 'mutasi']);
            Route::post('obats/{obat}/mutasi', [ObatController::class, 'storeMutasi']);
        });

        // Kasir
        Route::middleware('izin:kasir.tagihan')->group(function () {
            Route::get('tagihans', [TagihanController::class, 'index']);
            Route::get('tagihans/{tagihan}', [TagihanController::class, 'show']);
            Route::post('tagihans/{tagihan}/bayar', [TagihanController::class, 'bayar']);
        });

        // Master data
        Route::middleware('izin:master.kelola')->group(function () {
            Route::delete('obats/{obat}', [ObatController::class, 'destroy']);

            Route::post('polis', [PoliController::class, 'store']);
            Route::put('polis/{poli}', [PoliController::class, 'update']);
            Route::delete('polis/{poli}', [PoliController::class, 'destroy']);

            Route::apiResource('tindakans', TindakanController::class)->except('index');
            Route::post('kategori-tindakans', [KategoriTindakanController::class, 'store']);
            Route::put('kategori-tindakans/{kategori}', [KategoriTindakanController::class, 'update']);
            Route::delete('kategori-tindakans/{kategori}', [KategoriTindakanController::class, 'destroy']);
            Route::apiResource('icd10s', Icd10Controller::class)->except('index');
        });

        // Administrasi
        Route::middleware('izin:cabang.kelola')->group(function () {
            Route::post('cabangs', [CabangController::class, 'store']);
            Route::get('cabangs/{cabang}', [CabangController::class, 'show']);
            Route::put('cabangs/{cabang}', [CabangController::class, 'update']);
            Route::delete('cabangs/{cabang}', [CabangController::class, 'destroy']);
        });
        Route::apiResource('users', UserController::class)->middleware('izin:pengguna.kelola');
        Route::middleware('izin:peran.kelola,pengguna.kelola')->group(function () {
            Route::get('perans', [PeranController::class, 'index']);
            Route::get('izins', [PeranController::class, 'izin']);
        });
        Route::middleware('izin:peran.kelola')->group(function () {
            Route::post('perans', [PeranController::class, 'store']);
            Route::put('perans/{peran}', [PeranController::class, 'update']);
            Route::delete('perans/{peran}', [PeranController::class, 'destroy']);
        });
        Route::middleware('izin:pengaturan.kelola')->group(function () {
            Route::get('pengaturan', [PengaturanController::class, 'index']);
            Route::put('pengaturan', [PengaturanController::class, 'update']);
        });
        Route::middleware('izin:audit.lihat')->group(function () {
            Route::get('audit-logs', [AuditLogController::class, 'index']);
            Route::get('audit-logs/{auditLog}', [AuditLogController::class, 'show']);
        });
    });
});
