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
REM Jeda 30 detik. Sengaja TIDAK memakai "timeout": perintah itu butuh konsol
REM dengan stdin sungguhan, dan gagal diam-diam ("Input redirection is not
REM supported") ketika jendela ini dibuka oleh proses lain, bukan diklik dua
REM kali. Akibatnya loop berhenti di putaran pertama tanpa pesan apa pun.
REM "ping" ke localhost tidak butuh stdin dan bekerja di kedua cara.
ping -n 31 127.0.0.1 >nul
goto loop
