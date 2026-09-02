[CmdletBinding()]
param(
    [string]$TargetDirectory = 'test',
    [string]$CodeIgniterVersion = 'latest',
    [string]$CodeIgniterRepository = 'pocketarc/codeigniter',
    [string]$CacheDirectory = '.cache',
    [string]$DatabaseHost = 'localhost',
    [int]$DatabasePort = 3306,
    [string]$DatabaseUser = 'root',
    [string]$DatabasePassword = '',
    [string]$DatabaseName = 'auth_test',
    [string]$MailpitHost = '127.0.0.1',
    [int]$MailpitSmtpPort = 1025,
    [string]$BaseUrl = 'http://localhost:8080/',
    [string]$PhpCommand = 'php',
    [switch]$ResetDatabase,
    [switch]$SkipDatabase,
    [switch]$Force
)

$ErrorActionPreference = 'Stop'
$demoDirectory = [IO.Path]::GetFullPath($PSScriptRoot)
$repositoryRoot = [IO.Path]::GetFullPath((Join-Path $demoDirectory '..'))

function Get-FullPath([string]$Path, [string]$BaseDirectory) {
    if ([IO.Path]::IsPathRooted($Path)) {
        return [IO.Path]::GetFullPath($Path)
    }

    return [IO.Path]::GetFullPath((Join-Path $BaseDirectory $Path))
}

