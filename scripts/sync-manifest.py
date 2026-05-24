#!/usr/bin/env python3
"""
Fetch releases de PocketMine-MP y automáticamente ejecutar update-manifest.php

Este script:
1. Obtiene todas las releases de pmmp/PocketMine-MP
2. Para cada versión que no esté en manifest.json
3. Ejecuta: php scripts/update-manifest.php --version=X.X.X
4. El PHP script maneja descarga, checksums, actualización, validación
"""

import json
import os
import subprocess
import sys
from datetime import datetime

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
MANIFEST_PATH = os.path.join(os.path.dirname(__file__), "../manifest.json")
UPDATE_SCRIPT = os.path.join(os.path.dirname(__file__), "update-manifest.php")


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


def load_manifest():
    """Cargar manifest.json actual"""
    if not os.path.exists(MANIFEST_PATH):
        return {"manifest_version": 1, "updated_at": "", "versions": []}

    try:
        with open(MANIFEST_PATH, "r") as f:
            return json.load(f)
    except Exception as e:
        print_status("warning", f"No se puede leer manifest.json: {e}")
        return {"manifest_version": 1, "updated_at": "", "versions": []}


def get_existing_versions():
    """Obtener versiones que ya están en manifest.json"""
    manifest = load_manifest()
    return {v["id"] for v in manifest.get("versions", [])}


def run_update_script(version):
    """Ejecutar update-manifest.php para una versión"""
    cmd = ["php", UPDATE_SCRIPT, f"--version={version}"]

    print_status("info", f"Ejecutando: {' '.join(cmd)}")

    try:
        result = subprocess.run(
            cmd,
            capture_output=True,
            text=True,
            timeout=600,  # 10 min timeout
        )

        if result.returncode == 0:
            print_status("success", f"✓ Versión {version} actualizada")
            return True
        else:
            print_status("error", f"Error en update-manifest.php para {version}")
            if result.stdout:
                print(f"   STDOUT: {result.stdout[:200]}")
            if result.stderr:
                print(f"   STDERR: {result.stderr[:200]}")
            return False

    except subprocess.TimeoutExpired:
        print_status("error", f"Timeout ejecutando update-manifest.php para {version}")
        return False
    except Exception as e:
        print_status("error", f"Error ejecutando update-manifest.php: {e}")
        return False


def fetch_and_update():
    """Obtener releases y ejecutar update-manifest.php para cada una"""

    print(f"\n{COLOR_BOLD}🔄 Obteniendo releases de PocketMine-MP{COLOR_RESET}\n")

    # Obtener releases
    print_status("info", "Obteniendo releases...")
    pm_releases = get_pocketmine_releases()

    if not pm_releases:
        print_status("error", "No se pudieron obtener releases")
        return False

    print_status("success", f"Obtenidas {len(pm_releases)} releases")

    # Obtener versiones existentes
    existing = get_existing_versions()
    print_status("info", f"Versiones existentes en manifest: {len(existing)}")

    # Procesar cada release
    print(f"\n{COLOR_BOLD}📦 Procesando releases{COLOR_RESET}\n")

    added = 0
    skipped = 0
    failed = 0

    for release in pm_releases:
        version = release["tag_name"]

        # Si ya existe, saltar
        if version in existing:
            skipped += 1
            continue

        # Ejecutar update-manifest.php
        if run_update_script(version):
            added += 1
        else:
            failed += 1

    # Resumen
    print(f"\n{COLOR_BOLD}📊 Resumen:{COLOR_RESET}")
    print_status("success", f"Versiones agregadas: {added}")
    print_status("info", f"Versiones saltadas (ya existen): {skipped}")
    if failed > 0:
        print_status("warning", f"Versiones con error: {failed}")

    total = added + skipped + failed
    print_status("info", f"Total procesadas: {total} de {len(pm_releases)} releases")

    return True


def main():
    """Punto de entrada"""
    try:
        if fetch_and_update():
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
