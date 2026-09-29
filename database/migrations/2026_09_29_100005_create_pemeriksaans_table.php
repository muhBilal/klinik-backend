<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pemeriksaans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kunjungan_id')->unique()->constrained('kunjungans')->cascadeOnDelete();
            // Tanda vital (diisi perawat)
            $table->string('tekanan_darah', 10)->nullable();
            $table->unsignedSmallInteger('nadi')->nullable();
            $table->decimal('suhu', 4, 1)->nullable();
            $table->unsignedSmallInteger('respirasi')->nullable();
            $table->decimal('berat_badan', 5, 1)->nullable();
            $table->decimal('tinggi_badan', 5, 1)->nullable();
            // SOAP (diisi dokter)
            $table->text('subjektif')->nullable();
            $table->text('objektif')->nullable();
            $table->text('asesmen')->nullable();
            $table->text('plan')->nullable();
            $table->foreignId('perawat_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('dokter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('pemeriksaan_diagnosas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pemeriksaan_id')->constrained('pemeriksaans')->cascadeOnDelete();
            $table->foreignId('icd10_id')->constrained('icd10s')->restrictOnDelete();
            $table->string('jenis', 10)->default('primer'); // primer / sekunder
            $table->timestamps();
        });

        Schema::create('kunjungan_tindakans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kunjungan_id')->constrained('kunjungans')->cascadeOnDelete();
            $table->foreignId('tindakan_id')->constrained('tindakans')->restrictOnDelete();
            $table->unsignedSmallInteger('jumlah')->default(1);
            $table->unsignedInteger('tarif');
            $table->string('keterangan')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kunjungan_tindakans');
        Schema::dropIfExists('pemeriksaan_diagnosas');
        Schema::dropIfExists('pemeriksaans');
    }
};
