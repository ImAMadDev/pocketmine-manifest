#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * PocketIDE — validate-manifest.php
 *
 * Verifica la integridad completa de manifest.json:
 *
 * Validaciones incluidas:
 * - Estructura JSON válida y bien formada
 * - Campos raíz requeridos (manifest_version, updated_at, versions)
 * - Campos de versión requeridos en todas las entradas
 * - Checksums SHA256 válidos (formato hexadecimal de 64 caracteres)
 * - Sin placeholders (NEEDS_SHA256_COMPUTE, NEEDS_MANUAL_FILL)
 * - URLs accesibles (con --check-urls)
 * - SHA256 verificado descargando y validando (con --verify-checksums)
 * - Formato de versiones semántico (X.Y.Z)
 * - Estabilidad válida (stable, beta, alpha, rc)
 * - Fechas en formato ISO 8601
 *
 * Características:
 * - Ordenamiento automático por SemVer (más reciente primero)
 * - Reporte detallado con errores y advertencias
 * - Colores ANSI para mejor legibilidad
 * - Estadísticas de validación
 * - Detección de versiones duplicadas
 * - Validación de rangos de versiones
 *
 * Uso: php scripts/validate-manifest.php [OPTIONS]
 *
 * Opciones:
 * --check-urls           Verificar que todas las URLs responden (HEAD request)
 * --verify-checksums     Descargar y verificar SHA256 (lento, requiere espacio)
 * --strict               Modo estricto: advertencias se tratan como errores
 * --version=X.Y.Z        Validar solo una versión específica
 */

const MANIFEST_PATH = __DIR__ . "/../manifest.json";
const HEAD_REQUEST_TIMEOUT = 15;
const COLOR_RESET = "\033[0m";
const COLOR_RED = "\033[31m";
const COLOR_GREEN = "\033[32m";
const COLOR_YELLOW = "\033[33m";
const COLOR_BLUE = "\033[34m";
const COLOR_BOLD = "\033[1m";

// ─────────────────────────────────────────────────────────────────────────────
// Parsear argumentos
// ─────────────────────────────────────────────────────────────────────────────

$args = parseArgs($argv);
$checkUrls = isset($args["check-urls"]);
$verifyChecksums = isset($args["verify-checksums"]);
$strictMode = isset($args["strict"]);
$filterVersion = $args["version"] ?? null;

$startTime = microtime(true);
$errors = [];
$warnings = [];
$stats = [
    "versions_checked" => 0,
    "urls_checked" => 0,
    "checksums_verified" => 0,
];

// ─────────────────────────────────────────────────────────────────────────────
// Cargar manifest
// ─────────────────────────────────────────────────────────────────────────────

printf(
    "\n%s=== PocketIDE — Validar Manifest ===%s\n\n",
    COLOR_BOLD,
    COLOR_RESET,
);

if (!file_exists(MANIFEST_PATH)) {
    exitWithError("No se encontró " . MANIFEST_PATH);
}

$content = file_get_contents(MANIFEST_PATH);
$manifest = json_decode($content, true);

if (!is_array($manifest)) {
    exitWithError("manifest.json no es JSON válido");
}

if (!is_array($manifest["versions"] ?? null)) {
    exitWithError("Campo 'versions' no es un array o está ausente");
}

printf("Versiones en manifest: %d\n", count($manifest["versions"]));
printf(
    "Última actualización:  %s\n\n",
    $manifest["updated_at"] ?? "desconocido",
);

// ─────────────────────────────────────────────────────────────────────────────
// Auto-ordenamiento de versiones (Más reciente primero usando SemVer)
// ─────────────────────────────────────────────────────────────────────────────

$originalVersions = $manifest["versions"];

// Usamos uasort para preservar claves o mantener estabilidad en la ordenación
usort($manifest["versions"], function (array $a, array $b): int {
    $idA = $a["id"] ?? "0.0.0";
    $idB = $b["id"] ?? "0.0.0";
    // version_compare por defecto devuelve -1 si A < B, 1 si A > B.
    // Para orden descendente invertimos los operandos ($b, $a)
    return version_compare($idB, $idA);
});

// Comprobar si el orden cambió respecto al archivo físico original
$orderHasChanged = false;
foreach ($manifest["versions"] as $index => $versionData) {
    if (
        ($versionData["id"] ?? null) !==
        ($originalVersions[$index]["id"] ?? null)
    ) {
        $orderHasChanged = true;
        break;
    }
}

