<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRD v2 #7:
 * - Resep racikan (FR-01): satu baris resep = racikan berisi beberapa komponen obat (krim/salep racik dermatologi).
 * - STR tenaga medis (AD-05; UU 17/2023: STR berlaku seumur hidup → `str_berlaku_sampai` null = seumur hidup).
 * - Jenis produk & nomor notifikasi BPOM (AD-06): skincare/kosmetik yang dijual wajib bernomor notifikasi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resep_items', function (Blueprint $table) {
            $table->foreignId('obat_id')->nullable()->change();
            $table->boolean('racikan')->default(false)->after('obat_id');
            $table->string('nama_racikan', 150)->nullable()->after('racikan');
            $table->string('bentuk', 20)->nullable()->after('nama_racikan')->comment('krim / salep / kapsul / puyer / sirup / lainnya');
            $table->decimal('jumlah_racikan', 8, 2)->nullable()->after('bentuk')->comment('Isi racikan untuk etiket, mis. 30 (g) / 10 (kapsul)');
            $table->string('satuan_racikan', 20)->nullable()->after('jumlah_racikan');
            $table->unsignedInteger('biaya_racik')->default(0)->after('harga');
        });

        Schema::create('resep_item_komponens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resep_item_id')->constrained('resep_items')->cascadeOnDelete();
            $table->foreignId('obat_id')->constrained('obats')->restrictOnDelete();
            $table->decimal('jumlah', 12, 3)->comment('Total pemakaian untuk seluruh racikan, satuan stok');
            $table->unsignedInteger('harga')->comment('Snapshot harga per satuan stok');
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('str', 50)->nullable()->after('sip_berlaku_sampai')->comment('Surat Tanda Registrasi');
            $table->date('str_berlaku_sampai')->nullable()->after('str')->comment('null = seumur hidup');
        });

        Schema::table('obats', function (Blueprint $table) {
            $table->string('jenis', 15)->default('obat')->after('satuan')->comment('obat / skincare / bhp / alkes');
            $table->string('no_bpom', 30)->nullable()->after('jenis')->comment('Nomor notifikasi / izin edar BPOM');
        });
    }

    public function down(): void
    {
        Schema::table('obats', fn (Blueprint $table) => $table->dropColumn(['jenis', 'no_bpom']));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['str', 'str_berlaku_sampai']));
        Schema::dropIfExists('resep_item_komponens');
        Schema::table('resep_items', function (Blueprint $table) {
            $table->dropColumn(['racikan', 'nama_racikan', 'bentuk', 'jumlah_racikan', 'satuan_racikan', 'biaya_racik']);
        });
    }
};
