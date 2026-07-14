# Jalankan sebagai Administrator (klik kanan PowerShell -> Run as Administrator)
# Mengaktifkan SQL Server Authentication (Mixed Mode) untuk instance SQLEXPRESS
# lalu me-restart service supaya berlaku.

Set-ItemProperty -Path "HKLM:\SOFTWARE\Microsoft\Microsoft SQL Server\MSSQL16.SQLEXPRESS\MSSQLServer" -Name LoginMode -Value 2
Restart-Service -Name "MSSQL`$SQLEXPRESS" -Force
Start-Sleep -Seconds 3
Get-Service -Name "MSSQL`$SQLEXPRESS" | Select-Object Name, Status
Write-Host "Selesai. Mixed Mode aktif, service sudah restart."
