<?php

namespace App\Libraries;

/**
 * Menghitung skor interview 0-100 dari penilaian per kompetensi.
 *
 * Menggantikan slider 0-100 yang digeser recruiter sesuka hati lalu ikut
 * menentukan Gate 2. Angka slider itu tidak punya dasar apa pun dan tidak bisa
 * dijelaskan ke kandidat yang bertanya kenapa ia tidak lolos.
 *
 * Tiga tingkat, bukan lima. Pada wawancara 30 menit, pembedaan lima tingkat
 * cenderung semu: dua penilai memberi 3 dan 4 untuk jawaban yang sama tanpa
 * alasan yang bisa dibedakan. Tiga tingkat memaksa keputusan yang sungguh
 * berbeda, dan lebih cepat diisi sambil mendengarkan.
 *
 * Pertanyaan TANPA bobot tidak ikut dihitung. Itu butir berkategori "Lainnya"
 * (ekspektasi gaji, kesediaan penempatan, pembuka) - memang ditanyakan, tapi
 * bukan penilaian kemampuan. Gagasan pemisahan ini dari tim DS.
 */
final class PenilaianRubrik
{
    /** Nilai numerik tiap tingkat. Baik = 2 supaya rentangnya 0..2 per butir. */
    public const TINGKAT = ['kurang' => 0, 'cukup' => 1, 'baik' => 2];

    public const MAKS_PER_BUTIR = 2;

    /** Label untuk ditampilkan, urut dari terendah. */
    public const LABEL = ['kurang' => 'Kurang', 'cukup' => 'Cukup', 'baik' => 'Baik'];

    public const MAKS_CATATAN = 500;

    /**
     * Apakah satu butir rubrik ikut dinilai?
     *
     * @param array<string, mixed>|string $soal
     */
    public static function dinilai(array|string $soal): bool
    {
        return is_array($soal) && (int) ($soal['bobot'] ?? 0) > 0;
    }

    /**
     * Rakit penilaian dari kiriman form, dipasangkan dengan rubrik tersimpan.
     *
     * Bobot dan kompetensi diambil dari RUBRIK, bukan dari kiriman browser -
     * pola yang sama dengan halaman pertanyaan. Yang boleh datang dari form
     * hanya tingkat dan catatan.
     *
     * @param list<mixed>            $rubrik daftar soal milik lowongan
     * @param array<int|string, mixed> $nilai  kiriman form, kunci = indeks soal
     * @param array<int|string, mixed> $catatan
     *
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
                continue;   // belum diisi: tidak ikut, bukan dianggap nol
            }

            $c = trim(preg_replace('/\s+/u', ' ', (string) ($catatan[$i] ?? '')));

            $hasil[] = [
                'kompetensi' => (string) ($soal['kompetensi'] ?? ''),
                'kategori'   => (string) ($soal['kategori'] ?? ''),
                'bobot'      => (int) $soal['bobot'],
                'tingkat'    => $tingkat,
                'catatan'    => mb_substr($c, 0, self::MAKS_CATATAN),
            ];
        }

        return $hasil;
    }

    /** Berapa butir rubrik yang seharusnya dinilai. */
    public static function jumlahDinilai(array $rubrik): int
    {
        return count(array_filter($rubrik, [self::class, 'dinilai']));
    }

    /**
     * Skor 0-100 dari penilaian yang sudah dirakit.
     *
     * Rata-rata BERBOBOT, bukan jumlah: dengan bobot 4 dan 5 di bank tim DS,
     * penjumlahan mentah membuat skor bergantung pada BANYAKNYA pertanyaan,
     * sehingga posisi dengan 9 soal dan 10 soal tidak sebanding.
     *
     * @param list<array<string, mixed>> $penilaian
     *
     * @return int|null null = tak satu pun butir dinilai
     */
    public static function skor(array $penilaian): ?int
    {
        $dapat = 0;
        $maks  = 0;
        foreach ($penilaian as $p) {
            $b = (int) ($p['bobot'] ?? 0);
            if ($b <= 0 || ! isset(self::TINGKAT[$p['tingkat'] ?? ''])) {
                continue;
            }
            $dapat += self::TINGKAT[$p['tingkat']] * $b;
            $maks  += self::MAKS_PER_BUTIR * $b;
        }

        return $maks === 0 ? null : (int) round($dapat / $maks * 100);
    }

    /**
     * Kompetensi terlemah, untuk ditulis di riwayat kandidat.
     *
     * Inilah yang membuat keputusan bisa dijelaskan: bukan "skor 62", melainkan
     * butir mana yang kurang.
     *
     * @param list<array<string, mixed>> $penilaian
     *
     * @return list<string>
     */
    public static function terlemah(array $penilaian, int $maks = 3): array
    {
        $kurang = array_filter($penilaian, static fn (array $p): bool => ($p['tingkat'] ?? '') === 'kurang');
        usort($kurang, static fn (array $a, array $b): int => ($b['bobot'] ?? 0) <=> ($a['bobot'] ?? 0));

        return array_slice(array_column($kurang, 'kompetensi'), 0, $maks);
    }
}
