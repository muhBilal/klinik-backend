<?php

namespace App\Services;

use App\Enums\Izin;
use App\Enums\StatusKunjungan;
use App\Enums\StatusResep;
use App\Models\Kunjungan;
use App\Models\KunjunganTindakan;
use App\Models\Obat;
use App\Models\Pemeriksaan;
use App\Models\Tindakan;
use App\Models\User;
use App\Support\Gigi;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PemeriksaanService
{
    public function __construct(
        private NomorUrutService $nomor,
        private TagihanService $tagihan,
        private BhpService $bhp,
        private RekamMedisService $rekamMedis,
        private InformedConsentService $consent,
        private CatatanTindakanService $catatan,
        private OdontogramService $odontogram,
        private RencanaPerawatanService $rencana,
        private PaketService $paket,
    ) {}

    /**
     * Pasien dipanggil ke ruang periksa.
     */
    public function panggil(Kunjungan $kunjungan, User $user): Kunjungan
    {
        if ($kunjungan->status !== StatusKunjungan::Menunggu) {
            throw ValidationException::withMessages(['status' => 'Hanya kunjungan berstatus menunggu yang dapat dipanggil.']);
        }

        $kunjungan->update([
            'status' => StatusKunjungan::Diperiksa,
            'dipanggil_at' => now(),
            'dokter_id' => $kunjungan->dokter_id ?? ($user->tercatatSebagaiDokter() ? $user->id : null),
        ]);

        return $kunjungan;
    }

    /**
     * Simpan (upsert) data pemeriksaan; hanya kolom yang dikirim yang diubah. Pemegang `pemeriksaan.vital` (perawat, terapis) mengisi tanda
     * vital & anamnesis (subjektif); pemegang `rme.tindakan` mencatat tindakan beserta sesi paket yang dikerjakan (mis. terapis mencatat
     * facial sesi ke-3); pemegang `pemeriksaan.dokter` mengisi seluruh SOAP, diagnosa, tindakan, resep, dan penanda akses terbatas. Isi RME
     * hanya diubah oleh yang boleh membacanya (form tanpa data RME bisa menimpa isi yang tidak terlihat). Menutup & menandatangani tetap
     * hanya dokter (`selesai`).
     */
    public function simpan(Kunjungan $kunjungan, array $data, User $user): Kunjungan
    {
        $this->pastikanTerbuka($kunjungan);

        $bolehRme = $user->punyaIzin(Izin::RmeLihat) && $this->rekamMedis->bolehLihat($user, $kunjungan);
        $dokter = $bolehRme && $user->punyaIzin(Izin::PemeriksaanDokter);

        return DB::transaction(function () use ($kunjungan, $data, $user, $bolehRme, $dokter) {
            $kunjungan = Kunjungan::kunci($kunjungan->id);
            $this->pastikanTerbuka($kunjungan);

            $fields = match (true) {
                $dokter => [...Pemeriksaan::VITAL_FIELDS, ...Pemeriksaan::SOAP_FIELDS],
                $user->punyaIzin(Izin::PemeriksaanVital, Izin::PemeriksaanDokter) => [...Pemeriksaan::VITAL_FIELDS, 'subjektif'],
                default => [],
            };

            // perawat_id = tenaga non-dokter yang terakhir benar-benar mengubah tanda vital / anamnesis (bukan sekadar menyimpan tindakan).
            $pemeriksaan = $kunjungan->pemeriksaan()->firstOrNew();
            $pemeriksaan->fill(Arr::only($data, $fields));
            if ($dokter) {
                $pemeriksaan->dokter_id = $user->id;
            } elseif ($pemeriksaan->isDirty()) {
                $pemeriksaan->perawat_id = $user->id;
            }
            if ($pemeriksaan->isDirty()) {
                $pemeriksaan->save();
            }

            $idsAwal = $data['tindakan_ids_awal'] ?? null;
            if ($dokter) {
                if (array_key_exists('diagnosas', $data)) {
                    $this->syncDiagnosa($pemeriksaan, $data['diagnosas']);
                }
                if (array_key_exists('tindakans', $data)) {
                    $this->syncTindakan($kunjungan, $data['tindakans'], $user, $idsAwal);
                }
                if (array_key_exists('resep', $data)) {
                    $this->syncResep($kunjungan, $data['resep'] ?? [], $data['catatan_resep'] ?? null, $user);
                }
                $this->aturAksesTerbatas($kunjungan, $pemeriksaan, $data);
            } elseif ($bolehRme && array_key_exists('tindakans', $data) && $user->punyaIzin(Izin::RmeTindakan)) {
                // Tenaga tindakan (perawat/terapis) mencatat tindakan & sesi paket yang dikerjakan; diagnosa/resep tetap dokter.
                $this->syncTindakan($kunjungan, $data['tindakans'], $user, $idsAwal);
            }

            // Bentuk respons sama dengan GET /kunjungans/{id}: isi RME hanya bagi yang boleh membacanya.
            $rme = $user->punyaIzin(Izin::RmeLihat) && $this->rekamMedis->bolehLihat($user, $kunjungan);

            return $kunjungan->loadDetail($rme)->setAttribute('rme_disembunyikan', $user->punyaIzin(Izin::RmeLihat) && ! $rme);
        });
    }

    private function pastikanTerbuka(Kunjungan $kunjungan): void
    {
        if (! $kunjungan->terbuka()) {
            throw ValidationException::withMessages(['status' => 'Pemeriksaan sudah ditutup dan tidak dapat diubah.']);
        }
    }

    /**
     * Dokter menutup & menandatangani pemeriksaan (RM-07); tagihan dibuat dan pasien diarahkan ke kasir.
     * Syarat: minimal satu diagnosa, penutup ber-SIP aktif, dan informed consent lengkap untuk treatment yang mewajibkannya.
     */
    public function selesai(Kunjungan $kunjungan, User $user): Kunjungan
    {
        return DB::transaction(function () use ($kunjungan, $user) {
            // Kunci dulu: simpan tindakan / pesan paket yang berjalan bersamaan menunggu (atau ditolak karena sudah ditutup), sehingga
            // tagihan & hash tanda tangan mencakup isi final. Syarat diperiksa pada data terkunci.
            $kunjungan = Kunjungan::kunci($kunjungan->id);

            if ($kunjungan->status !== StatusKunjungan::Diperiksa) {
                throw ValidationException::withMessages(['status' => 'Pasien belum dipanggil atau pemeriksaan sudah selesai.']);
            }

            $pemeriksaan = $kunjungan->pemeriksaan;

            if (! $pemeriksaan || $pemeriksaan->diagnosas()->doesntExist()) {
                throw ValidationException::withMessages(['diagnosas' => 'Minimal satu diagnosa (ICD-10) harus diisi sebelum menyelesaikan pemeriksaan.']);
            }

            $this->rekamMedis->pastikanBolehMenandatangani($user);
            $this->consent->pastikanLengkap($kunjungan);

            // Odontogram turunan tindakan per gigi dipastikan final sebelum RME dikunci (tindakan bisa disimpan sebelum pasien dipanggil).
            $this->odontogram->sinkronDariTindakan($kunjungan, $user);

            $kunjungan->update([
                'status' => StatusKunjungan::MenungguPembayaran,
                'selesai_at' => now(),
                'dokter_id' => $kunjungan->dokter_id ?? $user->id,
            ]);

            // Item rencana perawatan gigi yang dikerjakan di kunjungan ini menjadi selesai (DG-02).
            $this->rencana->selesaikanDariKunjungan($kunjungan);

            // BHP dipotong sebelum tagihan dibuat: bila stok kurang, pemeriksaan tidak ikut tertutup.
            $this->bhp->potongStok($kunjungan, $user);

            $this->tagihan->buatDariKunjungan($kunjungan);

            // Ditandatangani terakhir: hash mencakup seluruh isi klinis yang sudah final.
            $this->rekamMedis->tandaTangani($kunjungan, $user);

            return $kunjungan->loadDetail();
        });
    }

    private function syncDiagnosa(Pemeriksaan $pemeriksaan, array $diagnosas): void
    {
        // Hapus per model (bukan query massal) agar setiap perubahan rekam medis tercatat di audit log.
        $pemeriksaan->diagnosas()->get()->each->delete();

        foreach ($diagnosas as $index => $diagnosa) {
            $pemeriksaan->diagnosas()->create([
                'icd10_id' => $diagnosa['icd10_id'],
                'jenis' => $diagnosa['jenis'] ?? ($index === 0 ? 'primer' : 'sekunder'),
            ]);
        }
    }

    /**
     * Kunjungan berakses terbatas (DR-03) bila dokter menandainya atau ada diagnosa sensitif (IMS/HIV).
     * Selama diagnosa sensitif masih ada, penanda tidak bisa dilepas.
     */
    private function aturAksesTerbatas(Kunjungan $kunjungan, Pemeriksaan $pemeriksaan, array $data): void
    {
        $sensitif = $pemeriksaan->diagnosas()->whereHas('icd10', fn ($q) => $q->where('sensitif', true))->exists();
        $diminta = array_key_exists('akses_terbatas', $data) ? (bool) $data['akses_terbatas'] : $kunjungan->akses_terbatas;
        $terbatas = $sensitif || $diminta;

        if ($terbatas !== $kunjungan->akses_terbatas) {
            $kunjungan->update(['akses_terbatas' => $terbatas]);
        }
    }

    /**
     * Upsert tindakan kunjungan. Baris lama dipertahankan (beserta catatan tindakan, consent & koreksi BHP-nya) bila
     * cocok `id`-nya, atau — untuk klien tanpa `id` — tindakan yang sama pada gigi yang sama. Baris yang tidak dikirim
     * dihapus per model. Tarif di-snapshot dari harga cabang kunjungan (harga dasar bila cabang tidak punya harga khusus).
     * Tindakan per gigi (DG-07) wajib nomor gigi dan memperbarui odontogram bila katalog mengisi kondisi hasilnya.
     *
     * Dokter & terapis bisa mengisi dari perangkat berbeda: `$idsAwal` = id baris yang terlihat klien saat form dimuat. Bila dikirim,
     * hanya baris itu yang boleh dihapus / dicocokkan tanpa id; baris yang ditambahkan petugas lain sesudahnya dibiarkan, dan baris ber-id
     * yang sudah dihapus petugas lain tidak dibuat ulang. Tanpa izin pemeriksaan.dokter, baris yang dicatat petugas lain tidak bisa
     * dihapus (422) dan hanya pemakaian sesi paketnya yang bisa diganti.
     *
     * @param  list<int>|null  $idsAwal
     */
    private function syncTindakan(Kunjungan $kunjungan, array $tindakans, User $user, ?array $idsAwal = null): void
    {
        $master = Tindakan::whereIn('id', Arr::pluck($tindakans, 'tindakan_id'))
            ->select(['id', 'nama', 'tarif', 'icd9cm_id', 'per_gigi', 'kondisi_gigi_hasil'])
            ->denganHargaCabang($kunjungan->cabang_id)
            ->get()
            ->keyBy('id');

        foreach ($tindakans as $index => $item) {
            if (! $master[$item['tindakan_id']]->tersedia) {
                throw ValidationException::withMessages([
                    "tindakans.{$index}.tindakan_id" => "{$master[$item['tindakan_id']]->nama} tidak dilayani di cabang ini.",
                ]);
            }
        }

        // Item rencana perawatan yang dikerjakan: gigi & permukaan mengikuti rencana bila tidak diisi (DG-02).
        foreach ($tindakans as $index => $item) {
            if (! empty($item['rencana_item_id'])) {
                $rencanaItem = $this->rencana->pastikanBisaDikerjakan((int) $item['rencana_item_id'], $kunjungan, $item['id'] ?? null, "tindakans.{$index}.rencana_item_id");
                if (empty($item['gigi']) && $rencanaItem->gigi) {
                    $tindakans[$index]['gigi'] = $rencanaItem->gigi;
                    $tindakans[$index]['permukaan'] ??= $rencanaItem->permukaan;
                }
            }
            $tindakans[$index]['permukaan'] = Gigi::normalPermukaan($tindakans[$index]['permukaan'] ?? null);

            $tindakan = $master[$item['tindakan_id']];
            if ($tindakan->per_gigi && empty($tindakans[$index]['gigi'])) {
                throw ValidationException::withMessages(["tindakans.{$index}.gigi" => "Pilih nomor gigi untuk {$tindakan->nama}."]);
            }
            if ($tindakan->kondisi_gigi_hasil?->cakupan() === 'permukaan' && ! empty($tindakans[$index]['gigi']) && ! $tindakans[$index]['permukaan']) {
                throw ValidationException::withMessages(["tindakans.{$index}.permukaan" => "Pilih permukaan gigi {$tindakans[$index]['gigi']} untuk {$tindakan->nama}."]);
            }
        }

        $petugas = array_filter(array_map(fn ($t) => $t['petugas_id'] ?? null, $tindakans));
        if ($petugas) {
            $this->catatan->pastikanPetugas($petugas, $kunjungan->cabang_id, 'tindakans.*.petugas_id');
        }
        // Asisten tindakan (dasar komisi peran asisten, KM-01) juga harus petugas medis di cabang kunjungan.
        $asisten = array_filter(array_map(fn ($t) => $t['asisten_id'] ?? null, $tindakans));
        if ($asisten) {
            $this->catatan->pastikanPetugas($asisten, $kunjungan->cabang_id, 'tindakans.*.asisten_id');
        }

        $dokter = $user->punyaIzin(Izin::PemeriksaanDokter);
        $semua = $kunjungan->tindakans()->with('tindakan:id,nama')->get()->keyBy('id');
        $sisa = $semua->collect();
        $terlihat = $idsAwal === null ? null : array_map('intval', $idsAwal);
        $bolehDisentuh = fn (KunjunganTindakan $t) => $terlihat === null || in_array($t->id, $terlihat, true);
        $pasangan = [];
        $lewati = [];

        foreach ($tindakans as $i => $item) {
            $baris = isset($item['id']) ? $sisa->get($item['id']) : null;
            if ($baris && (int) $baris->tindakan_id === (int) $item['tindakan_id']) {
                $pasangan[$i] = $sisa->pull($baris->id);
            } elseif (isset($item['id']) && $terlihat !== null && ! $semua->has($item['id'])) {
                $lewati[$i] = true; // sudah dihapus petugas lain sejak form dimuat
            }
        }
        foreach ($tindakans as $i => $item) {
            $cocok = fn ($t) => $bolehDisentuh($t) && (int) $t->tindakan_id === (int) $item['tindakan_id'] && (int) $t->gigi === (int) ($item['gigi'] ?? 0);
            if (! isset($pasangan[$i]) && ! isset($lewati[$i]) && ($baris = $sisa->first($cocok))) {
                $pasangan[$i] = $sisa->pull($baris->id);
            }
        }

        $dihapus = $sisa->filter($bolehDisentuh);
        $milikLain = $dokter ? null : $dihapus->first(fn (KunjunganTindakan $t) => (int) $t->petugas_id !== (int) $user->id);
        if ($milikLain) {
            throw ValidationException::withMessages([
                'tindakans' => "Tindakan {$milikLain->tindakan?->nama} dicatat petugas lain — hanya dokter yang bisa menghapusnya.",
            ]);
        }
        // Hapus per model (bukan query massal) agar tercatat di audit log, termasuk catatan tindakannya.
        $dihapus->each(fn (KunjunganTindakan $baris) => $this->hapusTindakan($baris));

        // Petugas default: dokter yang mengisi, perawat/terapis yang mencatat sendiri, atau dokter kunjungan (dasar komisi; bisa diubah).
        $petugasDefault = $user->tercatatSebagaiDokter() || ! $dokter ? $user->id : $kunjungan->dokter_id;

        $indeks = [];

        foreach ($tindakans as $i => $item) {
            if (isset($lewati[$i])) {
                continue;
            }
            $baris = $pasangan[$i] ?? null;
            $tindakan = $master[$item['tindakan_id']];
            // Perawat/terapis tidak mengubah baris yang dicatat petugas lain; hanya pemakaian sesi paketnya (mis. sesi pertama paket yang
            // baru dipesan) yang boleh diganti.
            $milikLain = $baris && ! $dokter && (int) $baris->petugas_id !== (int) $user->id;
            $atribut = $milikLain ? [
                ...$baris->only(['jumlah', 'tarif', 'keterangan', 'petugas_id', 'asisten_id', 'icd9cm_id', 'gigi', 'permukaan', 'rencana_item_id']),
                'paket_pasien_item_id' => $item['paket_pasien_item_id'] ?? null,
            ] : [
                'jumlah' => $item['jumlah'] ?? 1,
                'tarif' => $tindakan->tarif_cabang,
                'keterangan' => $item['keterangan'] ?? null,
                'petugas_id' => array_key_exists('petugas_id', $item) ? $item['petugas_id'] : ($baris ? $baris->petugas_id : $petugasDefault),
                'asisten_id' => array_key_exists('asisten_id', $item) ? $item['asisten_id'] : $baris?->asisten_id,
                // Kode ICD-9-CM diubah dokter; tindakan yang dicatat perawat/terapis memakai kode bawaan katalog / kode yang sudah ada.
                'icd9cm_id' => $dokter && array_key_exists('icd9cm_id', $item) ? $item['icd9cm_id'] : ($baris ? $baris->icd9cm_id : $tindakan->icd9cm_id),
                'gigi' => $item['gigi'] ?? null,
                'permukaan' => empty($item['gigi']) ? null : $item['permukaan'],
                'rencana_item_id' => $item['rencana_item_id'] ?? null,
                'paket_pasien_item_id' => $item['paket_pasien_item_id'] ?? null,
            ];

            // Memakai sesi paket pasien (TR-02): paket aktif, treatment sama, sisa cukup (sesi baris ini sendiri tidak dihitung).
            if ($atribut['paket_pasien_item_id']) {
                $this->paket->pastikanBisaDipakai((int) $atribut['paket_pasien_item_id'], (int) $item['tindakan_id'], (int) $atribut['jumlah'],
                    $kunjungan, $baris?->id, "tindakans.{$i}.paket_pasien_item_id");
            }

            if (! $baris) {
                $baris = $kunjungan->tindakans()->create(['tindakan_id' => $item['tindakan_id'], ...$atribut]);
                $indeks[$baris->id] = $i;
                // Draft pemakaian BHP dari standar katalog; boleh dikoreksi petugas sebelum pemeriksaan ditutup (IN-02).
                $this->bhp->siapkanDariStandar($baris);

                continue;
            }

            $indeks[$baris->id] = $i;

            $jumlahBerubah = $baris->jumlah !== (int) $atribut['jumlah'];
            $baris->update($atribut);

            // Jumlah berubah -> draft BHP dihitung ulang dari standar (koreksi sebelumnya tidak berlaku lagi).
            if ($jumlahBerubah) {
                $baris->bhps()->where('stok_dipotong', false)->get()->each->delete();
                $this->bhp->siapkanDariStandar($baris);
            }
        }

        // Tindakan per gigi dengan kondisi hasil (mis. tambal → komposit) memperbarui odontogram (DG-01/07).
        $this->odontogram->sinkronDariTindakan($kunjungan, $user, $indeks);
    }

    /** Tindakan dihapus dari pemeriksaan beserta catatan, draft BHP & kondisi odontogram turunannya; consent tetap tersimpan (lepas tautan). */
    private function hapusTindakan(KunjunganTindakan $baris): void
    {
        $this->odontogram->hapusTurunan($baris);
        if ($catatan = $baris->catatan) {
            $catatan->titiks()->get()->each->delete();
            $catatan->delete();
        }
        $baris->bhps()->get()->each->delete();
        $baris->delete();
    }

    private function syncResep(Kunjungan $kunjungan, array $items, ?string $catatan, User $user): void
    {
        $resep = $kunjungan->resep;

        if ($resep && $resep->status !== StatusResep::Menunggu) {
            throw ValidationException::withMessages(['resep' => 'Resep sudah diproses farmasi dan tidak dapat diubah.']);
        }

        if (empty($items)) {
            $resep?->delete();

            return;
        }

        $resep ??= $kunjungan->resep()->create([
            'cabang_id' => $kunjungan->cabang_id,
            'no_resep' => $this->nomor->noResep(now()),
            'status' => StatusResep::Menunggu,
        ]);

        $resep->update(['dokter_id' => $user->id, 'catatan' => $catatan]);
        $resep->items()->get()->each->delete();

        $obats = Obat::whereIn('id', Arr::pluck($items, 'obat_id'))->get()->keyBy('id');

        foreach ($items as $item) {
            $resep->items()->create([
                'obat_id' => $item['obat_id'],
                'jumlah' => $item['jumlah'],
                'aturan_pakai' => $item['aturan_pakai'],
                'harga' => $obats[$item['obat_id']]->harga,
            ]);
        }
    }
}
