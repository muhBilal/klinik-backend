<?php

namespace Database\Seeders;

use App\Enums\Penjamin;
use App\Enums\StatusKunjungan;
use App\Models\Cabang;
use App\Models\Icd10;
use App\Models\JadwalPraktik;
use App\Models\KomisiPeriode;
use App\Models\Kunjungan;
use App\Models\Obat;
use App\Models\Paket;
use App\Models\PaketPasien;
use App\Models\Pasien;
use App\Models\Poli;
use App\Models\Promo;
use App\Models\Resep;
use App\Models\SumberDaya;
use App\Models\Tagihan;
use App\Models\Tindakan;
use App\Models\TindakanHarga;
use App\Models\User;
use App\Services\BookingService;
use App\Services\DataKlinisService;
use App\Services\FarmasiService;
use App\Services\InformedConsentService;
use App\Services\InventoriService;
use App\Services\KasirService;
use App\Services\KomisiService;
use App\Services\NomorUrutService;
use App\Services\OdontogramService;
use App\Services\PaketService;
use App\Services\PemeriksaanService;
use App\Services\PersetujuanDataService;
use App\Services\PromoService;
use App\Support\CabangAktif;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Data demo transaksi ±6 minggu ke belakang + booking 1 minggu ke depan, untuk presentasi (bukan untuk test):
 * cabang kedua, ±100 pasien (nama Indonesia, tanpa Faker), booking (hadir / tidak hadir / batal), kunjungan dengan RME lengkap &
 * ditandatangani, informed consent, odontogram, tagihan & pembayaran berbagai metode (diskon, promo, kembalian), shift kas,
 * resep diserahkan, paket (dipesan dokter/terapis saat pemeriksaan — sesi pertama hari itu juga — atau dibeli langsung di kasir) &
 * dipakai, tindakan yang dicatat terapis, refund, persetujuan UU PDP, data klinis, rekap komisi (bulan lalu disetujui,
 * bulan ini draf). Semua lewat service aplikasi dengan waktu disimulasikan (`Carbon::setTestNow`) sehingga patuh aturan bisnis.
 *
 * Jalankan: `php artisan db:seed --class=DemoSeeder` (butuh data dasar DatabaseSeeder). Dilewati bila sudah ada tagihan,
 * kecuali `DEMO_PAKSA=1`. Hasil acak tetapi tetap sama setiap dijalankan (seed tetap).
 */
class DemoSeeder extends Seeder
{
    /** Lama riwayat demo (hari); test memakai nilai kecil. */
    public static int $hariKeBelakang = 42;

    private const PRIA = ['Budi', 'Andi', 'Rizky', 'Fajar', 'Dimas', 'Arif', 'Hendra', 'Yoga', 'Bayu', 'Agus', 'Reza', 'Ilham', 'Teguh', 'Adit', 'Galih', 'Wahyu'];

    private const WANITA = ['Siti', 'Dewi', 'Rina', 'Ayu', 'Putri', 'Indah', 'Lestari', 'Nadia', 'Maya', 'Fitri', 'Anisa', 'Sari', 'Wulan', 'Citra', 'Dian',
        'Ratna', 'Nabila', 'Salsa', 'Intan', 'Kartika', 'Melati', 'Vina', 'Yuliana', 'Amelia'];

    private const BELAKANG = ['Pratama', 'Saputra', 'Wijaya', 'Hidayat', 'Kusuma', 'Lestari', 'Nugroho', 'Santoso', 'Permata', 'Rahmawati', 'Setiawan',
        'Wulandari', 'Susanto', 'Anggraini', 'Firmansyah', 'Puspita', 'Halim', 'Siregar', 'Nasution', 'Gunawan', 'Utami', 'Maharani'];

    private const JALAN = ['Jl. Melati', 'Jl. Kenanga', 'Jl. Sudirman', 'Jl. Diponegoro', 'Jl. Merdeka', 'Jl. Cempaka', 'Jl. Anggrek', 'Jl. Gatot Subroto',
        'Jl. Ahmad Yani', 'Jl. Pemuda'];

    private const PEKERJAAN = ['Karyawan Swasta', 'Wiraswasta', 'PNS', 'Mahasiswa', 'Ibu Rumah Tangga', 'Guru', 'Dokter', 'Pengusaha', 'Desainer', 'Perawat'];

    /** Skenario per poli: [kode tindakan, peluang, kode diagnosa, subjektif, objektif, plan, pelaksana: dokter|terapis]. */
    private const ESTETIKA = [
        ['TRT-021', 25, 'L70.0', 'Jerawat meradang di pipi dan dahi sejak 2 bulan', 'Papul & pustul di pipi, komedo terbuka di dahi', 'Facial acne, sunscreen pagi, kontrol 2 minggu', 'terapis'],
        ['TRT-022', 12, 'L70.0', 'Bekas jerawat kehitaman, ingin kulit lebih cerah', 'Hiperpigmentasi pasca-inflamasi di kedua pipi', 'Chemical peeling, hindari matahari 1 minggu', 'terapis'],
        ['TRT-011', 22, 'L57.8', 'Flek & kulit kusam', 'Flek di area malar, tekstur kasar', 'Laser toning, ulang 2-4 minggu, sunscreen SPF 50', 'terapis'],
        ['TRT-012', 10, 'L57.8', 'Kemerahan & pori besar', 'Telangiektasis halus di pipi', 'IPL photo rejuvenation, kontrol 1 bulan', 'terapis'],
        ['TRT-001', 18, 'Z41.1', 'Kerutan dahi & garis kening mengganggu', 'Kerutan dinamis dahi & glabella sedang', 'Toksin botulinum, kontrol 2 minggu', 'dokter'],
        ['TRT-002', 8, 'Z41.1', 'Ingin pipi lebih berisi & lipatan senyum samar', 'Volume midface berkurang, lipatan nasolabial sedang', 'Filler HA, kompres dingin, kontrol 2 minggu', 'dokter'],
        [null, 5, 'L57.8', 'Konsultasi perawatan kulit', 'Kulit kombinasi, tidak ada lesi aktif', 'Edukasi skincare & sunscreen', 'dokter'],
    ];

