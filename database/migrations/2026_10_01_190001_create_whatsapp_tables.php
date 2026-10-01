<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp Business (PRD BK-06, CR-01 · PRD v2 #9): outbox pesan template (reminder booking H-1 & 2 jam, follow-up H+1 & H+7),
 * status kirim/terbaca dari webhook, balasan tombol (konfirmasi / minta ubah jadwal).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pesan_whatsapps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cabang_id')->nullable()->constrained('cabangs')->nullOnDelete();
            $table->foreignId('pasien_id')->constrained('pasiens')->restrictOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained('appointments')->nullOnDelete();
            $table->foreignId('kunjungan_id')->nullable()->constrained('kunjungans')->nullOnDelete();
            $table->string('jenis', 20)->comment('reminder_h1 / reminder_2jam / followup_h1 / followup_h7');
            $table->string('no_tujuan', 20);
            $table->string('template', 100);
            $table->json('parameter');
            $table->text('pratinjau')->comment('Teks perkiraan isi pesan untuk dibaca staf');
            $table->string('status', 15)->default('antre')->comment('antre / terkirim / diterima / dibaca / gagal');
            $table->unsignedSmallInteger('percobaan')->default(0);
            $table->string('wa_message_id', 150)->nullable()->index();
            $table->text('error')->nullable();
            $table->string('balasan', 30)->nullable()->comment('konfirmasi / ubah_jadwal');
            $table->timestamp('terkirim_at')->nullable();
            $table->timestamp('dibaca_at')->nullable();
            $table->timestamp('dibalas_at')->nullable();
            $table->timestamps();

            $table->unique(['jenis', 'appointment_id']);
            $table->unique(['jenis', 'kunjungan_id']);
            $table->index(['status', 'created_at']);
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->timestamp('minta_ubah_at')->nullable()->comment('Pasien meminta ubah jadwal lewat WhatsApp');
            $table->string('dikonfirmasi_via', 10)->nullable()->comment('staf / wa');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', fn (Blueprint $table) => $table->dropColumn(['minta_ubah_at', 'dikonfirmasi_via']));
        Schema::dropIfExists('pesan_whatsapps');
    }
};
