<?php

namespace App\Libraries;

use App\Models\InterviewModel;
use App\Models\SlotInterviewModel;
use DateTime;

/**
 * Pengelolaan slot jadwal wawancara: tambah, ubah kuota, hapus.
 *
 * SATU KELAS UNTUK DUA PENGELOLA (31 Agustus 2026). Recruiter mengatur jam
 * Interview HRD, atasan mengatur jam Interview User untuk posisinya sendiri.
 * Aturannya sama persis - yang berbeda cuma jenis wawancara dan posisi mana
 * yang dipegang - jadi menggandakan seluruh penjagaannya ke dua controller
 * berarti dua tempat yang harus diperbaiki tiap kali satu aturan berubah.
 *
 * SLOT YANG SUDAH DIPEGANG KANDIDAT TIDAK BISA DIHAPUS. Menghapusnya
 * menghilangkan jam yang sudah dijanjikan kepada orang yang menunggu, sementara
 * jadwalnya sendiri tetap berdiri di tabel interviews - kandidat datang ke jam
 * yang menurut sistem tidak pernah ada. Yang boleh dilakukan menurunkan
 * kuotanya; yang sudah masuk tetap masuk.
 */
final class PengaturanSlot
{
    private SlotInterviewModel $slots;

    public function __construct(
        private string $jenis,
        private int $jobId = SlotInterviewModel::TANPA_POSISI,
    ) {
        $this->slots = new SlotInterviewModel();
    }

    /**
     * Baris siap tampil: waktu yang sudah dinormalkan, jumlah pemakai, dan
     * apakah jamnya sudah lewat.
     *
     * @return list<array<string, mixed>>
     */
    public function daftar(): array
    {
        $pakai  = (new InterviewModel())->hitungPerSlot($this->jenis, $this->jobId);
        $daftar = $this->slots->semua($this->jenis, $this->jobId);

        foreach ($daftar as &$d) {
            $d['waktu']    = $d['scheduled_at'];
            $d['terpakai'] = (int) ($pakai[$d['waktu']] ?? 0);
            $d['lewat']    = $d['waktu'] <= date(SlotInterviewModel::FORMAT);
        }
        unset($d);

        return $daftar;
    }

    /**
     * Tiga aksi dalam satu POST, dibedakan field 'aksi'.
     *
     * @param  array<string, mixed> $post
     * @return array{0: bool, 1: string} berhasil, pesan untuk pengguna
     */
    public function tangani(array $post): array
    {
        $aksi = (string) ($post['aksi'] ?? '');

        if ($aksi === 'hapus') {
            return $this->hapus((int) ($post['id'] ?? 0));
        }
        if ($aksi === 'kuota') {
            return $this->ubahKuota((int) ($post['id'] ?? 0), (int) ($post['kuota'] ?? -1));
        }

        return $this->tambah(
            (string) ($post['tanggal'] ?? ''),
            (string) ($post['jam'] ?? ''),
            (int) ($post['kuota'] ?? 0),
            ($post['ulangi'] ?? '') === '1',
        );
    }

    /** @return array{0: bool, 1: string} */
    private function hapus(int $id): array
    {
        $slot = $this->milikSendiri($id);
        if ($slot === null) {
            return [false, 'Slot tidak ditemukan.'];
        }

        $waktu   = (new DateTime($slot['scheduled_at']))->format(SlotInterviewModel::FORMAT);
        $dipakai = (int) ((new InterviewModel())->hitungPerSlot($this->jenis, $this->jobId)[$waktu] ?? 0);
        if ($dipakai > 0) {
            return [false, 'Slot itu sudah dipegang ' . $dipakai . ' kandidat, jadi tidak bisa dihapus. '
                . 'Turunkan kuotanya ke 0 bila ingin menutupnya untuk pendaftar baru.'];
        }

        $this->slots->delete($id);

        return [true, 'Slot dihapus.'];
    }

    /** @return array{0: bool, 1: string} */
    private function ubahKuota(int $id, int $kuota): array
    {
        if ($this->milikSendiri($id) === null) {
            return [false, 'Slot tidak ditemukan.'];
        }
        if ($kuota < 0 || $kuota > SlotInterviewModel::MAKS_KUOTA) {
            return [false, 'Kuota harus 0 sampai ' . SlotInterviewModel::MAKS_KUOTA . '.'];
        }

        $this->slots->update($id, ['kuota' => $kuota]);

        return [true, 'Kuota diperbarui.'];
    }

    /** @return array{0: bool, 1: string} */
    private function tambah(string $tanggal, string $jam, int $kuota, bool $ulangi): array
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal) || ! preg_match('/^\d{2}:\d{2}$/', $jam)) {
            return [false, 'Tanggal atau jamnya tidak sah.'];
        }
        if ($kuota < 1 || $kuota > SlotInterviewModel::MAKS_KUOTA) {
            return [false, 'Kuota harus 1 sampai ' . SlotInterviewModel::MAKS_KUOTA . '.'];
        }

        // Tombol ulangi memakai TANGGAL YANG DIPILIH sebagai titik mulai, bukan
        // hari ini: yang menyiapkan jadwal pekan depan ingin tujuh hari kerja
        // dari pekan depan, bukan dari hari ia mengetik.
        $tanggalIsi = [$tanggal];
        if ($ulangi) {
            $tanggalIsi = SlotJadwal::hariKerja(SlotJadwal::HARI_KERJA, new DateTime($tanggal));

            // TANGGAL YANG DIPILIH SELALU IKUT, walau ia akhir pekan.
            // hariKerja() melompati akhir pekan, jadi memilih Sabtu lalu
            // mencentang "ulangi" kehilangan Sabtu itu sendiri - slot yang
            // justru diminta hilang tanpa sepatah kata, sementara tanpa centang
            // slotnya dibuat. Centang tidak boleh membatalkan permintaan yang
            // lebih tegas.
            if (! in_array($tanggal, $tanggalIsi, true)) {
                array_unshift($tanggalIsi, $tanggal);
            }
        }

        $dibuat = $dilewati = 0;
        foreach ($tanggalIsi as $t) {
            $waktu = $t . ' ' . $jam . ':00';
            // Sudah ada = dilewati, bukan ditimpa. Menimpa berarti mengubah
            // kuota slot yang mungkin sudah dipegang kandidat tanpa diminta.
            if ($this->slots->where('scheduled_at', $waktu)
                ->where('jenis', $this->jenis)->where('job_id', $this->jobId)
                ->countAllResults() > 0) {
                $dilewati++;

                continue;
            }
            $this->slots->insert([
                'scheduled_at' => $waktu,
                'jenis'        => $this->jenis,
                'job_id'       => $this->jobId,
                'kuota'        => $kuota,
                'created_at'   => date(SlotInterviewModel::FORMAT),
            ]);
            $dibuat++;
        }

        return [true, $dibuat . ' slot dibuat'
            . ($dilewati > 0 ? ', ' . $dilewati . ' dilewati karena jamnya sudah ada' : '') . '.'];
    }

    /**
     * Slot dengan $id, HANYA bila ia milik jenis dan posisi yang dipegang.
     *
     * Bukan sekadar find(). Tanpa penyaring ini, atasan posisi A yang mengetik
     * id slot posisi B di formulirnya bisa menghapus jam orang lain - dan yang
     * membatasi bukan tombol yang ia lihat, melainkan pemeriksaan di sini.
     *
     * @return array<string, mixed>|null
     */
    private function milikSendiri(int $id): ?array
    {
        return $this->slots->where('jenis', $this->jenis)->where('job_id', $this->jobId)->find($id);
    }
}
