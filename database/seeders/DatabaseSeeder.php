<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Cabang;
use App\Models\Icd10;
use App\Models\Icd9cm;
use App\Models\JadwalPraktik;
use App\Models\KategoriTindakan;
use App\Models\Obat;
use App\Models\Paket;
use App\Models\Pasien;
use App\Models\Poli;
use App\Models\Promo;
use App\Models\ProtokolFoto;
use App\Models\SumberDaya;
use App\Models\TemplateConsent;
use App\Models\TemplateSoap;
use App\Models\Tindakan;
use App\Models\User;
use App\Services\InventoriService;
use Faker\Factory;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(InventoriService $inventori): void
    {
        // Aman dijalankan berulang (mis. setiap container start): lewati bila data sudah ada.
        if (User::exists()) {
            $this->command?->info('Database sudah berisi data, seeder dilewati.');

            return;
        }

        // Satu cabang demo; cabang lain ditambahkan admin di menu Master Cabang.
        $cabang = Cabang::create(['kode' => 'UTAMA', 'nama' => 'Klinik Utama', 'alamat' => 'Jl. Kesehatan No. 1', 'telepon' => '021-5550001',
            'jam_buka' => '08:00', 'jam_tutup' => '21:00']);

        // Poli sesuai cakupan PRD (klinik estetika & spesialis): estetika medis, kulit & kelamin, gigi (bagian 6).
        $polis = collect([
            ['kode' => 'ESTETIKA', 'nama' => 'Poli Estetika Medis', 'spesialisasi' => 'estetika'],
            ['kode' => 'KULIT', 'nama' => 'Poli Kulit & Kelamin', 'spesialisasi' => 'kulit'],
            ['kode' => 'GIGI', 'nama' => 'Poli Gigi & Estetika Gigi', 'spesialisasi' => 'gigi'],
        ])->map(fn ($p) => Poli::create($p))->keyBy('kode');

        // Semua akun demo memakai password: password. Administrator lintas cabang (cabang_id null), staf di cabang utama.
        $users = [
            ['name' => 'Administrator', 'email' => 'admin@eklinik.test', 'role' => Role::Admin->value, 'cabang_id' => null],
            ['name' => 'Siti Pendaftaran', 'email' => 'pendaftaran@eklinik.test', 'role' => Role::Pendaftaran->value],
            ['name' => 'Ns. Rina Perawat', 'email' => 'perawat@eklinik.test', 'role' => Role::Perawat->value],
            ['name' => 'dr. Andi Wijaya', 'email' => 'dokter@eklinik.test', 'role' => Role::Dokter->value, 'poli_id' => $polis['ESTETIKA']->id, 'sip' => '503/SIP-DU/001/2026'],
            ['name' => 'drg. Maya Sari', 'email' => 'dokter.gigi@eklinik.test', 'role' => Role::Dokter->value, 'poli_id' => $polis['GIGI']->id, 'sip' => '503/SIP-DG/002/2026'],
            ['name' => 'dr. Lestari Wulandari, Sp.D.V.E', 'email' => 'dokter.kulit@eklinik.test', 'role' => Role::Dokter->value, 'poli_id' => $polis['KULIT']->id, 'sip' => '503/SIP-DS/003/2026'],
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
            Icd10::create(['kode' => $kode, 'nama' => $nama, 'sensitif' => Icd10::kodeSensitif($kode)]);
        }

        // Naskah informed consent (RM-03); dipasang ke treatment berisiko di bawah.
        $consents = collect($this->templateConsent())->map(fn ($c) => TemplateConsent::create($c));

        $obats = collect();
        foreach ($this->obat() as $item) {
            [$kode, $nama, $satuan, $harga, $stok] = $item;
            $kodeAngka = substr($kode, -3);
            $obat = Obat::create(['kode' => $kode, 'nama' => $nama, 'satuan' => $satuan, 'harga' => $harga,
                'stok_minimum' => $item[5] ?? 20, 'fraksional' => $item[6] ?? false, 'jam_pakai_setelah_buka' => $item[7] ?? null]);

            // Stok awal dibagi dua batch dengan kedaluwarsa berbeda supaya FEFO terlihat di data demo (IN-01).
            $inventori->terima($obat, $cabang->id, ceil($stok / 2), "B{$kodeAngka}-A", now()->addMonths(6), $admin, 'Stok awal');
            $inventori->terima($obat, $cabang->id, floor($stok / 2), "B{$kodeAngka}-B", now()->addMonths(18), $admin, 'Stok awal');
            $obats[$kode] = $obat;
        }

        // Katalog treatment: kategori, durasi + buffer (menit), BHP standar [kode obat => jumlah dalam satuan obat],
        // plus kode ICD-9-CM default, bentuk catatan tindakan, dan template consent (RM-02, RM-03, RM-05).
        $icd9cm = Icd9cm::pluck('id', 'kode');
        $rme = $this->rmeTindakan();
        // Protokol foto dibuat migration (data referensi); dipasang ke treatment yang lazim didokumentasikan before-after.
        $protokol = ProtokolFoto::pluck('id', 'nama');
        $protokolTindakan = [
            'TRT-001' => 'Wajah dinamis (injeksi)', 'TRT-002' => 'Wajah standar', 'TRT-011' => 'Wajah standar',
            'TRT-012' => 'Wajah standar', 'TRT-021' => 'Wajah standar', 'TRT-022' => 'Wajah standar',
            'TND-101' => 'Gigi intraoral', 'TND-102' => 'Gigi intraoral', 'TND-103' => 'Gigi intraoral',
            'TND-105' => 'Gigi intraoral', 'TND-106' => 'Gigi intraoral',
        ];
        // Tindakan per gigi (DG-07) + kondisi odontogram setelah dikerjakan (DG-01); null = per gigi tanpa kondisi otomatis.
        $tindakanGigi = [
            'TND-101' => 'cof', 'TND-102' => 'mis', 'TND-104' => 'gif', 'TND-105' => 'rct', 'TND-106' => 'poc',
            'TND-107' => 'mis', 'TND-108' => 'fis',
        ];

        // Komisi per treatment contoh (KM-01) — tiap klinik wajib menyesuaikan di master treatment. Bawaan dokter 10%; per kategori /
        // treatment menimpa seluruhnya (peran yang tidak disebut = tanpa komisi). [peran => [jenis, nilai]]
        $komisiKategori = [
            'Konsultasi' => ['dokter' => ['persen', 40]],
            'Facial & Peeling' => ['terapis' => ['persen', 10]],
            'Laser & Energy Device' => ['dokter' => ['persen', 5], 'terapis' => ['nominal', 50000]],
            'Perawatan Gigi' => ['dokter' => ['persen', 30], 'asisten' => ['nominal', 10000]],
        ];
        $komisiTindakan = ['TRT-001' => ['dokter' => ['persen', 15], 'asisten' => ['nominal', 25000]]];

        foreach ($this->tindakan() as $kategori => $tindakans) {
            $kategoriId = KategoriTindakan::create(['nama' => $kategori])->id;

            foreach ($tindakans as [$kode, $nama, $tarif, $durasi, $buffer, $bhp]) {
                [$kodeIcd9, $jenisCatatan, $consent] = $rme[$kode] ?? [null, 'umum', null];
                $tindakan = Tindakan::create(['kode' => $kode, 'nama' => $nama, 'tarif' => $tarif, 'kategori_id' => $kategoriId,
                    'durasi_menit' => $durasi, 'buffer_menit' => $buffer, 'icd9cm_id' => $kodeIcd9 ? $icd9cm[$kodeIcd9] : null,
                    'jenis_catatan' => $jenisCatatan, 'template_consent_id' => $consent ? $consents[$consent]->id : null,
                    'protokol_foto_id' => isset($protokolTindakan[$kode]) ? $protokol[$protokolTindakan[$kode]] : null,
                    'per_gigi' => array_key_exists($kode, $tindakanGigi), 'kondisi_gigi_hasil' => $tindakanGigi[$kode] ?? null]);

                foreach ($bhp as $kodeObat => $jumlah) {
                    $tindakan->bhps()->create(['obat_id' => $obats[$kodeObat]->id, 'jumlah' => $jumlah]);
                }
                foreach ($komisiTindakan[$kode] ?? $komisiKategori[$kategori] ?? ['dokter' => ['persen', 10]] as $peran => [$jenis, $nilai]) {
                    $tindakan->komisis()->create(['peran' => $peran, 'jenis' => $jenis, 'nilai' => $nilai]);
                }
            }
        }

        // Jasa konsultasi dokter per poli = treatment kategori Konsultasi (harga per cabang & komisi dokter diatur di katalog).
        foreach (['ESTETIKA' => 'KNS-001', 'KULIT' => 'KNS-002', 'GIGI' => 'KNS-003'] as $kodePoli => $kodeTindakan) {
            $polis[$kodePoli]->update(['tindakan_konsultasi_id' => Tindakan::where('kode', $kodeTindakan)->value('id')]);
        }

        // Paket multi-sesi (TR-02) & voucher/promo (TR-06) contoh
        $idTindakan = Tindakan::pluck('id', 'kode');
        foreach ([
            ['PKT-LSR6', 'Laser toning 6x', 6000000, 180, ['TRT-011' => 6]],
            ['PKT-GLOW', 'Glowing facial 4x + peeling 2x', 2000000, 120, ['TRT-021' => 4, 'TRT-022' => 2]],
            ['PKT-SCL2', 'Scaling 2x setahun', 450000, 365, ['TND-103' => 2]],
        ] as [$kode, $nama, $harga, $hari, $isi]) {
            $paket = Paket::create(['kode' => $kode, 'nama' => $nama, 'harga' => $harga, 'masa_berlaku_hari' => $hari]);
            foreach ($isi as $kodeTindakan => $sesi) {
                $paket->items()->create(['tindakan_id' => $idTindakan[$kodeTindakan], 'jumlah_sesi' => $sesi]);
            }
        }
        Promo::create(['kode' => 'WELCOME10', 'nama' => 'Diskon 10% pasien baru', 'jenis' => 'persen', 'nilai' => 10,
            'maks_potongan' => 100000, 'min_transaksi' => 200000, 'kuota_per_pasien' => 1, 'mulai' => today(), 'berakhir' => today()->addMonths(3)]);
        Promo::create(['kode' => 'LASER200', 'nama' => 'Potongan Rp200rb treatment & paket laser', 'jenis' => 'nominal', 'nilai' => 200000,
            'kuota' => 50, 'mulai' => today(), 'tindakan_ids' => [$idTindakan['TRT-011']],
            'paket_ids' => [Paket::where('kode', 'PKT-LSR6')->value('id')]]);

        // Template SOAP per spesialisasi & treatment (RM-01, DR-03)
        $icd10 = Icd10::pluck('id', 'kode');
        foreach ($this->templateSoap() as $template) {
            TemplateSoap::create([
                ...collect($template)->except(['poli', 'tindakan', 'diagnosa'])->all(),
                'poli_id' => isset($template['poli']) ? $polis[$template['poli']]->id : null,
                'tindakan_id' => isset($template['tindakan']) ? Tindakan::where('kode', $template['tindakan'])->value('id') : null,
                'icd10_ids' => collect($template['diagnosa'] ?? [])->map(fn ($kode) => $icd10[$kode])->all() ?: null,
            ]);
        }

        // Ruang & alat yang bisa dibooking (BK-01)
        foreach ([
            ['RG-01', 'Ruang Tindakan 1', 'ruang'],
            ['RG-02', 'Ruang Tindakan 2', 'ruang'],
            ['RG-GIGI', 'Ruang Gigi', 'ruang'],
            ['LASER-01', 'Mesin Laser', 'alat'],
            ['CHAIR-01', 'Dental Chair', 'alat'],
        ] as [$kode, $nama, $tipe]) {
            SumberDaya::create(['cabang_id' => $cabang->id, 'kode' => $kode, 'nama' => $nama, 'tipe' => $tipe]);
        }

        // Jadwal praktik Senin-Jumat 09:00-17:00, Sabtu 09:00-13:00 untuk dokter & terapis (BK-03)
        $penjadwal = User::whereIn('role', [Role::Dokter->value, 'terapis'])->get();

        foreach ($penjadwal as $petugas) {
            foreach ([1, 2, 3, 4, 5] as $hari) {
                JadwalPraktik::create(['cabang_id' => $cabang->id, 'user_id' => $petugas->id, 'hari' => $hari,
                    'jam_mulai' => '09:00', 'jam_selesai' => '17:00']);
            }
            JadwalPraktik::create(['cabang_id' => $cabang->id, 'user_id' => $petugas->id, 'hari' => 6,
                'jam_mulai' => '09:00', 'jam_selesai' => '13:00']);
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
            // Dermatologi & estetika
            ['B35.6', 'Tinea kruris'],
            ['B36.0', 'Pitiriasis versikolor'],
            ['B37.2', 'Kandidiasis kulit dan kuku'],
            ['L21.9', 'Dermatitis seboroik, tidak spesifik'],
            ['L23.9', 'Dermatitis kontak alergi, penyebab tidak spesifik'],
            ['L24.9', 'Dermatitis kontak iritan, penyebab tidak spesifik'],
            ['L40.0', 'Psoriasis vulgaris'],
            ['L50.9', 'Urtikaria, tidak spesifik'],
            ['L57.8', 'Perubahan kulit akibat paparan sinar kronis (photoaging)'],
            ['L65.9', 'Kerontokan rambut nonsikatrik, tidak spesifik'],
            ['L70.0', 'Akne vulgaris'],
            ['L71.9', 'Rosasea, tidak spesifik'],
            ['L80', 'Vitiligo'],
            ['L81.0', 'Hiperpigmentasi pascainflamasi'],
            ['L81.1', 'Kloasma (melasma)'],
            ['L90.5', 'Parut dan fibrosis kulit (bekas jerawat)'],
            ['L91.0', 'Parut hipertrofik (keloid)'],
            ['Z41.1', 'Tindakan untuk memperbaiki penampilan kosmetik'],
            // Infeksi menular seksual & HIV: otomatis sensitif (kunjungan berakses terbatas)
            ['A51.0', 'Sifilis genital primer'],
            ['A53.9', 'Sifilis, tidak spesifik'],
            ['A54.9', 'Infeksi gonokokus, tidak spesifik'],
            ['A56.0', 'Infeksi klamidia saluran urogenital bawah'],
            ['A59.0', 'Trikomoniasis urogenital'],
            ['A60.0', 'Infeksi herpes genital dan urogenital'],
            ['A63.0', 'Kondiloma akuminata (kutil anogenital)'],
            ['B24', 'Penyakit HIV, tidak spesifik'],
            ['Z21', 'Status infeksi HIV asimtomatik'],
        ];
    }

    /** [kode treatment => [kode ICD-9-CM default, jenis catatan tindakan, kunci template consent]] */
    private function rmeTindakan(): array
    {
        return [
            'KNS-001' => ['89.07', 'umum', null],
            'KNS-002' => ['89.07', 'umum', null],
            'KNS-003' => ['89.07', 'umum', null],
            'TND-004' => ['93.94', 'umum', null],
            'TND-005' => ['93.57', 'umum', null],
            'TND-006' => ['86.59', 'umum', 'umum'],
            'TND-007' => ['99.29', 'umum', null],
            'TND-008' => ['89.52', 'umum', null],
            'TND-101' => ['23.2', 'umum', null],
            'TND-102' => ['23.09', 'umum', 'gigi'],
            'TND-103' => ['96.54', 'umum', null],
            'TND-104' => ['23.2', 'umum', null],
            'TND-105' => ['23.71', 'umum', 'gigi'],
            'TND-106' => ['23.41', 'umum', 'gigi'],
            'TND-107' => ['23.01', 'umum', 'gigi'],
            'TND-108' => ['23.49', 'umum', null],
            'TRT-001' => ['99.29', 'injeksi', 'injeksi'],
            'TRT-002' => ['86.02', 'injeksi', 'injeksi'],
            'TRT-011' => ['86.3', 'energi', 'energi'],
            'TRT-012' => ['99.83', 'energi', 'energi'],
            'TRT-021' => ['86.99', 'umum', null],
            'TRT-022' => ['86.24', 'umum', 'peeling'],
        ];
    }

    /** [kategori => [[kode, nama, tarif, durasi, buffer, [kode obat => jumlah BHP]]]] */
    private function tindakan(): array
    {
        return [
            // Jasa konsultasi dokter; dipasang ke poli sebagai jasa konsultasi (ditagihkan otomatis tiap kunjungan).
            'Konsultasi' => [
                ['KNS-001', 'Konsultasi dokter estetika', 100000, 15, 0, []],
                ['KNS-002', 'Konsultasi dokter spesialis kulit & kelamin', 150000, 20, 0, []],
                ['KNS-003', 'Konsultasi dokter gigi', 75000, 15, 0, []],
            ],
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
                ['TND-104', 'Tambal gigi GIC', 150000, 30, 10, []],
                ['TND-105', 'Perawatan saluran akar (per kunjungan)', 600000, 60, 15, []],
                ['TND-106', 'Mahkota porselen (crown)', 2500000, 60, 15, []],
                ['TND-107', 'Cabut gigi sulung', 100000, 20, 10, []],
                ['TND-108', 'Fissure sealant', 175000, 20, 10, []],
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
            // Fraksional: dipakai sebagian lintas pasien. Vial botulinum terbuka hanya layak 24 jam.
            ['OBT-021', 'Botulinum Toxin Type A 100U', 'vial', 3000000, 10, 3, true, 24],
            ['OBT-022', 'Filler Asam Hialuronat 1 ml', 'syringe', 3500000, 10, 3, true],
            ['OBT-023', 'Krim Anestesi Lidocaine 5% 30 g', 'tube', 150000, 20, 5, true],
            ['OBT-024', 'Spuit 1 ml', 'pcs', 3000, 200, 50],
        ];
    }

    /**
     * Contoh naskah informed consent (unsur penjelasan mengikuti Permenkes 290/2008). Klinik wajib meninjau ulang
     * naskah bersama penanggung jawab medis/legal sebelum dipakai.
     *
     * @return array<string, array{nama: string, isi: string}>
     */
    private function templateConsent(): array
    {
        $pembuka = 'Saya yang bertanda tangan di bawah ini menyatakan telah menerima dan memahami penjelasan dari {dokter} '
            .'mengenai tindakan {tindakan} yang akan dilakukan kepada pasien {nama_pasien} (No. RM {no_rm}, tanggal lahir '
            .'{tanggal_lahir}) di {klinik} — {cabang}.';
        $penutup = 'Saya telah diberi kesempatan bertanya dan seluruh pertanyaan saya telah dijawab dengan jelas. Dengan penuh '
            .'kesadaran dan tanpa paksaan, saya menyatakan keputusan sebagaimana tercantum pada formulir ini.'."\n\n{tanggal}";

        $naskah = fn (array $poin) => implode("\n\n", [$pembuka, 'Penjelasan yang saya terima meliputi:',
            implode("\n", array_map(fn ($p, $i) => ($i + 1).'. '.$p, $poin, array_keys($poin))), $penutup]);

        return [
            'injeksi' => ['nama' => 'Persetujuan Tindakan Injeksi Estetika', 'isi' => $naskah([
                'Tujuan tindakan: memperbaiki kerutan, kontur, atau volume wajah sesuai rencana yang telah didiskusikan.',
                'Tata cara: penyuntikan produk (toksin botulinum atau filler asam hialuronat) pada titik yang telah ditandai, dapat didahului krim anestesi topikal.',
                'Hasil bersifat sementara, berbeda pada tiap individu, dan dapat memerlukan tindakan ulang atau koreksi (touch-up).',
                'Risiko yang mungkin terjadi: kemerahan, bengkak, memar, nyeri, asimetri, benjolan, infeksi, reaksi alergi; kelopak mata turun atau kelemahan otot sementara (toksin botulinum); sumbatan pembuluh darah yang dapat merusak kulit atau penglihatan (filler, jarang).',
                'Alternatif tindakan beserta untung-ruginya, termasuk pilihan untuk tidak melakukan tindakan.',
                'Kewajiban saya memberi tahu kehamilan/menyusui, alergi, penyakit saraf-otot, gangguan pembekuan darah, obat yang sedang dikonsumsi, dan riwayat tindakan estetika sebelumnya.',
            ])],
            'energi' => ['nama' => 'Persetujuan Tindakan Laser & Energy Device', 'isi' => $naskah([
                'Tujuan tindakan: memperbaiki pigmentasi, tekstur, bekas jerawat, kemerahan, atau keluhan lain sesuai rencana terapi.',
                'Tata cara: penyinaran kulit dengan alat laser / IPL / energy device menggunakan parameter yang disesuaikan dengan tipe kulit saya.',
                'Hasil bertahap, umumnya memerlukan beberapa sesi, dan tidak dapat dijamin sama pada setiap orang.',
                'Risiko yang mungkin terjadi: kemerahan, rasa panas, bengkak, krusta, lepuh, hiperpigmentasi atau hipopigmentasi, luka bakar, dan parut (jarang).',
                'Kewajiban saya menghindari paparan matahari, memakai tabir surya, dan memberi tahu pemakaian obat fotosensitif atau isotretinoin.',
                'Alternatif tindakan beserta untung-ruginya, termasuk pilihan untuk tidak melakukan tindakan.',
            ])],
            'peeling' => ['nama' => 'Persetujuan Tindakan Chemical Peeling', 'isi' => $naskah([
                'Tujuan tindakan: mengelupas lapisan kulit untuk memperbaiki jerawat, pigmentasi, atau tekstur kulit.',
                'Tata cara: pengolesan larutan kimia dengan konsentrasi dan waktu kontak tertentu, lalu dinetralkan.',
                'Risiko yang mungkin terjadi: rasa perih, kemerahan, pengelupasan, hiperpigmentasi, infeksi, dan parut (jarang).',
                'Perawatan setelah tindakan: tidak mengelupas kulit secara paksa, memakai pelembap dan tabir surya, serta menghindari scrub.',
                'Alternatif tindakan beserta untung-ruginya, termasuk pilihan untuk tidak melakukan tindakan.',
            ])],
            'gigi' => ['nama' => 'Persetujuan Tindakan Kedokteran Gigi', 'isi' => $naskah([
                'Diagnosis dan tujuan tindakan pada gigi/jaringan mulut sesuai hasil pemeriksaan.',
                'Tata cara tindakan, termasuk pemberian anestesi lokal bila diperlukan.',
                'Risiko yang mungkin terjadi: nyeri, bengkak, perdarahan, infeksi, dry socket, cedera jaringan sekitar, dan reaksi terhadap anestesi.',
                'Instruksi setelah tindakan dan tanda bahaya yang mengharuskan saya kembali ke klinik.',
                'Alternatif tindakan beserta untung-ruginya, termasuk pilihan untuk tidak melakukan tindakan.',
            ])],
            'umum' => ['nama' => 'Persetujuan Tindakan Medis', 'isi' => $naskah([
                'Diagnosis dan tujuan tindakan.',
                'Tata cara tindakan yang akan dilakukan.',
                'Risiko dan komplikasi yang mungkin terjadi.',
                'Prognosis dan hal yang perlu saya lakukan setelah tindakan.',
                'Alternatif tindakan beserta untung-ruginya, termasuk pilihan untuk tidak melakukan tindakan.',
            ])],
        ];
    }

    /** Template SOAP demo per spesialisasi (kode poli) dan per treatment (RM-01, DR-03). */
    private function templateSoap(): array
    {
        return [
            ['nama' => 'Akne vulgaris', 'poli' => 'KULIT', 'diagnosa' => ['L70.0'],
                'subjektif' => "Keluhan jerawat di wajah sejak ... bulan.\nFaktor pencetus: stres / menstruasi / kosmetik / makanan.\nRiwayat pengobatan & skincare: ...",
                'objektif' => "Lokasi: dahi / pipi / dagu / punggung / dada.\nEfloresensi: komedo terbuka/tertutup, papul, pustul, nodul, kista.\nDerajat (Lehmann): ringan / sedang / berat.\nSkar atau hiperpigmentasi pascainflamasi: ya / tidak.",
                'asesmen' => 'Akne vulgaris derajat ...',
                'plan' => "Edukasi perawatan wajah & kosmetik nonkomedogenik.\nTopikal: ...\nOral: ...\nTindakan: ekstraksi komedo / chemical peeling bila perlu.\nKontrol 4 minggu."],
            ['nama' => 'Melasma', 'poli' => 'KULIT', 'diagnosa' => ['L81.1'],
                'subjektif' => "Bercak kecokelatan di wajah sejak ...\nFaktor: paparan matahari / kehamilan / kontrasepsi hormonal / kosmetik.\nPemakaian tabir surya: ...",
                'objektif' => "Makula hiperpigmentasi cokelat, distribusi sentrofasial / malar / mandibular.\nLampu Wood: epidermal / dermal / campuran.\nTipe kulit Fitzpatrick: ...",
                'asesmen' => 'Melasma tipe ...',
                'plan' => "Fotoproteksi: tabir surya SPF 30+ diulang tiap 3 jam.\nTopikal: ...\nTindakan: chemical peeling / laser toning sesuai indikasi.\nKontrol 4-6 minggu."],
            ['nama' => 'Dermatitis (atopik / kontak)', 'poli' => 'KULIT', 'diagnosa' => ['L20.9'],
                'subjektif' => "Gatal dan ruam kemerahan di ... sejak ...\nRiwayat atopi pribadi/keluarga: ...\nKontak dengan bahan (sabun, logam, kosmetik, pekerjaan): ...",
                'objektif' => "Lokasi: ...\nEfloresensi: eritema, papul, vesikel, erosi, krusta, likenifikasi, skuama.\nBatas: tegas / tidak tegas.",
                'asesmen' => 'Dermatitis atopik / kontak alergi / kontak iritan.',
                'plan' => "Hindari pencetus/alergen, pelembap teratur.\nKortikosteroid topikal: ...\nAntihistamin oral: ...\nUji tempel bila perlu."],
            ['nama' => 'Infeksi jamur kulit', 'poli' => 'KULIT', 'diagnosa' => ['B35.4'],
                'subjektif' => "Bercak gatal bersisik di ... sejak ...\nGatal memberat saat berkeringat.\nRiwayat kontak / hewan peliharaan: ...",
                'objektif' => "Plak eritematosa berbatas tegas dengan tepi aktif / makula hipo-hiperpigmentasi berskuama halus.\nLokasi: ...\nKOH 10%: ...",
                'asesmen' => 'Tinea ... / Pitiriasis versikolor.',
                'plan' => "Antijamur topikal: ... selama ... minggu.\nAntijamur oral bila luas: ...\nEdukasi menjaga kulit tetap kering."],
            ['nama' => 'Infeksi menular seksual (akses terbatas)', 'poli' => 'KULIT', 'akses_terbatas' => true,
                'subjektif' => "Keluhan: duh tubuh / luka / benjolan di area genital sejak ...\nRiwayat kontak seksual berisiko: ...\nPasangan bergejala: ya / tidak.\nRiwayat IMS sebelumnya: ...",
                'objektif' => "Pemeriksaan genital: ...\nDuh tubuh (warna, bau, jumlah): ...\nUlkus / vegetasi / limfadenopati inguinal: ...",
                'asesmen' => 'Suspek IMS: ...',
                'plan' => "Pemeriksaan penunjang: ...\nTerapi: ...\nKonseling & tes HIV sukarela.\nNotifikasi & pengobatan pasangan.\nKontrol ..."],
            ['nama' => 'Konsultasi estetika wajah', 'poli' => 'ESTETIKA', 'diagnosa' => ['Z41.1'],
                'subjektif' => "Keinginan estetika: ...\nRiwayat tindakan estetika sebelumnya (jenis, tanggal, klinik): ...\nAlergi, hamil/menyusui, obat pengencer darah, isotretinoin: ...",
                'objektif' => "Tipe kulit Fitzpatrick: ...\nAnalisis wajah: kerutan dinamis/statis, volume, pigmentasi, pori, tekstur.\nFoto klinis sebelum tindakan: sudah / belum.",
                'asesmen' => 'Keluhan estetika: ...',
                'plan' => "Rencana tindakan: ...\nEstimasi sesi & biaya dijelaskan.\nInformed consent.\nInstruksi pra & pasca tindakan."],
            ['nama' => 'Injeksi toksin botulinum', 'poli' => 'ESTETIKA', 'tindakan' => 'TRT-001', 'diagnosa' => ['Z41.1'],
                'subjektif' => "Keinginan mengurangi kerutan di: dahi / glabella / crow's feet / ...\nInjeksi toksin botulinum terakhir: ...\nKontraindikasi (hamil/menyusui, penyakit neuromuskular, infeksi lokal): tidak ada.",
                'objektif' => "Kerutan dinamis: ...\nKerutan statis: ...\nAsimetri: ...",
                'asesmen' => 'Kerutan dinamis wajah, indikasi injeksi toksin botulinum.',
                'plan' => "Injeksi sesuai face chart, dosis total ... U.\nTidak berbaring 4 jam, tidak memijat area 24 jam, hindari olahraga berat 24 jam.\nKontrol 2 minggu (evaluasi / touch-up)."],
            ['nama' => 'Filler asam hialuronat', 'poli' => 'ESTETIKA', 'tindakan' => 'TRT-002', 'diagnosa' => ['Z41.1'],
                'subjektif' => "Keinginan menambah volume / memperbaiki kontur di ...\nRiwayat filler sebelumnya (produk, tanggal): ...\nRiwayat herpes labialis / gangguan pembekuan darah: ...",
                'objektif' => "Area: tear trough / malar / nasolabial / bibir / dagu / jawline.\nDerajat kehilangan volume: ...",
                'asesmen' => 'Kehilangan volume / kontur wajah, indikasi filler asam hialuronat.',
                'plan' => "Injeksi sesuai face chart (produk & batch tercatat), volume total ... ml.\nTanda oklusi vaskular (pucat, nyeri hebat, gangguan penglihatan): segera kembali ke klinik.\nKompres dingin, hindari panas & pijat 1 minggu. Kontrol 2 minggu."],
            ['nama' => 'Laser / energy device', 'poli' => 'ESTETIKA', 'tindakan' => 'TRT-011', 'diagnosa' => ['Z41.1'],
                'subjektif' => "Keluhan: flek / bekas jerawat / pori / kemerahan / rambut berlebih.\nPaparan matahari 2 minggu terakhir: ...\nObat fotosensitif / isotretinoin 6 bulan terakhir: tidak ada.",
                'objektif' => "Tipe kulit Fitzpatrick: ...\nLesi target: ...\nUji spot: dilakukan / tidak.",
                'asesmen' => 'Indikasi tindakan laser / energy device untuk ...',
                'plan' => "Tindakan sesuai parameter alat yang tercatat.\nPelembap, tabir surya, hindari matahari & scrub 1 minggu.\nSesi berikutnya ... minggu lagi."],
            ['nama' => 'Pemeriksaan gigi & mulut', 'poli' => 'GIGI',
                'subjektif' => "Keluhan utama gigi / gusi: ...\nRiwayat sakit gigi, perdarahan gusi, kebiasaan (bruxism, merokok): ...",
                'objektif' => "Ekstraoral: ...\nIntraoral: gigi ..., kondisi ...\nPerkusi / tekan / vitalitas: ...",
                'asesmen' => 'Diagnosis gigi ...',
                'plan' => "Rencana perawatan: ...\nEdukasi kebersihan gigi & mulut.\nKontrol ..."],
        ];
    }
}
