<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Data klinis pasien terstruktur (PRD PS-03) & persetujuan data pribadi UU PDP (PS-04).
 *
 * - `pasien_klinis` (1:1) + `pasien_alergis` (1:n): dipisah dari identitas `pasiens` agar hanya terbaca pemegang `rme.lihat`
 *   (kasir/marketing hanya punya `pasien.lihat` — PRD bagian 3: data klinis terpisah dari data komersial). Kolom teks bebas
 *   `pasiens.alergi` dikonversi menjadi baris alergi (dicocokkan ke master obat bila namanya cocok) lalu dihapus.
 * - `persetujuan_datas`: persetujuan pemrosesan data & opt-in marketing sebagai baris terpisah (bisa diberikan/dicabut sendiri-sendiri),
 *   naskah di-snapshot, tanda tangan terenkripsi, checksum — pola yang sama dengan persetujuan foto (F1-06).
 */
return new class extends Migration
{
    private const MAKANAN = ['seafood', 'udang', 'kepiting', 'cumi', 'ikan', 'telur', 'susu', 'kacang', 'gandum', 'kedelai', 'coklat', 'cokelat'];

    private const LINGKUNGAN = ['debu', 'lateks', 'latex', 'serbuk', 'bulu', 'tungau', 'dingin', 'nikel'];

    public function up(): void
    {
        Schema::create('pasien_klinis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pasien_id')->unique()->constrained('pasiens')->cascadeOnDelete();
            $table->string('fitzpatrick', 3)->nullable()->comment('Tipe kulit I–VI');
            $table->string('status_kehamilan', 10)->nullable()->comment('tidak / hamil / menyusui; null = belum ditanyakan');
            $table->date('status_kehamilan_at')->nullable()->comment('Tanggal status kehamilan dicatat/dikonfirmasi');
            $table->text('riwayat_obat')->nullable()->comment('Obat rutin / yang sedang & pernah dikonsumsi (mis. isotretinoin, antikoagulan)');
            $table->text('riwayat_penyakit')->nullable()->comment('Kondisi medis penyerta (mis. keloid, diabetes, autoimun)');
            $table->foreignId('diperbarui_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('pasien_alergis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pasien_id')->constrained('pasiens')->cascadeOnDelete();
            $table->string('kategori', 12)->comment('obat / makanan / lingkungan / lainnya');
            $table->string('zat', 150)->comment('Penyebab alergi, mis. Amoxicillin, udang');
            $table->foreignId('obat_id')->nullable()->constrained('obats')->nullOnDelete()->comment('Obat di master (peringatan resep)');
            $table->string('reaksi')->nullable();
            $table->string('keparahan', 10)->nullable()->comment('ringan / sedang / berat');
            $table->foreignId('dicatat_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('pasien_id');
        });

        $this->alergiTeksJadiBaris();
        Schema::table('pasiens', fn (Blueprint $table) => $table->dropColumn('alergi'));

        Schema::create('persetujuan_datas', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('pasien_id')->constrained('pasiens')->restrictOnDelete();
            $table->foreignId('cabang_id')->nullable()->constrained('cabangs')->nullOnDelete();
            $table->string('jenis', 12)->comment('pemrosesan / marketing');
            $table->json('kanal')->nullable()->comment('Marketing: whatsapp / sms / email / telepon');
            $table->text('isi')->comment('Naskah yang ditandatangani (snapshot)');
            $table->string('status', 10)->comment('berlaku / diganti / dicabut');
            $table->string('penandatangan_nama', 150);
            $table->string('hubungan', 20);
            $table->longText('ttd')->comment('PNG data URL, terenkripsi');
            $table->foreignId('dibuat_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('ditandatangani_at');
            $table->timestamp('berakhir_at')->nullable()->comment('Diganti persetujuan baru / dicabut');
            $table->foreignId('dicabut_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->string('alasan_cabut', 500)->nullable();
            $table->string('checksum', 64);
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['pasien_id', 'jenis', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('persetujuan_datas');

        Schema::table('pasiens', fn (Blueprint $table) => $table->text('alergi')->nullable()->after('pekerjaan'));
        DB::table('pasien_alergis')->orderBy('id')->get(['pasien_id', 'zat'])->groupBy('pasien_id')
            ->each(fn ($baris, $pasienId) => DB::table('pasiens')->where('id', $pasienId)
                ->update(['alergi' => mb_substr($baris->pluck('zat')->implode(', '), 0, 500)]));

        Schema::dropIfExists('pasien_alergis');
        Schema::dropIfExists('pasien_klinis');
    }

    /** "Amoxicillin, seafood" → dua baris alergi; nama yang cocok dengan master obat menjadi alergi obat bertaut. */
    private function alergiTeksJadiBaris(): void
    {
        $pasiens = DB::table('pasiens')->whereNotNull('alergi')->where('alergi', '!=', '')->get(['id', 'alergi']);
        if ($pasiens->isEmpty()) {
            return;
        }

        $obats = DB::table('obats')->get(['id', 'nama'])->map(fn ($o) => [$o->id, mb_strtolower($o->nama)]);
        $sekarang = now();
        $baris = [];

        foreach ($pasiens as $pasien) {
            foreach (preg_split('/[,;\/\n]+/', $pasien->alergi) as $zat) {
                $zat = trim($zat);
                $kecil = mb_strtolower($zat);
                if ($zat === '' || in_array($kecil, ['-', 'tidak ada', 'tidak', 'tidak ada alergi'], true)) {
                    continue;
                }

                $obat = $obats->first(fn ($o) => str_starts_with($o[1], $kecil));
                $kategori = match (true) {
                    $obat !== null => 'obat',
                    collect(self::MAKANAN)->contains(fn ($k) => str_contains($kecil, $k)) => 'makanan',
                    collect(self::LINGKUNGAN)->contains(fn ($k) => str_contains($kecil, $k)) => 'lingkungan',
                    default => 'lainnya',
                };

                $baris[] = ['pasien_id' => $pasien->id, 'kategori' => $kategori, 'zat' => mb_substr($zat, 0, 150), 'obat_id' => $obat[0] ?? null,
                    'created_at' => $sekarang, 'updated_at' => $sekarang];
            }
        }

        foreach (array_chunk($baris, 500) as $potong) {
            DB::table('pasien_alergis')->insert($potong);
        }
    }
};
