<?php

namespace App\Libraries;

/**
 * Skala sementara untuk penilaian per jawaban dari pendamping wawancara suara.
 * Hasil ini hanya ditampilkan ke recruiter; keputusan resmi tetap mengikuti
 * lembar penilaian yang dipakai alur recruiter saat ini.
 */
final class PenilaianRubrik
{
    public const TINGKAT = ['kurang' => 0, 'cukup' => 1, 'baik' => 2];
    public const LABEL = ['kurang' => 'Kurang', 'cukup' => 'Cukup', 'baik' => 'Baik'];
    public const MAKS_CATATAN = 500;

    /** @param array<string, mixed>|string $soal */
    public static function dinilai(array|string $soal): bool
    {
        return is_array($soal) && (int) ($soal['bobot'] ?? 0) > 0;
    }

    /**
     * @param list<mixed> $rubrik
     * @param array<int|string, mixed> $nilai
     * @param array<int|string, mixed> $catatan
     * @return list<array<string, mixed>>
     */
    public static function rakit(array $rubrik, array $nilai, array $catatan = []): array
    {
        $hasil = [];
        foreach ($rubrik as $i => $soal) {
            if (! self::dinilai($soal)) {
                continue;
            }
            $tingkat = (string) ($nilai[$i] ?? '');
            if (! isset(self::TINGKAT[$tingkat])) {
                continue;
            }
            $teks = trim(preg_replace('/\s+/u', ' ', (string) ($catatan[$i] ?? '')));
            $hasil[] = [
                'kompetensi' => (string) ($soal['kompetensi'] ?? ''),
                'bobot'      => (int) $soal['bobot'],
                'tingkat'    => $tingkat,
                'catatan'    => mb_substr($teks, 0, self::MAKS_CATATAN),
            ];
        }
        return $hasil;
    }

    /** @param list<array<string, mixed>> $penilaian */
    public static function skor(array $penilaian): ?int
    {
        $dapat = 0;
        $maks = 0;
        foreach ($penilaian as $baris) {
            $bobot = (int) ($baris['bobot'] ?? 0);
            if ($bobot <= 0 || ! isset(self::TINGKAT[$baris['tingkat'] ?? ''])) {
                continue;
            }
            $dapat += self::TINGKAT[$baris['tingkat']] * $bobot;
            $maks += 2 * $bobot;
        }
        return $maks === 0 ? null : (int) round($dapat / $maks * 100);
    }
}
