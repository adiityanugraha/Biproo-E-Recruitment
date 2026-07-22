@echo off
REM Pengirim email otomatis E-REQ.
REM Menjalankan "php spark email:send" tiap 30 detik, jadi email di antrian
REM terkirim otomatis tanpa perlu dijalankan manual.
REM
REM Cara pakai: klik dua kali file ini (atau jalankan dari terminal), lalu
REM BIARKAN jendela terbuka selama butuh pengiriman otomatis. Tekan Ctrl+C
REM atau tutup jendela untuk berhenti.

cd /d "%~dp0"
echo ============================================
echo  E-REQ - Pengirim email otomatis (tiap 30s)
echo  Biarkan jendela ini terbuka. Ctrl+C berhenti.
echo ============================================
:loop
php spark email:send
timeout /t 30 /nobreak >nul
goto loop