    private const KULIT = [
        ['L20.9', 'Gatal & kulit kering di lipatan siku', 'Plak eritema skuama di fossa cubiti', 'OBT-004', 10, '1 x 1 tablet malam'],
        ['B35.4', 'Bercak merah bersisik gatal di badan', 'Lesi anular tepi aktif di punggung', 'OBT-019', 1, 'Oles 2 x sehari'],
        ['L50.9', 'Biduran hilang timbul sejak 2 hari', 'Urtika multipel di lengan', 'OBT-004', 10, '1 x 1 tablet'],
        ['L21.9', 'Ketombe & kemerahan di sisi hidung', 'Eritema berminyak di lipatan nasolabial', 'OBT-019', 1, 'Oles 2 x sehari'],
        ['L70.0', 'Jerawat di punggung & wajah', 'Papul inflamasi di wajah dan punggung', null, 0, ''],
    ];

    /** Paket yang ditawarkan untuk treatment kunjungan (sesi pertama dikerjakan hari itu juga). */
    private const PAKET_TREATMENT = ['TRT-011' => 'PKT-LSR6', 'TRT-021' => 'PKT-GLOW', 'TRT-022' => 'PKT-GLOW', 'TND-103' => 'PKT-SCL2'];

    private const GIGI = [
        ['TND-103', 35, 'K05.1', 'Gusi mudah berdarah saat sikat gigi', 'Kalkulus supra-gingiva rahang bawah', 'Scaling, edukasi sikat gigi'],
        ['TND-101', 35, 'K02.1', 'Gigi berlubang terasa ngilu saat minum dingin', 'Karies dentin', 'Tambal komposit'],
        ['TND-102', 15, 'K04.0', 'Gigi geraham belakang sakit berdenyut', 'Karies mencapai pulpa, gigi tidak dapat dipertahankan', 'Cabut, kompres dingin, kontrol 1 minggu'],
        ['TND-105', 15, 'K04.0', 'Gigi sakit spontan malam hari', 'Pulpitis ireversibel', 'Perawatan saluran akar kunjungan 1'],
    ];

    private CarbonImmutable $sekarang;

    /** @var array<string, mixed> */
    private array $u = [];

    /** Konfigurasi cabang: cabang, staf, dokter per poli. */
    private array $cabangs = [];

    /** @var array<int, array{poli: string, baru: bool}> pasien_id → poli pertama */
    private array $pasienPoli = [];

    /** @var list<int> tagihan lunas yang bisa dipilih untuk refund */
    private array $kandidatRefund = [];

    private array $statistik = ['kunjungan' => 0, 'booking' => 0, 'paket' => 0, 'refund' => 0, 'gagal' => 0];

    public function __construct(
        private PemeriksaanService $pemeriksaan,
        private KasirService $kasir,
        private BookingService $booking,
        private PaketService $paket,
        private PromoService $promo,
        private FarmasiService $farmasi,
        private InformedConsentService $consent,
        private PersetujuanDataService $pdp,
        private DataKlinisService $klinis,
        private OdontogramService $odontogram,
        private InventoriService $inventori,
        private KomisiService $komisi,
        private NomorUrutService $nomor,
        private CabangAktif $cabangAktif,
    ) {}

    public function run(): void
    {
        if (! User::where('email', 'admin@eklinik.test')->exists() || ! Tindakan::where('kode', 'TRT-001')->exists()) {
            $this->command?->warn('DemoSeeder butuh data dasar: jalankan DatabaseSeeder dulu (php artisan migrate:fresh --seed).');

            return;
        }
        if (Tagihan::withoutGlobalScope('cabang')->exists() && ! env('DEMO_PAKSA')) {
            $this->command?->info('Sudah ada transaksi; DemoSeeder dilewati (DEMO_PAKSA=1 untuk tetap menambah data demo).');

            return;
        }

        mt_srand(2026);
        $this->sekarang = CarbonImmutable::now();
        $mulai = microtime(true);

        try {
            $this->siapkan();
            $this->paketLama();
            for ($mundur = self::$hariKeBelakang; $mundur >= 0; $mundur--) {
                $this->sehari($this->sekarang->startOfDay()->subDays($mundur));
            }
            $this->refund();
            $this->bookingMendatang();
            $this->rekapKomisi();
        } finally {
            Carbon::setTestNow();
            CarbonImmutable::setTestNow();
            $this->cabangAktif->set(null);
            auth()->forgetUser();
        }

        $this->command?->info(sprintf('Data demo: %d kunjungan, %d booking, %d paket terjual, %d refund (%d alur dilewati) dalam %.0f detik.',
            $this->statistik['kunjungan'], $this->statistik['booking'], $this->statistik['paket'], $this->statistik['refund'], $this->statistik['gagal'],
            microtime(true) - $mulai));
    }

    // ------------------------------------------------------------------ persiapan

