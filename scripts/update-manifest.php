#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Deepslate — update-manifest.php
 *
 * Descarga versión de PocketMine-MP y forks (Axolotl-PM, Altay, etc.),
 * extrae build_info.json de GitHub o deduce metadatos, calcula SHA256 y
 * actualiza manifest.json automáticamente con soporte multi-software.
 */

const PHP_DOWNLOAD = "https://github.com/pmmp/PHP-Binaries/releases/download";
const STUBS_DOWNLOAD = "https://github.com/ImAMadDev/pocketmine-stubs/releases/download";
const MANIFEST_PATH = __DIR__ . "/../manifest.json";
const FORKS_PATH = __DIR__ . "/../forks.json";
const CHUNK_SIZE = 1024 * 1024;
const MAX_RETRIES = 3;
const RETRY_DELAY = 2;

// Colores ANSI
const COLOR_RESET = "\033[0m";
const COLOR_RED = "\033[31m";
const COLOR_GREEN = "\033[32m";
const COLOR_YELLOW = "\033[33m";
const COLOR_BLUE = "\033[34m";
const COLOR_BOLD = "\033[1m";

// ─────────────────────────────────────────────────────────────────────────────
// Parsear argumentos y validar
// ─────────────────────────────────────────────────────────────────────────────

$args = parseArgs($argv);
$software = $args["software"] ?? "pocketmine";
$version = $args["version"] ?? null;
$dryRun = isset($args["dry-run"]);
$apiVersionOverride = $args["api-version"] ?? null;
$mcVersionOverride = $args["mc-version"] ?? null;
$phpVersionOverride = $args["min-php"] ?? $args["php-version"] ?? null;
$phpTagOverride = $args["php-tag"] ?? null;
$pharUrlOverride = $args["phar-url"] ?? null;
$forksFile = $args["forks-file"] ?? FORKS_PATH;
$force = isset($args["f"]) || isset($args["force"]);

if (!$version || !preg_match('/^\d+\.\d+\.\d+(-[a-zA-Z0-9.]+)?$/', $version)) {
    exitWithError("--version=X.Y.Z es requerido. Formato: semver (ej: 5.44.3 o 5.44.0)");
}

$forksData = loadForksConfig($forksFile);
$forkConfig = $forksData["forks"][$software] ?? null;

if (!$forkConfig) {
    $knownForks = implode(", ", array_keys($forksData["forks"] ?? []));
    exitWithError("Software '{$software}' no encontrado en forks.json. Disponibles: {$knownForks}");
}

$softwareName = $forkConfig["name"] ?? $software;
$softwareRepo = $forkConfig["repo"] ?? "";
$pharName = $forkConfig["phar_name"] ?? "PocketMine-MP.phar";
$tagPrefix = $forkConfig["tag_prefix"] ?? "";
$stubsTagPrefix = $forkConfig["stubs_tag_prefix"] ?? $tagPrefix;

$manifest = loadManifest();

// Verificar si la combinación (software, version) ya existe
$existingIndex = null;
foreach ($manifest["versions"] as $key => $v) {
    $entrySoftware = $v["software"] ?? "pocketmine";
    if ($entrySoftware === $software && ($v["id"] ?? null) === $version) {
        $existingIndex = $key;
        break;
    }
}

if ($existingIndex !== null) {
    if (!$force) {
        exitWithError("Versión {$version} para {$softwareName} ya existe en manifest.json (usa -f para forzar)");
    } else {
        printf("  ⚠️  Versión {$version} ({$softwareName}) ya existe, sobreescribiendo...\n");
        unset($manifest["versions"][$existingIndex]);
        $manifest["versions"] = array_values($manifest["versions"]);
    }
}

printf(
    "\n%s════════════════════════════════════════════════════════════════════════════════%s\n",
    COLOR_BOLD,
    COLOR_RESET,
);
printf("Deepslate — Agregar Versión %s (%s)\n", $version, $softwareName);
printf(
    "%s════════════════════════════════════════════════════════════════════════════════%s\n\n",
    COLOR_BOLD,
    COLOR_RESET,
);

