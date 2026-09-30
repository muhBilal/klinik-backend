<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Peran bawaan saat migration dijalankan (snapshot, jangan diubah — perubahan izin berikutnya lewat migration baru).
     * `users.role` tetap menyimpan kode peran, sehingga akun lama langsung mendapat izin yang setara dengan
     * perilaku sebelum RBAC dinamis.
     */
    private const PERAN = [
        ['kode' => 'admin', 'nama' => 'Administrator', 'sistem' => true, 'penuh' => true, 'izin' => [],
            'deskripsi' => 'Akses penuh ke seluruh fitur.'],
        ['kode' => 'pendaftaran', 'nama' => 'Petugas Pendaftaran', 'sistem' => true, 'penuh' => false,
            'izin' => ['pasien.lihat', 'pasien.kelola', 'kunjungan.daftar'],
            'deskripsi' => 'Front office: data pasien (non-klinis) & pendaftaran kunjungan.'],
        ['kode' => 'perawat', 'nama' => 'Perawat', 'sistem' => true, 'penuh' => false,
            'izin' => ['pasien.lihat', 'pemeriksaan.panggil', 'pemeriksaan.vital', 'rme.lihat', 'berkas.kelola'],
            'deskripsi' => 'Tanda vital, anamnesis, lampiran klinis.'],
        ['kode' => 'dokter', 'nama' => 'Dokter', 'sistem' => true, 'penuh' => false,
            'izin' => ['pasien.lihat', 'pemeriksaan.panggil', 'pemeriksaan.vital', 'pemeriksaan.dokter', 'rme.lihat', 'berkas.kelola'],
            'deskripsi' => 'Pemeriksaan lengkap: SOAP, diagnosa, tindakan, resep.'],
        ['kode' => 'apoteker', 'nama' => 'Apoteker', 'sistem' => true, 'penuh' => false,
            'izin' => ['farmasi.resep', 'farmasi.obat'],
            'deskripsi' => 'Resep, penyerahan obat & stok.'],
        ['kode' => 'kasir', 'nama' => 'Kasir', 'sistem' => true, 'penuh' => false,
            'izin' => ['kasir.tagihan', 'laporan.keuangan'],
            'deskripsi' => 'Tagihan & pembayaran.'],
        // Peran tambahan sesuai persona PRD; boleh diubah/dihapus admin.
        ['kode' => 'terapis', 'nama' => 'Terapis / Beautician', 'sistem' => false, 'penuh' => false,
            'izin' => ['pasien.lihat', 'pemeriksaan.panggil', 'pemeriksaan.vital', 'rme.lihat', 'berkas.kelola'],
            'deskripsi' => 'Pelaksana tindakan estetika.'],
        ['kode' => 'manajer', 'nama' => 'Manajer Cabang', 'sistem' => false, 'penuh' => false,
            'izin' => ['pasien.lihat', 'laporan.keuangan', 'audit.lihat'],
            'deskripsi' => 'Pemantauan operasional & keuangan cabang.'],
        ['kode' => 'marketing', 'nama' => 'Marketing / CRM', 'sistem' => false, 'penuh' => false,
            'izin' => ['pasien.lihat'],
            'deskripsi' => 'Data kontak pasien tanpa akses rekam medis.'],
    ];

    public function up(): void
    {
        Schema::create('perans', function (Blueprint $table) {
            $table->id();
            $table->string('kode', 30)->unique();
            $table->string('nama', 100);
            $table->string('deskripsi')->nullable();
            $table->boolean('is_sistem')->default(false)->comment('Peran bawaan: kode tidak bisa diubah & tidak bisa dihapus');
            $table->boolean('akses_penuh')->default(false)->comment('Semua izin tanpa daftar (administrator)');
            $table->timestamps();
        });

        Schema::create('peran_izins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('peran_id')->constrained('perans')->cascadeOnDelete();
            $table->string('izin', 50);

            $table->unique(['peran_id', 'izin']);
            $table->index('izin');
        });

        $now = now();

        foreach (self::PERAN as $peran) {
            $id = DB::table('perans')->insertGetId([
                'kode' => $peran['kode'],
                'nama' => $peran['nama'],
                'deskripsi' => $peran['deskripsi'],
                'is_sistem' => $peran['sistem'],
                'akses_penuh' => $peran['penuh'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('peran_izins')->insert(array_map(fn ($izin) => ['peran_id' => $id, 'izin' => $izin], $peran['izin']));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('peran_izins');
        Schema::dropIfExists('perans');
    }
};
