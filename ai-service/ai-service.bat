@echo off
REM Layanan AI E-REQ (FastAPI + uvicorn).
REM
REM Menjalankan ai-service di http://127.0.0.1:8000 dan MENGHIDUPKANNYA KEMBALI
REM bila ia mati. Tanpa layanan ini, screening CV dan penilaian wawancara tidak
REM pernah selesai - dan aplikasinya TIDAK menampilkan galat apa pun. Gejalanya
REM cuma skor yang tidak pernah terisi, dan itu bisa berhari-hari tidak disadari.
REM
REM Cara pakai: klik dua kali file ini (atau jalankan dari terminal), lalu
REM BIARKAN jendela terbuka. Tekan Ctrl+C atau tutup jendela untuk berhenti.
REM
REM Unduhan pertama ~1,5 GB (model transkripsi Whisper) - sekali saja, lalu
REM tersimpan di %USERPROFILE%\.cache\huggingface dan tidak butuh jaringan lagi.

cd /d "%~dp0"

echo ============================================
echo  E-REQ - Layanan AI (port 8000)
echo  Biarkan jendela ini terbuka. Ctrl+C berhenti.
echo ============================================
echo.

REM Tanpa venv, "python" sistem akan dipakai dan pustakanya tidak ada di sana.
REM Gagalnya berupa ImportError yang membingungkan, jadi lebih baik ditolak
REM di sini dengan keterangan cara memasangnya.
if not exist ".venv\Scripts\python.exe" (
    echo [GAGAL] Lingkungan Python belum disiapkan.
    echo.
    echo Jalankan sekali dari folder ini:
    echo     python -m venv .venv
    echo     .venv\Scripts\pip install -r requirements.txt
    echo.
    pause
    exit /b 1
)

REM Port sudah dipakai berarti ada uvicorn lain yang masih hidup - mungkin dari
REM jendela yang lupa ditutup. Menjalankan yang kedua membuatnya mati seketika
REM lalu dihidupkan lagi oleh loop di bawah, berulang tanpa henti. Yang lebih
REM buruk: layanan lama itu bisa memuat kode versi lama, dan gejalanya berupa
REM endpoint yang membalas 404 padahal kodenya sudah ada. Sudah terjadi.
netstat -ano | findstr /r /c:"127.0.0.1:8000 .*LISTENING" >nul
if not errorlevel 1 (
    echo [BATAL] Port 8000 sudah dipakai proses lain.
    echo.
    echo Layanannya kemungkinan sudah jalan. Periksa dengan membuka:
    echo     http://127.0.0.1:8000/health
    echo.
    echo Kalau itu uvicorn lama yang perlu dimatikan, cari PID-nya:
    echo     netstat -ano ^| findstr :8000
    echo     taskkill /PID ^<pid^> /F
    echo.
    pause
    exit /b 1
)

:loop
echo [%date% %time%] ai-service dijalankan...
.venv\Scripts\python.exe -m uvicorn main:app --host 127.0.0.1 --port 8000

REM Sampai di sini berarti uvicorn berhenti. Kalau Anda menekan Ctrl+C, jendela
REM ini ikut ditanya "Terminate batch job?" - jawab Y dan loop berhenti. Kalau
REM ia mati sendiri (galat, kehabisan memori), loop menghidupkannya lagi.
echo.
echo [%date% %time%] ai-service berhenti. Menghidupkan ulang 5 detik lagi...

REM Jeda memakai "ping", bukan "timeout": timeout butuh konsol dengan stdin
REM sungguhan dan gagal diam-diam saat jendela ini dibuka proses lain. Alasan
REM yang sama dengan kirim-email-otomatis.bat.
ping -n 6 127.0.0.1 >nul
goto loop