if ($orderHasChanged) {
    // Alerta informativa. No se añade al array de $warnings para evitar activar fallos por --strict
    printf(
        "%sℹ INFO: Las versiones en el archivo no están ordenadas. Ordenando internamente de mayor a menor... %s\n\n",
        COLOR_BLUE,
        COLOR_RESET,
    );
}

// ─────────────────────────────────────────────────────────────────────────────
// Validar campos raíz
// ─────────────────────────────────────────────────────────────────────────────

printf("[1/3] Validando estructura raíz...\n");

foreach (["manifest_version", "updated_at", "versions"] as $field) {
    if (!isset($manifest[$field])) {
        $errors[] = "Campo raíz '{$field}' ausente.";
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Validar cada versión
// ─────────────────────────────────────────────────────────────────────────────

printf("[2/3] Validando %d versiones...\n\n", count($manifest["versions"]));

$requiredFields = [
    "id",
    "api_version",
    "release_date",
    "stability",
    "minecraft_version",
    "min_php",
    "downloads",
    "stubs",
];

$requiredDownloads = [
    "pocketmine_phar",
    "pocketmine_phar_sha256",
    "php_windows_x64",
    "php_windows_x64_sha256",
    "php_linux_x86_64",
    "php_linux_x86_64_sha256",
    "php_macos_x86_64",
    "php_macos_x86_64_sha256",
    "php_macos_arm64",
    "php_macos_arm64_sha256",
];

$seenVersions = [];
$seenPharHashes = [];
$seenPhpHashes = [];

foreach ($manifest["versions"] as $i => $v) {
    $vid = $v["id"] ?? "versión #{$i}";

    // Filtrar por versión si se especifica
    if ($filterVersion && $vid !== $filterVersion) {
        continue;
    }

    $stats["versions_checked"]++;

    // Detectar duplicados
    if (isset($seenVersions[$vid])) {
        $errors[] = "[{$vid}] Versión duplicada (también detectada en otra entrada)";
    }
    $seenVersions[$vid] = $i;

    // Validar campos requeridos
    foreach ($requiredFields as $f) {
        if (!isset($v[$f])) {
            $errors[] = "[{$vid}] Campo '{$f}' ausente.";
        }
    }

    // Validar formato de versión semántico
    if (
        isset($v["id"]) &&
        !preg_match('/^\d+\.\d+\.\d+(-[a-zA-Z0-9]+)?$/', $v["id"])
    ) {
        $warnings[] = "[{$vid}] Formato de versión inusual (esperado X.Y.Z)";
    }

    // Validar estabilidad
    if (
        isset($v["stability"]) &&
        !in_array($v["stability"], ["stable", "beta", "alpha", "rc"], true)
    ) {
        $warnings[] = "[{$vid}] Estabilidad inválida: '{$v["stability"]}'";
    }

    // Validar fecha ISO 8601
    if (
        isset($v["release_date"]) &&
        !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v["release_date"])
    ) {
        $warnings[] = "[{$vid}] Fecha de release no es ISO 8601: '{$v["release_date"]}'";
    }

    // Detectar placeholders
    $haystack = json_encode($v);
    if (str_contains($haystack, "NEEDS_SHA256_COMPUTE")) {
        $warnings[] = "[{$vid}] SHA256 sin calcular (NEEDS_SHA256_COMPUTE)";
    }
    if (str_contains($haystack, "NEEDS_MANUAL_FILL")) {
        $warnings[] = "[{$vid}] Campos sin completar (NEEDS_MANUAL_FILL)";
    }

    // Validar downloads
    if (!is_array($v["downloads"] ?? null)) {
        $errors[] = "[{$vid}] 'downloads' no es un array";
        continue;
    }

    foreach ($requiredDownloads as $f) {
        if (empty($v["downloads"][$f])) {
            $errors[] = "[{$vid}] downloads.{$f} ausente o vacío";
        }
    }

    // Validar stubs
    if (!is_array($v["stubs"] ?? null)) {
        $errors[] = "[{$vid}] 'stubs' no es un array";
    } else {
        if (empty($v["stubs"]["url"])) {
            $errors[] = "[{$vid}] stubs.url ausente";
        }
        if (empty($v["stubs"]["checksum_sha256"])) {
            $errors[] = "[{$vid}] stubs.checksum_sha256 ausente";
        }
    }

    // Validar formato SHA256 (64 hex chars)
    $sha256Fields = array_filter(
        array_keys($v["downloads"] ?? []),
        fn($k) => str_ends_with($k, "_sha256"),
    );
    foreach ($sha256Fields as $f) {
        $hash = $v["downloads"][$f] ?? "";
        if (
            $hash !== "NEEDS_SHA256_COMPUTE" &&
            !preg_match('/^[a-f0-9]{64}$/', $hash)
        ) {
            $errors[] = "[{$vid}] downloads.{$f} no es SHA256 válido: '{$hash}'";
        }
    }

    // Comprobar URLs mutables
    foreach ($v["downloads"] as $downloadKey => $url) {
        if (!str_ends_with($downloadKey, "_sha256") && str_contains((string)$url, "-latest")) {
            $warnings[] = "[{$vid}] URL mutable detectada: downloads.{$downloadKey} contiene '-latest'. El SHA256 no será confiable a largo plazo.";
        }
    }

    // Rastrear hashes para comprobar duplicados
    $pharHash = $v["downloads"]["pocketmine_phar_sha256"] ?? "";
    if ($pharHash && $pharHash !== "NEEDS_SHA256_COMPUTE") {
        if (!isset($seenPharHashes[$pharHash])) {
            $seenPharHashes[$pharHash] = [];
        }
        $seenPharHashes[$pharHash][] = $vid;
    }

    $phpFields = [
        "php_windows_x64_sha256",
        "php_linux_x86_64_sha256",
        "php_macos_x86_64_sha256",
        "php_macos_arm64_sha256"
    ];
    foreach ($phpFields as $field) {
        $hash = $v["downloads"][$field] ?? "";
        if ($hash && $hash !== "NEEDS_SHA256_COMPUTE") {
            if (!isset($seenPhpHashes[$field][$hash])) {
                $seenPhpHashes[$field][$hash] = [];
            }
            $seenPhpHashes[$field][$hash][] = $vid;
        }
    }

    // Comprobar URLs (HEAD request)
    if ($checkUrls) {
        $urlsToCheck = array_filter(
            $v["downloads"] ?? [],
            fn($k) => !str_ends_with($k, "_sha256"),
            ARRAY_FILTER_USE_KEY,
        );
        $urlsToCheck["stubs"] = $v["stubs"]["url"] ?? "";

        foreach ($urlsToCheck as $urlKey => $url) {
            if (empty($url) || str_contains($url, "NEEDS")) {
                continue;
            }

            $stats["urls_checked"]++;
            $code = getHttpCode($url);

            if ($code !== 200 && $code !== 302) {
                $warnings[] = "[{$vid}] URL({$urlKey}) responde HTTP {$code}: {$url}";
            } else {
                printf("  ✓ %s: HTTP %d\n", $vid, $code);
            }
        }
    }

    // Verificar SHA256 del phar descargándolo
    if (
        $verifyChecksums &&
        !str_contains($v["downloads"]["pocketmine_phar_sha256"] ?? "", "NEEDS")
    ) {
        $pharUrl = $v["downloads"]["pocketmine_phar"] ?? "";
        $expected = $v["downloads"]["pocketmine_phar_sha256"] ?? "";

        if ($pharUrl && $expected) {
            printf("  [VERIFY] Descargando PHAR de %s...", $vid);
            flush();

            $tmp = tempnam(sys_get_temp_dir(), "pocketmine_") . ".phar";

            try {
                $content = @file_get_contents($pharUrl);
                if ($content === false) {
                    throw new Exception("No se pudo descargar");
                }
                file_put_contents($tmp, $content);

                $actual = hash_file("sha256", $tmp);
                unlink($tmp);

                $stats["checksums_verified"]++;

                if ($actual !== $expected) {
                    $errors[] = "[{$vid}] SHA256 del PHAR NO COINCIDE. Esperado: {$expected}, Real: {$actual}";
                    printf(" ❌\n");
                } else {
                    printf(" ✓\n");
                }
            } catch (Throwable $e) {
                printf(" ⚠️\n");
                @unlink($tmp);
                $warnings[] = "[{$vid}] No se pudo verificar SHA256: {$e->getMessage()}";
            }
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Validar integridad general
// ─────────────────────────────────────────────────────────────────────────────

printf("\n[3/3] Verificaciones adicionales...\n\n");

// Check for duplicated PHAR hashes
foreach ($seenPharHashes as $hash => $versions) {
    if (count($versions) > 1) {
        $errors[] = "SHA256 de PocketMine PHAR duplicado ({$hash}) en versiones: " . implode(", ", $versions);
    }
}

// Check for suspiciously high duplicates of PHP binary hashes
foreach ($seenPhpHashes as $field => $hashCounts) {
    foreach ($hashCounts as $hash => $versions) {
        if (count($versions) > 5) {
            $warnings[] = "SHA256 de PHP binary '{$field}' sospechosamente duplicado en " . count($versions) . " versiones (" . implode(", ", array_slice($versions, 0, 5)) . "...). Probablemente causado por URLs '-latest' mutables.";
        }
    }
}

if (empty($manifest["versions"])) {
    $errors[] = "No hay versiones definidas en manifest.json";
}

// ─────────────────────────────────────────────────────────────────────────────
// Reporte
// ─────────────────────────────────────────────────────────────────────────────

$elapsed = round(microtime(true) - $startTime, 2);

printf(
    "\n%s%s════════════════════════════════════════════════════════════════════════════════%s\n",
    COLOR_BOLD,
    COLOR_BLUE,
    COLOR_RESET,
);

// Mostrar estadísticas
printf("Estadísticas:\n");
printf("  Versiones validadas:   %d\n", $stats["versions_checked"]);
printf("  URLs verificadas:      %d\n", $stats["urls_checked"]);
printf("  Checksums verificados: %d\n", $stats["checksums_verified"]);
printf("  Tiempo total:          %.2f segundos\n\n", $elapsed);

// Mostrar advertencias
if (!empty($warnings)) {
    printf(
        "%s⚠️  ADVERTENCIAS (%d):%s\n",
        COLOR_YELLOW,
        count($warnings),
        COLOR_RESET,
    );
    foreach ($warnings as $w) {
        printf("   %s\n", $w);
    }
    printf("\n");
}

// Mostrar errores
if (!empty($errors)) {
    printf("%s❌ ERRORES (%d):%s\n", COLOR_RED, count($errors), COLOR_RESET);
    foreach ($errors as $e) {
        printf("   %s\n", $e);
    }
    printf(
        "\n%s%s════════════════════════════════════════════════════════════════════════════════%s\n",
        COLOR_BOLD,
        COLOR_RED,
        COLOR_RESET,
    );
    exit(1);
}

// Validación exitosa en modo estricto
if ($strictMode && !empty($warnings)) {
    printf(
        "%s%s════════════════════════════════════════════════════════════════════════════════%s\n",
        COLOR_BOLD,
        COLOR_YELLOW,
        COLOR_RESET,
    );
    printf(
        "%sERROR: Modo estricto: advertencias tratadas como errores%s\n\n",
        COLOR_RED,
        COLOR_RESET,
    );
    exit(1);
}

printf("%s✅ manifest.json válido%s", COLOR_GREEN, COLOR_RESET);
if (!empty($warnings)) {
    printf(
        " %s(con %d advertencia%s)%s",
        COLOR_YELLOW,
        count($warnings),
        count($warnings) === 1 ? "" : "s",
        COLOR_RESET,
    );
}
printf("\n");
printf(
    "%s%s════════════════════════════════════════════════════════════════════════════════%s\n\n",
    COLOR_BOLD,
    COLOR_GREEN,
    COLOR_RESET,
);

exit(0);

// ─────────────────────────────────────────────────────────────────────────────
// Funciones auxiliares
// ─────────────────────────────────────────────────────────────────────────────

function exitWithError(string $message): never
{
    fprintf(STDERR, "\n%s❌ ERROR:%s %s\n\n", COLOR_RED, COLOR_RESET, $message);
    exit(1);
}

function parseArgs(array $argv): array
{
    $result = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, "--")) {
            $arg = ltrim($arg, "-");
            if (str_contains($arg, "=")) {
                [$key, $val] = explode("=", $arg, 2);
                $result[$key] = $val;
            } else {
                $result[$arg] = true;
            }
        }
    }
    return $result;
}

function getHttpCode(string $url): int
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => HEAD_REQUEST_TIMEOUT,
        CURLOPT_USERAGENT => "pocketide/manifest-validator/1.0",
    ]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code;
}

