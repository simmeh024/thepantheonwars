param(
    [string]$BaseUrl = 'https://thepantheonwars.com',
    [int]$Runs = 5,
    [string]$Label = ('baseline-' + (Get-Date -Format 'yyyyMMdd-HHmmss'))
)

$ErrorActionPreference = 'Stop'
$targets = @(
    @{ Name = 'Home page'; Path = '/' },
    @{ Name = 'World catalog API'; Path = '/api/worlds.php' },
    @{ Name = 'Books API'; Path = '/api/books.php' }
)
$outDir = Join-Path $PSScriptRoot '..\benchmarks'
New-Item -ItemType Directory -Force -Path $outDir | Out-Null
$results = @()

foreach ($target in $targets) {
    $url = $BaseUrl.TrimEnd('/') + $target.Path
    foreach ($run in 1..$Runs) {
        $headers = [System.IO.Path]::GetTempFileName()
        try {
            $seconds = & curl.exe -sS -o NUL -D $headers -w '%{time_total}' $url
            $serverTiming = (Get-Content $headers | Where-Object { $_ -match '^Server-Timing:' }) -replace '^Server-Timing:\s*', ''
            $results += [pscustomobject]@{
                label = $Label; target = $target.Name; url = $url; run = $run
                total_ms = [math]::Round(([double]$seconds * 1000), 2)
                server_timing = ($serverTiming -join ' ')
                captured_at_utc = (Get-Date).ToUniversalTime().ToString('o')
            }
        } finally {
            Remove-Item -LiteralPath $headers -Force -ErrorAction SilentlyContinue
        }
    }
}

$path = Join-Path $outDir ($Label + '.json')
$results | ConvertTo-Json | Set-Content -Encoding utf8 $path
$results | Group-Object target | ForEach-Object {
    $times = $_.Group.total_ms
    [pscustomobject]@{ Target = $_.Name; Runs = $times.Count; AverageMs = [math]::Round(($times | Measure-Object -Average).Average, 2); MinMs = ($times | Measure-Object -Minimum).Minimum; MaxMs = ($times | Measure-Object -Maximum).Maximum }
} | Format-Table -AutoSize
Write-Host "Saved raw benchmark data to $path"
