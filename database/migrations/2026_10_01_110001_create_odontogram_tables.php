<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kedokteran gigi (PRD DG-01, DG-02, DG-07).
 *
 * - `polis.spesialisasi`: odontogram & rencana perawatan tampil di pemeriksaan poli `gigi` (PRD bagian 6).
 * - `tindakans.per_gigi` (+ `kondisi_gigi_hasil`): tindakan dicatat per nomor gigi, masuk tagihan per gigi, dan (bila diisi)
 *   otomatis memperbarui odontogram, mis. tambal komposit → `cof` pada permukaan yang ditambal.
 * - `odontogram_kondisis`: kondisi per gigi / per permukaan milik pasien, dicatat di satu kunjungan. Kondisi yang tidak berlaku
 *   lagi diakhiri (`berakhir_kunjungan_id`), tidak ditimpa — status odontogram pada kunjungan mana pun bisa disusun ulang.
 * - `rencana_perawatans` + `rencana_perawatan_items`: treatment plan per gigi dengan fase & estimasi biaya.
 * - `kunjungan_tindakans` (+): `gigi`, `permukaan`, `rencana_item_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('polis', function (Blueprint $table) {
            $table->string('spesialisasi', 20)->default('umum')->after('nama')->comment('umum / gigi / kulit / estetika / lainnya');
        });

        Schema::table('tindakans', function (Blueprint $table) {
            $table->boolean('per_gigi')->default(false)->after('protokol_foto_id')->comment('Wajib nomor gigi saat dikerjakan');
            $table->string('kondisi_gigi_hasil', 3)->nullable()->after('per_gigi')->comment('Kondisi odontogram setelah tindakan, mis. cof');
        });

        Schema::create('odontogram_kondisis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pasien_id')->constrained('pasiens')->restrictOnDelete();
            $table->foreignId('cabang_id')->nullable()->constrained('cabangs')->nullOnDelete();
            $table->foreignId('kunjungan_id')->constrained('kunjungans')->restrictOnDelete()->comment('Kunjungan saat kondisi dicatat');
            $table->foreignId('kunjungan_tindakan_id')->nullable()->constrained('kunjungan_tindakans')->nullOnDelete()
                ->comment('Diisi = hasil otomatis tindakan per gigi');
            $table->unsignedTinyInteger('gigi')->comment('Nomor FDI');
            $table->char('permukaan', 1)->nullable()->comment('M/O/D/B/L; null = seluruh gigi');
            $table->string('kondisi', 3);
            $table->string('keterangan')->nullable();
            $table->foreignId('dicatat_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('berakhir_kunjungan_id')->nullable()->constrained('kunjungans')->restrictOnDelete()
                ->comment('Kunjungan saat kondisi tidak berlaku lagi');
            $table->timestamp('berakhir_at')->nullable();
            $table->foreignId('berakhir_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('berakhir_karena_id')->nullable()->comment('Kondisi baru yang menggantikannya; null = diakhiri manual');
            $table->timestamps();

            $table->index(['pasien_id', 'gigi']);
            $table->index('berakhir_kunjungan_id');
        });

        Schema::create('rencana_perawatans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pasien_id')->constrained('pasiens')->restrictOnDelete();
            $table->foreignId('cabang_id')->nullable()->constrained('cabangs')->nullOnDelete()->comment('Cabang penyusun & dasar harga estimasi');
            $table->foreignId('kunjungan_id')->nullable()->constrained('kunjungans')->nullOnDelete()->comment('Disusun pada kunjungan');
            $table->foreignId('dokter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('judul', 150);
            $table->text('catatan')->nullable();
            $table->string('status', 15)->default('draf');
            $table->timestamp('disetujui_at')->nullable();
            $table->foreignId('disetujui_oleh')->nullable()->constrained('users')->nullOnDelete()->comment('Petugas yang mencatat persetujuan');
            $table->string('penyetuju_nama', 150)->nullable()->comment('Pasien / wali yang menyetujui estimasi');
            $table->timestamp('selesai_at')->nullable();
            $table->timestamp('dibatalkan_at')->nullable();
            $table->foreignId('dibatalkan_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->string('alasan_batal', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['pasien_id', 'status']);
        });

        Schema::create('rencana_perawatan_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rencana_perawatan_id')->constrained('rencana_perawatans')->cascadeOnDelete();
            $table->unsignedTinyInteger('fase')->default(1);
            $table->unsignedSmallInteger('urutan')->default(0);
            $table->unsignedTinyInteger('gigi')->nullable()->comment('Nomor FDI; null = tidak spesifik gigi (mis. scaling)');
            $table->string('permukaan', 5)->nullable();
            $table->foreignId('tindakan_id')->constrained('tindakans')->restrictOnDelete();
            $table->unsignedSmallInteger('jumlah')->default(1);
            $table->unsignedInteger('tarif')->default(0)->comment('Estimasi: harga cabang saat item disusun');
            $table->string('keterangan')->nullable();
            $table->string('status', 10)->default('rencana');
            $table->timestamp('selesai_at')->nullable();
            $table->timestamps();
        });

        Schema::table('kunjungan_tindakans', function (Blueprint $table) {
            $table->unsignedTinyInteger('gigi')->nullable()->after('icd9cm_id')->comment('Nomor FDI (tindakan per gigi)');
            $table->string('permukaan', 5)->nullable()->after('gigi');
            $table->foreignId('rencana_item_id')->nullable()->after('permukaan')->constrained('rencana_perawatan_items')->nullOnDelete();
        });

        $this->isiDataLama();
    }

    public function down(): void
    {
        Schema::table('kunjungan_tindakans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rencana_item_id');
            $table->dropColumn(['gigi', 'permukaan']);
        });
        Schema::dropIfExists('rencana_perawatan_items');
        Schema::dropIfExists('rencana_perawatans');
        Schema::dropIfExists('odontogram_kondisis');
        Schema::table('tindakans', fn (Blueprint $table) => $table->dropColumn(['per_gigi', 'kondisi_gigi_hasil']));
        Schema::table('polis', fn (Blueprint $table) => $table->dropColumn('spesialisasi'));
    }

    /**
     * Instalasi lama: tandai poli gigi/kulit/estetika dari kode/nama, dan tindakan gigi dari kode ICD-9-CM default
     * (23.xx = tindakan pada gigi). Kondisi hasil hanya diisi bila jelas dari kode/nama; sisanya diatur admin di katalog.
     */
    private function isiDataLama(): void
    {
        foreach (DB::table('polis')->get(['id', 'kode', 'nama']) as $poli) {
            $teks = strtolower($poli->kode.' '.$poli->nama);
            $spesialisasi = match (true) {
                str_contains($teks, 'gigi') || str_contains($teks, 'dental') => 'gigi',
                str_contains($teks, 'kulit') || str_contains($teks, 'derma') => 'kulit',
                str_contains($teks, 'estetik') || str_contains($teks, 'aesthetic') => 'estetika',
                default => null,
            };
            if ($spesialisasi) {
                DB::table('polis')->where('id', $poli->id)->update(['spesialisasi' => $spesialisasi]);
            }
        }

        $tindakans = DB::table('tindakans')
            ->join('icd9cms', 'icd9cms.id', '=', 'tindakans.icd9cm_id')
            ->where('icd9cms.kode', 'like', '23.%')
            ->get(['tindakans.id', 'tindakans.nama', 'icd9cms.kode']);

        foreach ($tindakans as $t) {
            $nama = strtolower($t->nama);
            $kondisi = match (true) {
                str_starts_with($t->kode, '23.0'), str_starts_with($t->kode, '23.1') => 'mis',
                $t->kode === '23.2' && str_contains($nama, 'komposit') => 'cof',
                $t->kode === '23.2' && str_contains($nama, 'amalgam') => 'amf',
                $t->kode === '23.2' && (str_contains($nama, 'gic') || str_contains($nama, 'ionomer')) => 'gif',
                $t->kode === '23.3' => 'inl',
                in_array($t->kode, ['23.5', '23.6'], true) => 'ipx',
                in_array($t->kode, ['23.70', '23.71'], true) => 'rct',
                default => null,
            };

            DB::table('tindakans')->where('id', $t->id)->update(['per_gigi' => true, 'kondisi_gigi_hasil' => $kondisi]);
        }
    }
};
