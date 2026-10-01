<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Foto klinis terstruktur (PRD FT-01, FT-02, FT-04, RM-04).
 *
 * - `protokol_fotos`: template posisi foto standar (wajah depan/45°/profil, gigi intraoral, tubuh), bisa dipasang ke treatment.
 * - `berkas` (+): metadata foto — protokol, posisi, tahap (sebelum/sesudah/kontrol), tindakan kunjungan, waktu ambil, thumbnail
 *   terenkripsi (dibuat di browser karena image server tidak punya GD/Imagick).
 * - `persetujuan_fotos`: consent foto bertingkat per pasien (klinis ⊂ edukasi ⊂ marketing), bertanda tangan, bisa diganti/dicabut.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('protokol_fotos', function (Blueprint $table) {
            $table->id();
            $table->string('nama', 100);
            $table->string('deskripsi')->nullable();
            // [{kode, label, petunjuk}] — urutan = urutan pengambilan
            $table->json('posisi');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('tindakans', function (Blueprint $table) {
            $table->foreignId('protokol_foto_id')->nullable()->after('jenis_catatan')->constrained('protokol_fotos')->nullOnDelete();
        });

        Schema::table('berkas', function (Blueprint $table) {
            $table->foreignId('protokol_foto_id')->nullable()->after('kategori')->constrained('protokol_fotos')->nullOnDelete();
            $table->string('posisi', 30)->nullable()->after('protokol_foto_id')->comment('Kode posisi di protokol, mis. depan / kanan_45');
            $table->string('tahap', 10)->nullable()->after('posisi')->comment('sebelum / sesudah / kontrol');
            $table->foreignId('kunjungan_tindakan_id')->nullable()->after('kunjungan_id')->constrained('kunjungan_tindakans')->nullOnDelete();
            $table->timestamp('diambil_at')->nullable()->after('ukuran');
            $table->unsignedSmallInteger('lebar')->nullable()->after('diambil_at');
            $table->unsignedSmallInteger('tinggi')->nullable()->after('lebar');
            $table->string('thumbnail_path')->nullable()->after('path');
        });

        Schema::create('persetujuan_fotos', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('pasien_id')->constrained('pasiens')->restrictOnDelete();
            $table->foreignId('cabang_id')->nullable()->constrained('cabangs')->nullOnDelete();
            $table->foreignId('kunjungan_id')->nullable()->constrained('kunjungans')->nullOnDelete();
            $table->string('tingkat', 10)->comment('klinis / edukasi / marketing (bertingkat)');
            $table->text('isi')->comment('Naskah yang ditandatangani (snapshot)');
            $table->string('status', 10)->comment('berlaku / diganti / dicabut');
            $table->string('penandatangan_nama', 150);
            $table->string('hubungan', 20);
            $table->longText('ttd')->comment('PNG data URL, terenkripsi');
            $table->foreignId('dibuat_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('ditandatangani_at');
            $table->timestamp('berakhir_at')->nullable()->comment('Diganti persetujuan baru / dicabut');
            $table->foreignId('dicabut_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->string('alasan_cabut', 500)->nullable();
            $table->string('checksum', 64);
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['pasien_id', 'status']);
        });

        $now = now();
        DB::table('protokol_fotos')->insert(array_map(fn ($p) => [
            'nama' => $p[0], 'deskripsi' => $p[1], 'posisi' => json_encode($p[2]), 'is_active' => true,
            'created_at' => $now, 'updated_at' => $now,
        ], self::PROTOKOL));
    }

    public function down(): void
    {
        Schema::dropIfExists('persetujuan_fotos');

        Schema::table('berkas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('kunjungan_tindakan_id');
            $table->dropConstrainedForeignId('protokol_foto_id');
            $table->dropColumn(['posisi', 'tahap', 'diambil_at', 'lebar', 'tinggi', 'thumbnail_path']);
        });

        Schema::table('tindakans', fn (Blueprint $table) => $table->dropConstrainedForeignId('protokol_foto_id'));
        Schema::dropIfExists('protokol_fotos');
    }

    /** Protokol dasar; klinik bisa menambah/mengubah di menu Rekam Medis → Protokol Foto. */
    private const PROTOKOL = [
        ['Wajah standar', 'Foto before-after wajah: depan, 45° dan profil (FT-01)', [
            ['kode' => 'depan', 'label' => 'Depan', 'petunjuk' => 'Wajah lurus ke kamera, rambut disingkirkan, ekspresi netral'],
            ['kode' => 'kanan_45', 'label' => '45° kanan', 'petunjuk' => 'Pasien menoleh 45° ke kirinya sehingga sisi kanan wajah tampak'],
            ['kode' => 'kiri_45', 'label' => '45° kiri', 'petunjuk' => 'Pasien menoleh 45° ke kanannya sehingga sisi kiri wajah tampak'],
            ['kode' => 'kanan_90', 'label' => 'Profil kanan', 'petunjuk' => 'Sisi kanan wajah tegak lurus kamera'],
            ['kode' => 'kiri_90', 'label' => 'Profil kiri', 'petunjuk' => 'Sisi kiri wajah tegak lurus kamera'],
        ]],
        ['Wajah dinamis (injeksi)', 'Ekspresi untuk evaluasi toksin botulinum', [
            ['kode' => 'depan', 'label' => 'Depan netral', 'petunjuk' => 'Ekspresi netral'],
            ['kode' => 'angkat_alis', 'label' => 'Angkat alis', 'petunjuk' => 'Pasien mengangkat alis setinggi mungkin (kerutan dahi)'],
            ['kode' => 'mengernyit', 'label' => 'Mengernyit', 'petunjuk' => 'Pasien mengernyitkan dahi (glabella)'],
            ['kode' => 'senyum_lebar', 'label' => 'Senyum lebar', 'petunjuk' => "Senyum lebar dengan mata menyipit (crow's feet)"],
        ]],
        ['Lesi / area close-up', 'Foto dekat lesi kulit dengan skala', [
            ['kode' => 'overview', 'label' => 'Overview area', 'petunjuk' => 'Seluruh area agar lokasi lesi terlihat'],
            ['kode' => 'close_up', 'label' => 'Close-up', 'petunjuk' => 'Jarak dekat, sertakan penggaris/skala bila ada'],
        ]],
        ['Gigi intraoral', 'Foto intraoral standar kedokteran gigi', [
            ['kode' => 'frontal', 'label' => 'Frontal', 'petunjuk' => 'Gigi oklusi, retraktor pipi terpasang'],
            ['kode' => 'lateral_kanan', 'label' => 'Lateral kanan', 'petunjuk' => 'Sisi kanan, oklusi'],
            ['kode' => 'lateral_kiri', 'label' => 'Lateral kiri', 'petunjuk' => 'Sisi kiri, oklusi'],
            ['kode' => 'oklusal_atas', 'label' => 'Oklusal atas', 'petunjuk' => 'Memakai kaca oklusal, rahang atas'],
            ['kode' => 'oklusal_bawah', 'label' => 'Oklusal bawah', 'petunjuk' => 'Memakai kaca oklusal, rahang bawah'],
        ]],
        ['Tubuh', 'Area tubuh (lesi luas, body contouring)', [
            ['kode' => 'depan', 'label' => 'Depan', 'petunjuk' => 'Berdiri tegak menghadap kamera'],
            ['kode' => 'belakang', 'label' => 'Belakang', 'petunjuk' => 'Membelakangi kamera'],
            ['kode' => 'kanan', 'label' => 'Samping kanan', 'petunjuk' => 'Sisi kanan menghadap kamera'],
            ['kode' => 'kiri', 'label' => 'Samping kiri', 'petunjuk' => 'Sisi kiri menghadap kamera'],
        ]],
    ];
};
