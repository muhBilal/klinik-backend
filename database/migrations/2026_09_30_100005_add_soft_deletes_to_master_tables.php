<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Data yang dirujuk rekam medis/transaksi tidak boleh hilang permanen (retensi RME).
     * Hapus = soft delete; kolom unik (NIK, email, kode) tetap terpakai oleh baris yang dihapus.
     */
    private const TABEL = ['pasiens', 'users', 'obats', 'tindakans', 'polis'];

    public function up(): void
    {
        foreach (self::TABEL as $tabel) {
            Schema::table($tabel, fn (Blueprint $table) => $table->softDeletes());
        }
    }

    public function down(): void
    {
        foreach (self::TABEL as $tabel) {
            Schema::table($tabel, fn (Blueprint $table) => $table->dropSoftDeletes());
        }
    }
};
