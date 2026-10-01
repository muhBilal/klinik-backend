<?php

use App\Models\Pasien;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nomor HP ternormalisasi (hanya digit, awalan 62 → 0) untuk deteksi pasien ganda (PS-02) dan nanti pencocokan WhatsApp (CR-01).
 * Diisi otomatis oleh model `Pasien`; migration mengisi data lama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pasiens', function (Blueprint $table) {
            $table->string('no_hp_digit', 20)->nullable()->after('no_hp')->index();
        });

        DB::table('pasiens')->whereNotNull('no_hp')->orderBy('id')->select(['id', 'no_hp'])
            ->chunkById(500, function ($baris) {
                foreach ($baris as $p) {
                    DB::table('pasiens')->where('id', $p->id)->update(['no_hp_digit' => Pasien::normalkanHp($p->no_hp)]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('pasiens', function (Blueprint $table) {
            $table->dropIndex(['no_hp_digit']);
            $table->dropColumn('no_hp_digit');
        });
    }
};
