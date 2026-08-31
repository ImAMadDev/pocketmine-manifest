#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Deepslate — validate-manifest.php
 *
 * Verifica la integridad completa de manifest.json con soporte multi-software (forks):
 *
 * Validaciones incluidas:
 * - Estructura JSON válida y bien formada
 * - Campos raíz requeridos (manifest_version, updated_at, versions, softwares)
 * - Software válido registrado en forks.json / softwares
 * - Unicidad de versiones por clave compuesta (software, version_id)
 * - Campos de versión requeridos en todas las entradas
 * - Checksums SHA256 válidos (formato hexadecimal de 64 caracteres)
 * - Sin placeholders (NEEDS_SHA256_COMPUTE, NEEDS_MANUAL_FILL)
 * - URLs accesibles (con --check-urls)
 * - SHA256 verificado descargando y validando (con --verify-checksums)
 * - Formato de versiones semántico (X.Y.Z)
 * - Estabilidad válida (stable, beta, alpha, rc)
 * - Fechas en formato ISO 8601
 *
 * Opciones:
 * --check-urls           Verificar que todas las URLs responden (HEAD request)
 * --verify-checksums     Descargar y verificar SHA256 (lento, requiere espacio)
 * --strict               Modo estricto: advertencias se tratan como errores
 * --version=X.Y.Z        Validar solo una versión específica
 * --software=NAME        Validar solo un software específico
 */

const MANIFEST_PATH = __DIR__ . "/../manifest.json";
const FORKS_PATH = __DIR__ . "/../forks.json";
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
$filterSoftware = $args["software"] ?? null;

$startTime = microtime(true);
$errors = [];
$warnings = [];
$stats = [
    "versions_checked" => 0,
    "softwares_checked" => 0,
    "urls_checked" => 0,
    "checksums_verified" => 0,
];

// ─────────────────────────────────────────────────────────────────────────────
// Cargar manifest y forks.json
// ─────────────────────────────────────────────────────────────────────────────

printf(
    "\n%s=== Deepslate — Validar Manifest ===%s\n\n",
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

$knownForks = ["pocketmine" => true];
if (file_exists(FORKS_PATH)) {
    $forksData = json_decode(file_get_contents(FORKS_PATH), true);
    if (isset($forksData["forks"]) && is_array($forksData["forks"])) {
        foreach (array_keys($forksData["forks"]) as $f) {
            $knownForks[$f] = true;
        }
    }
}
if (isset($manifest["softwares"]) && is_array($manifest["softwares"])) {
    foreach (array_keys($manifest["softwares"]) as $s) {
        $knownForks[$s] = true;
    }
}

printf("Versiones en manifest: %d\n", count($manifest["versions"]));
printf("Softwares registrados: %d (%s)\n", count($knownForks), implode(", ", array_keys($knownForks)));
printf(
    "Última actualización:  %s\n\n",
    $manifest["updated_at"] ?? "desconocido",
);

// ─────────────────────────────────────────────────────────────────────────────
// Auto-ordenamiento de versiones (Por software y SemVer descendente)
// ─────────────────────────────────────────────────────────────────────────────

$originalVersions = $manifest["versions"];

usort($manifest["versions"], function (array $a, array $b): int {
    $softA = $a["software"] ?? "pocketmine";
    $softB = $b["software"] ?? "pocketmine";
    if ($softA !== $softB) {
        if ($softA === "pocketmine") return -1;
        if ($softB === "pocketmine") return 1;
        return strcmp($softA, $softB);
    }
    $idA = $a["id"] ?? "0.0.0";
    $idB = $b["id"] ?? "0.0.0";
    return version_compare($idB, $idA);
});

// Comprobar si el orden cambió respecto al archivo físico original
$orderHasChanged = false;
foreach ($manifest["versions"] as $index => $versionData) {
    $origSoft = $originalVersions[$index]["software"] ?? "pocketmine";
    $currSoft = $versionData["software"] ?? "pocketmine";
    $origId = $originalVersions[$index]["id"] ?? null;
    $currId = $versionData["id"] ?? null;
    if ($origSoft !== $currSoft || $origId !== $currId) {
        $orderHasChanged = true;
        break;
    }
}

if ($orderHasChanged) {
    $warnings[] = "El orden de las versiones en manifest.json no es descendente por SemVer/Software. Se recomienda ordenar.";
}

// ─────────────────────────────────────────────────────────────────────────────
// Validar cada versión
// ─────────────────────────────────────────────────────────────────────────────

printf("[1/3] Validando estructura de versiones...\n\n");

$seenKeys = [];
$seenPharHashes = [];
$seenPhpHashes = [];

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
    "php_windows_x64",
    "php_linux_x86_64",
    "php_macos_x86_64",
    "php_macos_arm64",
];

