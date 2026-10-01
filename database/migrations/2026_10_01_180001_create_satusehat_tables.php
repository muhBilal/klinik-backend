<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Integrasi SATUSEHAT (PRD v2 5.14, SS-01..05, PS-05). Kredensial di .env (rahasia), bukan di database.
 * - pasiens.ihs_id      : IHS Number pasien hasil lookup NIK (PS-05 / SS-03)
 * - users.nik, ihs_id   : NIK tenaga medis → IHS Practitioner (SS-02)
 * - cabangs.satusehat_location_id : Location FHIR per cabang (SS-01)
 * - kunjungans.satusehat_encounter_id : Encounter yang terkirim
 * - obats.kode_kfa      : kode Kamus Farmasi & Alkes untuk MedicationRequest (opsional)
 * - satusehat_kirims    : antrean & status kirim per kunjungan (SS-05)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pasiens', function (Blueprint $table) {
            $table->string('ihs_id', 30)->nullable()->after('no_bpjs');
            $table->timestamp('ihs_dicek_at')->nullable()->after('ihs_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('nik', 16)->nullable()->unique()->after('email');
            $table->string('ihs_id', 30)->nullable()->after('nik');
        });

        Schema::table('cabangs', function (Blueprint $table) {
            $table->string('satusehat_location_id', 64)->nullable();
        });

        Schema::table('kunjungans', function (Blueprint $table) {
            $table->string('satusehat_encounter_id', 64)->nullable();
        });

        Schema::table('obats', function (Blueprint $table) {
            $table->string('kode_kfa', 30)->nullable()->after('no_bpom');
        });

        Schema::create('satusehat_kirims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kunjungan_id')->unique()->constrained('kunjungans')->restrictOnDelete();
            $table->foreignId('cabang_id')->constrained('cabangs')->restrictOnDelete();
            $table->string('status', 15)->default('menunggu')->comment('menunggu / terkirim / gagal');
            $table->unsignedSmallInteger('percobaan')->default(0);
            $table->string('encounter_id', 64)->nullable();
            $table->json('hasil')->nullable()->comment('Resource terkirim → id SATUSEHAT');
            $table->text('error')->nullable();
            $table->timestamp('terakhir_dicoba_at')->nullable();
            $table->timestamp('terkirim_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'cabang_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('satusehat_kirims');
        Schema::table('obats', fn (Blueprint $table) => $table->dropColumn('kode_kfa'));
        Schema::table('kunjungans', fn (Blueprint $table) => $table->dropColumn('satusehat_encounter_id'));
        Schema::table('cabangs', fn (Blueprint $table) => $table->dropColumn('satusehat_location_id'));
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['nik']);
            $table->dropColumn(['nik', 'ihs_id']);
        });
        Schema::table('pasiens', fn (Blueprint $table) => $table->dropColumn(['ihs_id', 'ihs_dicek_at']));
    }
};
