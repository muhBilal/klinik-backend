<?php

namespace App\Services;

use App\Enums\KeparahanAlergi;
use App\Models\Pasien;
use App\Models\PasienAlergi;
use App\Models\ProfilKlinis;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Profil klinis & alergi terstruktur pasien (PRD PS-03) beserta peringatan yang ditampilkan di pemeriksaan, catatan tindakan,
 * dan saat meresepkan (FR-02 sebagian).
 */
class ProfilKlinisService
{
    /** @return array{profil: ?ProfilKlinis, alergis: Collection, peringatan: list<array{tingkat: string, teks: string}>} */
    public function lihat(Pasien $pasien): array
    {
        $profil = $pasien->profilKlinis()->with('pembaru:id,name')->first();
        $alergis = $pasien->alergis()->with('obat:id,kode,nama')->orderByRaw("CASE keparahan WHEN 'berat' THEN 0 WHEN 'sedang' THEN 1 ELSE 2 END")->get();

        return ['profil' => $profil, 'alergis' => $alergis, 'peringatan' => $this->peringatan($pasien, $profil, $alergis)];
    }

    /**
     * @param  array{fitzpatrick?: ?int, status_kehamilan?: ?string, hpht?: ?string, riwayat_obat?: ?string, riwayat_penyakit?: ?string,
     *               alergis?: list<array{id?: int, jenis: string, zat: string, obat_id?: ?int, reaksi?: ?string, keparahan: string, catatan?: ?string}>}  $data
     */
    public function simpan(Pasien $pasien, array $data, User $user): array
    {
        DB::transaction(function () use ($pasien, $data, $user) {
            $profil = $pasien->profilKlinis()->firstOrNew();
            $atribut = Arr::only($data, ['fitzpatrick', 'status_kehamilan', 'hpht', 'riwayat_obat', 'riwayat_penyakit']);

            if (array_key_exists('status_kehamilan', $atribut) && $atribut['status_kehamilan'] !== $profil->status_kehamilan) {
                $atribut['status_kehamilan_at'] = now();
            }
            if (($atribut['status_kehamilan'] ?? $profil->status_kehamilan) !== 'hamil') {
                $atribut['hpht'] = null;
            }

            $profil->fill([...$atribut, 'diperbarui_oleh' => $user->id]);
            $profil->pasien_id = $pasien->id;
            $profil->save();

            if (array_key_exists('alergis', $data)) {
                $this->syncAlergi($pasien, $data['alergis'] ?? [], $user);
            }
        });

        return $this->lihat($pasien);
    }

    /** obat_id yang tercatat sebagai alergi pasien, untuk pemeriksaan resep. */
    public function obatAlergi(int $pasienId): array
    {
        return PasienAlergi::where('pasien_id', $pasienId)->whereNotNull('obat_id')->pluck('zat', 'obat_id')->all();
    }

    /** Replace-all per model (ter-audit); baris tanpa id = baru, id yang tidak dikirim = dihapus (soft delete). */
    private function syncAlergi(Pasien $pasien, array $alergis, User $user): void
    {
        $lama = $pasien->alergis()->get()->keyBy('id');
        $dikirim = collect($alergis)->pluck('id')->filter()->map(fn ($id) => (int) $id);

        $lama->reject(fn ($a) => $dikirim->contains($a->id))->each->delete();

        foreach ($alergis as $a) {
            $baris = isset($a['id']) ? $lama->get((int) $a['id']) : null;
            ($baris ?? $pasien->alergis()->make(['dicatat_oleh' => $user->id]))
                ->fill(Arr::only($a, ['jenis', 'zat', 'obat_id', 'reaksi', 'keparahan', 'catatan']) + ['obat_id' => null, 'reaksi' => null, 'catatan' => null])
                ->save();
        }
    }

    private function peringatan(Pasien $pasien, ?ProfilKlinis $profil, $alergis): array
    {
        $hasil = [];

        foreach ($alergis as $a) {
            $hasil[] = [
                'tingkat' => $a->keparahan === KeparahanAlergi::Berat ? 'bahaya' : 'waspada',
                'teks' => "Alergi {$a->zat}".($a->reaksi ? " ({$a->reaksi})" : '')." · {$a->keparahan->value}",
            ];
        }

        // Catatan alergi lama (teks bebas di identitas pasien) tetap ditampilkan sampai dipindah ke data terstruktur.
        if ($pasien->alergi && $alergis->isEmpty()) {
            $hasil[] = ['tingkat' => 'waspada', 'teks' => "Alergi: {$pasien->alergi}"];
        }

        if (in_array($profil?->status_kehamilan, ['hamil', 'menyusui'], true)) {
            $usia = $profil->hpht ? ' · '.intdiv((int) $profil->hpht->diffInDays(now()), 7).' minggu' : '';
            $hasil[] = ['tingkat' => 'bahaya', 'teks' => ucfirst($profil->status_kehamilan).$usia.' — hindari retinoid, toksin botulinum & tindakan kontraindikasi'];
        }

        if ($profil?->fitzpatrick >= 4) {
            $hasil[] = ['tingkat' => 'info', 'teks' => "Fitzpatrick tipe {$profil->fitzpatrick} — risiko hiperpigmentasi pasca inflamasi pada laser/peeling"];
        }

        return $hasil;
    }
}
