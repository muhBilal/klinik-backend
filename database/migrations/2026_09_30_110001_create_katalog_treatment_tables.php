<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Katalog treatment (PRD TR-01, Fase 1): kategori, durasi + buffer, harga per cabang, BHP standar.
 * `tindakans.tarif` tetap menjadi harga dasar (pusat); `tindakan_hargas` menimpanya untuk cabang tertentu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kategori_tindakans', function (Blueprint $table) {
            $table->id();
            $table->string('nama', 100)->unique();
            $table->string('deskripsi')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('tindakans', function (Blueprint $table) {
            $table->foreignId('kategori_id')->nullable()->after('nama')->constrained('kategori_tindakans')->nullOnDelete();
            $table->unsignedSmallInteger('durasi_menit')->default(15)->after('kategori_id')->comment('Lama tindakan, dasar slot booking (BK-02)');
            $table->unsignedSmallInteger('buffer_menit')->default(0)->after('durasi_menit')->comment('Jeda sterilisasi/persiapan setelah tindakan');
        });

        Schema::create('tindakan_hargas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tindakan_id')->constrained('tindakans')->cascadeOnDelete();
            $table->foreignId('cabang_id')->constrained('cabangs')->cascadeOnDelete();
            $table->unsignedInteger('tarif');
            $table->boolean('tersedia')->default(true)->comment('false = treatment tidak dilayani di cabang ini');
            $table->timestamps();

            $table->unique(['tindakan_id', 'cabang_id']);
            $table->index('cabang_id');
        });

        Schema::create('tindakan_bhps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tindakan_id')->constrained('tindakans')->cascadeOnDelete();
            $table->foreignId('obat_id')->constrained('obats')->restrictOnDelete();
            // Dalam satuan obat; desimal untuk pemakaian fraksional (mis. 0,2 vial) sebelum satuan pakai IN-03.
            $table->decimal('jumlah', 10, 3);
            $table->timestamps();

            $table->unique(['tindakan_id', 'obat_id']);
            $table->index('obat_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tindakan_bhps');
        Schema::dropIfExists('tindakan_hargas');

        Schema::table('tindakans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('kategori_id');
            $table->dropColumn(['durasi_menit', 'buffer_menit']);
        });

        Schema::dropIfExists('kategori_tindakans');
    }
};
