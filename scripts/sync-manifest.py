#!/usr/bin/env python3
"""
Sincronizar manifest.json con releases de PocketMine-MP y stubs

Este script:
1. Obtiene releases de pmmp/PocketMine-MP (build_info.json)
2. Obtiene releases de pocketide/pocketmine-stubs (para checksums)
3. Actualiza manifest.json automáticamente
4. Solo agrega versiones que no existan
"""

import hashlib
import json
import os
import sys
from datetime import datetime
from urllib.parse import urlparse

import requests

# Colores para output
COLOR_GREEN = "\033[92m"
COLOR_BLUE = "\033[94m"
COLOR_YELLOW = "\033[93m"
COLOR_RED = "\033[91m"
COLOR_RESET = "\033[0m"
COLOR_BOLD = "\033[1m"

# URLs
PM_API = "https://api.github.com/repos/pmmp/PocketMine-MP/releases"
STUBS_API = "https://api.github.com/repos/pocketide/pocketmine-stubs/releases"
MANIFEST_PATH = os.path.join(os.path.dirname(__file__), "../manifest.json")

# Campos requeridos para downloads
REQUIRED_DOWNLOADS = [
    "pocketmine_phar",
    "pocketmine_phar_sha256",
    "pocketmine_exe",
    "pocketmine_exe_sha256",
    "php_linux_x86_64",
    "php_linux_x86_64_sha256",
    "php_windows_x64",
    "php_windows_x64_sha256",
    "php_macos_x86_64",
    "php_macos_x86_64_sha256",
    "php_macos_arm64",
    "php_macos_arm64_sha256",
]


def print_status(level, message):
    """Imprimir con colores"""
    icons = {
        "info": f"{COLOR_BLUE}ℹ{COLOR_RESET}",
        "success": f"{COLOR_GREEN}✓{COLOR_RESET}",
        "warning": f"{COLOR_YELLOW}⚠{COLOR_RESET}",
        "error": f"{COLOR_RED}✗{COLOR_RESET}",
    }
    print(f"{icons.get(level, '•')} {message}")


def get_json(url, headers=None):
    """Obtener JSON desde URL"""
    if headers is None:
        headers = {}

    # Agregar token de GitHub si está disponible
    token = os.environ.get("GITHUB_TOKEN")
    if token:
        headers["Authorization"] = f"token {token}"

    try:
        response = requests.get(url, headers=headers, timeout=10)
        response.raise_for_status()
        return response.json()
    except requests.exceptions.RequestException as e:
        print_status("error", f"Error obteniendo {url}: {e}")
        return None


def get_build_info(version):
    """Obtener build_info.json de una release de PocketMine-MP"""
    url = f"{PM_API}/tags/{version}"

    data = get_json(url)
    if not data:
        return None

    # Buscar asset build_info.json
    for asset in data.get("assets", []):
        if asset["name"] == "build_info.json":
            build_info_url = asset["browser_download_url"]
            build_info = get_json(build_info_url)
            return build_info

    return None


def get_pocketmine_releases():
    """Obtener todas las releases de PocketMine-MP"""
    releases = []
    page = 1

    while True:
        url = f"{PM_API}?per_page=100&page={page}"
        data = get_json(url)

        if not data:
            break

        releases.extend(data)

        if len(data) < 100:
            break

        page += 1

    return releases


def get_stubs_checksum(version):
    """Obtener checksum de stubs para una versión"""
    url = f"{STUBS_API}/tags/{version}"

    data = get_json(url)
    if not data:
        return None

    # Buscar asset stubs.zip
    for asset in data.get("assets", []):
        if asset["name"] == "stubs.zip":
            # Descargar archivo y calcular SHA256
            file_url = asset["browser_download_url"]
            try:
                response = requests.get(file_url, timeout=30)
                response.raise_for_status()

                sha256 = hashlib.sha256(response.content).hexdigest()
                return sha256
            except requests.exceptions.RequestException as e:
                print_status(
                    "warning", f"No se puede descargar stubs para {version}: {e}"
                )
                return None

    return None


def load_manifest():
    """Cargar manifest.json actual"""
    if not os.path.exists(MANIFEST_PATH):
        return {
            "manifest_version": 1,
            "updated_at": datetime.utcnow().isoformat() + "Z",
            "versions": [],
        }

    with open(MANIFEST_PATH, "r") as f:
        return json.load(f)


def save_manifest(manifest):
    """Guardar manifest.json"""
    manifest["updated_at"] = datetime.utcnow().isoformat() + "Z"

    with open(MANIFEST_PATH, "w") as f:
        json.dump(manifest, f, indent=2)

    print_status("success", f"Manifest guardado en {MANIFEST_PATH}")


