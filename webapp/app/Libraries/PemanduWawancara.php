<?php

namespace App\Libraries;

/**
 * Aturan jalannya wawancara suara: butir mana yang ditanyakan, kapan AI
 * mendalami jawaban, dan kapan sesi ditutup.
 *
 * Seluruhnya fungsi murni tanpa basis data dan tanpa panggilan HTTP. Alasannya
 * bukan kerapian semata: inilah bagian yang menentukan pengalaman kandidat -
 * ditanya apa, didalami berapa kali, dan kapan dilepas - dan itu harus bisa
 * diuji tanpa mikrofon, tanpa LLM, dan tanpa menunggu jendela jadwal terbuka.
 *
 * Kosakata tingkat mengikuti PenilaianRubrik, dan skornya dihitung oleh
 * PenilaianRubrik juga. Satu rubrik, satu rumus, apa pun yang mengisinya -
 * recruiter atau model.
 */
final class PemanduWawancara
{
    public const SAPAAN = 'Selamat datang. Saya asisten wawancara BIPROO dan akan menemani sesi ini '
        . 'bersama recruiter Anda. Saya akan mengajukan beberapa pertanyaan, jawablah dengan suara '
        . 'seperti berbicara biasa. Setelah Anda selesai bicara dan diam sejenak, saya lanjut ke '
        . 'pertanyaan berikutnya.';

    public const PENUTUP = 'Terima kasih, pertanyaan dari saya sudah selesai. Silakan lanjutkan '
        . 'percakapan dengan recruiter Anda di Zoom.';

    /**
     * Butir yang akan ditanyakan AI, urut tanya.
     *
     * Bank soal tim DS memuat 15-24 butir; suara tidak sanggup membacakan
     * semuanya dalam sesi 30 menit. Yang diambil adalah butir dengan bobot
     * tertinggi - bila terpaksa memilih, yang paling menentukan skor didahulukan.
     * Urutan aslinya tetap dipertahankan supaya wawancaranya mengalir sebagaimana
     * disusun perekrut, bukan melompat menurut bobot.
     *
     * Butir tanpa bobot ("Lainnya": ekspektasi gaji, kesediaan penempatan) ikut
     * hanya bila kuotanya masih sisa. Ia tetap ditanyakan dan tetap dicatat,
     * tapi tidak ikut menghitung skor - persis aturan PenilaianRubrik.
     *
     * @param list<mixed> $rubrik isi jobs.pertanyaan_json
     *
     * @return list<array{index:int, pertanyaan:string, kompetensi:string, bobot:int, indikator:mixed, red_flag:mixed}>
     */
    public static function rencana(array $rubrik, int $maks): array
    {
        $semua = [];
        foreach ($rubrik as $i => $soal) {
            // Dua bentuk entri yang sah, sama seperti di Recruiter::rapikan():
            // string (hasil LLM, pertanyaan saja) atau objek (bank tim DS,
            // pertanyaan + rubrik penilaiannya).
            $teks = is_array($soal) ? (string) ($soal['pertanyaan'] ?? '') : (is_string($soal) ? $soal : '');
            if (trim($teks) === '') {
                continue;
            }
            $semua[] = [
                'index'      => (int) $i,
                'pertanyaan' => $teks,
                'kompetensi' => is_array($soal) ? (string) ($soal['kompetensi'] ?? '') : '',
                'bobot'      => is_array($soal) ? (int) ($soal['bobot'] ?? 0) : 0,
                'indikator'  => is_array($soal) ? ($soal['indikator'] ?? '') : '',
                'red_flag'   => is_array($soal) ? ($soal['red_flag'] ?? '') : '',
            ];
        }

        if (count($semua) <= $maks) {
            return $semua;
        }

        // Pilih menurut bobot (stabil: bobot sama -> urutan asli menang), lalu
        // kembalikan ke urutan asli untuk ditanyakan.
        $urut = $semua;
        usort($urut, static fn (array $a, array $b): int => [$b['bobot'], $a['index']] <=> [$a['bobot'], $b['index']]);
        $terpilih = array_slice($urut, 0, $maks);
        usort($terpilih, static fn (array $a, array $b): int => $a['index'] <=> $b['index']);

        return $terpilih;
    }