    private function siapkan(): void
    {
        $this->waktu($this->sekarang->startOfDay()->subDays(self::$hariKeBelakang + 3), 8 * 60);
        $email = fn (string $e) => User::where('email', $e)->firstOrFail();
        $this->u = [
            'admin' => $email('admin@eklinik.test'), 'manajer' => User::where('email', 'manajer@eklinik.test')->first(),
            'pendaftaran' => $email('pendaftaran@eklinik.test'), 'perawat' => $email('perawat@eklinik.test'),
            'kasir' => $email('kasir@eklinik.test'), 'apoteker' => $email('apoteker@eklinik.test'),
            'terapis' => User::where('email', 'terapis@eklinik.test')->first(),
        ];
        $utama = Cabang::where('kode', 'UTAMA')->first() ?? Cabang::orderBy('id')->firstOrFail();
        $poli = Poli::pluck('id', 'kode');

        // Cabang kedua (multi-cabang AD-01): klinik estetika, harga khusus untuk dua treatment
        $selatan = Cabang::firstOrCreate(['kode' => 'SELATAN'], ['nama' => 'Klinik Cabang Selatan', 'alamat' => 'Jl. Fatmawati No. 88',
            'telepon' => '021-5550002', 'jam_buka' => '09:00', 'jam_tutup' => '20:00']);
        $staf = fn (string $e, string $nama, string $role, array $extra = []) => User::firstOrCreate(['email' => $e],
            ['name' => $nama, 'password' => 'password', 'role' => $role, 'cabang_id' => $selatan->id, ...$extra]);
        $rudi = $staf('dokter.selatan@eklinik.test', 'dr. Rudi Hartono', 'dokter', ['poli_id' => $poli['ESTETIKA'] ?? null, 'sip' => '503/SIP-DU/004/2026']);
        $mega = $staf('terapis.selatan@eklinik.test', 'Mega Terapis', 'terapis');
        $selatanStaf = [
            'pendaftaran' => $staf('pendaftaran.selatan@eklinik.test', 'Rani Pendaftaran', 'pendaftaran'),
            'kasir' => $staf('kasir.selatan@eklinik.test', 'Yoga Kasir', 'kasir'),
            'apoteker' => $staf('apoteker.selatan@eklinik.test', 'Sari Apoteker, S.Farm', 'apoteker'),
        ];
        foreach ([$rudi, $mega] as $petugas) {
            foreach ([1, 2, 3, 4, 5, 6] as $hari) {
                JadwalPraktik::firstOrCreate(['cabang_id' => $selatan->id, 'user_id' => $petugas->id, 'hari' => $hari],
                    ['jam_mulai' => '09:00', 'jam_selesai' => $hari === 6 ? '14:00' : '18:00']);
            }
        }
        SumberDaya::firstOrCreate(['cabang_id' => $selatan->id, 'kode' => 'RG-S1'], ['nama' => 'Ruang Tindakan Selatan', 'tipe' => 'ruang']);
        foreach (['TRT-011' => 1100000, 'TRT-021' => 300000] as $kode => $tarif) {
            TindakanHarga::firstOrCreate(['tindakan_id' => $this->idTindakan($kode), 'cabang_id' => $selatan->id], ['tarif' => $tarif, 'tersedia' => true]);
        }

        // Stok cukup untuk ±6 minggu di kedua cabang
        $this->login($this->u['admin']);
        foreach (Obat::all() as $obat) {
            $banyak = in_array($obat->kode, ['OBT-021', 'OBT-022'], true) ? 40 : (in_array($obat->kode, ['OBT-023'], true) ? 60 : 400);
            foreach ([$utama->id, $selatan->id] as $cabangId) {
                $this->inventori->terima($obat, $cabangId, $banyak, 'DEMO-'.$obat->kode.'-'.$cabangId, now()->addMonths(14), $this->u['admin'], 'Stok demo');
            }
        }

        // Promo bawaan berlaku sejak awal periode demo
        Promo::query()->update(['mulai' => now()->toDateString()]);

        $this->cabangs = [
            [
                'cabang' => $utama, 'pendaftaran' => $this->u['pendaftaran'], 'kasir' => $this->u['kasir'], 'apoteker' => $this->u['apoteker'],
                'perawat' => $this->u['perawat'], 'terapis' => $this->u['terapis'],
                'dokter' => ['ESTETIKA' => $email('dokter@eklinik.test'), 'KULIT' => $email('dokter.kulit@eklinik.test'), 'GIGI' => $email('dokter.gigi@eklinik.test')],
                'kunjungan' => [6, 10], 'sabtu' => [3, 5],
            ],
            [
                'cabang' => $selatan, ...$selatanStaf, 'perawat' => null, 'terapis' => $mega,
                'dokter' => ['ESTETIKA' => $rudi], 'kunjungan' => [3, 5], 'sabtu' => [1, 3],
            ],
        ];

        // Pasien lama dari DatabaseSeeder dianggap terdaftar sejak lama
        Pasien::query()->update(['created_at' => now()->subMonths(4), 'updated_at' => now()->subMonths(4)]);
    }

    // ------------------------------------------------------------------ satu hari

    private function sehari(CarbonImmutable $hari): void
    {
        if ($hari->isSunday()) {
            return;
        }

        foreach ($this->cabangs as $c) {
            $this->cabangAktif->set($c['cabang']->id);
            $hariIni = $hari->isSameDay($this->sekarang);

            // Shift kasir dibuka 08:30 (hari ini hanya bila sudah lewat jamnya)
            $shift = null;
            if ($this->waktu($hari, 8 * 60 + 30)) {
                $this->login($c['kasir']);
                $shift = $this->kasir->shiftTerbuka($c['cabang']->id, $c['kasir']) ?? $this->kasir->bukaShift($c['cabang']->id, $c['kasir'], 500000);
            }

            [$min, $max] = $hari->isSaturday() ? $c['sabtu'] : $c['kunjungan'];
            $jumlah = mt_rand($min, $max);
            $kursor = array_fill_keys(array_keys($c['dokter']), 9 * 60 + mt_rand(0, 20));

            for ($i = 0; $i < $jumlah; $i++) {
                $poli = $this->pilihPoli(array_keys($c['dokter']));
                $mulai = $kursor[$poli];
                $kursor[$poli] += mt_rand(40, 70);
                if ($mulai > ($hari->isSaturday() ? 13 * 60 : 17 * 60)) {
                    continue;
                }
                try {
                    $this->satuKunjungan($c, $hari, $poli, $mulai);
                } catch (Throwable $e) {
                    $this->statistik['gagal']++;
                    $this->command?->warn("Lewati kunjungan demo {$hari->toDateString()} {$poli}: {$e->getMessage()}");
                }
            }

            // Hari ini: beberapa pasien masih di antrian (menunggu, diperiksa, menunggu bayar) agar dashboard & antrian hidup
            $menitSekarang = $this->sekarang->hour * 60 + $this->sekarang->minute;
            if ($hariIni && $menitSekarang >= 10 * 60 && $menitSekarang <= 19 * 60) {
                foreach ($c['cabang']->kode === 'UTAMA' ? [44, 30, 18, 6] : [44, 8] as $mundur) {
                    try {
                        // Yang sedang diperiksa (30 menit lalu) ditawari paket → pesanan terlihat di layar pemeriksaan
                        $this->satuKunjungan($c, $hari, $mundur === 30 ? 'ESTETIKA' : $this->pilihPoli(array_keys($c['dokter'])), $menitSekarang - $mundur,
                            tawarkanPaket: $mundur === 30);
                    } catch (Throwable $e) {
                        $this->statistik['gagal']++;
                        $this->command?->warn("Lewati antrian demo hari ini: {$e->getMessage()}");
                    }
                }
            }

            // No-show & batal (booking yang tidak menjadi kunjungan)
            if (mt_rand(1, 100) <= 45) {
                $this->bookingGagal($c, $hari, mt_rand(1, 100) <= 70);
            }

            // Tutup shift 20:00 dengan selisih kecil sesekali (hari ini dibiarkan terbuka)
            if ($shift && ! $hariIni && $this->waktu($hari, 20 * 60)) {
                $this->login($c['kasir']);
                $seharusnya = $this->kasir->rekapShift($shift)['kas_seharusnya'];
                $this->kasir->tutupShift($shift, $seharusnya + (mt_rand(1, 100) <= 12 ? -5000 * mt_rand(1, 3) : 0), null);
            }
        }
    }

