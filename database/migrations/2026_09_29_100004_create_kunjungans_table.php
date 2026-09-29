<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kunjungans', function (Blueprint $table) {
            $table->id();
            $table->string('no_registrasi', 20)->unique();
            $table->foreignId('pasien_id')->constrained('pasiens')->restrictOnDelete();
            $table->foreignId('poli_id')->constrained('polis')->restrictOnDelete();
            $table->foreignId('dokter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('tanggal');
            $table->unsignedInteger('no_antrian');
            $table->string('penjamin', 20)->default('umum');
            $table->string('no_penjamin', 30)->nullable();
            $table->text('keluhan')->nullable();
            $table->string('status', 30)->default('menunggu')->index();
            $table->timestamp('dipanggil_at')->nullable();
            $table->timestamp('selesai_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['poli_id', 'tanggal', 'no_antrian']);
            $table->index(['tanggal', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kunjungans');
    }
};
