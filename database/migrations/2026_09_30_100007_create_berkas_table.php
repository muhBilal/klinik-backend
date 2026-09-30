<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Metadata berkas terenkripsi (foto klinis, lampiran, informed consent). Isi file ada di disk `berkas`,
        // terenkripsi dengan APP_KEY — kehilangan APP_KEY = berkas tidak bisa dibuka.
        Schema::create('berkas', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('cabang_id')->nullable()->constrained('cabangs')->nullOnDelete();
            $table->foreignId('pasien_id')->constrained('pasiens')->restrictOnDelete();
            $table->foreignId('kunjungan_id')->nullable()->constrained('kunjungans')->restrictOnDelete();
            $table->string('kategori', 30)->index();
            $table->string('keterangan')->nullable();
            $table->string('nama_file');
            $table->string('mime', 100);
            $table->unsignedBigInteger('ukuran')->comment('Byte, sebelum enkripsi');
            $table->string('path');
            $table->char('checksum', 64)->comment('SHA-256 isi asli');
            $table->foreignId('diunggah_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['pasien_id', 'kategori']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('berkas');
    }
};
