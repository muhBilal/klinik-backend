<?php

namespace Database\Seeders;

use App\Enums\JenisMutasi;
use App\Enums\Role;
use App\Models\Cabang;
use App\Models\Icd10;
use App\Models\KategoriTindakan;
use App\Models\Obat;
use App\Models\Pasien;
use App\Models\Poli;
use App\Models\Tindakan;
use App\Models\User;
use App\Services\FarmasiService;
use Faker\Factory;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(FarmasiService $farmasi): void
    {
        // Aman dijalankan berulang (mis. setiap container start): lewati bila data sudah ada.
        if (User::exists()) {
            $this->command?->info('Database sudah berisi data, seeder dilewati.');

            return;
        }

        // Satu cabang demo; cabang lain ditambahkan admin di menu Master Cabang.
        $cabang = Cabang::create(['kode' => 'UTAMA', 'nama' => 'Klinik Utama', 'alamat' => 'Jl. Kesehatan No. 1', 'telepon' => '021-5550001',
            'jam_buka' => '08:00', 'jam_tutup' => '21:00']);

        $polis = collect([
            ['kode' => 'UMUM', 'nama' => 'Poli Umum', 'tarif_konsultasi' => 50000],
            ['kode' => 'GIGI', 'nama' => 'Poli Gigi', 'tarif_konsultasi' => 75000],
            ['kode' => 'KIA', 'nama' => 'Poli KIA', 'tarif_konsultasi' => 60000],
        ])->map(fn ($p) => Poli::create($p))->keyBy('kode');

        // Semua akun demo memakai password: password. Administrator lintas cabang (cabang_id null), staf di cabang utama.
        $users = [
            ['name' => 'Administrator', 'email' => 'admin@eklinik.test', 'role' => Role::Admin->value, 'cabang_id' => null],
            ['name' => 'Siti Pendaftaran', 'email' => 'pendaftaran@eklinik.test', 'role' => Role::Pendaftaran->value],
            ['name' => 'Ns. Rina Perawat', 'email' => 'perawat@eklinik.test', 'role' => Role::Perawat->value],
            ['name' => 'dr. Andi Wijaya', 'email' => 'dokter@eklinik.test', 'role' => Role::Dokter->value, 'poli_id' => $polis['UMUM']->id, 'sip' => '503/SIP-DU/001/2026'],
            ['name' => 'drg. Maya Sari', 'email' => 'dokter.gigi@eklinik.test', 'role' => Role::Dokter->value, 'poli_id' => $polis['GIGI']->id, 'sip' => '503/SIP-DG/002/2026'],
            ['name' => 'dr. Lestari, Sp.OG', 'email' => 'dokter.kia@eklinik.test', 'role' => Role::Dokter->value, 'poli_id' => $polis['KIA']->id, 'sip' => '503/SIP-DS/003/2026'],
            ['name' => 'Budi Apoteker, S.Farm', 'email' => 'apoteker@eklinik.test', 'role' => Role::Apoteker->value],
            ['name' => 'Dewi Kasir', 'email' => 'kasir@eklinik.test', 'role' => Role::Kasir->value],
            // Peran non-sistem bawaan (dapat diubah di menu Peran & Izin)
            ['name' => 'Nadia Terapis', 'email' => 'terapis@eklinik.test', 'role' => 'terapis'],
            ['name' => 'Rudi Manajer', 'email' => 'manajer@eklinik.test', 'role' => 'manajer'],
        ];

        foreach ($users as $user) {
            User::create(['cabang_id' => $cabang->id, ...$user, 'password' => 'password']);
        }

        $admin = User::where('role', Role::Admin->value)->first();

        foreach ($this->icd10() as [$kode, $nama]) {
            Icd10::create(compact('kode', 'nama'));
        }

        $obats = collect();
        foreach ($this->obat() as $item) {
            [$kode, $nama, $satuan, $harga, $stok] = $item;
            $obat = Obat::create(['kode' => $kode, 'nama' => $nama, 'satuan' => $satuan, 'harga' => $harga, 'stok_minimum' => $item[5] ?? 20]);
            $farmasi->mutasiManual($obat, JenisMutasi::Masuk, $stok, 'Stok awal', $admin);
            $obats[$kode] = $obat;
        }

        // Katalog treatment: kategori, durasi + buffer (menit), BHP standar [kode obat => jumlah dalam satuan obat]
        foreach ($this->tindakan() as $kategori => $tindakans) {
            $kategoriId = KategoriTindakan::create(['nama' => $kategori])->id;

            foreach ($tindakans as [$kode, $nama, $tarif, $durasi, $buffer, $bhp]) {
                $tindakan = Tindakan::create(['kode' => $kode, 'nama' => $nama, 'tarif' => $tarif, 'kategori_id' => $kategoriId,
                    'durasi_menit' => $durasi, 'buffer_menit' => $buffer]);

                foreach ($bhp as $kodeObat => $jumlah) {
                    $tindakan->bhps()->create(['obat_id' => $obats[$kodeObat]->id, 'jumlah' => $jumlah]);
                }
            }
        }

        // Faker hanya tersedia di dependensi dev; image produksi dilewati tanpa pasien acak.
        if (class_exists(Factory::class)) {
            Pasien::factory(25)->create();
        }
    }

    private function icd10(): array
    {
        return [
            ['A09', 'Diare dan gastroenteritis yang diduga infeksi'],
            ['B35.4', 'Tinea corporis'],
            ['E11.9', 'Diabetes melitus tipe 2 tanpa komplikasi'],
            ['E78.5', 'Hiperlipidemia, tidak spesifik'],
            ['I10', 'Hipertensi esensial (primer)'],
            ['J00', 'Nasofaringitis akut (common cold)'],
            ['J02.9', 'Faringitis akut, tidak spesifik'],
            ['J03.9', 'Tonsilitis akut, tidak spesifik'],
            ['J06.9', 'Infeksi saluran pernapasan atas akut, tidak spesifik'],
            ['J45.9', 'Asma, tidak spesifik'],
            ['K02.1', 'Karies dentin'],
            ['K04.0', 'Pulpitis'],
            ['K05.1', 'Gingivitis kronis'],
            ['K08.1', 'Kehilangan gigi karena kecelakaan, ekstraksi atau penyakit periodontal lokal'],
            ['K29.7', 'Gastritis, tidak spesifik'],
            ['K30', 'Dispepsia fungsional'],
            ['L20.9', 'Dermatitis atopik, tidak spesifik'],
            ['L30.9', 'Dermatitis, tidak spesifik'],
            ['M79.1', 'Mialgia'],
            ['M54.5', 'Nyeri punggung bawah'],
            ['N39.0', 'Infeksi saluran kemih, lokasi tidak spesifik'],
            ['O21.0', 'Hiperemesis gravidarum ringan'],
            ['R50.9', 'Demam, tidak spesifik'],
            ['R51', 'Sakit kepala'],
            ['Z00.0', 'Pemeriksaan kesehatan umum'],
            ['Z30.0', 'Konseling dan saran umum kontrasepsi'],
            ['Z34.9', 'Pengawasan kehamilan normal, tidak spesifik'],
        ];
    }

    /** [kategori => [[kode, nama, tarif, durasi, buffer, [kode obat => jumlah BHP]]]] */
    private function tindakan(): array
    {
        return [
            'Pemeriksaan Penunjang' => [
                ['TND-001', 'Pemeriksaan gula darah sewaktu', 25000, 10, 0, []],
                ['TND-002', 'Pemeriksaan kolesterol total', 35000, 10, 0, []],
                ['TND-003', 'Pemeriksaan asam urat', 25000, 10, 0, []],
                ['TND-008', 'EKG', 75000, 20, 5, []],
            ],
            'Tindakan Umum' => [
                ['TND-004', 'Nebulizer', 50000, 20, 5, []],
                ['TND-005', 'Perawatan luka ringan', 40000, 20, 5, []],
                ['TND-006', 'Jahit luka (hecting) < 5 jahitan', 100000, 30, 10, []],
                ['TND-007', 'Injeksi', 30000, 10, 0, ['OBT-024' => 1]],
            ],
            'Perawatan Gigi' => [
                ['TND-101', 'Tambal gigi komposit', 200000, 45, 15, []],
                ['TND-102', 'Cabut gigi permanen', 150000, 30, 15, []],
                ['TND-103', 'Scaling', 250000, 45, 15, []],
            ],
            'Kesehatan Ibu & Anak' => [
                ['TND-201', 'USG kehamilan', 150000, 20, 5, []],
                ['TND-202', 'Pemasangan KB suntik', 35000, 10, 0, ['OBT-024' => 1]],
            ],
            'Injeksi Estetika' => [
                ['TRT-001', 'Botulinum toxin dahi & glabella', 3500000, 30, 10, ['OBT-021' => 0.3, 'OBT-024' => 2]],
                ['TRT-002', 'Filler asam hialuronat (per 1 ml)', 4500000, 45, 15, ['OBT-022' => 1, 'OBT-023' => 0.2]],
            ],
            'Laser & Energy Device' => [
                ['TRT-011', 'Laser toning wajah', 1200000, 45, 15, ['OBT-023' => 0.2]],
                ['TRT-012', 'IPL photo rejuvenation', 900000, 45, 15, []],
            ],
            'Facial & Peeling' => [
                ['TRT-021', 'Facial acne', 350000, 60, 10, []],
                ['TRT-022', 'Chemical peeling wajah', 500000, 45, 10, []],
            ],
        ];
    }

    private function obat(): array
    {
        return [
            ['OBT-001', 'Paracetamol 500 mg', 'tablet', 500, 500],
            ['OBT-002', 'Amoxicillin 500 mg', 'kapsul', 1000, 300],
            ['OBT-003', 'Ibuprofen 400 mg', 'tablet', 800, 200],
            ['OBT-004', 'Cetirizine 10 mg', 'tablet', 700, 150],
            ['OBT-005', 'Omeprazole 20 mg', 'kapsul', 1500, 200],
            ['OBT-006', 'Antasida DOEN', 'tablet', 300, 300],
            ['OBT-007', 'Amlodipine 5 mg', 'tablet', 600, 250],
            ['OBT-008', 'Metformin 500 mg', 'tablet', 500, 250],
            ['OBT-009', 'Captopril 25 mg', 'tablet', 400, 15],
            ['OBT-010', 'Salbutamol 2 mg', 'tablet', 400, 100],
            ['OBT-011', 'Ambroxol 30 mg', 'tablet', 500, 200],
            ['OBT-012', 'CTM 4 mg', 'tablet', 200, 300],
            ['OBT-013', 'Dexamethasone 0,5 mg', 'tablet', 300, 10],
            ['OBT-014', 'Asam Mefenamat 500 mg', 'tablet', 600, 200],
            ['OBT-015', 'Vitamin B Kompleks', 'tablet', 200, 400],
            ['OBT-016', 'Oralit', 'sachet', 1000, 100],
            ['OBT-017', 'Zinc 20 mg', 'tablet', 800, 100],
            ['OBT-018', 'Tablet Tambah Darah', 'tablet', 300, 300],
            ['OBT-019', 'Miconazole Cream 2%', 'tube', 12000, 30],
            ['OBT-020', 'OBH Sirup 100 ml', 'botol', 15000, 40],
            // Bahan habis pakai treatment estetika (stok minimum khusus)
            ['OBT-021', 'Botulinum Toxin Type A 100U', 'vial', 3000000, 10, 3],
            ['OBT-022', 'Filler Asam Hialuronat 1 ml', 'syringe', 3500000, 10, 3],
            ['OBT-023', 'Krim Anestesi Lidocaine 5% 30 g', 'tube', 150000, 20, 5],
            ['OBT-024', 'Spuit 1 ml', 'pcs', 3000, 200, 50],
        ];
    }
}