    /** Satu pasien dari booking/datang langsung sampai bayar & ambil obat; langkah setelah waktu nyata dilewati (hari ini). */
    private function satuKunjungan(array $c, CarbonImmutable $hari, string $poli, int $menit, ?Pasien $pasienTetap = null, bool $tawarkanPaket = false): void
    {
        $cabangId = $c['cabang']->id;
        $dokter = $c['dokter'][$poli];
        [$pasien, $baru] = $pasienTetap ? [$pasienTetap, false] : $this->pilihPasien($poli, $hari, $menit);
        $skenario = $this->skenario($poli, $pasien, paksaPaket: $pasienTetap !== null);

        // 55% lewat booking (dibuat sehari sebelumnya), sisanya datang langsung
        $kunjungan = null;
        if ($skenario['tindakan'] && mt_rand(1, 100) <= 55 && $this->waktu($hari->subDay(), 15 * 60 + mt_rand(0, 120))) {
            $this->login($c['pendaftaran']);
            try {
                $appointment = $this->booking->buat(['pasien_id' => $pasien->id, 'poli_id' => $this->idPoli($poli), 'petugas_id' => $dokter->id,
                    'mulai_at' => $hari->startOfDay()->addMinutes($menit)->toDateTimeString(), 'tindakan_ids' => [$this->idTindakan($skenario['tindakan'])]],
                    $cabangId, $c['pendaftaran']);
                $this->statistik['booking']++;
                if (mt_rand(1, 100) <= 60) {
                    $this->booking->konfirmasi($appointment);
                }
                if (! $this->waktu($hari, $menit - 5)) {
                    return;
                }
                $kunjungan = $this->booking->checkin($appointment->fresh(), $c['pendaftaran']);
            } catch (Throwable) {
                $kunjungan = null; // slot bentrok → datang langsung
            }
        }

        if (! $kunjungan) {
            if (! $this->waktu($hari, $menit)) {
                return;
            }
            $this->login($c['pendaftaran']);
            $kunjungan = Kunjungan::create([
                'cabang_id' => $cabangId, 'pasien_id' => $pasien->id, 'poli_id' => $this->idPoli($poli), 'dokter_id' => $dokter->id,
                'no_registrasi' => $this->nomor->noRegistrasi(today()), 'no_antrian' => $this->nomor->noAntrian($cabangId, $this->idPoli($poli), today()),
                'tanggal' => today(), 'penjamin' => Penjamin::Umum, 'keluhan' => $skenario['subjektif'], 'status' => StatusKunjungan::Menunggu,
                'created_by' => $c['pendaftaran']->id,
            ]);
        }
        $this->statistik['kunjungan']++;

        // Pasien baru: persetujuan UU PDP di front office, data klinis oleh perawat/dokter
        if ($baru) {
            $this->persetujuanData($pasien, $c['pendaftaran']);
            $this->dataKlinis($pasien, $c['perawat'] ?? $dokter);
        }

        if (! $this->waktu($hari, $menit + 12)) {
            return;
        }
        $this->login($dokter);
        $this->pemeriksaan->panggil($kunjungan, $dokter);

        if (! $this->waktu($hari, $menit + 20)) {
            return;
        }
        $dipesan = $this->periksa($kunjungan, $c, $dokter, $poli, $skenario, $pasien, $tawarkanPaket);

        if (! $this->waktu($hari, $menit + 40)) {
            return;
        }
        $this->login($dokter);
        $this->pemeriksaan->selesai($kunjungan->fresh(), $dokter);

        // Sesekali pasien membeli paket langsung di kasir setelah kunjungan (TR-02)
        if (! $dipesan) {
            $this->mungkinJualPaket($c, $pasien, $poli, $hari, $menit + 42);
        }

        if (! $this->waktu($hari, $menit + 46)) {
            return;
        }
        $tagihan = Tagihan::withoutGlobalScope('cabang')->where('kunjungan_id', $kunjungan->id)->latest('id')->firstOrFail();
        $this->bayar($tagihan, $c['kasir'], $baru);

        $resep = Resep::withoutGlobalScope('cabang')->where('kunjungan_id', $kunjungan->id)->first();
        if ($resep && $this->waktu($hari, $menit + 55)) {
            $this->login($c['apoteker']);
            $this->farmasi->serahkan($resep, $c['apoteker']);
        }
    }

    /** @return bool paket dipesan di kunjungan ini */
    private function periksa(Kunjungan $kunjungan, array $c, User $dokter, string $poli, array $s, Pasien $pasien, bool $tawarkanPaket = false): bool
    {
        $terapis = $s['pelaksana'] === 'terapis' ? $c['terapis'] : null;
        $pesanan = $this->mungkinPesanPaket($kunjungan, $terapis ?? $dokter, $poli, $s, $pasien, $tawarkanPaket);
        $sesiPertama = $pesanan && $s['tindakan'] ? $pesanan->items->firstWhere('tindakan_id', $this->idTindakan($s['tindakan'])) : null;
        $tindakans = [];
        if ($s['tindakan']) {
            $baris = ['tindakan_id' => $this->idTindakan($s['tindakan']), 'jumlah' => 1];
            if ($terapis) {
                $baris['petugas_id'] = $terapis->id;
            } else {
                $baris['petugas_id'] = $dokter->id;
                if ($c['perawat'] && in_array($poli, ['ESTETIKA', 'GIGI'], true)) {
                    $baris['asisten_id'] = $c['perawat']->id;
                }
            }
            if ($s['gigi']) {
                [$baris['gigi'], $baris['permukaan']] = $s['gigi'];
            }
            if ($s['paket_item_id'] || $sesiPertama) {
                $baris['paket_pasien_item_id'] = $s['paket_item_id'] ?? $sesiPertama->id;
            }
            $tindakans[] = $baris;
        }

        // Treatment yang dikerjakan terapis dicatat terapis sendiri (rme.tindakan); dokter mengisi SOAP, diagnosa & resep lalu menutup.
        // Kunjungan dari booking sudah membawa baris tindakan atas nama dokter booking — pelaksananya diganti dokter (terapis tidak
        // mengubah baris petugas lain).
        $dicatatTerapis = $terapis && $tindakans && $kunjungan->tindakans()->doesntExist();
        if ($dicatatTerapis) {
            $this->login($terapis);
            $this->pemeriksaan->simpan($kunjungan, ['tindakans' => $tindakans], $terapis);
        }
        $this->login($dokter);

        // Karies dicatat di odontogram dulu; tambal komposit menggantinya menjadi tambalan (cof) otomatis
        if ($s['tindakan'] === 'TND-101') {
            $this->odontogram->tetapkan($kunjungan, ['gigi' => $s['gigi'][0], 'permukaan' => $s['gigi'][1], 'kondisi' => 'car'], $dokter);
        }

        $this->pemeriksaan->simpan($kunjungan, [
            'tekanan_darah' => mt_rand(105, 130).'/'.mt_rand(65, 85), 'nadi' => mt_rand(68, 92), 'suhu' => mt_rand(364, 371) / 10,
            'respirasi' => mt_rand(16, 20), 'berat_badan' => mt_rand(45, 82), 'tinggi_badan' => mt_rand(150, 178),
            'subjektif' => $s['subjektif'], 'objektif' => $s['objektif'], 'asesmen' => Icd10::where('kode', $s['diagnosa'])->value('nama'),
            'plan' => $s['plan'],
            'diagnosas' => [['icd10_id' => Icd10::where('kode', $s['diagnosa'])->value('id')]],
            ...($dicatatTerapis ? [] : ['tindakans' => $tindakans]),
            'resep' => $s['resep'],
        ], $dokter);

        // Informed consent untuk treatment yang mewajibkannya (RM-03)
        $kunjungan->load('tindakans.tindakan');
        foreach ($kunjungan->tindakans as $kt) {
            if ($kt->tindakan->template_consent_id) {
                $this->consent->simpan($kunjungan, [
                    'template_consent_id' => $kt->tindakan->template_consent_id, 'kunjungan_tindakan_id' => $kt->id, 'keputusan' => 'setuju',
                    'penandatangan_nama' => $pasien->nama, 'hubungan' => 'pasien', 'ttd_penandatangan' => $this->ttd(),
                    'saksi_nama' => ($c['perawat'] ?? $c['terapis'])?->name, 'ttd_saksi' => $this->ttd(),
                ], $dokter);
            }
        }

        return $pesanan !== null;
    }

