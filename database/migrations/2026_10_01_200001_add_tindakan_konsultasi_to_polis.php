<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // AD-01: tarif konsultasi poli per cabang. Konsultasi bisa ditautkan ke treatment katalog
    // sehingga otomatis memakai harga per cabang (`tindakan_hargas`). `tarif_konsultasi` tetap jadi
    // fallback bila poli belum ditautkan ke treatment.
    public function up(): void
    {
        Schema::table('polis', function (Blueprint $table) {
            $table->foreignId('tindakan_konsultasi_id')->nullable()->after('tarif_konsultasi')
                ->constrained('tindakans')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('polis', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tindakan_konsultasi_id');
        });
    }
};