// ─────────────────────────────────────────────────────────────────────────────
// Paso 1: Obtener información del release / build_info.json
// ─────────────────────────────────────────────────────────────────────────────

printf("[1/4] Obteniendo información del release desde GitHub (%s)...\n\n", $softwareRepo);

$build_info = fetchBuildInfo($softwareRepo, $version);
$releaseDetails = null;

if (!$build_info && $softwareRepo) {
    $releaseDetails = fetchReleaseDetails($softwareRepo, $version);
}

$php_version = $phpVersionOverride ?? $build_info["php_version"] ?? "8.2";
$mcpe_version = $mcVersionOverride ?? $build_info["mcpe_version"] ?? ($releaseDetails["mc_version"] ?? "unknown");
$stability = $build_info["is_dev"] ?? false
    ? "alpha"
    : ($build_info["channel"] ?? ($releaseDetails["stability"] ?? "stable"));
$api_version = $apiVersionOverride ?? extractApiVersion($version);
$mc_version = $mcpe_version;

$php_tag = $phpTagOverride;
if (!$php_tag) {
    if (!empty($build_info["php_download_url"])) {
        preg_match('#/tag/([^/]+)$#', $build_info["php_download_url"], $m);
        $php_tag = $m[1] ?? "pm5-php-{$php_version}-latest";
    } else {
        $php_tag = "pm5-php-{$php_version}-latest";
    }
}

if (!str_starts_with($php_tag, "pm5-")) {
    $php_tag = "pm5-" . $php_tag;
}

// Si el tag contiene -latest, intentar resolver al tag real
$php_tag_resolved = $php_tag;
if (str_contains($php_tag, "-latest")) {
    printf("  ⚠ PHP tag contiene '-latest' (mutable), intentando resolver...\n");
    $testUrl = PHP_DOWNLOAD . "/{$php_tag}/";
    $resolved = resolveRedirectUrl($testUrl);
    if ($resolved && preg_match('#/download/([^/]+)/#', $resolved, $rm)) {
        $php_tag_resolved = $rm[1];
        printf("  ✓ Tag resuelto: %s → %s\n", $php_tag, $php_tag_resolved);
    } else {
        printf("  ⚠ No se pudo resolver, usando tag original: %s\n", $php_tag);
        $php_tag_resolved = $php_tag;
    }
}

printf("  ✓ Software:          %s (%s)\n", $softwareName, $software);
printf("  ✓ Versión:           %s\n", $version);
printf("  ✓ API version:       %s\n", $api_version);
printf("  ✓ Minecraft:         %s\n", $mc_version);
printf("  ✓ PHP:               %s\n", $php_version);
printf("  ✓ Stability:         %s\n", $stability);
printf("  ✓ PHP tag (original):%s\n", $php_tag);
printf("  ✓ PHP tag (resolved):%s\n\n", $php_tag_resolved);

// ─────────────────────────────────────────────────────────────────────────────
// Paso 2: Descargar y calcular SHA256 de artefactos
// ─────────────────────────────────────────────────────────────────────────────

printf("[2/4] Descargando artefactos y stubs...\n\n");

$php_file_prefix = str_starts_with($php_tag, "pm5-")
    ? "PHP-{$php_version}"
    : "PHP";

// Construir URL del PHAR
$pharUrl = $pharUrlOverride;
if (!$pharUrl) {
    if (!empty($forkConfig["phar_url_template"])) {
        $pharUrl = str_replace("{version}", $version, $forkConfig["phar_url_template"]);
    } elseif (!empty($softwareRepo)) {
        $pharUrl = "https://github.com/{$softwareRepo}/releases/download/{$version}/{$pharName}";
    } else {
        exitWithError("No se pudo determinar la URL del PHAR para {$software}");
    }
}

