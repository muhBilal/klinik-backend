<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LaporanService;
use App\Support\CabangAktif;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Laporan keuangan (PRD LP-02, LP-03, AD-01) untuk cabang aktif, atau semua cabang bagi pengguna lintas cabang tanpa pilihan cabang.
 * `?format=csv` mengunduh CSV (pemisah titik koma + BOM UTF-8 agar langsung terbaca Excel berbahasa Indonesia) — bagian awal LP-06.
 */
class LaporanController extends Controller
{
    private const KELOMPOK = ['treatment', 'kategori', 'dokter', 'cabang', 'metode'];

    public function __construct(private LaporanService $service) {}

    public function penjualan(Request $request, CabangAktif $cabang): JsonResponse|StreamedResponse
    {
        $data = $request->validate([
            'dari' => ['required', 'date'],
            'sampai' => ['required', 'date', 'after_or_equal:dari'],
            'kelompok' => ['nullable', 'in:'.implode(',', self::KELOMPOK)],
            'format' => ['nullable', 'in:json,csv'],
        ]);
        [$dari, $sampai] = $this->rentang($data);
        $kelompok = $data['kelompok'] ?? 'treatment';

        $hasil = $this->service->penjualan($cabang->id(), $dari, $sampai, $kelompok);

        if (($data['format'] ?? 'json') === 'csv') {
            $kolom = $kelompok === 'metode'
                ? ['kunci' => 'Metode', 'transaksi' => 'Transaksi', 'masuk' => 'Masuk', 'refund' => 'Refund', 'bersih' => 'Bersih']
                : (in_array($kelompok, ['treatment', 'kategori'], true)
                    ? ['kode' => 'Kode', 'label' => ucfirst($kelompok), 'grup' => 'Kategori', 'jumlah' => 'Jumlah', 'sesi_paket' => 'Sesi paket',
                        'bruto' => 'Bruto', 'neto' => 'Neto (sebelum pajak)', 'refund' => 'Refund', 'bersih' => 'Bersih']
                    : ['label' => ucfirst($kelompok), 'transaksi' => 'Transaksi', 'bruto' => 'Bruto', 'potongan' => 'Diskon + promo',
                        'neto' => 'Neto', 'refund' => 'Refund', 'bersih' => 'Bersih']);

            return $this->csv("penjualan-{$kelompok}-{$dari->toDateString()}_{$sampai->toDateString()}.csv", $kolom, $hasil['baris']);
        }

        return response()->json([...$hasil, 'dari' => $dari->toDateString(), 'sampai' => $sampai->toDateString(), 'kelompok' => $kelompok,
            'cabang_id' => $cabang->id()]);
    }

    public function paket(Request $request, CabangAktif $cabang): JsonResponse|StreamedResponse
    {
        $data = $request->validate([
            'dari' => ['required', 'date'],
            'sampai' => ['required', 'date', 'after_or_equal:dari'],
            'format' => ['nullable', 'in:json,csv'],
        ]);
        [$dari, $sampai] = $this->rentang($data);

        $hasil = $this->service->paket($cabang->id(), $dari, $sampai);

        if (($data['format'] ?? 'json') === 'csv') {
            return $this->csv("kewajiban-paket-{$sampai->toDateString()}.csv", [
                'no_paket' => 'No. paket', 'nama' => 'Paket', 'pasien' => 'Pasien', 'no_rm' => 'No. RM', 'berlaku_sampai' => 'Berlaku s.d.',
                'sesi_sisa' => 'Sisa sesi', 'nilai_sisa' => 'Nilai sisa (kewajiban)',
            ], $hasil['kewajiban']);
        }

        return response()->json([...$hasil, 'dari' => $dari->toDateString(), 'sampai' => $sampai->toDateString(), 'cabang_id' => $cabang->id()]);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function rentang(array $data): array
    {
        $dari = CarbonImmutable::parse($data['dari'])->startOfDay();
        $sampai = CarbonImmutable::parse($data['sampai'])->endOfDay();

        abort_if($dari->diffInDays($sampai) > 366, 422, 'Rentang laporan maksimal satu tahun.');

        return [$dari, $sampai];
    }

    /**
     * Cegah CSV/formula injection: teks yang diawali = + - @ (atau tab/CR) dibuka Excel sebagai rumus.
     * Angka dibiarkan apa adanya (refund negatif tetap angka).
     */
    private static function selAman(mixed $nilai): mixed
    {
        return is_string($nilai) && $nilai !== '' && in_array($nilai[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$nilai : $nilai;
    }

    /** @param  array<string, string>  $kolom  kunci data => judul kolom */
    private function csv(string $nama, array $kolom, array $baris): StreamedResponse
    {
        return response()->streamDownload(function () use ($kolom, $baris) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_values($kolom), ';');
            foreach ($baris as $r) {
                fputcsv($out, array_map(fn ($k) => self::selAman($r[$k] ?? ''), array_keys($kolom)), ';');
            }
            fclose($out);
        }, $nama, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
