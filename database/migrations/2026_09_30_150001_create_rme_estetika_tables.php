<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RME estetika (PRD RM-01/02/03/05/07, DR-03, ES-01/02, 7.1 SIP).
 *
 * - Template SOAP per poli / treatment, kode tindakan ICD-9-CM, favorit kode per dokter.
 * - Informed consent per tindakan dengan tanda tangan pasien (isi & tanda tangan di-snapshot, tanda tangan terenkripsi).
 * - Catatan tindakan: area, parameter alat (laser), face chart injeksi (titik, dosis, produk & batch).
 * - Tanda tangan elektronik RME saat pemeriksaan ditutup + addendum (koreksi setelah dikunci).
 * - Akses terbatas kunjungan (kasus IMS/HIV).
 */
return new class extends Migration
{
    /** Kode ICD-10 yang otomatis membuat kunjungan berakses terbatas (IMS, HIV). Sama dengan Icd10::kodeSensitif(). */
    private const POLA_SENSITIF = '/^(A5\d|A6[0-4]|B2[0-4]|Z21|R75)/';

    public function up(): void
    {
        // Kode tindakan/prosedur ICD-9-CM (RM-02; juga dipakai resource Procedure SATUSEHAT).
        Schema::create('icd9cms', function (Blueprint $table) {
            $table->id();
            $table->string('kode', 10)->unique();
            $table->string('nama');
            $table->timestamps();
        });

        Schema::table('icd10s', function (Blueprint $table) {
            $table->boolean('sensitif')->default(false)->after('nama')
                ->comment('Diagnosa rahasia (IMS, HIV): kunjungan otomatis berakses terbatas');
        });

        // Template naskah informed consent (RM-03). Placeholder {nama_pasien}, {tindakan}, ... diisi saat ditandatangani.
        Schema::create('template_consents', function (Blueprint $table) {
            $table->id();
            $table->string('nama', 150);
            $table->text('isi');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('tindakans', function (Blueprint $table) {
            $table->foreignId('icd9cm_id')->nullable()->after('kategori_id')->constrained('icd9cms')->nullOnDelete();
            $table->foreignId('template_consent_id')->nullable()->after('icd9cm_id')->constrained('template_consents')->nullOnDelete()
                ->comment('Diisi = treatment wajib informed consent');
            $table->string('jenis_catatan', 10)->default('umum')->after('template_consent_id')
                ->comment('umum / injeksi (face chart) / energi (parameter alat)');
        });

        // Template SOAP per spesialisasi (poli) dan per treatment (RM-01, DR-03).
        Schema::create('template_soaps', function (Blueprint $table) {
            $table->id();
            $table->string('nama', 150);
            $table->foreignId('poli_id')->nullable()->constrained('polis')->nullOnDelete()->comment('null = semua poli');
            $table->foreignId('tindakan_id')->nullable()->constrained('tindakans')->nullOnDelete();
            $table->text('subjektif')->nullable();
            $table->text('objektif')->nullable();
            $table->text('asesmen')->nullable();
            $table->text('plan')->nullable();
            $table->json('icd10_ids')->nullable()->comment('Saran diagnosa');
            $table->boolean('akses_terbatas')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('poli_id');
        });

        // Kunjungan berakses terbatas: isi RME hanya untuk tim yang menangani & pemegang rme.terbatas (DR-03).
        Schema::table('kunjungans', function (Blueprint $table) {
            $table->boolean('akses_terbatas')->default(false)->after('keluhan');
        });

        // Petugas pelaksana (AN-03, dasar komisi) & kode ICD-9-CM per tindakan.
        Schema::table('kunjungan_tindakans', function (Blueprint $table) {
            $table->foreignId('petugas_id')->nullable()->after('tarif')->constrained('users')->nullOnDelete();
            $table->foreignId('icd9cm_id')->nullable()->after('petugas_id')->constrained('icd9cms')->restrictOnDelete();
        });

        // Tanda tangan elektronik RME (RM-07): dikunci setelah ditandatangani; hash untuk verifikasi keutuhan.
        Schema::table('pemeriksaans', function (Blueprint $table) {
            $table->timestamp('ditandatangani_at')->nullable();
            $table->foreignId('ditandatangani_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->string('hash_ttd', 64)->nullable();
        });

        // Koreksi RME setelah ditandatangani. Append-only: tidak bisa diubah/dihapus.
        Schema::create('pemeriksaan_addendums', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pemeriksaan_id')->constrained('pemeriksaans')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('bagian', 20);
            $table->text('isi');
            $table->string('alasan', 500);
            $table->timestamp('created_at')->nullable();

            $table->index('pemeriksaan_id');
        });

        // Catatan tindakan (RM-05): satu per tindakan kunjungan.
        Schema::create('catatan_tindakans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kunjungan_tindakan_id')->unique()->constrained('kunjungan_tindakans')->cascadeOnDelete();
            $table->string('jenis', 10);
            $table->string('area')->nullable();
            $table->text('catatan')->nullable();
            // Parameter energy device (ES-02): panjang gelombang, fluence, spot size, jumlah shot, reaksi kulit, ...
            $table->json('parameter')->nullable();
            $table->foreignId('sumber_daya_id')->nullable()->constrained('sumber_dayas')->nullOnDelete()->comment('Alat yang dipakai');
            $table->foreignId('dicatat_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Face chart injeksi (ES-01): titik suntik pada diagram wajah, koordinat relatif 0..1.
        Schema::create('catatan_tindakan_titiks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catatan_tindakan_id')->constrained('catatan_tindakans')->cascadeOnDelete();
            $table->string('tampilan', 10)->default('depan');
            $table->decimal('x', 5, 4);
            $table->decimal('y', 5, 4);
            $table->string('area', 100)->nullable();
            $table->foreignId('obat_id')->nullable()->constrained('obats')->restrictOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained('stok_batches')->nullOnDelete();
            $table->decimal('jumlah', 10, 3)->nullable();
            $table->string('satuan', 10)->nullable();
            $table->string('kedalaman', 30)->nullable();
            $table->string('alat', 50)->nullable()->comment('Jarum / kanula + ukuran');
            $table->string('catatan')->nullable();
            $table->timestamps();

            $table->index('catatan_tindakan_id');
        });

        // Informed consent (RM-03). Tidak pernah dihapus; isi & tanda tangan di-snapshot saat ditandatangani.
        Schema::create('informed_consents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('cabang_id')->nullable()->constrained('cabangs')->nullOnDelete();
            $table->foreignId('kunjungan_id')->constrained('kunjungans')->restrictOnDelete();
            $table->foreignId('pasien_id')->constrained('pasiens')->restrictOnDelete();
            $table->foreignId('kunjungan_tindakan_id')->nullable()->constrained('kunjungan_tindakans')->nullOnDelete();
            $table->foreignId('template_consent_id')->nullable()->constrained('template_consents')->nullOnDelete();
            $table->string('judul', 200);
            $table->string('tindakan_nama')->nullable();
            $table->text('isi');
            $table->string('status', 10)->comment('disetujui / ditolak / dicabut');
            $table->string('penandatangan_nama', 150);
            $table->string('hubungan', 20);
            $table->longText('ttd_penandatangan')->comment('PNG data URL, terenkripsi');
            $table->string('saksi_nama', 150)->nullable();
            $table->longText('ttd_saksi')->nullable();
            $table->foreignId('dokter_id')->nullable()->constrained('users')->nullOnDelete()->comment('Pemberi penjelasan');
            $table->foreignId('dibuat_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('ditandatangani_at');
            $table->timestamp('dicabut_at')->nullable();
            $table->foreignId('dicabut_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->string('alasan_cabut', 500)->nullable();
            $table->string('checksum', 64);
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index('kunjungan_id');
            $table->index('pasien_id');
        });

        // Kode diagnosa/tindakan favorit per dokter (RM-02).
        Schema::create('kode_favorits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('jenis', 10)->comment('icd10 / icd9cm');
            $table->unsignedBigInteger('kode_id');
            $table->timestamps();

            $table->unique(['user_id', 'jenis', 'kode_id']);
        });

        // Masa berlaku SIP (UU 17/2023): hanya dokter ber-SIP aktif yang boleh menandatangani RME.
        Schema::table('users', function (Blueprint $table) {
            $table->date('sip_berlaku_sampai')->nullable()->after('sip');
        });

        $this->isiReferensi();
    }

    /** Data referensi minimum: kode ICD-9-CM umum klinik kulit, estetika & gigi, dan penanda ICD-10 sensitif. */
    private function isiReferensi(): void
    {
        $now = now();

        DB::table('icd9cms')->insertOrIgnore(array_map(
            fn ($baris) => ['kode' => $baris[0], 'nama' => $baris[1], 'created_at' => $now, 'updated_at' => $now],
            self::ICD9CM,
        ));

        foreach (DB::table('icd10s')->get(['id', 'kode']) as $icd) {
            if (preg_match(self::POLA_SENSITIF, $icd->kode) === 1) {
                DB::table('icd10s')->where('id', $icd->id)->update(['sensitif' => true]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('sip_berlaku_sampai'));
        Schema::dropIfExists('kode_favorits');
        Schema::dropIfExists('informed_consents');
        Schema::dropIfExists('catatan_tindakan_titiks');
        Schema::dropIfExists('catatan_tindakans');
        Schema::dropIfExists('pemeriksaan_addendums');

        Schema::table('pemeriksaans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ditandatangani_oleh');
            $table->dropColumn(['ditandatangani_at', 'hash_ttd']);
        });

        Schema::table('kunjungan_tindakans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('icd9cm_id');
            $table->dropConstrainedForeignId('petugas_id');
        });

        Schema::table('kunjungans', fn (Blueprint $table) => $table->dropColumn('akses_terbatas'));
        Schema::dropIfExists('template_soaps');

        Schema::table('tindakans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('template_consent_id');
            $table->dropConstrainedForeignId('icd9cm_id');
            $table->dropColumn('jenis_catatan');
        });

        Schema::dropIfExists('template_consents');
        Schema::table('icd10s', fn (Blueprint $table) => $table->dropColumn('sensitif'));
        Schema::dropIfExists('icd9cms');
    }

    /** ICD-9-CM volume 3 yang lazim di klinik rawat jalan kulit, estetika & gigi. */
    private const ICD9CM = [
        ['23.01', 'Pencabutan gigi sulung'],
        ['23.09', 'Pencabutan gigi lainnya'],
        ['23.11', 'Pengangkatan sisa akar gigi'],
        ['23.19', 'Pencabutan gigi secara bedah lainnya (odontektomi)'],
        ['23.2', 'Restorasi gigi dengan tambalan'],
        ['23.3', 'Restorasi gigi dengan inlay'],
        ['23.41', 'Pemasangan mahkota gigi (crown)'],
        ['23.42', 'Pemasangan jembatan cekat (fixed bridge)'],
        ['23.43', 'Pemasangan jembatan lepasan'],
        ['23.49', 'Restorasi gigi lainnya'],
        ['23.5', 'Implantasi gigi'],
        ['23.6', 'Implan gigi prostetik'],
        ['23.70', 'Perawatan saluran akar, tidak spesifik'],
        ['23.71', 'Perawatan saluran akar dengan irigasi'],
        ['23.73', 'Apikoektomi'],
        ['24.0', 'Insisi gusi atau tulang alveolar'],
        ['24.31', 'Eksisi lesi atau jaringan gusi'],
        ['24.7', 'Pemasangan alat ortodonti'],
        ['24.8', 'Operasi ortodonti lainnya (kontrol/penyesuaian)'],
        ['86.01', 'Aspirasi kulit dan jaringan subkutan'],
        ['86.02', 'Injeksi atau tato lesi/defek kulit (termasuk filler)'],
        ['86.04', 'Insisi dengan drainase kulit dan jaringan subkutan'],
        ['86.05', 'Insisi dengan pengangkatan benda asing dari kulit'],
        ['86.11', 'Biopsi tertutup kulit dan jaringan subkutan'],
        ['86.22', 'Debridemen eksisional luka, infeksi, atau luka bakar'],
        ['86.23', 'Pengangkatan kuku, dasar kuku, atau lipatan kuku'],
        ['86.24', 'Kemosurgeri kulit (chemical peeling)'],
        ['86.25', 'Dermabrasi'],
        ['86.27', 'Debridemen kuku, dasar kuku, atau lipatan kuku'],
        ['86.28', 'Debridemen non-eksisional luka, infeksi, atau luka bakar'],
        ['86.3', 'Eksisi lokal atau destruksi lesi kulit lainnya (laser, kauter, krioterapi)'],
        ['86.4', 'Eksisi radikal lesi kulit'],
        ['86.59', 'Penutupan kulit dan jaringan subkutan lokasi lain (jahit luka)'],
        ['86.64', 'Transplantasi rambut'],
        ['86.82', 'Ritidektomi wajah (facelift)'],
        ['86.83', 'Operasi plastik reduksi ukuran'],
        ['86.84', 'Relaksasi parut atau kontraktur kulit'],
        ['86.89', 'Perbaikan dan rekonstruksi kulit lainnya'],
        ['86.92', 'Elektrolisis dan epilasi kulit lainnya (hair removal)'],
        ['86.99', 'Operasi lain pada kulit dan jaringan subkutan'],
        ['87.11', 'Foto rontgen gigi seluruh mulut'],
        ['87.12', 'Foto rontgen gigi lainnya (periapikal, panoramik)'],
        ['88.78', 'USG diagnostik uterus gravid'],
        ['89.01', 'Wawancara dan evaluasi, singkat'],
        ['89.02', 'Wawancara dan evaluasi, terbatas'],
        ['89.03', 'Wawancara dan evaluasi, komprehensif'],
        ['89.06', 'Konsultasi, terbatas'],
        ['89.07', 'Konsultasi, komprehensif'],
        ['89.31', 'Pemeriksaan gigi'],
        ['89.52', 'Elektrokardiogram (EKG)'],
        ['89.7', 'Pemeriksaan fisik umum'],
        ['93.57', 'Aplikasi pembalut luka lainnya'],
        ['93.94', 'Pemberian obat pernapasan dengan nebulizer'],
        ['96.54', 'Scaling, pemolesan, dan debridemen gigi'],
        ['97.35', 'Pelepasan protesa gigi'],
        ['99.21', 'Injeksi antibiotik'],
        ['99.23', 'Injeksi steroid (termasuk intralesi)'],
        ['99.24', 'Injeksi hormon lainnya (termasuk KB suntik)'],
        ['99.29', 'Injeksi/infus zat terapeutik atau profilaksis lain (termasuk toksin botulinum)'],
        ['99.82', 'Terapi sinar ultraviolet (fototerapi)'],
        ['99.83', 'Fototerapi lainnya (IPL, LED)'],
        ['99.97', 'Pemasangan gigi tiruan (denture)'],
    ];
};
