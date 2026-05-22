#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * PocketIDE — update-manifest.php
 *
 * Descarga versión de PocketMine-MP, extrae build_info.json de GitHub,
 * calcula SHA256 y actualiza manifest.json automáticamente.
 *
 * Características:
 * - Auto-detecta info de build_info.json (GitHub releases)
 * - Descarga artefactos con reintentos inteligentes
 * - Cálculo de SHA256 con streaming
 * - Soporte para 5 plataformas
 * - Modo dry-run para verificación
 *
 * Uso:
 *   php scripts/update-manifest.php --version=5.43.1
 *   php scripts/update-manifest.php --version=5.43.1 --dry-run
 *   php scripts/update-manifest.php --version=5.43.1 --api-version=5.43.0  # override
 */

const PM_RELEASES = "https://github.com/pmmp/PocketMine-MP/releases";
const PM_DOWNLOAD = "https://github.com/pmmp/PocketMine-MP/releases/download";
const PHP_DOWNLOAD = "https://github.com/pmmp/PHP-Binaries/releases/download";
const STUBS_DOWNLOAD = "https://github.com/pocketide/pocketmine-stubs/releases/download";
const MANIFEST_PATH = __DIR__ . "/../manifest.json";
const CHUNK_SIZE = 1024 * 1024;
const MAX_RETRIES = 3;
const RETRY_DELAY = 2;

// Colores ANSI
const COLOR_RESET = "\033[0m";
const COLOR_RED = "\033[31m";
const COLOR_GREEN = "\033[32m";
const COLOR_YELLOW = "\033[33m";
const COLOR_BOLD = "\033[1m";

// ─────────────────────────────────────────────────────────────────────────────
// Parsear argumentos y validar
// ─────────────────────────────────────────────────────────────────────────────

$args = parseArgs($argv);
$version = $args["version"] ?? null;
$dryRun = isset($args["dry-run"]);
$apiVersionOverride = $args["api-version"] ?? null;
$mcVersionOverride = $args["mc-version"] ?? null;

if (!$version || !preg_match('/^\d+\.\d+\.\d+$/', $version)) {
    exitWithError("--version=X.Y.Z es requerido. Formato: semver (ej: 5.43.1)");
}

// Cargar manifest
$manifest = loadManifest();
foreach ($manifest["versions"] as $v) {
    if (($v["id"] ?? null) === $version) {
        exitWithError("Versión {$version} ya existe en manifest.json");
    }
}

printf(
    "\n%s════════════════════════════════════════════════════════════════════════════════%s\n",
    COLOR_BOLD,
    COLOR_RESET,
);
printf("PocketIDE — Agregar Versión %s\n", $version);
printf(
    "%s════════════════════════════════════════════════════════════════════════════════%s\n\n",
    COLOR_BOLD,
    COLOR_RESET,
);

// ─────────────────────────────────────────────────────────────────────────────
// Paso 1: Obtener build_info.json de la versión
// ─────────────────────────────────────────────────────────────────────────────

printf("[1/4] Obteniendo información del PHAR desde GitHub...\n\n");

$build_info = fetchBuildInfo($version);
if (!$build_info) {
    exitWithError(
        "No se encontró build_info.json para v{$version}. Verifica que la versión existe en GitHub.",
    );
}

$php_version = $build_info["php_version"] ?? "8.2";
$mcpe_version = $build_info["mcpe_version"] ?? "unknown";
$stability =
    $build_info["is_dev"] ?? false
        ? "alpha"
        : $build_info["channel"] ?? "stable";
$api_version = $apiVersionOverride ?? extractApiVersion($version);
$mc_version = $mcVersionOverride ?? $mcpe_version;

// Extraer PHP tag de la URL
preg_match('#/tag/([^/]+)$#', $build_info["php_download_url"] ?? "", $m);
$php_tag = $m[1] ?? "pm5-php-{$php_version}-latest";

printf("  ✓ Versión:           %s\n", $version);
printf("  ✓ API version:       %s\n", $api_version);
printf("  ✓ Minecraft:         %s\n", $mc_version);
printf("  ✓ PHP:               %s\n", $php_version);
printf("  ✓ Stability:         %s\n", $stability);
printf("  ✓ PHP tag:           %s\n\n", $php_tag);

// ─────────────────────────────────────────────────────────────────────────────
// Paso 2: Descargar y calcular SHA256 de artefactos
// ─────────────────────────────────────────────────────────────────────────────

printf("[2/4] Descargando artefactos...\n\n");

$downloads = [
    "pocketmine_phar" => PM_DOWNLOAD . "/{$version}/PocketMine-MP.phar",
    "php_windows_x64" =>
        PHP_DOWNLOAD . "/{$php_tag}/PHP-{$php_version}-Windows-x64-PM5.zip",
    "php_linux_x86_64" =>
        PHP_DOWNLOAD . "/{$php_tag}/PHP-{$php_version}-Linux-x86_64-PM5.tar.gz",
    "php_linux_aarch64" =>
        PHP_DOWNLOAD .
        "/{$php_tag}/PHP-{$php_version}-Linux-aarch64-PM5.tar.gz",
    "php_macos_x86_64" =>
        PHP_DOWNLOAD . "/{$php_tag}/PHP-{$php_version}-MacOS-x86_64-PM5.tar.gz",
    "php_macos_arm64" =>
        PHP_DOWNLOAD . "/{$php_tag}/PHP-{$php_version}-MacOS-arm64-PM5.tar.gz",
];

