<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Penilaian interview per kompetensi. Satu baris per butir rubrik yang dinilai.
 *
 * DULU TAMBAH-SAJA, DAN ITU SALAH (diperbaiki 14 Agustus 2026).
 *
 * Alasan lamanya: keputusan Gate 2 hanya boleh sekali, jadi dianggap tidak
 * perlu ada jalur memperbarui. Yang terlewat, lembarnya bisa dikirim berkali-
 * kali SEBELUM keputusan itu jatuh - unggah ulang setelah transkripsi gagal
 * adalah jalur yang memang disediakan. Tiap kiriman menambah satu set baru di
 * atas yang lama, dan LembarPenilaian::skor() merata-ratakan SEMUANYA.
 *
 * Terlihat pada lamaran #72: Appearance tercatat delapan kali bernilai
 * 4,4,4,5,5,4,5,5, dan skor kandidatnya jadi campuran delapan pengiriman yang
 * tidak pernah dimaksudkan siapa pun. Tidak ada yang salah di layar, tidak ada
 * galat, dan angkanya tetap masuk akal - itu yang membuatnya bertahan lama.
 */
class InterviewPenilaianModel extends Model
{
    protected $table         = 'interview_penilaian';
    // sumber: 'ai' | 'recruiter' - siapa yang memberi nilai ini. Sejak penilaian
    // dibaca dari transkrip, satu lembar diisi dua pihak, dan tanpa kolom ini
    // keduanya tidak bisa dibedakan lagi setelah tersimpan.
    protected $allowedFields = ['application_id', 'kompetensi', 'kategori', 'sumber', 'bobot', 'tingkat', 'catatan'];

    /** @return list<array<string, mixed>> */
    public function untukLamaran(int $appId): array
    {
        return $this->where('application_id', $appId)->orderBy('id')->findAll();
    }

    /**
     * Ganti seluruh penilaian dari SATU pihak, bukan menumpuk di atasnya.
     *
     * Yang dihapus hanya milik $sumber. Recruiter mengirim ulang lembarnya
     * tidak boleh menghapus penilaian AI, dan sebaliknya - keduanya menilai
     * kompetensi yang berbeda dan datang di waktu yang berbeda.
     *
     * Nilai DAN narasi harus masuk lewat satu panggilan. Dua panggilan berturut
     * untuk sumber yang sama membuat yang kedua menghapus hasil yang pertama.
     *
     * Satu transaksi untuk seluruh lembar: kegagalan di tengah meninggalkan
     * penilaian separuh, dan sesudah penghapusannya jalan, separuh itu berarti
     * lembar yang lama sudah hilang.
     *
     * @param list<array<string, mixed>> $baris tanpa application_id dan sumber
     */
    public function ganti(int $appId, string $sumber, array $baris): void
    {
        $db = db_connect();
        $db->transException(true)->transStart();

        $this->where(['application_id' => $appId, 'sumber' => $sumber])->delete();
        foreach ($baris as $r) {
            $this->insert($r + ['application_id' => $appId, 'sumber' => $sumber]);
        }

        $db->transComplete();
    }
}
