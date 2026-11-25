# Cap tai khoan MySQL chi doc cho AI. Chay tai thu muc goc project (noi co docker-compose.yml):
#   powershell -ExecutionPolicy Bypass -File .\database\grant_ai_reader.ps1
$ErrorActionPreference = 'Stop'
$vars = @{}
foreach ($line in Get-Content -Path .\.env) {
    if ($line -match '^\s*([A-Za-z0-9_]+)\s*=\s*(.*?)\s*$') { $vars[$Matches[1]] = $Matches[2] }
}
$user = $vars['AI_DB_USERNAME']; $pass = $vars['AI_DB_PASSWORD']; $db = $vars['DB_DATABASE']
if ($user -notmatch '^[A-Za-z0-9_]+$') { throw 'AI_DB_USERNAME chi duoc gom chu, so, gach duoi.' }
if ($pass -notmatch '^[A-Za-z0-9_-]+$') { throw 'AI_DB_PASSWORD chi duoc gom chu, so, gach duoi, gach ngang (khong rong).' }
if ($db -notmatch '^[A-Za-z0-9_]+$') { throw 'DB_DATABASE khong hop le.' }
$tables = 'categories','products','units','warehouses','warehouse_locations','product_lots','product_prices','inventory_documents','inventory_lines','stock_balances','stock_movements'
$sql = @("CREATE USER IF NOT EXISTS '$user'@'%' IDENTIFIED BY '$pass';", "ALTER USER '$user'@'%' IDENTIFIED BY '$pass';")
foreach ($table in $tables) { $sql += "GRANT SELECT ON $db.$table TO '$user'@'%';" }
$sql += 'FLUSH PRIVILEGES;'
$tmp = Join-Path $env:TEMP 'grant_ai_reader.sql'
$sql | Set-Content -Encoding ASCII $tmp
docker compose cp $tmp mysql:/tmp/grant_ai_reader.sql
docker compose exec mysql sh -c 'MYSQL_PWD=$MYSQL_ROOT_PASSWORD mysql -uroot < /tmp/grant_ai_reader.sql'
docker compose exec mysql sh -c 'rm -f /tmp/grant_ai_reader.sql'
Remove-Item $tmp
Write-Host "Da cap quyen SELECT cho $user tren $($tables.Count) bang nghiep vu."
