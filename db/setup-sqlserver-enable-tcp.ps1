# Jalankan sebagai Administrator
# Mengaktifkan protokol TCP/IP untuk instance SQLEXPRESS dengan port tetap 1433,
# lalu restart service supaya PHP (via ODBC) bisa konek.

$tcpBase = "HKLM:\SOFTWARE\Microsoft\Microsoft SQL Server\MSSQL16.SQLEXPRESS\MSSQLServer\SuperSocketNetLib\Tcp"

Set-ItemProperty -Path $tcpBase -Name Enabled -Value 1

$ipAll = "$tcpBase\IPAll"
Set-ItemProperty -Path $ipAll -Name TcpPort -Value "1433"
Set-ItemProperty -Path $ipAll -Name TcpDynamicPorts -Value ""

Restart-Service -Name "MSSQL`$SQLEXPRESS" -Force
Start-Sleep -Seconds 3
Get-Service -Name "MSSQL`$SQLEXPRESS" | Select-Object Name, Status

$fw = Get-NetFirewallRule -DisplayName "SQL Server SQLEXPRESS TCP 1433" -ErrorAction SilentlyContinue
if (-not $fw) {
    New-NetFirewallRule -DisplayName "SQL Server SQLEXPRESS TCP 1433" -Direction Inbound -Protocol TCP -LocalPort 1433 -Action Allow | Out-Null
}

Write-Host "Selesai. TCP/IP aktif di port 1433, service sudah restart, firewall rule dibuat."