    /**
     * Giliran berikutnya, atau penanda penutup bila sesi sudah habis.
     *
     * $balasan adalah hasil penilaian jawaban yang BARU SAJA masuk (null saat
     * sesi dimulai). Sengaja dioper, bukan dibaca ulang dari $dialog: keputusan
     * "dalami atau lanjut" bergantung pada `nyambung` dan teks lanjutan yang
     * hanya ada di balasan itu dan tidak disimpan ke basis data.
     *
     * @param list<array>               $rencana hasil rencana()
     * @param list<array<string,mixed>> $dialog  baris interview_dialog, urut
     * @param array{tingkat:?string, lanjutan:string, nyambung:bool}|null $balasan
     *
     * @return array{sumber:string, butir_index:?int, pertanyaan:string, kompetensi:string, bobot:int}
     */
    public static function berikutnya(
        array $rencana,
        array $dialog,
        ?array $balasan,
        int $maksLanjutan,
        int $maksGiliran,
    ): array {
        $terjawab = array_values(array_filter(
            $dialog,
            static fn (array $r): bool => $r['sumber'] !== 'penutup' && ($r['jawaban'] ?? null) !== null,
        ));

        // Pagar keras kuota LLM. Berlaku melintasi berapa pun kali halaman
        // dibuka ulang, karena dihitung dari isi basis data, bukan dari memori
        // satu kunjungan.
        if (count($terjawab) >= $maksGiliran) {
            return self::penutup();
        }

        $terakhir = $terjawab === [] ? null : $terjawab[count($terjawab) - 1];

        if ($balasan !== null && $terakhir !== null) {
            $butir = $terakhir['butir_index'];
            $teks  = trim((string) ($balasan['lanjutan'] ?? ''));

            // Tidak nyambung: ulangi pertanyaan yang sama, sekali saja. Dua kali
            // gagal biasanya bukan salah dengar melainkan mikrofonnya bermasalah,
            // dan memaksa kandidat mengulang terus itu mempermalukan.
            if (($balasan['nyambung'] ?? true) === false && ! self::pernah($dialog, 'ulangi', $butir)) {
                return [
                    'sumber'      => 'ulangi',
                    'butir_index' => $butir,
                    'pertanyaan'  => $teks !== '' ? $teks : (string) $terakhir['pertanyaan'],
                    'kompetensi'  => (string) ($terakhir['kompetensi'] ?? ''),
                    'bobot'       => (int) ($terakhir['bobot'] ?? 0),
                ];
            }

            // Pendalaman hanya untuk jawaban yang MEMANG berisi. Menggali jawaban
            // "kurang" berarti membakar anggaran pada kandidat yang belum
            // menjawab pertanyaan pertamanya - yang ia butuhkan pertanyaan lain,
            // bukan pertanyaan yang sama diperdalam.
            $sisa = $maksLanjutan - count(array_filter(
                $dialog,
                static fn (array $r): bool => $r['sumber'] === 'lanjutan',
            ));
            if (
                $teks !== ''
                && $sisa > 0
                && in_array($balasan['tingkat'] ?? null, ['cukup', 'baik'], true)
                && ! self::pernah($dialog, 'lanjutan', $butir)
            ) {
                return [
                    'sumber'      => 'lanjutan',
                    'butir_index' => $butir,
                    'pertanyaan'  => $teks,
                    'kompetensi'  => (string) ($terakhir['kompetensi'] ?? ''),
                    'bobot'       => (int) ($terakhir['bobot'] ?? 0),
                ];
            }
        }

        // Butir bank pertama yang belum pernah ditanyakan.
        $sudah = array_column(
            array_filter($dialog, static fn (array $r): bool => $r['sumber'] === 'bank'),
            'butir_index',
        );
        foreach ($rencana as $b) {
            if (! in_array($b['index'], array_map('intval', $sudah), true)) {
                return [
                    'sumber'      => 'bank',
                    'butir_index' => $b['index'],
                    'pertanyaan'  => $b['pertanyaan'],
                    'kompetensi'  => $b['kompetensi'],
                    'bobot'       => $b['bobot'],
                ];
            }
        }

        return self::penutup();
    }