$downloads = [
    "server_phar" => $pharUrl,
    "pocketmine_phar" => $pharUrl,
    "php_windows_x64" =>
        PHP_DOWNLOAD . "/{$php_tag_resolved}/{$php_file_prefix}-Windows-x64-PM5.zip",
    "php_linux_x86_64" =>
        PHP_DOWNLOAD . "/{$php_tag_resolved}/{$php_file_prefix}-Linux-x86_64-PM5.tar.gz",
    "php_macos_x86_64" =>
        PHP_DOWNLOAD . "/{$php_tag_resolved}/{$php_file_prefix}-MacOS-x86_64-PM5.tar.gz",
    "php_macos_arm64" =>
        PHP_DOWNLOAD . "/{$php_tag_resolved}/{$php_file_prefix}-MacOS-arm64-PM5.tar.gz",
];

// Extensiones esperadas y sus magic bytes para verificación
$expectedMagic = [
    "server_phar" => ["<?php", "__HALT"],
    "pocketmine_phar" => ["<?php", "__HALT"],
    "php_windows_x64" => ["PK"],
    "php_linux_x86_64" => ["\x1f\x8b"],
    "php_macos_x86_64" => ["\x1f\x8b"],
    "php_macos_arm64" => ["\x1f\x8b"],
];

// Construir URL de stubs dinámicamente según configuración
$stubsReleaseTag = !empty($stubsTagPrefix) ? "{$stubsTagPrefix}{$version}" : $version;
if (!empty($forkConfig["stubs_zip_template"])) {
    $stubsZipFile = str_replace("{version}", $version, $forkConfig["stubs_zip_template"]);
} elseif (!empty($forkConfig["stubs_zip_name"])) {
    $stubsZipFile = str_replace("{version}", $version, $forkConfig["stubs_zip_name"]);
} elseif ($software !== "pocketmine") {
    $stubsZipFile = "stubs-{$software}-{$version}.zip";
} else {
    $stubsZipFile = "stubs-{$version}.zip";
}
$stubsUrl = STUBS_DOWNLOAD . "/{$stubsReleaseTag}/{$stubsZipFile}";

$checksums = [];
$tmpFiles = [];
// Descarga y procesamiento de los Stubs PRIMERO (falla rápido si no existen)
printf("  [↓] %-35s", "stubs ({$stubsZipFile})");
flush();

if ($dryRun) {
    $stubsSha256 = "57419a441873f9357b5bb6b29d184acbe070d9be3fc20830340c02938ea15deb";
    printf(" (dry-run)\n");
} else {
    $stubsTmpFile = sys_get_temp_dir() . "/stubs-{$software}-{$version}.zip";

    if (downloadWithRetries($stubsUrl, $stubsTmpFile, MAX_RETRIES)) {
        $stubsSha256 = hash_file("sha256", $stubsTmpFile);
        $tmpFiles[] = $stubsTmpFile;
        printf(" ✓\n");
    } else {
        printf(" ✗ (no encontrados en %s)\n", $stubsUrl);
        foreach ($tmpFiles as $f) {
            @unlink($f);
        }
        exitWithError("Stubs requeridos no disponibles en {$stubsUrl}. Omitiendo versión {$software}@{$version}.");
    }
}

