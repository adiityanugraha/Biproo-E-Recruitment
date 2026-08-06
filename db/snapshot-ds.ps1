# Membuat snapshot basis data E-REQ untuk tim DS, dengan data pribadi kandidat
# sudah disamarkan.
#
#   .\db\snapshot-ds.ps1
#
# Hasilnya satu berkas .bak yang siap dikirim. Basis data asli tidak pernah
# disentuh: seluruh penyamaran dikerjakan pada salinan.
#
# Skrip berhenti dan menghapus keluarannya kalau verifikasi gagal, supaya tidak
# mungkin ada .bak berisi data asli yang lolos terkirim.

param(
    [string] $Server  = 'localhost\SQLEXPRESS',
    [string] $Sumber  = 'ereq',
    [string] $Salinan = 'ereq_ds',
    [string] $Folder  = 'C:\temp',
    [switch] $Simpan     # biarkan basis data salinan tetap ada setelah selesai
)

$ErrorActionPreference = 'Stop'

$mentah = Join-Path $Folder "$Sumber.mentah.bak"
$hasil  = Join-Path $Folder "$Salinan.bak"
$skrip  = Join-Path $PSScriptRoot 'bersihkan-ds.sql'

function Jalankan([string] $sql, [string] $db = 'master') {
    # -b: kembalikan exit code bukan nol saat SQL gagal, supaya kegagalan tidak
    #     lewat diam-diam. -I: QUOTED_IDENTIFIER, wajib karena ada filtered index.
    $keluaran = sqlcmd -S $Server -E -b -I -d $db -Q $sql
    if ($LASTEXITCODE -ne 0) { throw "SQL gagal:`n$keluaran" }
    return $keluaran
}

if (-not (Test-Path $Folder)) { New-Item -ItemType Directory -Path $Folder | Out-Null }

# 1. Cadangkan sumbernya.
#    JANGAN tambahkan WITH COMPRESSION: tidak ada di SQL Server Express (Msg 1844).
Write-Host "[1/5] Mencadangkan $Sumber ..."
Jalankan "BACKUP DATABASE [$Sumber] TO DISK = '$mentah' WITH INIT;" | Out-Null

# 2. Pulihkan sebagai salinan. Nama berkas logis dibaca dari sumbernya, bukan
#    ditebak, supaya tetap benar kalau basis datanya kelak berganti nama.
Write-Host "[2/5] Memulihkan sebagai $Salinan ..."
$logis = Jalankan "SET NOCOUNT ON; SELECT name FROM sys.database_files ORDER BY type;" $Sumber |
         Where-Object { $_ -match '\S' -and $_ -notmatch '^-+$' -and $_ -notmatch '^name\s*$' } |
         ForEach-Object { $_.Trim() }
if ($logis.Count -lt 2) { throw "Tidak bisa membaca nama berkas logis $Sumber." }

Jalankan @"
IF DB_ID('$Salinan') IS NOT NULL
BEGIN
    ALTER DATABASE [$Salinan] SET SINGLE_USER WITH ROLLBACK IMMEDIATE;
    DROP DATABASE [$Salinan];
END
RESTORE DATABASE [$Salinan] FROM DISK = '$mentah'
WITH MOVE '$($logis[0])' TO '$Folder\$Salinan.mdf',
     MOVE '$($logis[1])' TO '$Folder\${Salinan}_log.ldf';
"@ | Out-Null

# 3. Samarkan data pribadi pada salinan itu.
Write-Host "[3/5] Menyamarkan data pribadi ..."
$keluaran = sqlcmd -S $Server -E -b -I -h-1 -W -d $Salinan -i $skrip
if ($LASTEXITCODE -ne 0) {
    Remove-Item $mentah -Force -ErrorAction SilentlyContinue
    throw "Penyamaran gagal, tidak ada berkas yang dibuat:`n$keluaran"
}

# 4. Verifikasi. Angka terakhir dari bersihkan-ds.sql harus 0.
Write-Host "[4/5] Memverifikasi ..."
$sisa = ($keluaran | Where-Object { $_ -match '^\s*\d+\s*$' } | Select-Object -Last 1).Trim()
if ($sisa -ne '0') {
    Remove-Item $mentah -Force -ErrorAction SilentlyContinue
    throw "Masih ada $sisa data pribadi tersisa. Berkas TIDAK dibuat."
}

# 5. Cadangkan salinan yang sudah bersih, lalu buang cadangan mentahnya supaya
#    tidak mungkin tertukar saat dikirim.
Write-Host "[5/5] Membuat $hasil ..."
Jalankan "BACKUP DATABASE [$Salinan] TO DISK = '$hasil' WITH INIT;" | Out-Null
Remove-Item $mentah -Force

if (-not $Simpan) {
    Jalankan "ALTER DATABASE [$Salinan] SET SINGLE_USER WITH ROLLBACK IMMEDIATE; DROP DATABASE [$Salinan];" | Out-Null
}

$mb = [math]::Round((Get-Item $hasil).Length / 1MB, 1)
Write-Host ""
Write-Host "Selesai. Kirim berkas ini ke tim DS:" -ForegroundColor Green
Write-Host "  $hasil  ($mb MB)"
Write-Host "Petunjuk restore untuk mereka: docs/setup-tim-ds.md bagian 7."
