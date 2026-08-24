<?php

namespace App\Libraries;

/**
 * Saran posisi untuk kandidat yang digugurkan karena SALAH POSISI.
 *
 * NOL PANGGILAN API. Seluruh bahannya vektor yang sudah tersimpan: vektor CV di
 * screening_results, vektor syarat lowongan di jobs. Menghitungnya dari nol
 * menuntut 34 lowongan x 3 bidang teks embedding per kandidat, dari jatah 1.000
 * sehari - cuma cukup untuk sembilan orang.
 *
 * KENAPA HANYA BIDANG PENGALAMAN
 * ------------------------------
 * Rancangan awalnya memakai rumus berbobot 50/30/20 yang sama dengan skor
 * kemiripan CV, supaya angkanya berarti hal yang sama di kedua tempat. Rencana
 * itu dibatalkan oleh pengukuran (docs/kalibrasi-saran-posisi.md, 24 Agustus
 * 2026), bukan oleh selera:
 *
 *   | Cara              | hit@3 teks produksi | vs tebak acak |
 *   |-------------------|---------------------|---------------|
 *   | pengalaman saja   | 51,9%               | 1,90x         |
 *   | berbobot 50/30/20 | 38,5%               | 1,41x         |
 *   | pendidikan saja   | 11,1%               | 0,78x         |
 *
 * Pendidikan BUKAN sinyal lemah, ia gangguan - lebih buruk daripada menebak -
 * dan pada rumus berbobot ia memegang 20%. Membuangnya menaikkan hasil.
 *
 * Skill tidak ikut karena tidak bisa dinilai: data historisnya cuma terisi
 * 4-26%. Itu vonis atas ketiadaan data, bukan atas skill. Kalau suatu saat ada
 * cukup CV yang bidang skill-nya terbaca, ukur lagi sebelum menambahkannya.
 *
 * Skor kemiripan CV di gate TIDAK memakai kelas ini dan tidak ikut berubah.
 *
 * AMBANGNYA RELATIF, bukan angka ajaib. Yang disarankan hanya posisi yang
 * kecocokannya TIDAK LEBIH RENDAH daripada posisi yang baru saja menolak
 * kandidat. Bisa dijelaskan ke kandidat maupun atasan dalam satu kalimat, dan
 * tidak ada ambang tetap yang bisa dipertanggungjawabkan - hit@3 51,9% berarti
 * separuh saran memang meleset, jadi mengarang batas "cocok" akan menjanjikan
 * kepastian yang datanya tidak punya.
 *
 * Akibatnya daftar saran BOLEH KOSONG, dan itu jawaban yang sah: tidak ada
 * posisi yang lebih cocok untuk orang ini.
 */
final class SaranPosisi
{
    /** Jumlah saran yang ditampilkan. */
    public const JUMLAH = 3;

    /**
     * Bidang yang dipakai memeringkat.
     *
     * Satu bidang, jadi tidak ada bobot yang harus dibela ke siapa pun. Kalau
     * ini diubah, ukur ulang dengan kalibrasi/saran_posisi.py lebih dulu.
     */
    public const BIDANG = 'pengalaman';

    /**
     * Cosine similarity dua vektor. Panjang beda atau vektor nol -> 0.0.
     *
     * @param list<float> $a
     * @param list<float> $b
     */
    public static function cosine(array $a, array $b): float
    {
        $n = count($a);
        if ($n === 0 || $n !== count($b)) {
            return 0.0;
        }

        $dot = $na = $nb = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $x = (float) $a[$i];
            $y = (float) $b[$i];
            $dot += $x * $y;
            $na += $x * $x;
            $nb += $y * $y;
        }

        if ($na === 0.0 || $nb === 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($na) * sqrt($nb));
    }

    /**
     * Kecocokan CV dengan satu lowongan, 0..1. null = tidak bisa dinilai.
     *
     * DIPAKAI JUGA UNTUK MENGHITUNG AMBANG. Ambangnya kecocokan dengan posisi
     * yang menolak kandidat, dan ia wajib dihitung lewat fungsi ini - bukan
     * diambil dari screening_results.score_overall, yang memakai rumus berbobot
     * dan karenanya berskala lain. Membandingkan keduanya akan menyaring saran
     * dengan batas yang tidak sebanding.
     *
     * @param array<string, list<float>> $vektorCv
     * @param array<string, list<float>> $vektorJob
     */
    public static function skor(array $vektorCv, array $vektorJob): ?float
    {
        $a = $vektorCv[self::BIDANG] ?? null;
        $b = $vektorJob[self::BIDANG] ?? null;
        if (! is_array($a) || ! is_array($b) || $a === [] || $b === []) {
            return null;
        }

        // Cosine bisa negatif; dipangkas ke 0 seperti scoring.ke_0_1() di
        // ai-service. Nilai negatif berarti tidak mirip sama sekali, bukan
        // setengah mirip.
        return round(max(0.0, min(1.0, self::cosine($a, $b))), 4);
    }

    /**
     * Tiga posisi teratas yang setidaknya sama cocoknya dengan $ambang.
     *
     * $lowongan: baris jobs apa adanya, wajib memuat id, judul, vektor_json.
     * $kecuali: id lowongan yang tidak boleh diusulkan - yang sudah pernah
     *           dilamar kandidat ini, termasuk yang baru saja menolaknya.
     *
     * @param array<string, list<float>> $vektorCv
     * @param list<array<string, mixed>> $lowongan
     * @param list<int>                  $kecuali
     *
     * @return list<array{id: int, judul: string, skor: float}> urut dari paling cocok
     */
    public static function untuk(array $vektorCv, array $lowongan, float $ambang, array $kecuali = []): array
    {
        if ($vektorCv === []) {
            return [];
        }

        $hasil = [];
        foreach ($lowongan as $j) {
            $id = (int) ($j['id'] ?? 0);
            if ($id === 0 || in_array($id, $kecuali, true)) {
                continue;
            }

            $vj = json_decode((string) ($j['vektor_json'] ?? ''), true);
            if (! is_array($vj)) {
                continue;
            }

            $s = self::skor($vektorCv, $vj);
            // Skor 0 berarti dua vektornya tegak lurus - tidak ada satu pun
            // kesamaan yang terukur. Ia bisa lolos ambang saat posisi penolak
            // juga bernilai 0, dan hasilnya daftar berisi posisi yang tidak
            // punya hubungan apa pun dengan CV kandidat. Itu bukan saran.
            if ($s === null || $s <= 0.0 || $s < $ambang) {
                continue;
            }

            $hasil[] = ['id' => $id, 'judul' => (string) ($j['judul'] ?? ''), 'skor' => $s];
        }

        // Urutan kedua memakai id supaya dua posisi berskor sama persis - dan
        // itu sering terjadi pada lowongan kembar seperti "Retail Gadget Ibox"
        // dan "Retail Gadget Erafone" - selalu tampil dalam urutan yang sama.
        usort($hasil, static fn (array $a, array $b): int => [$b['skor'], $a['id']] <=> [$a['skor'], $b['id']]);

        return array_slice($hasil, 0, self::JUMLAH);
    }
}
