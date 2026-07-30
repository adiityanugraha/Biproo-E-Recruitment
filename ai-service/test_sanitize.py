"""Atribut sensitif wajib hilang sebelum embedding; kompetensi wajib bertahan."""

import pytest

from sanitize import bersihkan

BIODATA = """Nama: Budi Contoh
Jenis Kelamin: Laki-laki
Tempat/Tgl Lahir: Jakarta, 12 Maret 1995
Agama: Islam
Status: Belum Menikah
Alamat: Jl. Melati No. 5, Jakarta Selatan
NIK: 3174012203950001
Telepon: 0812-3456-7890
Email: budi.contoh@gmail.com
Pengalaman: Backend Developer di PT Contoh Sejahtera selama 3 tahun
"""


def test_atribut_sensitif_hilang_semua():
    hasil = bersihkan(BIODATA)

    for bocor in (
        "Laki-laki", "Islam", "Belum Menikah", "Jl. Melati",
        "3174012203950001", "0812-3456-7890", "budi.contoh@gmail.com",
        "12 Maret 1995", "Jakarta Selatan",
    ):
        assert bocor not in hasil, f"masih bocor: {bocor}"


def test_kompetensi_bertahan():
    hasil = bersihkan(BIODATA)

    assert "Backend Developer" in hasil
    assert "PT Contoh Sejahtera" in hasil


@pytest.mark.parametrize("baris", [
    "Umur: 28 tahun",
    "Usia 30",
    "Age: 25 years",
    "gender: female",
    "Kewarganegaraan: Indonesia",
    "Golongan Darah: O",
])
def test_variasi_label_sensitif(baris):
    assert bersihkan(f"{baris}\nSkill: Python") .startswith("Skill")


def test_bahasa_inggris_sebagai_skill_tidak_ikut_terbuang():
    """'Bahasa Inggris' = kompetensi, beda dari 'Kewarganegaraan: Indonesia'."""
    hasil = bersihkan("Skill: Bahasa Inggris aktif, Microsoft Excel, negosiasi")

    assert "Bahasa Inggris" in hasil
    assert "Microsoft Excel" in hasil


def test_email_dan_hp_di_tengah_kalimat_tersensor():
    hasil = bersihkan("Hubungi saya di 081234567890 atau budi@mail.com untuk diskusi proyek")

    assert "081234567890" not in hasil
    assert "budi@mail.com" not in hasil
    assert "diskusi proyek" in hasil


def test_rentang_tahun_kerja_tidak_dianggap_tanggal_lahir():
    """Rentang tahun pengalaman harus bertahan - itu bukti pengalaman, bukan usia."""
    hasil = bersihkan("Backend Developer 2021-2024 di PT Contoh")

    assert "2021-2024" in hasil


def test_lama_pengalaman_bertahan():
    """Keputusan sadar: "3 tahun" TIDAK disensor - mustahil dibedakan dari usia
    tanpa konteks, dan membuangnya menghapus bukti kompetensi."""
    hasil = bersihkan("Pengalaman: Backend Developer selama 3 tahun di PT Contoh")

    assert "3 tahun" in hasil


def test_sensor_tidak_menyambung_baris_berikutnya():
    """Regresi: \\s* pada pola usia pernah menelan newline."""
    hasil = bersihkan("Usia 30\nSkill: Python")

    assert hasil.splitlines()[0].startswith("Skill")


def test_teks_kosong_aman():
    assert bersihkan("") == ""


def test_label_sensitif_di_tengah_kalimat_tersensor():
    """Regresi: label hanya tertangkap di awal baris, "Alamat: ..." inline lolos."""
    hasil = bersihkan("Kasir di Toko Maju. Alamat: Jl. Melati No. 5. Melayani pelanggan.")

    assert "Jl. Melati" not in hasil
    assert "Kasir di Toko Maju" in hasil
    assert "Melayani pelanggan" in hasil  # kalimat setelahnya utuh


def test_label_ambigu_tidak_disensor_inline():
    """"status" dan "foto" sengaja tidak inline - bisa bagian uraian kerja."""
    hasil = bersihkan("Mengelola status: pesanan harian dan foto: produk katalog")

    assert "pesanan harian" in hasil