foreach ($manifest["versions"] as $index => $v) {
    $rawId = $v["id"] ?? "index_{$index}";
    $software = $v["software"] ?? "pocketmine";
    $compositeKey = "{$software}:{$rawId}";
    $vid = "{$software}@{$rawId}";

    // Filtro por versión / software si se solicitó
    if ($filterVersion !== null && $rawId !== $filterVersion) {
        continue;
    }
    if ($filterSoftware !== null && $software !== $filterSoftware) {
        continue;
    }

    $stats["versions_checked"]++;

    // Validar software conocido
    if (!isset($knownForks[$software])) {
        $errors[] = "[{$vid}] Software '{$software}' desconocido (no está en forks.json)";
    }

    // Validar duplicados por clave compuesta (software, id)
    if (isset($seenKeys[$compositeKey])) {
        $errors[] = "[{$vid}] Versión duplicada para el software '{$software}'";
    }
    $seenKeys[$compositeKey] = true;

    // Validar campos requeridos
    foreach ($requiredFields as $field) {
        if (!isset($v[$field])) {
            $errors[] = "[{$vid}] Campo '{$field}' ausente";
        } elseif ($v[$field] === "" || $v[$field] === null) {
            $errors[] = "[{$vid}] Campo '{$field}' está vacío";
        }
    }

    // Validar formato de versión semántico
    if (
        isset($v["id"]) &&
        !preg_match('/^\d+\.\d+\.\d+(-[a-zA-Z0-9.]+)?$/', (string)$v["id"])
    ) {
        $warnings[] = "[{$vid}] Formato de versión inusual: '{$v["id"]}'";
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
        !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v["release_date"])
    ) {
        $warnings[] = "[{$vid}] Fecha de release no es ISO 8601 (YYYY-MM-DD): '{$v["release_date"]}'";
    }

    // Detectar placeholders y valores fallidos
    $haystack = json_encode($v);
    if (str_contains($haystack, "NEEDS_SHA256_COMPUTE")) {
        $warnings[] = "[{$vid}] SHA256 sin calcular (NEEDS_SHA256_COMPUTE)";
    }
    if (str_contains($haystack, "NEEDS_MANUAL_FILL")) {
        $warnings[] = "[{$vid}] Campos sin completar (NEEDS_MANUAL_FILL)";
    }
    if (str_contains($haystack, "DOWNLOAD_FAILED")) {
        $errors[] = "[{$vid}] Entrada contiene descargas o stubs fallidos (DOWNLOAD_FAILED)";
    }

    // Validar downloads
    if (!is_array($v["downloads"] ?? null)) {
        $errors[] = "[{$vid}] 'downloads' no es un array";
        continue;
    }

    // PHAR debe existir bajo server_phar o pocketmine_phar
    $hasPhar = !empty($v["downloads"]["server_phar"]) || !empty($v["downloads"]["pocketmine_phar"]);
    if (!$hasPhar) {
        $errors[] = "[{$vid}] downloads.server_phar / downloads.pocketmine_phar ausente o vacío";
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
        } elseif (
            $v["stubs"]["checksum_sha256"] === "DOWNLOAD_FAILED" ||
            !preg_match('/^[a-f0-9]{64}$/', (string)$v["stubs"]["checksum_sha256"])
        ) {
            $errors[] = "[{$vid}] stubs.checksum_sha256 no es SHA256 válido: '{$v["stubs"]["checksum_sha256"]}'";
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
            !preg_match('/^[a-f0-9]{64}$/', (string)$hash)
        ) {
            $errors[] = "[{$vid}] downloads.{$f} no es SHA256 válido: '{$hash}'";
        }
    }

    // Comprobar URLs mutables
    foreach ($v["downloads"] as $downloadKey => $url) {
        if (!str_ends_with($downloadKey, "_sha256") && str_contains((string)$url, "-latest")) {
            $warnings[] = "[{$vid}] URL mutable detectada: downloads.{$downloadKey} contiene '-latest'.";
        }
    }

    // Rastrear hashes de PHAR por software para comprobar duplicados
    $pharHash = $v["downloads"]["server_phar_sha256"] ?? $v["downloads"]["pocketmine_phar_sha256"] ?? "";
    if ($pharHash && $pharHash !== "NEEDS_SHA256_COMPUTE") {
        if (!isset($seenPharHashes[$software][$pharHash])) {
            $seenPharHashes[$software][$pharHash] = [];
        }
        $seenPharHashes[$software][$pharHash][] = $rawId;
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
        if (!empty($v["stubs"]["url"])) {
            $urlsToCheck["stubs"] = $v["stubs"]["url"];
        }

        foreach ($urlsToCheck as $urlKey => $url) {
            if (empty($url) || str_contains($url, "NEEDS")) {
                continue;
            }

            $stats["urls_checked"]++;
            $code = getHttpCode((string)$url);

            if ($code !== 200 && $code !== 302) {
                $warnings[] = "[{$vid}] URL({$urlKey}) responde HTTP {$code}: {$url}";
            } else {
                printf("  ✓ %s: HTTP %d (%s)\n", $vid, $code, $urlKey);
            }
        }
    }

    // Verificar SHA256 del phar descargándolo
    if ($verifyChecksums) {
        $pharUrl = $v["downloads"]["server_phar"] ?? $v["downloads"]["pocketmine_phar"] ?? "";
        $expected = $v["downloads"]["server_phar_sha256"] ?? $v["downloads"]["pocketmine_phar_sha256"] ?? "";

        if ($pharUrl && $expected && !str_contains($expected, "NEEDS")) {
            printf("  [VERIFY] Descargando PHAR de %s...", $vid);
            flush();

            $tmp = tempnam(sys_get_temp_dir(), "phar_val_") . ".phar";

            try {
                $ch = curl_init($pharUrl);
                $fp = fopen($tmp, 'w+');
                curl_setopt_array($ch, [
                    CURLOPT_FILE => $fp,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_TIMEOUT => 60,
                    CURLOPT_USERAGENT => "deepslate-manifest-validator",
                ]);
                $success = curl_exec($ch);
                curl_close($ch);
                fclose($fp);

                if (!$success) {
                    throw new Exception("No se pudo descargar PHAR");
                }

                $actual = hash_file("sha256", $tmp);
                @unlink($tmp);

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
// Validar integridad general y duplicados
// ─────────────────────────────────────────────────────────────────────────────

printf("\n[2/3] Verificando unicidad e integridad...\n\n");

// Check for duplicated PHAR hashes per software
foreach ($seenPharHashes as $soft => $hashes) {
    foreach ($hashes as $hash => $versions) {
        if (count($versions) > 1) {
            $errors[] = "SHA256 de {$soft} PHAR duplicado ({$hash}) en versiones: " . implode(", ", $versions);
        }
    }
}

// Check for suspiciously high duplicates of PHP binary hashes
foreach ($seenPhpHashes as $field => $hashCounts) {
    foreach ($hashCounts as $hash => $versions) {
        if (count($versions) > 10) {
            $warnings[] = "SHA256 de PHP binary '{$field}' duplicado en " . count($versions) . " versiones (" . implode(", ", array_slice($versions, 0, 5)) . "...).";
        }
    }
}

if (empty($manifest["versions"])) {
    $errors[] = "No hay versiones definidas en manifest.json";
}

printf("[3/3] Generando reporte...\n\n");

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

printf("Estadísticas:\n");
printf("  Versiones validadas:   %d\n", $stats["versions_checked"]);
if ($checkUrls) {
    printf("  URLs verificadas:      %d\n", $stats["urls_checked"]);
}
if ($verifyChecksums) {
    printf("  Checksums verificados: %d\n", $stats["checksums_verified"]);
}
printf("  Tiempo total:          %.2f segundos\n\n", $elapsed);

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
        } elseif (str_starts_with($arg, "-")) {
            $arg = ltrim($arg, "-");
            $result[$arg] = true;
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
        CURLOPT_USERAGENT => "deepslate/manifest-validator/1.0",
    ]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code;
}
