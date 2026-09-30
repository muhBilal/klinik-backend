<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Tabel transaksi yang datanya milik satu cabang. */
    private const TRANSAKSI = ['kunjungans', 'reseps', 'tagihans'];

    public function up(): void
    {
        Schema::create('cabangs', function (Blueprint $table) {
            $table->id();
            $table->string('kode', 10)->unique();
            $table->string('nama', 100);
            $table->string('alamat')->nullable();
            $table->string('telepon', 30)->nullable();
            $table->string('email', 100)->nullable();
            $table->time('jam_buka')->nullable();
            $table->time('jam_tutup')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('users', function (Blueprint $table) {
            // null = boleh mengakses semua cabang (pemilik / admin pusat)
            $table->foreignId('cabang_id')->nullable()->after('poli_id')->constrained('cabangs')->nullOnDelete();
        });

        foreach (self::TRANSAKSI as $tabel) {
            Schema::table($tabel, function (Blueprint $table) {
                // Selalu diisi aplikasi (trait DalamCabang); nullable hanya agar migration data lama aman.
                $table->foreignId('cabang_id')->nullable()->after('id')->constrained('cabangs')->restrictOnDelete();
            });
        }

        Schema::table('kunjungans', function (Blueprint $table) {
            $table->dropUnique(['poli_id', 'tanggal', 'no_antrian']);
            $table->unique(['cabang_id', 'poli_id', 'tanggal', 'no_antrian']);
            $table->index(['cabang_id', 'tanggal']);
        });

        $this->pindahkanDataLama();
    }

    /**
     * Database yang sudah berisi data (sebelum multi-cabang) dipindahkan ke satu cabang "Klinik Utama".
     * Database baru (test / instalasi baru) tidak disentuh; cabang dibuat oleh seeder atau admin.
     */
    private function pindahkanDataLama(): void
    {
        if (! DB::table('users')->exists() && ! DB::table('kunjungans')->exists()) {
            return;
        }

        $cabangId = DB::table('cabangs')->insertGetId([
            'kode' => 'UTAMA',
            'nama' => 'Klinik Utama',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (self::TRANSAKSI as $tabel) {
            DB::table($tabel)->whereNull('cabang_id')->update(['cabang_id' => $cabangId]);
        }

        // Staf menjadi milik cabang utama; administrator tetap lintas cabang.
        DB::table('users')->where('role', '!=', 'admin')->update(['cabang_id' => $cabangId]);

        // Counter antrian lama `antrian:{poli}:{Ymd}` -> `antrian:{cabang}:{poli}:{Ymd}` agar nomor hari ini tidak bentrok.
        foreach (DB::table('counters')->where('key', 'like', 'antrian:%')->get() as $counter) {
            $bagian = explode(':', $counter->key);
            if (count($bagian) === 3) {
                DB::table('counters')->insertOrIgnore(['key' => "antrian:{$cabangId}:{$bagian[1]}:{$bagian[2]}", 'value' => $counter->value]);
                DB::table('counters')->where('key', $counter->key)->delete();
            }
        }
    }

    public function down(): void
    {
        Schema::table('kunjungans', function (Blueprint $table) {
            $table->dropIndex(['cabang_id', 'tanggal']);
            $table->dropUnique(['cabang_id', 'poli_id', 'tanggal', 'no_antrian']);
            $table->unique(['poli_id', 'tanggal', 'no_antrian']);
        });

        foreach ([...self::TRANSAKSI, 'users'] as $tabel) {
            Schema::table($tabel, fn (Blueprint $table) => $table->dropConstrainedForeignId('cabang_id'));
        }

        Schema::dropIfExists('cabangs');
    }
};
