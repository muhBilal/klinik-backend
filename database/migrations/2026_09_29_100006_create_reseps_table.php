<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reseps', function (Blueprint $table) {
            $table->id();
            $table->string('no_resep', 20)->unique();
            $table->foreignId('kunjungan_id')->unique()->constrained('kunjungans')->cascadeOnDelete();
            $table->foreignId('dokter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('menunggu')->index();
            $table->text('catatan')->nullable();
            $table->foreignId('apoteker_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('diserahkan_at')->nullable();
            $table->timestamps();
        });

        Schema::create('resep_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resep_id')->constrained('reseps')->cascadeOnDelete();
            $table->foreignId('obat_id')->constrained('obats')->restrictOnDelete();
            $table->unsignedInteger('jumlah');
            $table->string('aturan_pakai');
            $table->unsignedInteger('harga');
            $table->timestamps();
        });

        Schema::create('stok_mutasis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('obat_id')->constrained('obats')->cascadeOnDelete();
            $table->string('jenis', 20);
            $table->integer('jumlah'); // + masuk, - keluar
            $table->integer('stok_akhir');
            $table->string('referensi', 30)->nullable();
            $table->string('keterangan')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stok_mutasis');
        Schema::dropIfExists('resep_items');
        Schema::dropIfExists('reseps');
    }
};
