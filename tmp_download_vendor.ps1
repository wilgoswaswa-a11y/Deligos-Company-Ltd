$ErrorActionPreference = 'Continue'
$dir = 'assets\vendor'
New-Item -ItemType Directory -Force -Path $dir | Out-Null
New-Item -ItemType Directory -Force -Path "$dir\bootstrap\css", "$dir\bootstrap\js", "$dir\bootstrap-icons\font\fonts", "$dir\jquery", "$dir\html2canvas" | Out-Null

$files = @(
  @('https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css', 'assets\vendor\bootstrap\css\bootstrap.min.css'),
  @('https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css', 'assets\vendor\bootstrap-icons\font\bootstrap-icons.css'),
  @('https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/fonts/bootstrap-icons.woff2', 'assets\vendor\bootstrap-icons\font\fonts\bootstrap-icons.woff2'),
  @('https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/fonts/bootstrap-icons.woff', 'assets\vendor\bootstrap-icons\font\fonts\bootstrap-icons.woff'),
  @('https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js', 'assets\vendor\bootstrap\js\bootstrap.bundle.min.js'),
  @('https://code.jquery.com/jquery-3.6.0.min.js', 'assets\vendor\jquery\jquery-3.6.0.min.js'),
  @('https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js', 'assets\vendor\html2canvas\html2canvas.min.js')
)

foreach ($f in $files) {
  try {
    Invoke-WebRequest -Uri $f[0] -OutFile $f[1] -UseBasicParsing -TimeoutSec 30
    Write-Output ('OK ' + $f[1] + ' ' + (Get-Item $f[1]).Length)
  } catch {
    Write-Output ('FAIL ' + $f[0] + ' : ' + $_.Exception.Message)
  }
}