// Descargar PHAR y Binarios
foreach ($downloads as $key => $url) {
    if ($key === "pocketmine_phar" && isset($checksums["server_phar_sha256"])) {
        $checksums["pocketmine_phar"] = $url;
        $checksums["pocketmine_phar_sha256"] = $checksums["server_phar_sha256"];
        continue;
    }

    printf("  [↓] %-35s", $key);
    flush();

    if ($dryRun) {
        $checksums[$key] = $url;
        $checksums[$key . "_sha256"] =
            "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855";
        printf(" (dry-run)\n");
    } else {
        $tmpFile = sys_get_temp_dir() . "/pm_{$software}_{$version}_" . basename($url);

        if (downloadWithRetries($url, $tmpFile, MAX_RETRIES)) {
            $magic = $expectedMagic[$key] ?? [];
            $integrityError = validateDownloadIntegrity($tmpFile, $magic);

            if ($integrityError !== null) {
                printf(" ⚠️  (%s, recalculando URL)\n", $integrityError);
                @unlink($tmpFile);

                $resolvedUrl = resolveRedirectUrl($url);
                if ($resolvedUrl && $resolvedUrl !== $url) {
                    printf("    → Reintentando con URL resuelta...\n");
                    if (downloadWithRetries($resolvedUrl, $tmpFile, MAX_RETRIES)) {
                        $integrityError = validateDownloadIntegrity($tmpFile, $magic);
                        if ($integrityError === null) {
                            $sha256 = hash_file("sha256", $tmpFile);
                            $checksums[$key] = $resolvedUrl;
                            $checksums[$key . "_sha256"] = $sha256;
                            $tmpFiles[] = $tmpFile;
                            printf("    ✓ (URL resuelta)\n");
                            continue;
                        }
                    }
                }
                printf("    ✗ No se pudo verificar integridad, saltando\n");
            } else {
                $sha256 = hash_file("sha256", $tmpFile);
                $checksums[$key] = $url;
                $checksums[$key . "_sha256"] = $sha256;
                $tmpFiles[] = $tmpFile;
                printf(" ✓\n");
            }
        } else {
            printf(" ⚠️  (saltando)\n");
        }
    }
}

if (isset($checksums["server_phar_sha256"])) {
    $checksums["pocketmine_phar"] = $downloads["pocketmine_phar"];
    $checksums["pocketmine_phar_sha256"] = $checksums["server_phar_sha256"];
}

// Validar que todas las descargas requeridas estén completas
$requiredDownloadKeys = [
    "server_phar",
    "pocketmine_phar",
    "php_windows_x64",
    "php_linux_x86_64",
    "php_macos_x86_64",
    "php_macos_arm64",
];
$missingDownloads = [];
foreach ($requiredDownloadKeys as $reqKey) {
    if (empty($checksums[$reqKey]) || empty($checksums[$reqKey . "_sha256"])) {
        $missingDownloads[] = $reqKey;
    }
}
if (!empty($missingDownloads)) {
    foreach ($tmpFiles as $f) {
        @unlink($f);
    }
    exitWithError("Descargas requeridas faltantes o con error para {$software}@{$version}: " . implode(", ", $missingDownloads));
}

printf("\n");

// ─────────────────────────────────────────────────────────────────────────────
// Paso 3: Crear entrada de versión
// ─────────────────────────────────────────────────────────────────────────────

printf("[3/4] Creando entrada de versión...\n\n");

$changelogUrl = $software === "pocketmine"
    ? "https://github.com/{$softwareRepo}/releases/download/{$version}/changelogs/" . getMajorMinor($version) . ".md"
    : "https://github.com/{$softwareRepo}/releases/tag/{$version}";

$newEntry = [
    "id" => $version,
    "software" => $software,
    "api_version" => $api_version,
    "release_date" => date("Y-m-d"),
    "stability" => $stability,
    "minecraft_version" => $mc_version,
    "min_php" => $php_version,
    "php_binary_tag" => $php_tag_resolved,
    "changelog_url" => $changelogUrl,
    "downloads" => $checksums,
    "stubs" => [
        "url" => $stubsUrl,
        "checksum_sha256" => $stubsSha256,
    ],
];

// ─────────────────────────────────────────────────────────────────────────────
// Paso 4: Guardar en manifest.json
// ─────────────────────────────────────────────────────────────────────────────

printf("[4/4] Finalizando...\n\n");

// Construir sección softwares para el root del manifest
$manifestSoftwares = [];
foreach ($forksData["forks"] as $fKey => $fVal) {
    $manifestSoftwares[$fKey] = [
        "name" => $fVal["name"] ?? $fKey,
        "repo" => $fVal["repo"] ?? "",
        "description" => $fVal["description"] ?? "",
    ];
}