    /**
     * Dokter/terapis menawarkan paket saat pemeriksaan (TR-02): dipesan dari kunjungan, ditagihkan bersama tagihan kunjungan; bila
     * isinya cocok dengan treatment hari itu, sesi pertamanya langsung dikerjakan. Sesekali paket dipesan untuk dimulai lain kali.
     */
    private function mungkinPesanPaket(Kunjungan $kunjungan, User $pemesan, string $poli, array $s, Pasien $pasien, bool $paksa): ?PaketPasien
    {
        if ($s['paket_item_id'] || $this->paket->aktif($pasien)->isNotEmpty()) {
            return null;
        }
        $cocok = self::PAKET_TREATMENT[$s['tindakan'] ?? ''] ?? null;
        $pilihan = $cocok ? [$cocok] : (['ESTETIKA' => ['PKT-LSR6', 'PKT-GLOW'], 'GIGI' => ['PKT-SCL2']][$poli] ?? []);
        if (! $pilihan || (! $paksa && mt_rand(1, 100) > ($cocok ? 16 : 3))) {
            return null;
        }

        $this->login($pemesan);
        $pesanan = $this->paket->pesanDariKunjungan($kunjungan, Paket::where('kode', $pilihan[array_rand($pilihan)])->firstOrFail(), null, $pemesan);
        $this->statistik['paket']++;

        return $pesanan;
    }

    private function bayar(Tagihan $tagihan, User $kasir, bool $pasienBaru): void
    {
        $this->login($kasir);
        // Promo pasien baru (WELCOME10) & diskon manual sesekali
        if ($pasienBaru && $tagihan->total >= 200000 && mt_rand(1, 100) <= 40) {
            try {
                $tagihan = $this->promo->terapkan($tagihan, 'WELCOME10');
            } catch (Throwable) {
            }
        }
        $diskon = ! $tagihan->promo_id && $tagihan->total > 500000 && mt_rand(1, 100) <= 12 ? (int) (round($tagihan->total * 0.05 / 1000) * 1000) : 0;
        $promo = $tagihan->promo_id ? $this->promo->hitung($tagihan->promo, $tagihan) : 0;
        $grand = $this->kasir->hitungGrandTotal($tagihan->total, $diskon + $promo, $tagihan->pajak_persen);

        $metode = $this->acak(['tunai' => 30, 'qris' => 32, 'debit' => 22, 'transfer' => 16]);
        $jumlah = $metode === 'tunai' && mt_rand(1, 100) <= 60 ? (int) (ceil($grand / 50000) * 50000) : $grand;
        $this->kasir->bayar($tagihan, $grand > 0 ? [['metode' => $metode, 'jumlah' => $jumlah]] : [], $diskon, $kasir);

        if ($grand >= 300000) {
            $this->kandidatRefund[] = $tagihan->id;
        }
    }

    private function mungkinJualPaket(array $c, Pasien $pasien, string $poli, CarbonImmutable $hari, int $menit): void
    {
        $pilihan = ['ESTETIKA' => ['PKT-LSR6', 'PKT-GLOW'], 'GIGI' => ['PKT-SCL2']][$poli] ?? [];
        if (! $pilihan || mt_rand(1, 100) > 4 || $this->paket->aktif($pasien)->isNotEmpty() || ! $this->waktu($hari, $menit)) {
            return;
        }

        $this->login($c['kasir']);
        $paket = Paket::where('kode', $pilihan[array_rand($pilihan)])->firstOrFail();
        $jual = $this->paket->jual($pasien, $paket, null, $c['kasir']);
        $tagihan = Tagihan::withoutGlobalScope('cabang')->findOrFail($jual->tagihan_id);
        $this->kasir->bayar($tagihan, [['metode' => $this->acak(['transfer' => 45, 'debit' => 35, 'qris' => 20]), 'jumlah' => $tagihan->grand_total]], 0, $c['kasir']);
        $this->statistik['paket']++;
    }

    /** Booking yang tidak menjadi kunjungan: tidak hadir (ditandai sore) atau dibatalkan pasien. */
    private function bookingGagal(array $c, CarbonImmutable $hari, bool $tidakHadir): void
    {
        $dokter = $c['dokter']['ESTETIKA'] ?? reset($c['dokter']);
        $menit = 17 * 60 - ($hari->isSaturday() ? 5 * 60 : 0) + mt_rand(0, 30);
        if (! $this->waktu($hari->subDay(), 16 * 60)) {
            return;
        }
        [$pasien] = $this->pilihPasien('ESTETIKA', $hari->subDay(), 16 * 60);
        $this->login($c['pendaftaran']);
        try {
            $appointment = $this->booking->buat(['pasien_id' => $pasien->id, 'poli_id' => $this->idPoli('ESTETIKA'), 'petugas_id' => $dokter->id,
                'mulai_at' => $hari->startOfDay()->addMinutes($menit)->toDateTimeString(), 'tindakan_ids' => [$this->idTindakan('TRT-021')]],
                $c['cabang']->id, $c['pendaftaran']);
            $this->statistik['booking']++;
            if (! $tidakHadir) {
                $this->booking->batal($appointment, 'Pasien berhalangan');
            } elseif ($this->waktu($hari, 19 * 60)) {
                $this->booking->tidakHadir($appointment->fresh());
            }
        } catch (Throwable) {
        }
    }

