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
use App\Services\SatuSehat\SatuSehatService;
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
     * Simpan (upsert) data pemeriksaan. Tanpa izin pemeriksaan.dokter (perawat, terapis) hanya tanda vital &
     * anamnesis (subjektif) yang disimpan; pemegang izin pemeriksaan.dokter mengisi seluruh SOAP, diagnosa,
     * tindakan, resep, dan penanda akses terbatas.
     */
    public function simpan(Kunjungan $kunjungan, array $data, User $user): Kunjungan
    {
        if (! $kunjungan->terbuka()) {
            throw ValidationException::withMessages(['status' => 'Pemeriksaan sudah ditutup dan tidak dapat diubah.']);
        }

        $isPerawat = ! $user->punyaIzin(Izin::PemeriksaanDokter);

        return DB::transaction(function () use ($kunjungan, $data, $user, $isPerawat) {
            $fields = $isPerawat
                ? [...Pemeriksaan::VITAL_FIELDS, 'subjektif']
                : [...Pemeriksaan::VITAL_FIELDS, ...Pemeriksaan::SOAP_FIELDS];

            $attributes = Arr::only($data, $fields);
            $attributes[$isPerawat ? 'perawat_id' : 'dokter_id'] = $user->id;

            $pemeriksaan = $kunjungan->pemeriksaan()->updateOrCreate(['kunjungan_id' => $kunjungan->id], $attributes);

            if (! $isPerawat) {
                if (array_key_exists('diagnosas', $data)) {
                    $this->syncDiagnosa($pemeriksaan, $data['diagnosas']);
                }
                if (array_key_exists('tindakans', $data)) {
                    $this->syncTindakan($kunjungan, $data['tindakans'], $user);
                }
                if (array_key_exists('resep', $data)) {
                    $this->syncResep($kunjungan, $data['resep'] ?? [], $data['catatan_resep'] ?? null, $user, (bool) ($data['abaikan_alergi'] ?? false));
                }
                $this->aturAksesTerbatas($kunjungan, $pemeriksaan, $data);
            }

            return $kunjungan->loadDetail();
        });
    }

    /**
     * Dokter menutup & menandatangani pemeriksaan (RM-07); tagihan dibuat dan pasien diarahkan ke kasir.
     * Syarat: minimal satu diagnosa, penutup ber-SIP aktif, dan informed consent lengkap untuk treatment yang mewajibkannya.
     */
    public function selesai(Kunjungan $kunjungan, User $user): Kunjungan
    {
        if ($kunjungan->status !== StatusKunjungan::Diperiksa) {
            throw ValidationException::withMessages(['status' => 'Pasien belum dipanggil atau pemeriksaan sudah selesai.']);
        }

        $pemeriksaan = $kunjungan->pemeriksaan;

        if (! $pemeriksaan || $pemeriksaan->diagnosas()->doesntExist()) {
            throw ValidationException::withMessages(['diagnosas' => 'Minimal satu diagnosa (ICD-10) harus diisi sebelum menyelesaikan pemeriksaan.']);
        }

        $this->rekamMedis->pastikanBolehMenandatangani($user);
        $this->consent->pastikanLengkap($kunjungan);

        return DB::transaction(function () use ($kunjungan, $user) {
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

            // SATUSEHAT (SS-04/05): masuk antrean kirim setelah transaksi berhasil (tanpa efek bila integrasi nonaktif)
            app(SatuSehatService::class)->antrekan($kunjungan);

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
     */
    private function syncTindakan(Kunjungan $kunjungan, array $tindakans, User $user): void
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

        $sisa = $kunjungan->tindakans()->get()->keyBy('id');
        $pasangan = [];

        foreach ($tindakans as $i => $item) {
            $baris = isset($item['id']) ? $sisa->get($item['id']) : null;
            if ($baris && (int) $baris->tindakan_id === (int) $item['tindakan_id']) {
                $pasangan[$i] = $sisa->pull($baris->id);
            }
        }
        foreach ($tindakans as $i => $item) {
            $cocok = fn ($t) => (int) $t->tindakan_id === (int) $item['tindakan_id'] && (int) $t->gigi === (int) ($item['gigi'] ?? 0);
            if (! isset($pasangan[$i]) && ($baris = $sisa->first($cocok))) {
                $pasangan[$i] = $sisa->pull($baris->id);
            }
        }

        // Hapus per model (bukan query massal) agar tercatat di audit log, termasuk catatan tindakannya.
        $sisa->each(fn (KunjunganTindakan $baris) => $this->hapusTindakan($baris));

        // Petugas default: dokter yang mengisi, atau dokter kunjungan (dasar komisi; bisa diubah per tindakan).
        $petugasDefault = $user->tercatatSebagaiDokter() ? $user->id : $kunjungan->dokter_id;

        $indeks = [];

        foreach ($tindakans as $i => $item) {
            $baris = $pasangan[$i] ?? null;
            $tindakan = $master[$item['tindakan_id']];
            $atribut = [
                'jumlah' => $item['jumlah'] ?? 1,
                'tarif' => $tindakan->tarif_cabang,
                'keterangan' => $item['keterangan'] ?? null,
                'petugas_id' => array_key_exists('petugas_id', $item) ? $item['petugas_id'] : ($baris ? $baris->petugas_id : $petugasDefault),
                'icd9cm_id' => array_key_exists('icd9cm_id', $item) ? $item['icd9cm_id'] : ($baris ? $baris->icd9cm_id : $tindakan->icd9cm_id),
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

    private function syncResep(Kunjungan $kunjungan, array $items, ?string $catatan, User $user, bool $abaikanAlergi = false): void
    {
        $resep = $kunjungan->resep;

        if ($resep && $resep->status !== StatusResep::Menunggu) {
            throw ValidationException::withMessages(['resep' => 'Resep sudah diproses farmasi dan tidak dapat diubah.']);
        }

        if (empty($items)) {
            $resep?->delete();

            return;
        }

        // Obat per baris: obat jadi = obat_id; racikan = obat komponen. Kunci error mengikuti posisi di payload.
        $obatPerBaris = [];
        foreach ($items as $i => $item) {
            if (! empty($item['racikan'])) {
                foreach ($item['komponen'] ?? [] as $k => $komponen) {
                    $obatPerBaris["resep.{$i}.komponen.{$k}.obat_id"] = (int) $komponen['obat_id'];
                }
            } else {
                $obatPerBaris["resep.{$i}.obat_id"] = isset($item['obat_id']) ? (int) $item['obat_id'] : null;
            }
        }

        // Peringatan alergi obat (PS-03, FR-02): obat yang baru ditambahkan & tercatat sebagai alergi pasien butuh konfirmasi dokter.
        // Obat yang sudah ada di resep sebelumnya (sudah dikonfirmasi) tidak ditanyakan ulang.
        if (! $abaikanAlergi && ($alergi = app(ProfilKlinisService::class)->obatAlergi($kunjungan->pasien_id))) {
            $sebelumnya = $resep
                ? $resep->items()->pluck('obat_id')->merge(
                    DB::table('resep_item_komponens')->whereIn('resep_item_id', $resep->items()->pluck('id'))->pluck('obat_id'),
                )->filter()->all()
                : [];

            foreach ($obatPerBaris as $kunci => $obatId) {
                if ($obatId && isset($alergi[$obatId]) && ! in_array($obatId, $sebelumnya, false)) {
                    throw ValidationException::withMessages([
                        $kunci => "Pasien tercatat alergi {$alergi[$obatId]}. Konfirmasi bila tetap diresepkan.",
                        'konfirmasi_alergi' => 'Resep memuat obat yang tercatat sebagai alergi pasien.',
                    ]);
                }
            }
        }

        $obats = Obat::whereIn('id', array_filter($obatPerBaris))->get()->keyBy('id');
        $biayaRacik = (int) app(PengaturanService::class)->get('farmasi.biaya_racik');

        // Validasi bentuk baris sebelum resep diubah
        foreach ($items as $i => $item) {
            if (! empty($item['racikan'])) {
                $wajib = ['nama_racikan' => 'Nama racikan wajib diisi.', 'bentuk' => 'Pilih bentuk racikan.'];
                foreach ($wajib as $field => $pesan) {
                    if (empty($item[$field])) {
                        throw ValidationException::withMessages(["resep.{$i}.{$field}" => $pesan]);
                    }
                }
                if (empty($item['komponen'])) {
                    throw ValidationException::withMessages(["resep.{$i}.komponen" => 'Racikan butuh minimal satu komponen obat.']);
                }
                foreach ($item['komponen'] as $k => $komponen) {
                    $obat = $obats[(int) $komponen['obat_id']];
                    if (! $obat->fraksional && abs($komponen['jumlah'] - round($komponen['jumlah'])) > 0.0005) {
                        throw ValidationException::withMessages([
                            "resep.{$i}.komponen.{$k}.jumlah" => "{$obat->nama} tidak bisa dipakai sebagian; isi bilangan bulat {$obat->satuan}.",
                        ]);
                    }
                }
            } elseif (empty($item['obat_id'])) {
                throw ValidationException::withMessages(["resep.{$i}.obat_id" => 'Pilih obat.']);
            }
        }

        $resep ??= $kunjungan->resep()->create([
            'cabang_id' => $kunjungan->cabang_id,
            'no_resep' => $this->nomor->noResep(now()),
            'status' => StatusResep::Menunggu,
        ]);

        $resep->update(['dokter_id' => $user->id, 'catatan' => $catatan]);
        $resep->items()->get()->each->delete();

        foreach ($items as $item) {
            if (empty($item['racikan'])) {
                $resep->items()->create([
                    'obat_id' => $item['obat_id'],
                    'jumlah' => $item['jumlah'],
                    'aturan_pakai' => $item['aturan_pakai'],
                    'harga' => $obats[$item['obat_id']]->harga,
                ]);

                continue;
            }

            // Harga satu racikan = Σ komponen × harga satuan (dibulatkan ke atas) + biaya racik (pengaturan).
            $hargaKomponen = collect($item['komponen'])->sum(fn ($k) => (int) ceil($k['jumlah'] * $obats[(int) $k['obat_id']]->harga));
            $baris = $resep->items()->create([
                'obat_id' => null,
                'racikan' => true,
                'nama_racikan' => $item['nama_racikan'],
                'bentuk' => $item['bentuk'],
                'jumlah_racikan' => $item['jumlah_racikan'] ?? null,
                'satuan_racikan' => $item['satuan_racikan'] ?? null,
                'jumlah' => $item['jumlah'],
                'aturan_pakai' => $item['aturan_pakai'],
                'harga' => $hargaKomponen + $biayaRacik,
                'biaya_racik' => $biayaRacik,
            ]);

            foreach ($item['komponen'] as $k) {
                $baris->komponens()->create([
                    'obat_id' => $k['obat_id'],
                    'jumlah' => $k['jumlah'],
                    'harga' => $obats[(int) $k['obat_id']]->harga,
                ]);
            }
        }
    }
}