$manifest["manifest_version"] = 2;
$manifest["softwares"] = $manifestSoftwares;

if ($dryRun) {
    array_unshift($manifest["versions"], $newEntry);
    $manifest["updated_at"] = gmdate("Y-m-d\TH:i:s\Z");
    $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    printf("  [DRY RUN] JSON resultante (primeras 50 líneas):\n\n");
    $lines = explode("\n", $json);
    echo implode("\n", array_slice($lines, 0, 50)) . "\n...\n";
} else {
    $lockFile = fopen(MANIFEST_PATH, "r+");
    if (!$lockFile) {
        exitWithError("No se pudo abrir manifest.json para bloqueo");
    }
    if (!flock($lockFile, LOCK_EX)) {
        fclose($lockFile);
        exitWithError("No se pudo adquirir el bloqueo sobre manifest.json");
    }

    $content = stream_get_contents($lockFile);
    $currentManifest = json_decode($content, true);
    if (!is_array($currentManifest)) {
        $currentManifest = ["manifest_version" => 2, "softwares" => $manifestSoftwares, "versions" => []];
    }

    $currentManifest["manifest_version"] = 2;
    $currentManifest["softwares"] = $manifestSoftwares;

    // Remover versión existente si la hay (ya que estamos forzando)
    foreach ($currentManifest["versions"] as $key => $v) {
        $entrySoftware = $v["software"] ?? "pocketmine";
        if ($entrySoftware === $software && ($v["id"] ?? null) === $version) {
            unset($currentManifest["versions"][$key]);
        }
    }
    $currentManifest["versions"] = array_values($currentManifest["versions"]);

    // Agregar la nueva versión al principio
    array_unshift($currentManifest["versions"], $newEntry);
    $currentManifest["updated_at"] = gmdate("Y-m-d\TH:i:s\Z");

    $json = json_encode($currentManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

    ftruncate($lockFile, 0);
    rewind($lockFile);
    if (fwrite($lockFile, $json) === false) {
        flock($lockFile, LOCK_UN);
        fclose($lockFile);
        exitWithError("No se pudo escribir manifest.json");
    }

    flock($lockFile, LOCK_UN);
    fclose($lockFile);

    printf("  ✓ manifest.json actualizado\n");
    printf("  ✓ Versión %s (%s) agregada\n", $version, $softwareName);

    foreach ($tmpFiles as $f) {
        @unlink($f);
    }
    printf("  ✓ Archivos temporales limpiados\n");
}

printf(
    "\n%s════════════════════════════════════════════════════════════════════════════════%s\n",
    COLOR_BOLD,
    COLOR_RESET,
);
if ($dryRun) {
    printf("DRY RUN - Sin cambios reales realizados\n");
} else {
    printf(
        "✅ Versión %s (%s) agregada correctamente con hashes reales\n",
        $version,
        $softwareName,
    );
}
printf(
    "%s════════════════════════════════════════════════════════════════════════════════%s\n\n",
    COLOR_BOLD,
    COLOR_RESET,
);

exit(0);

// ─────────────────────────────────────────────────────────────────────────────
// Funciones auxiliares
// ─────────────────────────────────────────────────────────────────────────────

function exitWithError(string $msg): never
{
    fprintf(STDERR, "\n%s❌ ERROR:%s %s\n\n", COLOR_RED, COLOR_RESET, $msg);
    exit(1);
}

function parseArgs(array $argv): array
{
    $result = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, "--")) {
            $arg = ltrim($arg, "-");
            if (str_contains($arg, "=")) {
                [$k, $v] = explode("=", $arg, 2);
                $result[$k] = $v;
            } else {
                $result[$arg] = true;
            }
        } elseif (str_starts_with($arg, "-")) {
            $arg = ltrim($arg, "-");
            $result[$arg] = true;
        }
    }
    return $result;
}