    private function bookingMendatang(): void
    {
        for ($maju = 1; $maju <= 7; $maju++) {
            $hari = $this->sekarang->startOfDay()->addDays($maju);
            if ($hari->isSunday()) {
                continue;
            }
            foreach ($this->cabangs as $c) {
                $this->cabangAktif->set($c['cabang']->id);
                $this->keSekarang(30);
                $this->login($c['pendaftaran']);
                foreach (array_keys($c['dokter']) as $poli) {
                    $menit = 9 * 60 + mt_rand(0, 30);
                    for ($i = 0; $i < mt_rand(1, $poli === 'ESTETIKA' ? 4 : 2); $i++, $menit += mt_rand(60, 90)) {
                        // Booking mendatang dari pasien lama (tidak menambah "pasien baru hari ini")
                        $ids = collect($this->pasienPoli)->filter(fn ($p) => $p['poli'] === $poli)->keys();
                        $pasien = $ids->isEmpty() ? null : Pasien::find($ids->random());
                        if (! $pasien) {
                            continue;
                        }
                        $s = $this->skenario($poli, $pasien);
                        try {
                            $a = $this->booking->buat(['pasien_id' => $pasien->id, 'poli_id' => $this->idPoli($poli), 'petugas_id' => $c['dokter'][$poli]->id,
                                'mulai_at' => $hari->startOfDay()->addMinutes($menit)->toDateTimeString(),
                                'tindakan_ids' => $s['tindakan'] ? [$this->idTindakan($s['tindakan'])] : []], $c['cabang']->id, $c['pendaftaran']);
                            $this->statistik['booking']++;
                            if (mt_rand(1, 100) <= 40) {
                                $this->booking->konfirmasi($a);
                            }
                        } catch (Throwable) {
                        }
                    }
                }
            }
        }
    }

    /** Dua tagihan lunas direfund (komplain) oleh administrator sehari kemudian. */
    private function refund(): void
    {
        $this->login($this->u['admin']);
        foreach (array_slice($this->kandidatRefund, 5, 40) as $id) {
            if ($this->statistik['refund'] >= 2) {
                break;
            }
            if (mt_rand(1, 100) > 10) {
                continue;
            }
            $tagihan = Tagihan::withoutGlobalScope('cabang')->find($id);
            $kapan = CarbonImmutable::parse($tagihan->dibayar_at)->addDay()->setTime(11, 0);
            if ($kapan->isAfter($this->sekarang) || $tagihan->paketPasiens()->exists()) {
                continue;
            }
            Carbon::setTestNow($kapan);
            CarbonImmutable::setTestNow($kapan);
            $this->cabangAktif->set($tagihan->cabang_id);
            try {
                $this->kasir->refund($tagihan, 'Pasien komplain hasil perawatan (demo)', $this->u['admin']);
                $this->statistik['refund']++;
            } catch (Throwable) {
            }
        }
    }

    /** Rekap komisi: bulan lalu disetujui (+ satu bonus), bulan berjalan masih draf. */
    private function rekapKomisi(): void
    {
        $penghitung = $this->u['manajer'] ?? $this->u['admin'];
        $bulanLalu = $this->sekarang->subMonthNoOverflow()->startOfMonth();
        $this->keSekarang(20);

        foreach ($this->cabangs as $c) {
            $this->cabangAktif->set($c['cabang']->id);
            $this->login($penghitung);
            foreach ([[$bulanLalu, $bulanLalu->endOfMonth(), true], [$this->sekarang->startOfMonth(), $this->sekarang, false]] as [$dari, $sampai, $setujui]) {
                if (KomisiPeriode::withoutGlobalScope('cabang')->where('cabang_id', $c['cabang']->id)->whereDate('mulai', $dari)->exists()) {
                    continue;
                }
                $periode = $this->komisi->buatPeriode(['cabang_id' => $c['cabang']->id, 'nama' => 'Komisi '.$dari->translatedFormat('F Y'),
                    'mulai' => $dari->toDateString(), 'selesai' => $sampai->toDateString()], $penghitung);
                $periode = $this->komisi->hitung($periode, $penghitung);
                if ($setujui) {
                    if ($c['terapis']) {
                        $this->komisi->penyesuaian($periode, $c['terapis']->id, 150000, 'Bonus target treatment bulanan', $penghitung);
                    }
                    $this->login($this->u['admin']);
                    $this->komisi->setujui($periode->fresh(), $this->u['admin']);
                    $this->login($penghitung);
                }
            }
        }
    }

    // ------------------------------------------------------------------ pasien

    /** @return array{0: Pasien, 1: bool} pasien & apakah pasien baru hari ini */
    private function pilihPasien(string $poli, CarbonImmutable $hari, int $menit): array
    {
        // Pasien kembali (terutama yang punya sisa paket) atau pasien baru
        $kembali = collect($this->pasienPoli)->filter(fn ($p) => $p['poli'] === $poli)->keys();
        if ($kembali->count() >= 6 && mt_rand(1, 100) <= 65) {
            $id = $kembali->random();
            $pasien = Pasien::find($id);
            if ($pasien && ! Kunjungan::withoutGlobalScope('cabang')->where('pasien_id', $id)->whereDate('tanggal', $hari)->exists()) {
                return [$pasien, false];
            }
        }

        $lama = Pasien::whereNotIn('id', array_keys($this->pasienPoli))->inRandomOrder()->first();
        if ($lama && mt_rand(1, 100) <= 30) {
            $this->pasienPoli[$lama->id] = ['poli' => $poli];

            return [$lama, true];
        }

        $this->waktu($hari, max(0, $menit - 3), cekBatas: false);
        $pasien = $this->pasienBaru($poli);
        $this->pasienPoli[$pasien->id] = ['poli' => $poli];

        return [$pasien, true];
    }

