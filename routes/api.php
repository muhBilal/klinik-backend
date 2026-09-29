<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\Icd10Controller;
use App\Http\Controllers\Api\KunjunganController;
use App\Http\Controllers\Api\ObatController;
use App\Http\Controllers\Api\PasienController;
use App\Http\Controllers\Api\PemeriksaanController;
use App\Http\Controllers\Api\PoliController;
use App\Http\Controllers\Api\ResepController;
use App\Http\Controllers\Api\TagihanController;
use App\Http\Controllers\Api\TindakanController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

/*
| Hak akses per role (admin selalu diizinkan, lihat EnsureRole):
| pendaftaran : pasien, pendaftaran kunjungan
| perawat     : tanda vital & anamnesis
| dokter      : pemeriksaan SOAP, diagnosa, tindakan, resep
| apoteker    : resep & stok obat
| kasir       : tagihan & pembayaran
*/

Route::post('login', [AuthController::class, 'login'])->middleware('throttle:10,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('me', [AuthController::class, 'me']);
    Route::post('logout', [AuthController::class, 'logout']);
    Route::get('dashboard', DashboardController::class);

    // Data referensi (read-only untuk semua role)
    Route::get('polis', [PoliController::class, 'index']);
    Route::get('polis/{poli}', [PoliController::class, 'show']);
    Route::get('dokters', [UserController::class, 'dokter']);
    Route::get('icd10s', [Icd10Controller::class, 'index']);
    Route::get('tindakans', [TindakanController::class, 'index']);
    Route::get('obats', [ObatController::class, 'index']);
    Route::get('obats/{obat}', [ObatController::class, 'show']);

    // Pasien
    Route::get('pasiens', [PasienController::class, 'index']);
    Route::get('pasiens/{pasien}', [PasienController::class, 'show']);
    Route::get('pasiens/{pasien}/riwayat', [KunjunganController::class, 'riwayat'])->middleware('role:dokter,perawat');
    Route::middleware('role:pendaftaran')->group(function () {
        Route::post('pasiens', [PasienController::class, 'store']);
        Route::put('pasiens/{pasien}', [PasienController::class, 'update']);
    });

    // Kunjungan & antrian
    Route::get('kunjungans', [KunjunganController::class, 'index']);
    Route::get('kunjungans/{kunjungan}', [KunjunganController::class, 'show']);
    Route::middleware('role:pendaftaran')->group(function () {
        Route::post('kunjungans', [KunjunganController::class, 'store']);
        Route::post('kunjungans/{kunjungan}/batal', [KunjunganController::class, 'batal']);
    });

    // Pemeriksaan
    Route::middleware('role:dokter,perawat')->group(function () {
        Route::post('kunjungans/{kunjungan}/panggil', [KunjunganController::class, 'panggil']);
        Route::put('kunjungans/{kunjungan}/pemeriksaan', [PemeriksaanController::class, 'update']);
    });
    Route::post('kunjungans/{kunjungan}/selesai', [PemeriksaanController::class, 'selesai'])->middleware('role:dokter');

    // Farmasi
    Route::middleware('role:apoteker')->group(function () {
        Route::get('reseps', [ResepController::class, 'index']);
        Route::get('reseps/{resep}', [ResepController::class, 'show']);
        Route::post('reseps/{resep}/serahkan', [ResepController::class, 'serahkan']);

        Route::post('obats', [ObatController::class, 'store']);
        Route::put('obats/{obat}', [ObatController::class, 'update']);
        Route::get('obats/{obat}/mutasi', [ObatController::class, 'mutasi']);
        Route::post('obats/{obat}/mutasi', [ObatController::class, 'storeMutasi']);
    });

    // Kasir
    Route::middleware('role:kasir')->group(function () {
        Route::get('tagihans', [TagihanController::class, 'index']);
        Route::get('tagihans/{tagihan}', [TagihanController::class, 'show']);
        Route::post('tagihans/{tagihan}/bayar', [TagihanController::class, 'bayar']);
    });

    // Administrasi
    Route::middleware('role:admin')->group(function () {
        Route::delete('pasiens/{pasien}', [PasienController::class, 'destroy']);
        Route::delete('obats/{obat}', [ObatController::class, 'destroy']);

        Route::post('polis', [PoliController::class, 'store']);
        Route::put('polis/{poli}', [PoliController::class, 'update']);
        Route::delete('polis/{poli}', [PoliController::class, 'destroy']);

        Route::apiResource('tindakans', TindakanController::class)->except('index');
        Route::apiResource('icd10s', Icd10Controller::class)->except('index');
        Route::apiResource('users', UserController::class);
    });
});
