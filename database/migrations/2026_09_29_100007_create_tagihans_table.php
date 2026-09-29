<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tagihans', function (Blueprint $table) {
            $table->id();
            $table->string('no_tagihan', 20)->unique();
            $table->foreignId('kunjungan_id')->unique()->constrained('kunjungans')->cascadeOnDelete();
            $table->unsignedBigInteger('total')->default(0);
            $table->unsignedBigInteger('diskon')->default(0);
            $table->unsignedBigInteger('grand_total')->default(0);
            $table->string('status', 20)->default('belum_bayar')->index();
            $table->string('metode_bayar', 20)->nullable();
            $table->unsignedBigInteger('dibayar')->default(0);
            $table->unsignedBigInteger('kembalian')->default(0);
            $table->foreignId('kasir_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('dibayar_at')->nullable();
            $table->timestamps();
        });

        Schema::create('tagihan_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tagihan_id')->constrained('tagihans')->cascadeOnDelete();
            $table->string('kategori', 20); // konsultasi / tindakan / obat
            $table->string('deskripsi');
            $table->unsignedInteger('jumlah');
            $table->unsignedInteger('harga');
            $table->unsignedBigInteger('subtotal');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tagihan_items');
        Schema::dropIfExists('tagihans');
    }
};