    /**
     * Paket yang dibeli beberapa bulan lalu (sebelum jendela demo) untuk laporan paket: satu sudah hangus dengan sisa sesi, dua segera
     * kedaluwarsa. Pasiennya tidak dipakai kunjungan acak agar sisanya tetap utuh.
     */
    private function paketLama(): void
    {
        $c = $this->cabangs[0];
        $this->cabangAktif->set($c['cabang']->id);
        $pasiens = Pasien::orderBy('id')->take(3)->get()->values();

        foreach ([[0, 'PKT-GLOW', 112, [105, 98]], [1, 'PKT-GLOW', 126, [119, 112, 104]], [2, 'PKT-LSR6', 171, [160, 140, 120, 100]]] as [$i, $kode, $beli, $sesi]) {
            $pasien = $pasiens[$i] ?? null;
            if (! $pasien) {
                continue;
            }
            $this->pasienPoli[$pasien->id] = ['poli' => 'ARSIP'];
            try {
                $this->waktu($this->hariKerja($this->sekarang->startOfDay()->subDays($beli)), 10 * 60);
                $this->login($c['kasir']);
                $jual = $this->paket->jual($pasien, Paket::where('kode', $kode)->firstOrFail(), null, $c['kasir']);
                $tagihan = Tagihan::withoutGlobalScope('cabang')->findOrFail($jual->tagihan_id);
                $this->kasir->bayar($tagihan, [['metode' => 'transfer', 'jumlah' => $tagihan->grand_total]], 0, $c['kasir']);
                $this->statistik['paket']++;
                foreach ($sesi as $mundur) {
                    $this->satuKunjungan($c, $this->hariKerja($this->sekarang->startOfDay()->subDays($mundur)), 'ESTETIKA', 11 * 60, $pasien);
                }
            } catch (Throwable $e) {
                $this->statistik['gagal']++;
                $this->command?->warn("Lewati paket lama demo: {$e->getMessage()}");
            }
        }
    }

    private function hariKerja(CarbonImmutable $hari): CarbonImmutable
    {
        return $hari->isSunday() ? $hari->subDay() : $hari;
    }

    private function pasienBaru(string $poli): Pasien
    {
        // Estetika didominasi perempuan dewasa muda
        $perempuan = mt_rand(1, 100) <= ($poli === 'GIGI' ? 55 : 82);
        $depan = $perempuan ? self::WANITA : self::PRIA;
        $nama = $depan[array_rand($depan)].' '.self::BELAKANG[array_rand(self::BELAKANG)];
        $umur = $poli === 'GIGI' ? mt_rand(8, 65) : mt_rand(19, 52);

        return Pasien::create([
            'nik' => '3174'.str_pad((string) mt_rand(0, 999999999999), 12, '0', STR_PAD_LEFT),
            'nama' => $nama, 'jenis_kelamin' => $perempuan ? 'P' : 'L', 'tempat_lahir' => ['Jakarta', 'Bandung', 'Surabaya', 'Bogor', 'Depok', 'Medan'][mt_rand(0, 5)],
            'tanggal_lahir' => today()->subYears($umur)->subDays(mt_rand(0, 364))->toDateString(),
            'golongan_darah' => ['A', 'B', 'AB', 'O'][mt_rand(0, 3)],
            'alamat' => self::JALAN[array_rand(self::JALAN)].' No. '.mt_rand(1, 150).', Jakarta Selatan',
            'no_hp' => '08'.mt_rand(11, 59).str_pad((string) mt_rand(0, 99999999), 8, '0', STR_PAD_LEFT),
            'pekerjaan' => self::PEKERJAAN[array_rand(self::PEKERJAAN)],
        ]);
    }

    private function persetujuanData(Pasien $pasien, User $petugas): void
    {
        if (mt_rand(1, 100) > 85) {
            return; // sebagian pasien belum menandatangani → tampil sebagai pengingat
        }
        $this->login($petugas);
        $marketing = mt_rand(1, 100) <= 55;
        $this->pdp->simpan($pasien, ['marketing' => $marketing, 'kanal' => $marketing ? (mt_rand(1, 100) <= 35 ? ['whatsapp', 'email'] : ['whatsapp']) : [],
            'penandatangan_nama' => $pasien->nama, 'hubungan' => 'pasien', 'ttd' => $this->ttd()], $petugas);
    }

    private function dataKlinis(Pasien $pasien, User $petugas): void
    {
        $this->login($petugas);
        $umur = $pasien->tanggal_lahir->age;
        $alergi = match (true) {
            mt_rand(1, 100) <= 6 => [['kategori' => 'obat', 'zat' => 'Amoxicillin', 'obat_id' => Obat::where('kode', 'OBT-002')->value('id'), 'reaksi' => 'Ruam kemerahan', 'keparahan' => 'sedang']],
            mt_rand(1, 100) <= 6 => [['kategori' => 'makanan', 'zat' => 'Udang', 'reaksi' => 'Gatal & bentol', 'keparahan' => 'ringan']],
            mt_rand(1, 100) <= 3 => [['kategori' => 'lingkungan', 'zat' => 'Lateks', 'reaksi' => 'Gatal kontak', 'keparahan' => 'ringan']],
            default => [],
        };
        $this->klinis->simpan($pasien, [
            'fitzpatrick' => $this->acak(['II' => 8, 'III' => 40, 'IV' => 40, 'V' => 12]),
            'status_kehamilan' => $pasien->jenis_kelamin === 'P' && $umur >= 18 && $umur <= 45 ? $this->acak(['tidak' => 90, 'menyusui' => 7, 'hamil' => 3]) : null,
            'riwayat_obat' => mt_rand(1, 100) <= 12 ? ['Pil KB', 'Isotretinoin, berhenti 6 bulan lalu', 'Vitamin C & E', 'Metformin 500 mg'][mt_rand(0, 3)] : null,
            'riwayat_penyakit' => mt_rand(1, 100) <= 8 ? ['Riwayat keloid', 'Diabetes melitus terkontrol', 'Asma ringan', 'Herpes labialis berulang'][mt_rand(0, 3)] : null,
            'alergis' => $alergi,
        ], $petugas);
    }

