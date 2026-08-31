# pocketmine-manifest

![Automated](https://img.shields.io/badge/Automated-✅-brightgreen?style=flat-square) ![Status](https://img.shields.io/badge/Status-Ready-blue?style=flat-square) ![PocketMine](https://img.shields.io/badge/PocketMine--MP%20%26%20Forks-orange?style=flat-square)

Repositorio de soporte para **Deepslate** — contiene el `manifest.json` maestro
que el IDE consulta al arrancar para conocer las versiones disponibles de **PocketMine-MP y sus forks** (Axolotl-PM, Altay, etc.), con sus URLs de descarga, binarios PHP, stubs para Intelephense y checksums SHA256 verificados.

```
https://raw.githubusercontent.com/ImAMadDev/pocketmine-manifest/main/manifest.json
```

---

## 🍴 Soporte Multi-Software (`forks.json`)

El repositorio incluye un archivo central de configuración [`forks.json`](forks.json) que define los softwares y forks soportados:

```json
{
  "forks": {
    "pocketmine": {
      "name": "PocketMine-MP",
      "repo": "pmmp/PocketMine-MP",
      "type": "phar",
      "phar_url_template": "https://github.com/pmmp/PocketMine-MP/releases/download/{version}/PocketMine-MP.phar",
      "phar_name": "PocketMine-MP.phar",
      "tag_prefix": "",
      "stubs_tag_prefix": ""
    },
    "axolotl-pm": {
      "name": "Axolotl-PM",
      "repo": "axolotl-pm/PocketMine-MP",
      "type": "phar",
      "phar_url_template": "https://github.com/axolotl-pm/PocketMine-MP/releases/download/{version}/PocketMine-MP.phar",
      "phar_name": "PocketMine-MP.phar",
      "tag_prefix": "axolotl-",
      "stubs_tag_prefix": "axolotl-"
    },
    "altay": {
      "name": "Altay",
      "repo": "altayofficial/Altay",
      "type": "phar",
      "phar_url_template": "https://github.com/altayofficial/Altay/releases/download/{version}/Altay.phar",
      "phar_name": "Altay.phar",
      "tag_prefix": "altay-",
      "stubs_tag_prefix": "altay-"
    }
  }
}
```

Para registrar un nuevo fork, basta con agregarlo a `forks.json`.

---

## 🤖 Automatización y GitHub Actions
 
### Automático (Vía Triggers / `repository_dispatch`)
 
El workflow se activa automáticamente cuando el repositorio de stubs (`pocketmine-stubs`) publica una nueva versión o actualización:
1. Recibe el evento `stubs-released` o `stubs-updated` con el software y la versión.
2. Si detecta una versión no registrada en `manifest.json`:
   - Descarga el PHAR y binarios PHP requeridos.
   - Resuelve el paquete de stubs desde `pocketmine-stubs`.
   - Calcula SHA256 de todos los artefactos.
   - Valida la integridad del manifest.
   - Crea un Pull Request automático listo para revisión.
 
### Manual (Vía GitHub Actions)

1. Ve a **Actions** → **"Check New Releases & Auto-Update Manifest"** → **Run workflow**.
2. Parámetros:
   - `software`: `pocketmine`, `axolotl-pm`, `altay` (default: `pocketmine`).
   - `force_version`: Forzar una versión específica (ej: `5.44.3`).
   - `auto_pr`: Crear PR automáticamente (`true` / `false`).

---

## 🚀 Scripts de Mantenimiento

### 1. `update-manifest.php` — Agregar o actualizar versión

```bash
# PocketMine-MP estándar
php scripts/update-manifest.php --version=5.44.3

# Fork configurado en forks.json (ej: Axolotl-PM)
php scripts/update-manifest.php --software=axolotl-pm --version=5.44.0

# Fork Altay
php scripts/update-manifest.php --software=altay --version=5.44.4

# Simulación (dry-run) sin escribir en manifest.json
php scripts/update-manifest.php --software=axolotl-pm --version=5.44.0 --dry-run

# Forzar sobreescritura de versión existente
php scripts/update-manifest.php --software=pocketmine --version=5.44.3 -f
```

**Opciones disponibles:**
| Opción | Default | Descripción |
|--------|---------|-------------|
| `--version=X.Y.Z` | *(requerido)* | Versión del software a agregar |
| `--software=NAME` | `pocketmine` | Identificador del fork / software |
| `--forks-file=PATH` | `forks.json` | Ruta al archivo de configuración de forks |
| `--api-version=X.Y.Z` | Auto | Override de API version |
| `--mc-version=X.Y.Z` | Auto | Override de Minecraft version |
| `--min-php=X.Y` | Auto (`8.2`) | Override de versión mínima de PHP |
| `--phar-url=URL` | Auto | URL directa personalizada al archivo PHAR |
| `--dry-run` | `false` | Muestra el JSON resultante sin modificar archivos |
| `-f`, `--force` | `false` | Sobreescribe la versión si ya existe |

---

### 2. `validate-manifest.php` — Validar integridad

```bash
# Validación estándar
php scripts/validate-manifest.php

# Validar solo un software específico
php scripts/validate-manifest.php --software=axolotl-pm

# Verificar que todas las URLs responden (HEAD requests)
php scripts/validate-manifest.php --check-urls

# Verificar SHA256 descargando archivos (modo exhaustivo)
php scripts/validate-manifest.php --verify-checksums

# Modo estricto (falla ante cualquier advertencia)
php scripts/validate-manifest.php --strict
```

---

### 3. `fetch_releases.py` — Sincronización masiva desde GitHub

```bash
# Procesar todos los releases de PocketMine-MP
python3 scripts/fetch_releases.py --software=pocketmine

# Procesar releases de un fork específico
python3 scripts/fetch_releases.py --software=axolotl-pm

# Procesar todos los forks registrados en forks.json
python3 scripts/fetch_releases.py --software=all

# Procesar en paralelo con GitHub Token (para evitar rate limit)
python3 scripts/fetch_releases.py --software=all --parallel --token=ghp_xxxx
```

---

## 📝 Formato de `manifest.json`

```jsonc
{
  "manifest_version": 2,
  "updated_at": "2026-08-31T18:00:00Z",
  "softwares": {
    "pocketmine": {
      "name": "PocketMine-MP",
      "repo": "pmmp/PocketMine-MP",
      "description": "Official PocketMine-MP server software"
    },
    "axolotl-pm": {
      "name": "Axolotl-PM",
      "repo": "axolotl-pm/PocketMine-MP",
      "description": "High-performance PocketMine-MP fork"
    }
  },
  "versions": [
    {
      "id": "5.44.3",
      "software": "pocketmine",
      "api_version": "5.44.0",
      "release_date": "2026-08-05",
      "stability": "stable",
      "minecraft_version": "1.26.30",
      "min_php": "8.2",
      "php_binary_tag": "pm5-php-8.2-latest",
      "changelog_url": "https://github.com/pmmp/PocketMine-MP/releases/download/5.44.3/changelogs/5.44.md",
      "downloads": {
        "server_phar": "https://github.com/pmmp/PocketMine-MP/releases/download/5.44.3/PocketMine-MP.phar",
        "server_phar_sha256": "d8b4d87a64124d83659f629c87d6a8e0e142192f6c719fe4684272ff44105539",
        "pocketmine_phar": "https://github.com/pmmp/PocketMine-MP/releases/download/5.44.3/PocketMine-MP.phar",
        "pocketmine_phar_sha256": "d8b4d87a64124d83659f629c87d6a8e0e142192f6c719fe4684272ff44105539",
        "php_windows_x64": "https://github.com/pmmp/PHP-Binaries/releases/download/pm5-php-8.2-latest/PHP-8.2-Windows-x64-PM5.zip",
        "php_windows_x64_sha256": "c9ca8505da43977d431267ffa782a7b5b077fca068ae2a9da81271c62ef18ba9",
        "php_linux_x86_64": "https://github.com/pmmp/PHP-Binaries/releases/download/pm5-php-8.2-latest/PHP-8.2-Linux-x86_64-PM5.tar.gz",
        "php_linux_x86_64_sha256": "000fc605878d24be3d6faa6991c0d3a39ae90a0f9ff24d9f4e4722ef823082fc",
        "php_macos_x86_64": "https://github.com/pmmp/PHP-Binaries/releases/download/pm5-php-8.2-latest/PHP-8.2-MacOS-x86_64-PM5.tar.gz",
        "php_macos_x86_64_sha256": "bca5c269f329a4f763719eb19772509373af1a6122a215bce4aa4c95e2743888",
        "php_macos_arm64": "https://github.com/pmmp/PHP-Binaries/releases/download/pm5-php-8.2-latest/PHP-8.2-MacOS-arm64-PM5.tar.gz",
        "php_macos_arm64_sha256": "eae469f7f7843b718e53021bbcc6a1a18f91b617cf7895a8cf8985451a66c8e6"
      },
      "stubs": {
        "url": "https://github.com/ImAMadDev/pocketmine-stubs/releases/download/5.44.3/stubs-5.44.3.zip",
        "checksum_sha256": "6f6486086dacdf6c91596f1bde9bdfc2db4ccd1d53a343e7f18275b362444178"
      }
    }
  ]
}
```

---

## 🏗️ Ecosistema Deepslate

| Repositorio | Propósito |
|-------------|-----------|
| [`ImAMadDev/deepslate`](https://github.com/ImAMadDev/deepslate) | IDE principal (Tauri 2 + React 19) |
| [`ImAMadDev/pocketmine-manifest`](https://github.com/ImAMadDev/pocketmine-manifest) | **Este repositorio** — Manifest maestro multi-software |
| [`ImAMadDev/pocketmine-stubs`](https://github.com/ImAMadDev/pocketmine-stubs) | Stubs PHP universales generados por versión y fork |

---

*Deepslate Ecosystem — ImAMadDev/pocketmine-manifest*
