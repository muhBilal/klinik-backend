<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Jejak audit (PRD AD-03, Permenkes 24/2022). Hanya INSERT; model AuditLog menolak update/delete.
        // Sengaja tanpa foreign key agar baris audit tidak pernah ikut berubah/terhapus bersama data yang diaudit.
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->unsignedBigInteger('cabang_id')->nullable()->index();
            $table->string('aksi', 30)->index();
            $table->string('tipe', 50)->nullable()->comment('Jenis data, mis. pasien, kunjungan, pemeriksaan');
            $table->unsignedBigInteger('subjek_id')->nullable();
            $table->unsignedBigInteger('pasien_id')->nullable()->index()->comment('Pasien pemilik data (jejak akses per pasien, UU PDP)');
            $table->string('label')->nullable();
            $table->json('perubahan')->nullable()->comment('{kolom: {lama, baru}}');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('created_at')->nullable()->index();

            $table->index(['tipe', 'subjek_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
