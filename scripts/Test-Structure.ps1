$ErrorActionPreference = 'Stop'

$projectRoot = Split-Path -Parent $PSScriptRoot
$requiredPaths = @(
    'app/public/web.config',
    'app/public/index.php',
    'app/public/login.php',
    'app/public/logout.php',
    'app/src/Auth.php',
    'app/src/Database.php',
    'backend/pom.xml',
    'backend/src/main/resources/application.yml',
    'backend/src/main/java/com/eis/pdf/PdfManagerServlet.java',
    'backend/src/main/webapp/lib/tcpdf/fonts/THSarabun.ttf',
    'app/public/js/pdf.min.js',
    'app/public/js/pdf.worker.min.js',
    'database/NMU_EIS/001_schema.sql'
)

foreach ($relativePath in $requiredPaths) {
    $fullPath = Join-Path $projectRoot $relativePath
    if (-not (Test-Path -LiteralPath $fullPath)) {
        throw "Missing required path: $relativePath"
    }
}

Write-Host 'EIS-DOCS structure is valid.'