function loadManifest(): array
{
    if (!file_exists(MANIFEST_PATH)) {
        exitWithError("manifest.json no encontrado");
    }
    $data = json_decode(file_get_contents(MANIFEST_PATH), true);
    return is_array($data) ? $data : [];
}

function loadForksConfig(string $path): array
{
    if (!file_exists($path)) {
        exitWithError("Archivo forks config no encontrado en {$path}");
    }
    $data = json_decode(file_get_contents($path), true);
    if (!is_array($data) || !isset($data["forks"])) {
        exitWithError("forks.json inválido: debe contener la clave 'forks'");
    }
    return $data;
}

function fetchBuildInfo(string $repo, string $version): ?array
{
    if (empty($repo)) {
        return null;
    }

    $url = "https://github.com/{$repo}/releases/download/{$version}/build_info.json";

    $context = stream_context_create([
        "http" => ["follow_location" => true, "timeout" => 15, "user_agent" => "deepslate/manifest"],
        "https" => ["follow_location" => true, "timeout" => 15, "user_agent" => "deepslate/manifest"],
    ]);

    $content = @file_get_contents($url, false, $context);
    if (!$content) {
        return null;
    }

    $data = json_decode($content, true);
    return is_array($data) ? $data : null;
}

function fetchReleaseDetails(string $repo, string $version): array
{
    $result = [
        "mc_version" => "unknown",
        "stability" => "stable",
    ];

    $url = "https://api.github.com/repos/{$repo}/releases/tags/{$version}";

    $context = stream_context_create([
        "http" => [
            "method" => "GET",
            "header" => "User-Agent: deepslate-manifest\r\nAccept: application/vnd.github+json\r\n",
            "timeout" => 15,
        ],
        "https" => [
            "method" => "GET",
            "header" => "User-Agent: deepslate-manifest\r\nAccept: application/vnd.github+json\r\n",
            "timeout" => 15,
        ],
    ]);

    $content = @file_get_contents($url, false, $context);
    if ($content) {
        $data = json_decode($content, true);
        if (is_array($data)) {
            $body = $data["body"] ?? "";
            if (preg_match('/Bedrock[^\d]*(\d+\.\d+\.\d+)/i', $body, $bm)) {
                $result["mc_version"] = $bm[1];
            }
            if (!empty($data["prerelease"])) {
                $result["stability"] = "beta";
            }
        }
    }

    return $result;
}