def create_version_entry(version, build_info, stubs_checksum=None):
    """Crear entrada de versión para manifest"""

    php_version = build_info.get("php_version", "8.2")
    mc_version = build_info.get("mcpe_version", "1.0.0")
    is_dev = build_info.get("is_dev", False)
    channel = build_info.get("channel", "stable")
    stability = "alpha" if is_dev else channel

    # API version (misma que la versión del release, sin patch)
    api_parts = version.split(".")
    if len(api_parts) >= 2:
        api_version = f"{api_parts[0]}.{api_parts[1]}.0"
    else:
        api_version = version

    entry = {
        "id": version,
        "api_version": api_version,
        "release_date": datetime.utcnow().strftime("%Y-%m-%d"),
        "stability": stability,
        "minecraft_version": mc_version,
        "min_php": php_version,
        "recommended_php": f"{php_version}.latest",
        "changelog_url": f"https://github.com/pmmp/PocketMine-MP/releases/tag/{version}",
        "downloads": {
            "pocketmine_phar": f"https://github.com/pmmp/PocketMine-MP/releases/download/{version}/PocketMine-MP.phar",
            "pocketmine_phar_sha256": "NEEDS_SHA256_COMPUTE",
            "pocketmine_exe": f"https://github.com/pmmp/PocketMine-MP/releases/download/{version}/PocketMine-MP.exe",
            "pocketmine_exe_sha256": "NEEDS_SHA256_COMPUTE",
            "php_linux_x86_64": f"https://github.com/pmmp/PHP-Binaries/releases/download/pm5-php-{php_version}-latest/PHP-{php_version}-latest-Linux-x86_64.tar.gz",
            "php_linux_x86_64_sha256": "NEEDS_SHA256_COMPUTE",
            "php_windows_x64": f"https://github.com/pmmp/PHP-Binaries/releases/download/pm5-php-{php_version}-latest/PHP-{php_version}-latest-Windows-x86_64.zip",
            "php_windows_x64_sha256": "NEEDS_SHA256_COMPUTE",
            "php_macos_x86_64": f"https://github.com/pmmp/PHP-Binaries/releases/download/pm5-php-{php_version}-latest/PHP-{php_version}-latest-MacOS-x86_64.tar.gz",
            "php_macos_x86_64_sha256": "NEEDS_SHA256_COMPUTE",
            "php_macos_arm64": f"https://github.com/pmmp/PHP-Binaries/releases/download/pm5-php-{php_version}-latest/PHP-{php_version}-latest-MacOS-arm64.tar.gz",
            "php_macos_arm64_sha256": "NEEDS_SHA256_COMPUTE",
        },
        "stubs": {
            "url": f"https://github.com/pocketide/pocketmine-stubs/releases/download/{version}/stubs.zip",
            "checksum_sha256": stubs_checksum or "NEEDS_SHA256_COMPUTE",
        },
    }

    return entry


def sync_manifest():
    """Sincronizar manifest.json"""

    print(f"\n{COLOR_BOLD}🔄 Sincronizando Manifest{COLOR_RESET}\n")

    # Cargar manifest actual
    manifest = load_manifest()
    existing_versions = {v["id"] for v in manifest.get("versions", [])}

    print_status("info", f"Versiones existentes en manifest: {len(existing_versions)}")

    # Obtener releases de PocketMine-MP
    print_status("info", "Obteniendo releases de PocketMine-MP...")
    pm_releases = get_pocketmine_releases()

    if not pm_releases:
        print_status("error", "No se pudieron obtener releases de PocketMine-MP")
        return False

    print_status("success", f"Obtenidas {len(pm_releases)} releases de PocketMine-MP")

    # Procesar cada release
    added = 0
    skipped = 0

    for release in pm_releases:
        version = release["tag_name"]

        # Si ya existe, saltar
        if version in existing_versions:
            skipped += 1
            continue

        # Obtener build_info.json
        print_status("info", f"Procesando versión {version}...")
        build_info = get_build_info(version)

        if not build_info:
            print_status(
                "warning", f"No se puede obtener build_info para {version}, saltando"
            )
            continue

        # Obtener checksum de stubs
        stubs_checksum = get_stubs_checksum(version)

        # Crear entrada
        entry = create_version_entry(version, build_info, stubs_checksum)

        # Agregar al manifest (al inicio para tener versiones recientes primero)
        manifest["versions"].insert(0, entry)
        added += 1

        print_status("success", f"Agregada versión {version}")

    # Guardar manifest
    if added > 0:
        print(f"\n{COLOR_BOLD}📊 Resumen:{COLOR_RESET}")
        print_status("success", f"Versiones agregadas: {added}")
        print_status("info", f"Versiones saltadas (ya existen): {skipped}")
        print_status(
            "info", f"Total de versiones en manifest: {len(manifest['versions'])}"
        )

        save_manifest(manifest)
        return True
    else:
        print_status("info", "No hay nuevas versiones para agregar")
        return True


def main():
    """Punto de entrada"""
    try:
        if sync_manifest():
            print_status("success", "✅ Sincronización completada exitosamente\n")
            return 0
        else:
            print_status("error", "❌ Error durante sincronización\n")
            return 1
    except KeyboardInterrupt:
        print("\n\nInterrumpido por usuario")
        return 130
    except Exception as e:
        print_status("error", f"Error inesperado: {e}\n")
        return 1


if __name__ == "__main__":
    sys.exit(main())