    /**
     * Tingkat final tiap butir: giliran TERAKHIR yang menang.
     *
     * Pendalaman menimpa penilaian pertanyaan induknya dengan sengaja. Kandidat
     * yang jawaban pertamanya normatif lalu terbukti punya pengalaman nyata saat
     * digali dinilai dari yang terbukti itu - dan sebaliknya, jawaban mengesankan
     * yang runtuh begitu didalami tidak boleh tetap bernilai "baik".
     *
     * @param list<array<string,mixed>> $dialog
     *
     * @return array<int, array{tingkat:string, alasan:string}>
     */
    public static function tingkatPerButir(array $dialog): array
    {
        $hasil = [];
        foreach ($dialog as $r) {
            if ($r['butir_index'] === null || ($r['tingkat'] ?? null) === null) {
                continue;
            }
            $hasil[(int) $r['butir_index']] = [
                'tingkat' => (string) $r['tingkat'],
                'alasan'  => (string) ($r['alasan'] ?? ''),
            ];
        }

        return $hasil;
    }

    /**
     * Skor sementara 0-100 dari penilaian AI, lewat rumus rubrik yang sama
     * dengan penilaian recruiter. null = belum ada butir berbobot yang dinilai.
     *
     * "Sementara" bukan basa-basi: yang masuk basis data penilaian tetap hasil
     * submit recruiter di form nilai, setelah ia membaca transkripnya. Angka ini
     * ancar-ancar, bukan keputusan.
     *
     * @param list<mixed>               $rubrik
     * @param list<array<string,mixed>> $dialog
     */
    public static function skorSementara(array $rubrik, array $dialog): ?int
    {
        $per     = self::tingkatPerButir($dialog);
        $nilai   = array_map(static fn (array $p): string => $p['tingkat'], $per);
        $catatan = array_map(static fn (array $p): string => $p['alasan'], $per);

        // Versi terbaru menyimpan pertanyaan sebagai teks polos. Beri tiap
        // pertanyaan bobot sama agar skor sementara tetap berguna; bank lama
        // yang punya rubrik dan bobot eksplisit tetap memakai nilai aslinya.
        foreach ($rubrik as $i => $soal) {
            if (is_string($soal)) {
                $rubrik[$i] = ['pertanyaan' => $soal, 'kompetensi' => 'Pertanyaan ' . ($i + 1), 'bobot' => 1];
            }
        }

        return PenilaianRubrik::skor(PenilaianRubrik::rakit($rubrik, $nilai, $catatan));
    }

    /** Apakah sesi sudah ditutup? */
    public static function selesai(array $dialog): bool
    {
        foreach ($dialog as $r) {
            if ($r['sumber'] === 'penutup') {
                return true;
            }
        }

        return false;
    }

    /** @return array{sumber:string, butir_index:null, pertanyaan:string, kompetensi:string, bobot:int} */
    private static function penutup(): array
    {
        return [
            'sumber'      => 'penutup',
            'butir_index' => null,
            'pertanyaan'  => self::PENUTUP,
            'kompetensi'  => '',
            'bobot'       => 0,
        ];
    }

    /** Sudah pernah ada giliran bertipe $sumber untuk butir $butir? */
    private static function pernah(array $dialog, string $sumber, ?int $butir): bool
    {
        foreach ($dialog as $r) {
            if ($r['sumber'] === $sumber && (int) $r['butir_index'] === (int) $butir) {
                return true;
            }
        }

        return false;
    }
}
