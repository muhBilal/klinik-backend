<?php

use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\AturanKomisiController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BerkasController;
use App\Http\Controllers\Api\BhpController;
use App\Http\Controllers\Api\CabangController;
use App\Http\Controllers\Api\CatatanTindakanController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\Icd10Controller;
use App\Http\Controllers\Api\Icd9cmController;
use App\Http\Controllers\Api\ImporMasterController;
use App\Http\Controllers\Api\InformedConsentController;
use App\Http\Controllers\Api\JadwalController;
use App\Http\Controllers\Api\KategoriTindakanController;
use App\Http\Controllers\Api\KodeFavoritController;
use App\Http\Controllers\Api\KomisiController;
use App\Http\Controllers\Api\KunjunganController;
use App\Http\Controllers\Api\LaporanController;
use App\Http\Controllers\Api\ObatController;
use App\Http\Controllers\Api\OdontogramController;
use App\Http\Controllers\Api\PaketController;
use App\Http\Controllers\Api\PaketPasienController;
use App\Http\Controllers\Api\PasienController;
use App\Http\Controllers\Api\PemeriksaanController;
use App\Http\Controllers\Api\PengaturanController;
use App\Http\Controllers\Api\PeranController;
use App\Http\Controllers\Api\PersetujuanDataController;
use App\Http\Controllers\Api\PersetujuanFotoController;
use App\Http\Controllers\Api\PoliController;
use App\Http\Controllers\Api\ProfilController;
use App\Http\Controllers\Api\ProfilKlinisController;
use App\Http\Controllers\Api\PromoController;
use App\Http\Controllers\Api\ProtokolFotoController;
use App\Http\Controllers\Api\RencanaPerawatanController;
use App\Http\Controllers\Api\ResepController;
use App\Http\Controllers\Api\SatuSehatController;
use App\Http\Controllers\Api\ShiftKasController;
use App\Http\Controllers\Api\SistemController;
use App\Http\Controllers\Api\StokBatchController;
use App\Http\Controllers\Api\SumberDayaController;
use App\Http\Controllers\Api\TagihanController;
use App\Http\Controllers\Api\TemplateConsentController;
use App\Http\Controllers\Api\TemplateSoapController;
use App\Http\Controllers\Api\TindakanController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WebhookWhatsAppController;
use App\Http\Controllers\Api\WhatsAppController;
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
// Webhook WhatsApp Cloud API (BK-06, CR-01): verifikasi langganan & status/balasan bertanda tangan HMAC
Route::get('webhook/whatsapp', [WebhookWhatsAppController::class, 'verifikasi'])->middleware('throttle:30,1');
Route::post('webhook/whatsapp', [WebhookWhatsAppController::class, 'terima'])->middleware('throttle:600,1');
Route::get('berkas/{berkas}/unduh', [BerkasController::class, 'unduh'])->name('berkas.unduh')->middleware('signed');

