<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Perbaikan rekap kas (PRD v2 #6, ditemukan saat menyusun laporan per metode bayar): baris pembayaran tunai sebelumnya menyimpan
 * uang yang DISERAHKAN pasien termasuk kembalian, sehingga rekap per metode & "kas seharusnya" shift berlebih sebesar kembalian.
 * Kini `jumlah` = nilai bersih yang masuk; `diterima` = uang yang diserahkan (untuk struk). Data lama dikoreksi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pembayarans', function (Blueprint $table) {
            $table->unsignedBigInteger('diterima')->nullable()->after('jumlah')->comment('Uang diserahkan (tunai); null = sama dengan jumlah');
        });

        DB::table('tagihans')->where('kembalian', '>', 0)->orderBy('id')->select(['id', 'kembalian'])
            ->chunkById(500, function ($tagihans) {
                foreach ($tagihans as $t) {
                    $sisa = (int) $t->kembalian;
                    $tunai = DB::table('pembayarans')->where('tagihan_id', $t->id)->where('metode', 'tunai')->orderByDesc('id')->get(['id', 'jumlah']);

                    foreach ($tunai as $p) {
                        if ($sisa <= 0) {
                            break;
                        }
                        $potong = min($sisa, (int) $p->jumlah);
                        DB::table('pembayarans')->where('id', $p->id)->update(['diterima' => $p->jumlah, 'jumlah' => $p->jumlah - $potong]);
                        $sisa -= $potong;
                    }
                }
            });
    }

    public function down(): void
    {
        DB::table('pembayarans')->whereNotNull('diterima')->update(['jumlah' => DB::raw('diterima')]);

        Schema::table('pembayarans', function (Blueprint $table) {
            $table->dropColumn('diterima');
        });
    }
};
