<?php

namespace App\Services\SatuSehat;

use App\Models\Kunjungan;
use Illuminate\Support\Str;

/**
 * Susun Bundle transaksi FHIR R4 untuk satu kunjungan rawat jalan (PRD v2 SS-04): Encounter, Condition (ICD-10 per diagnosa),
 * Observation (tanda vital, LOINC + UCUM), Procedure (ICD-9-CM per tindakan), dan MedicationRequest untuk obat ber-kode KFA.
 * Referensi antar-resource memakai urn:uuid sehingga dikirim dalam satu permintaan.
 */
class FhirMapper
{
    /** Tanda vital → [kode LOINC, nama, satuan UCUM, satuan tampil]. */
    private const VITAL = [
        'nadi' => ['8867-4', 'Heart rate', '/min', 'beats/minute'],
        'respirasi' => ['9279-1', 'Respiratory rate', '/min', 'breaths/minute'],
        'suhu' => ['8310-5', 'Body temperature', 'Cel', 'C'],
        'berat_badan' => ['29463-7', 'Body weight', 'kg', 'kg'],
        'tinggi_badan' => ['8302-2', 'Body height', 'cm', 'cm'],
    ];

    /**
     * @param  array{patient: string, practitioner: string, location: string, organization: string}  $ids
     */
    public function bundle(Kunjungan $kunjungan, array $ids): array
    {
        $kunjungan->loadMissing([
            'pasien', 'dokter', 'cabang', 'poli',
            'pemeriksaan.diagnosas.icd10', 'tindakans.icd9cm', 'tindakans.tindakan', 'resep.items.obat',
        ]);

        $pasien = $kunjungan->pasien;
        $dokter = $kunjungan->dokter;
        $mulai = ($kunjungan->dipanggil_at ?? $kunjungan->created_at)->toIso8601String();
        $tiba = $kunjungan->created_at->toIso8601String();
        $selesai = ($kunjungan->selesai_at ?? now())->toIso8601String();

        $subject = ['reference' => "Patient/{$ids['patient']}", 'display' => $pasien->nama];
        $praktisi = ['reference' => "Practitioner/{$ids['practitioner']}", 'display' => $dokter?->name];
        $uuidEncounter = (string) Str::uuid();
        $refEncounter = ['reference' => "urn:uuid:{$uuidEncounter}", 'display' => "Kunjungan {$kunjungan->no_registrasi}"];

        $entri = [];
        $diagnosis = [];

        foreach ($kunjungan->pemeriksaan?->diagnosas ?? [] as $rank => $d) {
            $uuid = (string) Str::uuid();
            $diagnosis[] = [
                'condition' => ['reference' => "urn:uuid:{$uuid}", 'display' => $d->icd10->nama],
                'use' => ['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/diagnosis-role', 'code' => 'DD', 'display' => 'Discharge diagnosis']]],
                'rank' => $rank + 1,
            ];
            $entri[] = $this->entri($uuid, 'Condition', [
                'resourceType' => 'Condition',
                'clinicalStatus' => ['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/condition-clinical', 'code' => 'active', 'display' => 'Active']]],
                'category' => [['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/condition-category', 'code' => 'encounter-diagnosis', 'display' => 'Encounter Diagnosis']]]],
                'code' => ['coding' => [['system' => 'http://hl7.org/fhir/sid/icd-10', 'code' => $d->icd10->kode, 'display' => $d->icd10->nama]]],
                'subject' => $subject,
                'encounter' => $refEncounter,
            ]);
        }

        array_unshift($entri, $this->entri($uuidEncounter, 'Encounter', [
            'resourceType' => 'Encounter',
            'identifier' => [['system' => "http://sys-ids.kemkes.go.id/encounter/{$ids['organization']}", 'value' => $kunjungan->no_registrasi]],
            'status' => 'finished',
            'class' => ['system' => 'http://terminology.hl7.org/CodeSystem/v3-ActCode', 'code' => 'AMB', 'display' => 'ambulatory'],
            'subject' => $subject,
            'participant' => [[
                'type' => [['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/v3-ParticipationType', 'code' => 'ATND', 'display' => 'attender']]]],
                'individual' => $praktisi,
            ]],
            'period' => ['start' => $tiba, 'end' => $selesai],
            'location' => [['location' => ['reference' => "Location/{$ids['location']}", 'display' => "{$kunjungan->cabang?->nama} · {$kunjungan->poli?->nama}"]]],
            'diagnosis' => $diagnosis,
            'statusHistory' => [
                ['status' => 'arrived', 'period' => ['start' => $tiba, 'end' => $mulai]],
                ['status' => 'in-progress', 'period' => ['start' => $mulai, 'end' => $selesai]],
                ['status' => 'finished', 'period' => ['start' => $selesai, 'end' => $selesai]],
            ],
            'serviceProvider' => ['reference' => "Organization/{$ids['organization']}"],
        ]));

        foreach ($this->observasi($kunjungan) as [$kode, $nama, $nilai, $ucum, $unit]) {
            $entri[] = $this->entri((string) Str::uuid(), 'Observation', [
                'resourceType' => 'Observation',
                'status' => 'final',
                'category' => [['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/observation-category', 'code' => 'vital-signs', 'display' => 'Vital Signs']]]],
                'code' => ['coding' => [['system' => 'http://loinc.org', 'code' => $kode, 'display' => $nama]]],
                'subject' => $subject,
                'performer' => [$praktisi],
                'encounter' => $refEncounter,
                'effectiveDateTime' => $mulai,
                'issued' => $selesai,
                'valueQuantity' => ['value' => $nilai, 'unit' => $unit, 'system' => 'http://unitsofmeasure.org', 'code' => $ucum],
            ]);
        }

        foreach ($kunjungan->tindakans as $t) {
            if (! $t->icd9cm) {
                continue; // Procedure SATUSEHAT butuh kode ICD-9-CM
            }
            $entri[] = $this->entri((string) Str::uuid(), 'Procedure', [
                'resourceType' => 'Procedure',
                'status' => 'completed',
                'code' => ['coding' => [['system' => 'http://hl7.org/fhir/sid/icd-9-cm', 'code' => $t->icd9cm->kode, 'display' => $t->icd9cm->nama]]],
                'subject' => $subject,
                'encounter' => $refEncounter,
                'performedPeriod' => ['start' => $mulai, 'end' => $selesai],
                'performer' => [['actor' => $praktisi]],
                'note' => [['text' => $t->tindakan?->nama]],
            ]);
        }

        foreach ($kunjungan->resep?->items ?? [] as $item) {
            if ($item->racikan || ! $item->obat?->kode_kfa) {
                continue; // MedicationRequest SATUSEHAT mensyaratkan kode KFA; racikan menyusul (butuh Medication compound)
            }
            $entri[] = $this->entri((string) Str::uuid(), 'MedicationRequest', [
                'resourceType' => 'MedicationRequest',
                'status' => 'completed',
                'intent' => 'order',
                'medicationCodeableConcept' => ['coding' => [['system' => 'http://sys-ids.kemkes.go.id/kfa', 'code' => $item->obat->kode_kfa, 'display' => $item->obat->nama]]],
                'subject' => $subject,
                'encounter' => $refEncounter,
                'authoredOn' => $selesai,
                'requester' => $praktisi,
                'dosageInstruction' => [['text' => $item->aturan_pakai]],
                'dispenseRequest' => ['quantity' => ['value' => $item->jumlah, 'unit' => $item->obat->satuan]],
            ]);
        }

        return ['resourceType' => 'Bundle', 'type' => 'transaction', 'entry' => $entri];
    }

    /** @return list<array{0: string, 1: string, 2: float, 3: string, 4: string}> */
    private function observasi(Kunjungan $kunjungan): array
    {
        $p = $kunjungan->pemeriksaan;
        if (! $p) {
            return [];
        }

        $hasil = [];
        foreach (self::VITAL as $kolom => [$kode, $nama, $ucum, $unit]) {
            if (is_numeric($p->{$kolom})) {
                $hasil[] = [$kode, $nama, (float) $p->{$kolom}, $ucum, $unit];
            }
        }

        if (preg_match('/^(\d{2,3})\s*\/\s*(\d{2,3})$/', (string) $p->tekanan_darah, $m)) {
            $hasil[] = ['8480-6', 'Systolic blood pressure', (float) $m[1], 'mm[Hg]', 'mm[Hg]'];
            $hasil[] = ['8462-4', 'Diastolic blood pressure', (float) $m[2], 'mm[Hg]', 'mm[Hg]'];
        }

        return $hasil;
    }

    private function entri(string $uuid, string $tipe, array $resource): array
    {
        return ['fullUrl' => "urn:uuid:{$uuid}", 'resource' => $resource, 'request' => ['method' => 'POST', 'url' => $tipe]];
    }
}