function downloadWithRetries(string $url, string $dest, int $maxRetries): bool
{
    if (function_exists('curl_init')) {
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $fp = @fopen($dest, 'w+');
            if (!$fp) {
                return false;
            }
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_FILE => $fp,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 300,
                CURLOPT_USERAGENT => "deepslate/manifest",
                CURLOPT_FAILONERROR => false,
            ]);
            $success = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            fclose($fp);

            if ($httpCode === 404 || $httpCode === 410) {
                @unlink($dest);
                return false;
            }

            if ($success && $httpCode >= 200 && $httpCode < 300 && file_exists($dest) && filesize($dest) > 0) {
                return true;
            }

            @unlink($dest);
            if ($attempt < $maxRetries) {
                sleep(RETRY_DELAY);
            }
        }
        return false;
    }

    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        $context = stream_context_create([
            "http" => [
                "method" => "GET",
                "follow_location" => true,
                "timeout" => 300,
                "user_agent" => "deepslate/manifest",
                "ignore_errors" => true,
            ],
            "https" => [
                "method" => "GET",
                "follow_location" => true,
                "timeout" => 300,
                "user_agent" => "deepslate/manifest",
                "ignore_errors" => true,
            ],
        ]);

        $in = @fopen($url, "rb", false, $context);
        $out = @fopen($dest, "wb");

        if (!$in || !$out) {
            if ($in) fclose($in);
            if ($out) fclose($out);
            if ($attempt < $maxRetries) {
                sleep(RETRY_DELAY);
            }
            continue;
        }

        $meta = stream_get_meta_data($in);
        $headers = $meta["wrapper_data"] ?? [];
        $httpCode = 0;
        foreach ($headers as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#i', $h, $m)) {
                $httpCode = (int) $m[1];
            }
        }

        if ($httpCode === 404 || $httpCode === 410) {
            fclose($in);
            fclose($out);
            @unlink($dest);
            return false;
        }

        $expectedSize = null;
        foreach ($headers as $header) {
            if (preg_match('/^Content-Length:\s*(\d+)/i', $header, $hm)) {
                $expectedSize = (int) $hm[1];
            }
        }

        $bytesWritten = 0;
        $success = true;
        while (!feof($in)) {
            $chunk = @fread($in, CHUNK_SIZE);
            if ($chunk === false) {
                $success = false;
                break;
            }
            $written = @fwrite($out, $chunk);
            if ($written === false) {
                $success = false;
                break;
            }
            $bytesWritten += $written;
        }

        fclose($in);
        fclose($out);

        if (!$success || $bytesWritten === 0 || ($httpCode >= 400 && $httpCode < 600)) {
            @unlink($dest);
            if ($attempt < $maxRetries) {
                sleep(RETRY_DELAY);
            }
            continue;
        }

        if ($expectedSize !== null && $bytesWritten !== $expectedSize) {
            fprintf(
                STDERR,
                "\n    ⚠ Descarga incompleta: %d/%d bytes (intento %d/%d)\n",
                $bytesWritten,
                $expectedSize,
                $attempt,
                $maxRetries,
            );
            @unlink($dest);
            if ($attempt < $maxRetries) {
                sleep(RETRY_DELAY);
            }
            continue;
        }

        return true;
    }

    return false;
}

function resolveRedirectUrl(string $url): ?string
{
    if (!function_exists('curl_init')) {
        $context = stream_context_create([
            "http" => [
                "method" => "HEAD",
                "follow_location" => true,
                "timeout" => 15,
                "user_agent" => "deepslate/manifest",
            ],
            "https" => [
                "method" => "HEAD",
                "follow_location" => true,
                "timeout" => 15,
                "user_agent" => "deepslate/manifest",
            ],
        ]);

        $headers = @get_headers($url, true, $context);
        if ($headers === false) {
            return null;
        }

        $location = $headers["Location"] ?? null;
        if (is_array($location)) {
            return end($location) ?: null;
        }
        return $location;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_USERAGENT => "deepslate/manifest",
        CURLOPT_RETURNTRANSFER => true,
    ]);
    curl_exec($ch);
    $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 200 && $httpCode < 400 && $finalUrl) {
        return $finalUrl;
    }

    return null;
}

function validateDownloadIntegrity(string $filePath, array $expectedMagicPatterns = []): ?string
{
    if (!file_exists($filePath)) {
        return "archivo no existe";
    }

    $size = filesize($filePath);

    if ($size < 1024) {
        return "archivo muy pequeño ({$size} bytes)";
    }

    if (empty($expectedMagicPatterns)) {
        return null;
    }

    $header = file_get_contents($filePath, false, null, 0, 64);
    if ($header === false) {
        return "no se puede leer el header";
    }

    if (str_contains($header, "<!DOCTYPE") || str_contains($header, "<html")) {
        return "contenido HTML detectado (posible página de error)";
    }

    foreach ($expectedMagicPatterns as $magic) {
        if (str_contains($header, $magic)) {
            return null;
        }
    }

    return "magic bytes no coinciden (esperado: " . implode(" | ", $expectedMagicPatterns) . ")";
}

function extractApiVersion(string $version): string
{
    $parts = explode(".", $version);
    if (count($parts) >= 3) {
        $parts[2] = "0";
    }
    return implode(".", $parts);
}

function getMajorMinor(string $version): string
{
    $parts = explode(".", $version);
    return $parts[0] . "." . $parts[1];
}