$checksums = [];
$tmpFiles = [];

foreach ($downloads as $key => $url) {
    printf("  [↓] %-35s", $key);
    flush();

    if ($dryRun) {
        $checksums[$key] = "NEEDS_SHA256_COMPUTE";
        $checksums[$key . "_sha256"] = "NEEDS_SHA256_COMPUTE";
        printf(" (dry-run)\n");
    } else {
        $tmpFile = sys_get_temp_dir() . "/" . basename($url);

        if (downloadWithRetries($url, $tmpFile, MAX_RETRIES)) {
            $sha256 = hash_file("sha256", $tmpFile);
            $checksums[$key] = $url;
            $checksums[$key . "_sha256"] = $sha256;
            $tmpFiles[] = $tmpFile;
            printf(" ✓\n");
        } else {
            printf(" ⚠️  (saltando)\n");
        }
    }
}

printf("\n");

// ─────────────────────────────────────────────────────────────────────────────
// Paso 3: Crear entrada de versión
// ─────────────────────────────────────────────────────────────────────────────

printf("[3/4] Creando entrada de versión...\n\n");

$newEntry = [
    "id" => $version,
    "api_version" => $api_version,
    "release_date" => date("Y-m-d"),
    "stability" => $stability,
    "minecraft_version" => $mc_version,
    "min_php" => $php_version,
    "changelog_url" =>
        PM_DOWNLOAD .
        "/{$version}/changelogs/" .
        getMajorMinor($version) .
        ".md",
    "downloads" => $checksums,
    "stubs" => [
        "url" => STUBS_DOWNLOAD . "/{$version}/stubs.zip",
        "checksum_sha256" => "NEEDS_SHA256_COMPUTE",
    ],
];

array_unshift($manifest["versions"], $newEntry);
$manifest["updated_at"] = gmdate("Y-m-d\TH:i:s\Z");

printf("  ✓ Entrada creada\n\n");

// ─────────────────────────────────────────────────────────────────────────────
// Paso 4: Guardar o mostrar
// ─────────────────────────────────────────────────────────────────────────────

printf("[4/4] Finalizando...\n\n");

$json =
    json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

if ($dryRun) {
    printf("  [DRY RUN] JSON resultante:\n\n");
    echo $json;
} else {
    if (!file_put_contents(MANIFEST_PATH, $json)) {
        exitWithError("No se pudo escribir manifest.json");
    }

    printf("  ✓ manifest.json actualizado\n");
    printf("  ✓ Versión %s agregada\n", $version);

    // Limpiar temporales
    foreach ($tmpFiles as $f) {
        @unlink($f);
    }
    printf("  ✓ Archivos temporales limpiados\n");
}

// Detectar campos incompletos
$incomplete = [];
if ($checksums["pocketmine_phar_sha256"] === "NEEDS_SHA256_COMPUTE") {
    $incomplete[] = "phar SHA256";
}
if ($newEntry["stubs"]["checksum_sha256"] === "NEEDS_SHA256_COMPUTE") {
    $incomplete[] = "stubs SHA256";
}

if (!empty($incomplete)) {
    printf("\n  ⚠️  Campos incompletos (edita manualmente):\n");
    foreach ($incomplete as $field) {
        printf("     • %s\n", $field);
    }
}

printf(
    "\n%s════════════════════════════════════════════════════════════════════════════════%s\n",
    COLOR_BOLD,
    COLOR_RESET,
);

if ($dryRun) {
    printf("DRY RUN - Sin cambios realizados\n");
} else {
    printf("✅ Versión %s agregada correctamente\n", $version);
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

function fetchBuildInfo(string $version): ?array
{
    // Obtener build_info.json desde el archivo del PHAR en GitHub
    $url = PM_DOWNLOAD . "/{$version}/build_info.json";

    $context = stream_context_create([
        "http" => ["follow_location" => true, "timeout" => 30],
        "https" => ["follow_location" => true, "timeout" => 30],
    ]);

    $content = @file_get_contents($url, false, $context);
    if (!$content) {
        return null;
    }

    $data = json_decode($content, true);
    return is_array($data) ? $data : null;
}

function downloadWithRetries(string $url, string $dest, int $maxRetries): bool
{
    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        $context = stream_context_create([
            "http" => [
                "method" => "GET",
                "follow_location" => true,
                "timeout" => 300,
                "user_agent" => "pocketide/manifest",
            ],
            "https" => [
                "method" => "GET",
                "follow_location" => true,
                "timeout" => 300,
                "user_agent" => "pocketide/manifest",
            ],
        ]);

        $in = @fopen($url, "rb", false, $context);
        $out = @fopen($dest, "wb");

        if (!$in || !$out) {
            if ($attempt < $maxRetries) {
                sleep(RETRY_DELAY);
            }
            continue;
        }

        $success = true;
        while (!feof($in)) {
            $chunk = @fread($in, CHUNK_SIZE);
            if ($chunk === false || @fwrite($out, $chunk) === false) {
                $success = false;
                break;
            }
        }

        fclose($in);
        fclose($out);

        if ($success && filesize($dest) > 0) {
            return true;
        }

        @unlink($dest);
        if ($attempt < $maxRetries) {
            sleep(RETRY_DELAY);
        }
    }

    return false;
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

