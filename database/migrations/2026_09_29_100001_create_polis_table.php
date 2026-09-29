<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('polis', function (Blueprint $table) {
            $table->id();
            $table->string('kode', 10)->unique();
            $table->string('nama');
            $table->unsignedInteger('tarif_konsultasi')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('pendaftaran')->index();
            $table->foreignId('poli_id')->nullable()->constrained('polis')->nullOnDelete();
            $table->string('sip', 50)->nullable()->comment('Surat Izin Praktik (dokter)');
            $table->boolean('is_active')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('poli_id');
            $table->dropColumn(['role', 'sip', 'is_active']);
        });

        Schema::dropIfExists('polis');
    }
};
