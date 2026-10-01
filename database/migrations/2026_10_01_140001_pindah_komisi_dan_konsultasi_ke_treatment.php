<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Komisi & jasa konsultasi diatur langsung di master treatment (menggantikan mesin aturan komisi F1-09 versi awal).
 *
 * - `tindakan_komisis`: komisi per treatment per peran (dokter = dokter kunjungan, terapis = pelaksana, asisten), persen atau nominal.
 * - Jasa konsultasi dokter = treatment biasa (kategori "Konsultasi"). `polis.tindakan_konsultasi_id` menunjuk treatment yang ditagihkan
 *   otomatis tiap kunjungan poli itu — harga per cabang & komisinya ikut katalog treatment. `polis.tarif_konsultasi` dihapus.
 * - Data lama dikonversi: tarif konsultasi poli → treatment "Konsultasi <poli>"; aturan aktif paling spesifik (tanpa aturan khusus
 *   cabang, yang tidak punya padanan) → komisi per treatment; item tagihan konsultasi & baris komisi lama diberi `tindakan_id`.
 * - `komisi_barises.aturan_komisi_id` diganti `tindakan_id` (snapshot treatment), tabel `aturan_komisis` dihapus.
 */
return new class extends Migration
{
    private const PERAN = ['dokter', 'terapis', 'asisten'];

    public function up(): void
    {
        Schema::create('tindakan_komisis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tindakan_id')->constrained('tindakans')->cascadeOnDelete();
            $table->string('peran', 10)->comment('dokter / terapis / asisten');
            $table->string('jenis', 10)->comment('persen / nominal');
            $table->decimal('nilai', 12, 2)->comment('Persen (0-100) dari dasar, atau rupiah per unit tindakan');
            $table->timestamps();

            $table->unique(['tindakan_id', 'peran']);
        });

        Schema::table('polis', function (Blueprint $table) {
            $table->foreignId('tindakan_konsultasi_id')->nullable()->after('spesialisasi')->constrained('tindakans')->nullOnDelete()
                ->comment('Treatment jasa konsultasi yang ditagihkan otomatis tiap kunjungan');
        });

        $this->konsultasiJadiTreatment();
        $this->aturanJadiKomisiTreatment();

        Schema::table('komisi_barises', function (Blueprint $table) {
            $table->foreignId('tindakan_id')->nullable()->after('tagihan_id')->constrained('tindakans')->nullOnDelete();
        });
        DB::table('komisi_barises')->whereNotNull('kunjungan_tindakan_id')->update([
            'tindakan_id' => DB::raw('(SELECT kt.tindakan_id FROM kunjungan_tindakans kt WHERE kt.id = komisi_barises.kunjungan_tindakan_id)'),
        ]);
        DB::table('komisi_barises')->where('sumber', 'konsultasi')->update([
            'tindakan_id' => DB::raw('(SELECT p.tindakan_konsultasi_id FROM kunjungans k JOIN polis p ON p.id = k.poli_id WHERE k.id = komisi_barises.kunjungan_id)'),
        ]);
        Schema::table('komisi_barises', fn (Blueprint $table) => $table->dropConstrainedForeignId('aturan_komisi_id'));

        Schema::dropIfExists('aturan_komisis');
        Schema::table('polis', fn (Blueprint $table) => $table->dropColumn('tarif_konsultasi'));
    }

    public function down(): void
    {
        Schema::table('polis', function (Blueprint $table) {
            $table->unsignedInteger('tarif_konsultasi')->default(0)->after('spesialisasi');
        });
        DB::table('polis')->whereNotNull('tindakan_konsultasi_id')->update([
            'tarif_konsultasi' => DB::raw('(SELECT t.tarif FROM tindakans t WHERE t.id = polis.tindakan_konsultasi_id)'),
        ]);

        Schema::create('aturan_komisis', function (Blueprint $table) {
            $table->id();
            $table->string('sumber', 15)->comment('tindakan / konsultasi');
            $table->foreignId('tindakan_id')->nullable()->constrained('tindakans')->cascadeOnDelete();
            $table->foreignId('kategori_tindakan_id')->nullable()->constrained('kategori_tindakans')->cascadeOnDelete();
            $table->foreignId('poli_id')->nullable()->constrained('polis')->cascadeOnDelete();
            $table->foreignId('cabang_id')->nullable()->constrained('cabangs')->cascadeOnDelete()->comment('null = semua cabang');
            $table->string('peran', 10)->comment('dokter / terapis / asisten');
            $table->string('jenis', 10)->comment('persen / nominal');
            $table->decimal('nilai', 12, 2)->comment('Persen (0-100) atau rupiah per unit tindakan');
            $table->string('keterangan')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['sumber', 'peran']);
        });

        // Komisi per treatment → aturan khusus treatment; treatment jasa konsultasi poli → aturan konsultasi poli itu (dokter saja).
        $konsultasi = DB::table('polis')->whereNotNull('tindakan_konsultasi_id')->pluck('id', 'tindakan_konsultasi_id');
        $sekarang = now();
        foreach (DB::table('tindakan_komisis')->orderBy('id')->get() as $k) {
            $poliId = $konsultasi[$k->tindakan_id] ?? null;
            if ($poliId && $k->peran !== 'dokter') {
                continue;
            }
            DB::table('aturan_komisis')->insert([
                'sumber' => $poliId ? 'konsultasi' : 'tindakan', 'tindakan_id' => $poliId ? null : $k->tindakan_id, 'poli_id' => $poliId,
                'peran' => $k->peran, 'jenis' => $k->jenis, 'nilai' => $k->nilai, 'is_active' => true,
                'created_at' => $sekarang, 'updated_at' => $sekarang,
            ]);
        }

        Schema::table('komisi_barises', fn (Blueprint $table) => $table->dropConstrainedForeignId('tindakan_id'));
        Schema::table('komisi_barises', function (Blueprint $table) {
            $table->foreignId('aturan_komisi_id')->nullable()->after('tagihan_id')->constrained('aturan_komisis')->nullOnDelete();
        });

        DB::table('tagihan_items')->where('kategori', 'konsultasi')->update(['tindakan_id' => null]);
        Schema::table('polis', fn (Blueprint $table) => $table->dropConstrainedForeignId('tindakan_konsultasi_id'));
        Schema::dropIfExists('tindakan_komisis');
    }

    /** Tarif konsultasi tiap poli → treatment "Konsultasi <poli>" (kategori Konsultasi). Poli bertarif 0 = tanpa jasa konsultasi. */
    private function konsultasiJadiTreatment(): void
    {
        $polis = DB::table('polis')->whereNull('deleted_at')->where('tarif_konsultasi', '>', 0)->orderBy('id')
            ->get(['id', 'kode', 'nama', 'tarif_konsultasi']);
        if ($polis->isEmpty()) {
            return;
        }

        $sekarang = now();
        $kategoriId = DB::table('kategori_tindakans')->where('nama', 'Konsultasi')->value('id')
            ?? DB::table('kategori_tindakans')->insertGetId(['nama' => 'Konsultasi', 'deskripsi' => 'Jasa konsultasi dokter', 'is_active' => true,
                'created_at' => $sekarang, 'updated_at' => $sekarang]);
        $icd = DB::table('icd9cms')->where('kode', '89.07')->value('id');

        foreach ($polis as $poli) {
            $kode = mb_substr("KNS-{$poli->kode}", 0, 20);
            $tindakanId = DB::table('tindakans')->where('kode', $kode)->value('id') ?? DB::table('tindakans')->insertGetId([
                'kode' => $kode, 'nama' => "Konsultasi {$poli->nama}", 'kategori_id' => $kategoriId, 'icd9cm_id' => $icd,
                'durasi_menit' => 15, 'buffer_menit' => 0, 'tarif' => $poli->tarif_konsultasi, 'is_active' => true,
                'created_at' => $sekarang, 'updated_at' => $sekarang,
            ]);
            DB::table('polis')->where('id', $poli->id)->update(['tindakan_konsultasi_id' => $tindakanId]);
        }

        // Item tagihan konsultasi lama ikut menunjuk treatment-nya (laporan & rekap komisi yang melintasi tanggal migrasi).
        DB::table('tagihan_items')->where('kategori', 'konsultasi')->whereNull('tindakan_id')->update([
            'tindakan_id' => DB::raw('(SELECT p.tindakan_konsultasi_id FROM tagihans t JOIN kunjungans k ON k.id = t.kunjungan_id '
                .'JOIN polis p ON p.id = k.poli_id WHERE t.id = tagihan_items.tagihan_id)'),
        ]);
    }

    /**
     * Aturan lama → komisi per treatment: per treatment & peran dipakai aturan aktif paling spesifik tanpa cabang (treatment > kategori >
     * umum; treatment konsultasi: poli > semua poli, dokter saja). Nilai 0 (sengaja tanpa komisi) = tidak ada baris.
     */
    private function aturanJadiKomisiTreatment(): void
    {
        $aturan = DB::table('aturan_komisis')->where('is_active', true)->whereNull('cabang_id')->get();
        if ($aturan->isEmpty()) {
            return;
        }

        $konsultasi = DB::table('polis')->whereNotNull('tindakan_konsultasi_id')->pluck('id', 'tindakan_konsultasi_id');
        $id = fn ($nilai) => $nilai === null ? null : (int) $nilai;
        $sekarang = now();
        $baris = [];

        foreach (DB::table('tindakans')->whereNull('deleted_at')->get(['id', 'kategori_id']) as $t) {
            $poliId = $id($konsultasi[$t->id] ?? null);

            foreach (self::PERAN as $peran) {
                $pilih = $poliId !== null
                    ? $aturan->where('sumber', 'konsultasi')->where('peran', $peran)
                        ->filter(fn ($a) => $a->poli_id === null || $id($a->poli_id) === $poliId)
                        ->sortByDesc(fn ($a) => $a->poli_id ? 1 : 0)->first()
                    : $aturan->where('sumber', 'tindakan')->where('peran', $peran)
                        ->filter(fn ($a) => $id($a->tindakan_id) === (int) $t->id
                            || ($a->tindakan_id === null && ($a->kategori_tindakan_id === null || $id($a->kategori_tindakan_id) === $id($t->kategori_id))))
                        ->sortByDesc(fn ($a) => $a->tindakan_id ? 2 : ($a->kategori_tindakan_id ? 1 : 0))->first();

                if ($pilih && (float) $pilih->nilai > 0) {
                    $baris[] = ['tindakan_id' => $t->id, 'peran' => $peran, 'jenis' => $pilih->jenis, 'nilai' => $pilih->nilai,
                        'created_at' => $sekarang, 'updated_at' => $sekarang];
                }
            }
        }

        foreach (array_chunk($baris, 500) as $potong) {
            DB::table('tindakan_komisis')->insert($potong);
        }
    }
};