    /** Skenario klinis untuk pasien & poli, termasuk pemakaian sisa paket bila ada. */
    private function skenario(string $poli, Pasien $pasien, bool $paksaPaket = false): array
    {
        $dasar = ['tindakan' => null, 'pelaksana' => 'dokter', 'gigi' => null, 'paket_item_id' => null, 'resep' => []];

        // Pasien dengan sisa paket datang untuk sesi berikutnya
        foreach ($this->paket->aktif($pasien) as $paketPasien) {
            $item = $paketPasien->items->firstWhere('sisa', '>', 0);
            if ($item && ($paksaPaket || mt_rand(1, 100) <= 85)) {
                $kode = Tindakan::whereKey($item->tindakan_id)->value('kode');
                $info = collect([...self::ESTETIKA, ...array_map(fn ($g) => [$g[0], 0, $g[2], $g[3], $g[4], $g[5], 'dokter'], self::GIGI)])
                    ->first(fn ($s) => $s[0] === $kode) ?? [$kode, 0, 'Z41.1', 'Sesi paket lanjutan', 'Kondisi membaik', 'Lanjutkan sesi paket', 'terapis'];

                return [...$dasar, 'tindakan' => $kode, 'diagnosa' => $info[2], 'subjektif' => "Sesi paket lanjutan — {$info[3]}", 'objektif' => $info[4],
                    'plan' => $info[5], 'pelaksana' => $info[6], 'paket_item_id' => $item->id];
            }
        }

        if ($poli === 'ESTETIKA') {
            $s = $this->acakBaris(self::ESTETIKA);

            return [...$dasar, 'tindakan' => $s[0], 'diagnosa' => $s[2], 'subjektif' => $s[3], 'objektif' => $s[4], 'plan' => $s[5], 'pelaksana' => $s[6]];
        }
        if ($poli === 'KULIT') {
            $s = self::KULIT[array_rand(self::KULIT)];
            $resep = $s[3] ? [['obat_id' => Obat::where('kode', $s[3])->value('id'), 'jumlah' => $s[4], 'aturan_pakai' => $s[5]]] : [];

            return [...$dasar, 'tindakan' => $s[0] === 'L70.0' ? 'TRT-022' : null, 'pelaksana' => 'terapis', 'diagnosa' => $s[0], 'subjektif' => $s[1],
                'objektif' => $s[2], 'plan' => $s[3] ? 'Terapi topikal/oral, kontrol 1 minggu' : 'Chemical peeling, kontrol 2 minggu', 'resep' => $resep];
        }

        $s = $this->acakBaris(array_map(fn ($g) => [$g[0], $g[1], $g[2], $g[3], $g[4], $g[5]], self::GIGI));
        $gigi = match ($s[0]) {
            'TND-101' => [[16, 26, 36, 46, 15, 25][mt_rand(0, 5)], ['O', 'MO', 'DO'][mt_rand(0, 2)]],
            'TND-102' => [[38, 48, 18][mt_rand(0, 2)], null],
            'TND-105' => [[36, 46][mt_rand(0, 1)], null],
            default => null,
        };
        $alergiAmox = $pasien->alergis()->where('zat', 'Amoxicillin')->exists();
        $resep = $s[0] === 'TND-102' ? array_values(array_filter([
            $alergiAmox ? null : ['obat_id' => Obat::where('kode', 'OBT-002')->value('id'), 'jumlah' => 15, 'aturan_pakai' => '3 x 1 tablet sesudah makan'],
            ['obat_id' => Obat::where('kode', 'OBT-014')->value('id'), 'jumlah' => 10, 'aturan_pakai' => '3 x 1 tablet bila nyeri'],
        ])) : [];

        return [...$dasar, 'tindakan' => $s[0], 'diagnosa' => $s[2], 'subjektif' => $s[3], 'objektif' => $s[4], 'plan' => $s[5], 'gigi' => $gigi, 'resep' => $resep];
    }

    // ------------------------------------------------------------------ utilitas

    /**
     * Pindahkan "sekarang" ke hari + menit sejak tengah malam. Untuk hari ini, false bila waktunya belum tiba (langkah dilewati).
     */
    private function waktu(CarbonImmutable $hari, int $menit, bool $cekBatas = true): bool
    {
        $t = $hari->startOfDay()->addMinutes($menit + mt_rand(0, 3));
        if ($cekBatas && $t->isAfter($this->sekarang)) {
            return false;
        }
        Carbon::setTestNow(Carbon::instance($t->toMutable()));
        CarbonImmutable::setTestNow($t);

        return true;
    }

    /** "Sekarang" = waktu nyata dikurangi beberapa menit (aksi yang dilakukan "barusan"). */
    private function keSekarang(int $mundurMenit): void
    {
        $t = $this->sekarang->subMinutes($mundurMenit);
        Carbon::setTestNow(Carbon::instance($t->toMutable()));
        CarbonImmutable::setTestNow($t);
    }

    private function login(?User $user): void
    {
        $user ? auth()->setUser($user) : auth()->forgetUser();
    }

    private function pilihPoli(array $poli): string
    {
        $bobot = ['ESTETIKA' => 60, 'KULIT' => 22, 'GIGI' => 18];

        return $this->acak(array_intersect_key($bobot, array_flip($poli)));
    }

    /** @param  array<string, int>  $bobot */
    private function acak(array $bobot): string
    {
        $n = mt_rand(1, array_sum($bobot));
        foreach ($bobot as $nilai => $b) {
            if (($n -= $b) <= 0) {
                return (string) $nilai;
            }
        }

        return (string) array_key_first($bobot);
    }

    /** Pilih baris berbobot (kolom ke-2 = bobot). */
    private function acakBaris(array $baris): array
    {
        $kunci = $this->acak(array_map(fn ($b) => $b[1], $baris));

        return $baris[(int) $kunci];
    }

    private function idTindakan(string $kode): int
    {
        static $cache = [];

        return $cache[$kode] ??= Tindakan::where('kode', $kode)->value('id');
    }

    private function idPoli(string $kode): int
    {
        static $cache = [];

        return $cache[$kode] ??= Poli::where('kode', $kode)->value('id');
    }

    /** Tanda tangan demo: PNG grayscale 240×80 berisi coretan (bukan gambar acak), sah menurut validasi tanda tangan. */
    private function ttd(): string
    {
        [$w, $h] = [240, 80];
        $baris = array_fill(0, $h, str_repeat("\xff", $w));
        $amp = mt_rand(12, 24);
        $frek = mt_rand(25, 45) / 1000;
        $fase = mt_rand(0, 628) / 100;
        for ($x = 18; $x < $w - 18; $x++) {
            $y = (int) round($h / 2 + $amp * sin($x * $frek * 3 + $fase) * cos($x * $frek));
            for ($t = -1; $t <= 1; $t++) {
                if ($y + $t >= 0 && $y + $t < $h) {
                    $baris[$y + $t][$x] = "\x20";
                }
            }
        }
        $chunk = fn (string $tipe, string $data) => pack('N', strlen($data)).$tipe.$data.pack('N', crc32($tipe.$data));
        $png = "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', $w, $h, 8, 0, 0, 0, 0))
            .$chunk('IDAT', gzcompress(implode('', array_map(fn ($r) => "\0".$r, $baris)))).$chunk('IEND', '');

        return 'data:image/png;base64,'.base64_encode($png);
    }
}
