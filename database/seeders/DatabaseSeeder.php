<?php

namespace Database\Seeders;

use App\Enums\JenisMutasi;
use App\Enums\Role;
use App\Models\Icd10;
use App\Models\Obat;
use App\Models\Pasien;
use App\Models\Poli;
use App\Models\Tindakan;
use App\Models\User;
use App\Services\FarmasiService;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(FarmasiService $farmasi): void
    {
        $polis = collect([
            ['kode' => 'UMUM', 'nama' => 'Poli Umum', 'tarif_konsultasi' => 50000],
            ['kode' => 'GIGI', 'nama' => 'Poli Gigi', 'tarif_konsultasi' => 75000],
            ['kode' => 'KIA', 'nama' => 'Poli KIA', 'tarif_konsultasi' => 60000],
        ])->map(fn ($p) => Poli::create($p))->keyBy('kode');

        // Semua akun demo memakai password: password
        $users = [
            ['name' => 'Administrator', 'email' => 'admin@eklinik.test', 'role' => Role::Admin],
            ['name' => 'Siti Pendaftaran', 'email' => 'pendaftaran@eklinik.test', 'role' => Role::Pendaftaran],
            ['name' => 'Ns. Rina Perawat', 'email' => 'perawat@eklinik.test', 'role' => Role::Perawat],
            ['name' => 'dr. Andi Wijaya', 'email' => 'dokter@eklinik.test', 'role' => Role::Dokter, 'poli_id' => $polis['UMUM']->id, 'sip' => '503/SIP-DU/001/2026'],
            ['name' => 'drg. Maya Sari', 'email' => 'dokter.gigi@eklinik.test', 'role' => Role::Dokter, 'poli_id' => $polis['GIGI']->id, 'sip' => '503/SIP-DG/002/2026'],
            ['name' => 'dr. Lestari, Sp.OG', 'email' => 'dokter.kia@eklinik.test', 'role' => Role::Dokter, 'poli_id' => $polis['KIA']->id, 'sip' => '503/SIP-DS/003/2026'],
            ['name' => 'Budi Apoteker, S.Farm', 'email' => 'apoteker@eklinik.test', 'role' => Role::Apoteker],
            ['name' => 'Dewi Kasir', 'email' => 'kasir@eklinik.test', 'role' => Role::Kasir],
        ];

        foreach ($users as $user) {
            User::factory()->create($user);
        }

        $admin = User::where('role', Role::Admin)->first();

        foreach ($this->icd10() as [$kode, $nama]) {
            Icd10::create(compact('kode', 'nama'));
        }

        foreach ($this->tindakan() as [$kode, $nama, $tarif]) {
            Tindakan::create(compact('kode', 'nama', 'tarif'));
        }

        foreach ($this->obat() as [$kode, $nama, $satuan, $harga, $stok]) {
            $obat = Obat::create(['kode' => $kode, 'nama' => $nama, 'satuan' => $satuan, 'harga' => $harga, 'stok_minimum' => 20]);
            $farmasi->mutasiManual($obat, JenisMutasi::Masuk, $stok, 'Stok awal', $admin);
        }

        Pasien::factory(25)->create();
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

    private function tindakan(): array
    {
        return [
            ['TND-001', 'Pemeriksaan gula darah sewaktu', 25000],
            ['TND-002', 'Pemeriksaan kolesterol total', 35000],
            ['TND-003', 'Pemeriksaan asam urat', 25000],
            ['TND-004', 'Nebulizer', 50000],
            ['TND-005', 'Perawatan luka ringan', 40000],
            ['TND-006', 'Jahit luka (hecting) < 5 jahitan', 100000],
            ['TND-007', 'Injeksi', 30000],
            ['TND-008', 'EKG', 75000],
            ['TND-101', 'Tambal gigi komposit', 200000],
            ['TND-102', 'Cabut gigi permanen', 150000],
            ['TND-103', 'Scaling', 250000],
            ['TND-201', 'USG kehamilan', 150000],
            ['TND-202', 'Pemasangan KB suntik', 35000],
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
        ];
    }
}