function ConvertTo-PhpSingleQuoted([string]$Value) {
    return "'" + $Value.Replace('\', '\\').Replace("'", "\'") + "'"
}

function New-RandomHex([int]$ByteCount) {
    $bytes = New-Object byte[] $ByteCount
    $generator = [Security.Cryptography.RandomNumberGenerator]::Create()
    try {
        $generator.GetBytes($bytes)
    }
    finally {
        $generator.Dispose()
    }

    return -join ($bytes | ForEach-Object { $_.ToString('x2') })
}

function Write-Utf8File([string]$Path, [string]$Content) {
    $encoding = New-Object Text.UTF8Encoding($false)
    [IO.File]::WriteAllText($Path, $Content, $encoding)
}

function Copy-DirectoryContents([string]$Source, [string]$Destination) {
    New-Item -ItemType Directory -Path $Destination -Force | Out-Null
    Get-ChildItem -LiteralPath $Source -Force | Copy-Item -Destination $Destination -Recurse -Force
}

function Resolve-CodeIgniterArchive(
    [string]$RequestedVersion,
    [string]$Repository,
    [string]$CachePath
) {
    if ($Repository -notmatch '\A[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+\z') {
        throw "Depot GitHub invalide : $Repository"
    }
    if ($RequestedVersion -ne 'latest' -and $RequestedVersion -notmatch '\A[0-9A-Za-z._-]+\z') {
        throw "Version CodeIgniter invalide : $RequestedVersion"
    }

    New-Item -ItemType Directory -Path $CachePath -Force | Out-Null
    $release = $null
    $resolvedVersion = $RequestedVersion
    $downloadUrl = $null
    $apiUrl = if ($RequestedVersion -eq 'latest') {
        "https://api.github.com/repos/$Repository/releases/latest"
    }
    else {
        "https://api.github.com/repos/$Repository/releases/tags/$RequestedVersion"
    }

    try {
        $release = Invoke-RestMethod -Uri $apiUrl -Headers @{ 'User-Agent' = 'Aauth-demo-deployer' }
        $resolvedVersion = [string]$release.tag_name
        $downloadUrl = [string]$release.zipball_url
    }
    catch {
        if ($RequestedVersion -eq 'latest') {
            $cachedArchive = Get-ChildItem -LiteralPath $CachePath -Filter 'codeigniter-*.zip' -File |
                Sort-Object LastWriteTimeUtc -Descending |
                Select-Object -First 1
            if ($null -ne $cachedArchive) {
                Write-Warning "GitHub est indisponible. Utilisation du cache : $($cachedArchive.Name)"
                return $cachedArchive.FullName
            }
        }

        $requestedCache = Join-Path $CachePath ("codeigniter-$RequestedVersion.zip")
        if (Test-Path -LiteralPath $requestedCache -PathType Leaf) {
            Write-Warning "GitHub est indisponible. Utilisation du cache : $requestedCache"
            return $requestedCache
        }

        throw "Impossible de resoudre la release CodeIgniter '$RequestedVersion' depuis $Repository et aucune archive compatible n'est en cache. $($_.Exception.Message)"
    }

    if ($resolvedVersion -notmatch '\A[0-9A-Za-z._-]+\z' -or [string]::IsNullOrEmpty($downloadUrl)) {
        throw 'La reponse GitHub ne contient pas une release CodeIgniter exploitable.'
    }

    $archivePath = Join-Path $CachePath ("codeigniter-$resolvedVersion.zip")
    if (Test-Path -LiteralPath $archivePath -PathType Leaf) {
        Write-Host "CodeIgniter $resolvedVersion trouve dans le cache."
        return $archivePath
    }

    $temporaryArchive = Join-Path $CachePath ('.download-' + [guid]::NewGuid().ToString('N') + '.zip')
    try {
        Write-Host "Telechargement de CodeIgniter $resolvedVersion depuis $Repository..."
        Invoke-WebRequest -Uri $downloadUrl -Headers @{ 'User-Agent' = 'Aauth-demo-deployer' } -OutFile $temporaryArchive -UseBasicParsing
        Move-Item -LiteralPath $temporaryArchive -Destination $archivePath
    }
    finally {
        if (Test-Path -LiteralPath $temporaryArchive) {
            Remove-Item -LiteralPath $temporaryArchive -Force
        }
    }

    return $archivePath
}

$cache = Get-FullPath $CacheDirectory $demoDirectory
New-Item -ItemType Directory -Path $cache -Force | Out-Null

# Migrate archives used by the first version of this demo into the ignored
# cache. This is a one-time compatibility path and avoids downloading again.
Get-ChildItem -LiteralPath $demoDirectory -Filter 'codeigniter-*.zip' -File | ForEach-Object {
    $cachedLegacyArchive = Join-Path $cache $_.Name
    if (-not (Test-Path -LiteralPath $cachedLegacyArchive)) {
        Move-Item -LiteralPath $_.FullName -Destination $cachedLegacyArchive
    }
}

$archive = Resolve-CodeIgniterArchive $CodeIgniterVersion $CodeIgniterRepository $cache
$target = Get-FullPath $TargetDirectory $demoDirectory
$marker = Join-Path $target '.aauth-test-environment'

if (Test-Path -LiteralPath $target) {
    $hasContent = @(Get-ChildItem -LiteralPath $target -Force).Count -gt 0
    if ($hasContent -and -not (Test-Path -LiteralPath $marker) -and -not $Force) {
        throw "Le dossier cible n'est pas vide et n'a pas ete cree par ce script. Utilisez -Force pour confirmer : $target"
    }
}
else {
    New-Item -ItemType Directory -Path $target -Force | Out-Null
}

$temporaryDirectory = Join-Path $demoDirectory ('.deploy-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $temporaryDirectory | Out-Null

try {
    Expand-Archive -LiteralPath $archive -DestinationPath $temporaryDirectory -Force
    $codeIgniterRoot = Get-ChildItem -LiteralPath $temporaryDirectory -Directory | Select-Object -First 1
    if ($null -eq $codeIgniterRoot -or -not (Test-Path -LiteralPath (Join-Path $codeIgniterRoot.FullName 'index.php'))) {
        throw 'La racine CodeIgniter est introuvable dans l archive.'
    }

    Copy-DirectoryContents $codeIgniterRoot.FullName $target

    Copy-Item -LiteralPath (Join-Path $repositoryRoot 'application\config\aauth.php') -Destination (Join-Path $target 'application\config\aauth.php') -Force
    Copy-Item -LiteralPath (Join-Path $repositoryRoot 'application\controllers\Account.php') -Destination (Join-Path $target 'application\controllers\Account.php') -Force
    Copy-Item -LiteralPath (Join-Path $repositoryRoot 'application\libraries\Aauth.php') -Destination (Join-Path $target 'application\libraries\Aauth.php') -Force
    Copy-DirectoryContents (Join-Path $repositoryRoot 'application\helpers') (Join-Path $target 'application\helpers')
    Copy-DirectoryContents (Join-Path $repositoryRoot 'application\language') (Join-Path $target 'application\language')
    Copy-DirectoryContents (Join-Path $repositoryRoot 'application\views\aauth_demo') (Join-Path $target 'application\views\aauth_demo')
    Copy-DirectoryContents (Join-Path $repositoryRoot 'assets') (Join-Path $target 'assets')
    Copy-Item -LiteralPath (Join-Path $demoDirectory 'router.php') -Destination (Join-Path $target 'router.php') -Force

    $environmentConfigDirectory = Join-Path $target 'application\config\development'
    New-Item -ItemType Directory -Path $environmentConfigDirectory -Force | Out-Null

    $environmentConfigPath = Join-Path $environmentConfigDirectory 'config.php'
    $encryptionKey = $null
    if (Test-Path -LiteralPath $environmentConfigPath) {
        $existingConfig = [IO.File]::ReadAllText($environmentConfigPath)
        $match = [regex]::Match($existingConfig, "\`$config\['encryption_key'\]\s*=\s*'([a-f0-9]{64})'")
        if ($match.Success) {
            $encryptionKey = $match.Groups[1].Value
        }
    }
    if ([string]::IsNullOrEmpty($encryptionKey)) {
        $encryptionKey = New-RandomHex 32
    }

    if (-not $BaseUrl.EndsWith('/')) {
        $BaseUrl += '/'
    }

    $configTemplate = @'
<?php
defined('BASEPATH') OR exit('No direct script access allowed');

// Generated by demo/deploy-test.ps1 for ENVIRONMENT=development.
$config['base_url'] = {{BASE_URL}};
$config['index_page'] = '';
$config['encryption_key'] = {{ENCRYPTION_KEY}};
$config['sess_driver'] = 'files';
$config['sess_cookie_name'] = 'aauth_test_session';
$config['sess_save_path'] = sys_get_temp_dir();
$config['csrf_protection'] = TRUE;
$config['csrf_regenerate'] = FALSE;
'@
    $configContent = $configTemplate.Replace('{{BASE_URL}}', (ConvertTo-PhpSingleQuoted $BaseUrl))
    $configContent = $configContent.Replace('{{ENCRYPTION_KEY}}', (ConvertTo-PhpSingleQuoted $encryptionKey))
    Write-Utf8File $environmentConfigPath $configContent

    $databaseTemplate = @'
<?php
defined('BASEPATH') OR exit('No direct script access allowed');

// Generated by demo/deploy-test.ps1 for ENVIRONMENT=development.
$active_group = 'default';
$query_builder = TRUE;

$db['default'] = array(
    'dsn' => '',
    'hostname' => {{DB_HOST}},
    'port' => {{DB_PORT}},
    'username' => {{DB_USER}},
    'password' => {{DB_PASSWORD}},
    'database' => {{DB_NAME}},
    'dbdriver' => 'mysqli',
    'dbprefix' => '',
    'pconnect' => FALSE,
    'db_debug' => TRUE,
    'cache_on' => FALSE,
    'cachedir' => '',
    'char_set' => 'utf8mb4',
    'dbcollat' => 'utf8mb4_unicode_ci',
    'swap_pre' => '',
    'encrypt' => FALSE,
    'compress' => FALSE,
    'stricton' => TRUE,
    'failover' => array(),
    'save_queries' => TRUE,
);
'@
    $databaseContent = $databaseTemplate.Replace('{{DB_HOST}}', (ConvertTo-PhpSingleQuoted $DatabaseHost))
    $databaseContent = $databaseContent.Replace('{{DB_PORT}}', $DatabasePort.ToString())
    $databaseContent = $databaseContent.Replace('{{DB_USER}}', (ConvertTo-PhpSingleQuoted $DatabaseUser))
    $databaseContent = $databaseContent.Replace('{{DB_PASSWORD}}', (ConvertTo-PhpSingleQuoted $DatabasePassword))
    $databaseContent = $databaseContent.Replace('{{DB_NAME}}', (ConvertTo-PhpSingleQuoted $DatabaseName))
    Write-Utf8File (Join-Path $environmentConfigDirectory 'database.php') $databaseContent

    $emailTemplate = @'
<?php
defined('BASEPATH') OR exit('No direct script access allowed');

// Generated by demo/deploy-test.ps1 for the local Mailpit SMTP server.
$config['protocol'] = 'smtp';
$config['smtp_host'] = {{MAILPIT_HOST}};
$config['smtp_port'] = {{MAILPIT_PORT}};
$config['smtp_user'] = '';
$config['smtp_pass'] = '';
$config['smtp_crypto'] = '';
$config['smtp_timeout'] = 5;
$config['mailtype'] = 'text';
$config['charset'] = 'utf-8';
$config['crlf'] = "\r\n";
$config['newline'] = "\r\n";
$config['validate'] = FALSE;
'@
    $emailContent = $emailTemplate.Replace('{{MAILPIT_HOST}}', (ConvertTo-PhpSingleQuoted $MailpitHost))
    $emailContent = $emailContent.Replace('{{MAILPIT_PORT}}', $MailpitSmtpPort.ToString())
    Write-Utf8File (Join-Path $environmentConfigDirectory 'email.php') $emailContent

    Write-Utf8File $marker "Managed by demo/deploy-test.ps1`n"

    if (-not $SkipDatabase) {
        $env:AAUTH_TEST_DB_HOST = $DatabaseHost
        $env:AAUTH_TEST_DB_PORT = $DatabasePort.ToString()
        $env:AAUTH_TEST_DB_USER = $DatabaseUser
        $env:AAUTH_TEST_DB_PASSWORD = $DatabasePassword
        $env:AAUTH_TEST_DB_NAME = $DatabaseName
        try {
            $schemaArgument = '--schema={0}' -f (Join-Path $repositoryRoot 'sql\Aauth_v3.sql')
            $databaseArguments = @(
                (Join-Path $demoDirectory 'deploy-database.php'),
                $schemaArgument
            )
            if ($ResetDatabase) {
                $databaseArguments += '--reset'
            }

            & $PhpCommand @databaseArguments
            if ($LASTEXITCODE -ne 0) {
                throw "L import SQL a echoue avec le code $LASTEXITCODE."
            }
        }
        finally {
            Remove-Item Env:AAUTH_TEST_DB_HOST, Env:AAUTH_TEST_DB_PORT, Env:AAUTH_TEST_DB_USER, Env:AAUTH_TEST_DB_PASSWORD, Env:AAUTH_TEST_DB_NAME -ErrorAction SilentlyContinue
        }
    }

    Write-Host "Environnement deploye dans : $target"
    Write-Host "Demarrage : cd `"$target`"; `$env:CI_ENV='development'; $PhpCommand -S 127.0.0.1:8080 router.php"
    Write-Host ('Connexion : ' + $BaseUrl + 'account/login')
    Write-Host "SMTP Mailpit : ${MailpitHost}:$MailpitSmtpPort"
}
finally {
    $temporaryFullPath = [IO.Path]::GetFullPath($temporaryDirectory)
    if (
        $temporaryFullPath.StartsWith($demoDirectory + [IO.Path]::DirectorySeparatorChar) -and
        (Test-Path -LiteralPath $temporaryFullPath)
    ) {
        Remove-Item -LiteralPath $temporaryFullPath -Recurse -Force
    }
}