Route::middleware(['auth:sanctum', 'cabang'])->group(function () {
    // Profil & keamanan akun (tetap bisa diakses user yang wajib 2FA tetapi belum mengaktifkannya)
    Route::get('me', [AuthController::class, 'me']);
    Route::patch('me', [AuthController::class, 'updateProfile']);
    Route::put('me/theme', [AuthController::class, 'updateTheme']);
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
        Route::get('petugas', [UserController::class, 'petugas']);
        Route::get('icd10s', [Icd10Controller::class, 'index']);
        Route::get('icd9cms', [Icd9cmController::class, 'index']);
        Route::get('template-soaps', [TemplateSoapController::class, 'index']);
        Route::get('protokol-fotos', [ProtokolFotoController::class, 'index']);
        Route::get('tindakans', [TindakanController::class, 'index']);
        Route::get('kategori-tindakans', [KategoriTindakanController::class, 'index']);
        Route::get('pakets', [PaketController::class, 'index']);
        Route::get('obats', [ObatController::class, 'index']);
        Route::get('obats/{obat}', [ObatController::class, 'show']);

        // Pasien (master pusat, lintas cabang)
        Route::middleware('izin:pasien.lihat')->group(function () {
            Route::get('pasiens', [PasienController::class, 'index']);
            // Kandidat pasien ganda sebelum pasien baru disimpan (PS-02)
            Route::get('pasiens-duplikat', [PasienController::class, 'duplikat']);
            Route::get('pasiens/{pasien}', [PasienController::class, 'show']);
        });

        // Profil klinis & alergi terstruktur (PS-03): data klinis
        Route::get('pasiens/{pasien}/profil-klinis', [ProfilKlinisController::class, 'show'])->middleware('izin:rme.lihat');
        Route::put('pasiens/{pasien}/profil-klinis', [ProfilKlinisController::class, 'update'])->middleware('izin:pemeriksaan.vital,pemeriksaan.dokter');

        // Consent UU PDP (PS-04): pemrosesan data & opt-in marketing
        Route::get('pasiens/{pasien}/persetujuan-data', [PersetujuanDataController::class, 'index'])->middleware('izin:pasien.lihat');
        Route::middleware('izin:pasien.kelola,rme.tindakan')->group(function () {
            Route::get('pasiens/{pasien}/persetujuan-data/pratinjau', [PersetujuanDataController::class, 'pratinjau']);
            Route::post('pasiens/{pasien}/persetujuan-data', [PersetujuanDataController::class, 'store']);
            Route::post('persetujuan-datas/{persetujuanData}/cabut', [PersetujuanDataController::class, 'cabut']);
        });
        Route::get('persetujuan-datas/{persetujuanData}', [PersetujuanDataController::class, 'show'])->middleware('izin:pasien.kelola,rme.lihat');
        Route::get('pasiens/{pasien}/riwayat', [KunjunganController::class, 'riwayat'])->middleware('izin:rme.lihat');
        Route::middleware('izin:pasien.kelola')->group(function () {
            Route::post('pasiens', [PasienController::class, 'store']);
            Route::put('pasiens/{pasien}', [PasienController::class, 'update']);
        });
        Route::delete('pasiens/{pasien}', [PasienController::class, 'destroy'])->middleware('izin:pasien.hapus');

        // Consent foto klinis bertingkat (FT-04): front office atau tenaga tindakan yang mengambil tanda tangan pasien
        Route::get('pasiens/{pasien}/persetujuan-foto', [PersetujuanFotoController::class, 'index'])->middleware('izin:pasien.lihat');
        Route::middleware('izin:pasien.kelola,rme.tindakan')->group(function () {
            Route::get('pasiens/{pasien}/persetujuan-foto/pratinjau', [PersetujuanFotoController::class, 'pratinjau']);
            Route::post('pasiens/{pasien}/persetujuan-foto', [PersetujuanFotoController::class, 'store']);
            Route::post('persetujuan-fotos/{persetujuanFoto}/cabut', [PersetujuanFotoController::class, 'cabut']);
        });
        Route::get('persetujuan-fotos/{persetujuanFoto}', [PersetujuanFotoController::class, 'show'])->middleware('izin:pasien.kelola,rme.lihat');

        // Paket multi-sesi milik pasien (TR-02): dibaca front office, kasir & tenaga tindakan; dijual kasir (tagihan mandiri);
        // perpanjang / alihkan / refund sisa = kebijakan, butuh persetujuan manajer (kasir.void).
        Route::middleware('izin:pasien.lihat,kasir.tagihan,rme.tindakan,pemeriksaan.dokter')->group(function () {
            Route::get('pasiens/{pasien}/pakets', [PaketPasienController::class, 'index']);
            Route::get('paket-pasiens/{paketPasien}', [PaketPasienController::class, 'show']);
        });
        Route::post('pasiens/{pasien}/pakets', [PaketPasienController::class, 'store'])->middleware('izin:kasir.tagihan');
        Route::middleware('izin:kasir.void')->group(function () {
            Route::post('paket-pasiens/{paketPasien}/perpanjang', [PaketPasienController::class, 'perpanjang']);
            Route::post('paket-pasiens/{paketPasien}/alihkan', [PaketPasienController::class, 'alihkan']);
            Route::post('paket-pasiens/{paketPasien}/refund', [PaketPasienController::class, 'refund']);
        });

        // Kunjungan & antrian (cabang aktif). Detail tanpa izin rme.lihat hanya berisi data administrasi.
        Route::get('kunjungans', [KunjunganController::class, 'index']);
        Route::get('kunjungans/{kunjungan}', [KunjunganController::class, 'show'])->whereNumber('kunjungan');
        Route::middleware('izin:kunjungan.daftar')->group(function () {
            Route::post('kunjungans', [KunjunganController::class, 'store']);
            Route::post('kunjungans/{kunjungan}/batal', [KunjunganController::class, 'batal']);
        });

        // Booking & kalender (BK-01, BK-02); check-in mengubah booking menjadi kunjungan (AN-01)
        Route::middleware('izin:booking.lihat')->group(function () {
            Route::get('appointments', [AppointmentController::class, 'index']);
            Route::get('appointments/{appointment}', [AppointmentController::class, 'show']);
            Route::get('appointments-slot', [AppointmentController::class, 'slot']);
            Route::get('appointments-kebutuhan', [AppointmentController::class, 'kebutuhan']);
        });
        // Jadwal & ruang/alat juga dibaca pengelola jadwal dan katalog treatment (ruang/alat wajib, BK-08)
        Route::middleware('izin:booking.lihat,jadwal.kelola,master.kelola')->group(function () {
            Route::get('jadwals', [JadwalController::class, 'index']);
            Route::get('sumber-dayas', [SumberDayaController::class, 'index']);
        });
        Route::middleware('izin:booking.kelola')->group(function () {
            Route::post('appointments', [AppointmentController::class, 'store']);
            Route::put('appointments/{appointment}', [AppointmentController::class, 'update']);
            Route::post('appointments/{appointment}/konfirmasi', [AppointmentController::class, 'konfirmasi']);
            Route::post('appointments/{appointment}/batal', [AppointmentController::class, 'batal']);
            Route::post('appointments/{appointment}/tidak-hadir', [AppointmentController::class, 'tidakHadir']);
            Route::post('appointments/{appointment}/checkin', [AppointmentController::class, 'checkin']);
            Route::delete('appointments/{appointment}', [AppointmentController::class, 'destroy']);
        });

        // Jadwal praktik, cuti, ruang & alat (BK-03)
        Route::middleware('izin:jadwal.kelola')->group(function () {
            Route::post('jadwals', [JadwalController::class, 'store']);
            Route::put('jadwals/{jadwal}', [JadwalController::class, 'update']);
            Route::delete('jadwals/{jadwal}', [JadwalController::class, 'destroy']);
            Route::post('jadwal-pengecualians', [JadwalController::class, 'storePengecualian']);
            Route::delete('jadwal-pengecualians/{pengecualian}', [JadwalController::class, 'destroyPengecualian']);
            Route::post('sumber-dayas', [SumberDayaController::class, 'store']);
            Route::put('sumber-dayas/{sumberDaya}', [SumberDayaController::class, 'update']);
            Route::delete('sumber-dayas/{sumberDaya}', [SumberDayaController::class, 'destroy']);
        });

        // Pemeriksaan. Selesai = tutup & tanda tangani RME (dokter ber-SIP aktif); koreksi setelahnya lewat addendum (RM-07).
        Route::post('kunjungans/{kunjungan}/panggil', [KunjunganController::class, 'panggil'])->middleware('izin:pemeriksaan.panggil');
        Route::put('kunjungans/{kunjungan}/pemeriksaan', [PemeriksaanController::class, 'update'])->middleware('izin:pemeriksaan.vital,pemeriksaan.dokter');
        Route::middleware('izin:pemeriksaan.dokter')->group(function () {
            Route::post('kunjungans/{kunjungan}/selesai', [PemeriksaanController::class, 'selesai']);
            Route::post('kunjungans/{kunjungan}/addendum', [PemeriksaanController::class, 'addendum']);
            Route::post('kode-favorits', [KodeFavoritController::class, 'store']);
            Route::delete('kode-favorits', [KodeFavoritController::class, 'destroy']);
        });

        // RME estetika: catatan tindakan, face chart, parameter alat (RM-05, ES-01/02) & informed consent (RM-03)
        Route::middleware('izin:rme.lihat')->group(function () {
            Route::get('kunjungans/{kunjungan}/verifikasi', [PemeriksaanController::class, 'verifikasi'])->whereNumber('kunjungan');
            Route::get('kunjungan-tindakans/{kunjunganTindakan}/catatan', [CatatanTindakanController::class, 'show']);
            Route::get('informed-consents/{informedConsent}', [InformedConsentController::class, 'show']);
        });
        Route::middleware('izin:rme.tindakan')->group(function () {
            Route::put('kunjungan-tindakans/{kunjunganTindakan}/catatan', [CatatanTindakanController::class, 'update']);
            Route::get('kunjungans/{kunjungan}/informed-consents/pratinjau', [InformedConsentController::class, 'pratinjau']);
            Route::post('kunjungans/{kunjungan}/informed-consents', [InformedConsentController::class, 'store']);
            Route::post('informed-consents/{informedConsent}/cabut', [InformedConsentController::class, 'cabut']);
        });
        Route::get('template-consents', [TemplateConsentController::class, 'index'])->middleware('izin:rme.tindakan,master.kelola');

        // Kedokteran gigi: odontogram FDI (DG-01) & rencana perawatan per gigi (DG-02). Tindakan per gigi masuk tagihan (DG-07).
        Route::get('odontogram/referensi', [OdontogramController::class, 'referensi']);
        Route::middleware('izin:rme.lihat')->group(function () {
            Route::get('pasiens/{pasien}/odontogram', [OdontogramController::class, 'show']);
            Route::get('pasiens/{pasien}/rencana-perawatans', [RencanaPerawatanController::class, 'index']);
            Route::get('rencana-perawatans/{rencana}', [RencanaPerawatanController::class, 'show']);
        });
        Route::middleware('izin:pemeriksaan.dokter,rme.tindakan')->group(function () {
            Route::post('kunjungans/{kunjungan}/odontogram', [OdontogramController::class, 'store']);
            Route::delete('kunjungans/{kunjungan}/odontogram/{kondisi}', [OdontogramController::class, 'destroy']);
            Route::post('kunjungans/{kunjungan}/odontogram/{kondisi}/akhiri', [OdontogramController::class, 'akhiri']);
            Route::post('kunjungans/{kunjungan}/odontogram/{kondisi}/pulihkan', [OdontogramController::class, 'pulihkan']);
            Route::post('rencana-perawatans/{rencana}/setujui', [RencanaPerawatanController::class, 'setujui']);
        });
        Route::middleware('izin:pemeriksaan.dokter')->group(function () {
            Route::post('pasiens/{pasien}/rencana-perawatans', [RencanaPerawatanController::class, 'store']);
            Route::put('rencana-perawatans/{rencana}', [RencanaPerawatanController::class, 'update']);
            Route::post('rencana-perawatans/{rencana}/revisi', [RencanaPerawatanController::class, 'revisi']);
            Route::post('rencana-perawatans/{rencana}/batal', [RencanaPerawatanController::class, 'batal']);
        });

        // Lampiran klinis terenkripsi
        Route::middleware('izin:rme.lihat')->group(function () {
            Route::get('berkas', [BerkasController::class, 'index']);
            Route::get('berkas/{berkas}/tautan', [BerkasController::class, 'tautan']);
            Route::post('berkas/tautan', [BerkasController::class, 'tautanBanyak']);
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
            Route::post('reseps/{resep}/batal', [ResepController::class, 'batal']);
        });
        Route::middleware('izin:farmasi.obat')->group(function () {
            Route::post('obats', [ObatController::class, 'store']);
            Route::put('obats/{obat}', [ObatController::class, 'update']);
            Route::get('obats/{obat}/mutasi', [ObatController::class, 'mutasi']);
            Route::post('obats/{obat}/mutasi', [ObatController::class, 'storeMutasi']);
        });

        // Inventori: batch, kedaluwarsa, stok opname (IN-01, IN-03, IN-05) & pemakaian BHP (IN-02)
        Route::middleware('izin:inventori.kelola')->group(function () {
            Route::get('stok-batches', [StokBatchController::class, 'index']);
            Route::get('stok-batches/kedaluwarsa', [StokBatchController::class, 'kedaluwarsa']);
            Route::post('stok-batches', [StokBatchController::class, 'store']);
            Route::post('stok-batches/{stokBatch}/sesuaikan', [StokBatchController::class, 'sesuaikan']);
            Route::post('stok-batches/{stokBatch}/buang', [StokBatchController::class, 'buang']);
            Route::post('stok-batches/{stokBatch}/mutasi', [StokBatchController::class, 'mutasi']);

            Route::get('kunjungan-tindakans/{kunjunganTindakan}/bhps', [BhpController::class, 'index']);
            Route::put('kunjungan-tindakans/{kunjunganTindakan}/bhps', [BhpController::class, 'update']);
        });

        // Integrasi SATUSEHAT (PRD v2 5.14): pemantauan antrean & kirim ulang (SS-05), lookup IHS pasien (PS-05)
        Route::middleware('izin:integrasi.kelola')->group(function () {
            Route::get('satusehat/status', [SatuSehatController::class, 'status']);
            Route::get('satusehat/kirims', [SatuSehatController::class, 'index']);
            Route::post('satusehat/kirims/{kirim}/ulang', [SatuSehatController::class, 'ulang']);
            Route::post('satusehat/kirim-ulang-gagal', [SatuSehatController::class, 'ulangSemua']);
            Route::post('satusehat/tes-koneksi', [SatuSehatController::class, 'tesKoneksi']);

            // Observabilitas: scheduler, antrean, job gagal (PRD v2 7.2)
            Route::get('sistem/status', [SistemController::class, 'status']);
            Route::get('sistem/job-gagal', [SistemController::class, 'jobGagal']);
            Route::post('sistem/job-gagal/{uuid}/ulang', [SistemController::class, 'ulang']);
            Route::delete('sistem/job-gagal/{uuid}', [SistemController::class, 'hapus']);

            // WhatsApp (BK-06, CR-01)
            Route::get('whatsapp/status', [WhatsAppController::class, 'status']);
            Route::get('whatsapp/pesan', [WhatsAppController::class, 'index']);
            Route::post('whatsapp/pesan/{pesan}/ulang', [WhatsAppController::class, 'ulang']);
            Route::post('whatsapp/jadwalkan', [WhatsAppController::class, 'jadwalkan']);
        });
        Route::post('pasiens/{pasien}/satusehat', [SatuSehatController::class, 'lookupPasien'])->middleware('izin:pasien.kelola,integrasi.kelola');

        // Laporan keuangan (LP-02, LP-03, AD-01 konsolidasi); ?format=csv (LP-06 sebagian)
        Route::middleware('izin:laporan.keuangan')->group(function () {
            Route::get('laporan/penjualan', [LaporanController::class, 'penjualan']);
            Route::get('laporan/paket', [LaporanController::class, 'paket']);
        });

        // Komisi & jasa medis (KM-01, KM-03). Slip sendiri (`komisi/rincian` tanpa user_id) terbuka untuk semua petugas.
        Route::get('komisi/rincian', [KomisiController::class, 'rincian']);
        Route::middleware('izin:komisi.kelola,laporan.keuangan')->get('komisi/rekap', [KomisiController::class, 'rekap']);
        Route::middleware('izin:komisi.kelola')->group(function () {
            Route::get('aturan-komisis', [AturanKomisiController::class, 'index']);
            Route::post('aturan-komisis', [AturanKomisiController::class, 'store']);
            Route::put('aturan-komisis/{aturanKomisi}', [AturanKomisiController::class, 'update']);
            Route::delete('aturan-komisis/{aturanKomisi}', [AturanKomisiController::class, 'destroy']);
            Route::post('komisi/hitung-ulang', [KomisiController::class, 'hitungUlang']);
        });
        Route::middleware('izin:komisi.setujui')->post('komisi/setujui', [KomisiController::class, 'setujui']);

        // Kasir
        Route::middleware('izin:kasir.tagihan')->group(function () {
            Route::get('tagihans', [TagihanController::class, 'index']);
            Route::get('tagihans/{tagihan}', [TagihanController::class, 'show']);
            Route::post('tagihans', [TagihanController::class, 'store']);
            Route::post('tagihans/{tagihan}/bayar', [TagihanController::class, 'bayar']);
            // Voucher & kode promo (TR-06)
            Route::post('tagihans/{tagihan}/promo', [TagihanController::class, 'pasangPromo']);
            Route::delete('tagihans/{tagihan}/promo', [TagihanController::class, 'lepasPromo']);

            // Batal & refund butuh izin terpisah (persetujuan manajer) — BL-06
            Route::middleware('izin:kasir.void')->group(function () {
                Route::post('tagihans/{tagihan}/batal', [TagihanController::class, 'batal']);
                Route::post('tagihans/{tagihan}/refund', [TagihanController::class, 'refund']);
            });

            // Shift kas (BL-05)
            Route::middleware('izin:kasir.shift')->group(function () {
                Route::get('shift-kas', [ShiftKasController::class, 'index']);
                Route::get('shift-kas/aktif', [ShiftKasController::class, 'aktif']);
                Route::get('shift-kas/{shiftKas}', [ShiftKasController::class, 'show'])->whereNumber('shiftKas');
                Route::post('shift-kas', [ShiftKasController::class, 'store']);
                Route::post('shift-kas/{shiftKas}/tutup', [ShiftKasController::class, 'tutup']);
            });
        });

        // Voucher & promo (TR-06)
        Route::apiResource('promos', PromoController::class)->middleware('izin:promo.kelola');

        // Master data
        Route::middleware('izin:master.kelola')->group(function () {
            Route::apiResource('pakets', PaketController::class)->except('index');
            Route::delete('obats/{obat}', [ObatController::class, 'destroy']);
            // Impor master resmi (AD-10): icd10 / icd9cm / obat
            Route::post('impor-master/{jenis}', ImporMasterController::class)->where('jenis', 'icd10|icd9cm|obat');

            Route::post('polis', [PoliController::class, 'store']);
            Route::put('polis/{poli}', [PoliController::class, 'update']);
            Route::delete('polis/{poli}', [PoliController::class, 'destroy']);

            Route::apiResource('tindakans', TindakanController::class)->except('index');
            Route::post('kategori-tindakans', [KategoriTindakanController::class, 'store']);
            Route::put('kategori-tindakans/{kategori}', [KategoriTindakanController::class, 'update']);
            Route::delete('kategori-tindakans/{kategori}', [KategoriTindakanController::class, 'destroy']);
            Route::apiResource('icd10s', Icd10Controller::class)->except('index');
            Route::apiResource('icd9cms', Icd9cmController::class)->except('index');

            // Template SOAP (RM-01) & naskah informed consent (RM-03)
            Route::post('template-soaps', [TemplateSoapController::class, 'store']);
            Route::put('template-soaps/{templateSoap}', [TemplateSoapController::class, 'update']);
            Route::delete('template-soaps/{templateSoap}', [TemplateSoapController::class, 'destroy']);
            Route::post('template-consents', [TemplateConsentController::class, 'store']);
            Route::get('template-consents/{templateConsent}', [TemplateConsentController::class, 'show']);
            Route::put('template-consents/{templateConsent}', [TemplateConsentController::class, 'update']);
            Route::delete('template-consents/{templateConsent}', [TemplateConsentController::class, 'destroy']);
            Route::post('protokol-fotos', [ProtokolFotoController::class, 'store']);
            Route::put('protokol-fotos/{protokolFoto}', [ProtokolFotoController::class, 'update']);
            Route::delete('protokol-fotos/{protokolFoto}', [ProtokolFotoController::class, 'destroy']);
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
