"""
Fetches all releases from a GitHub repository using the GitHub API.
For each release, executes: php scripts/update-manifest.php --version=X.X.X

Repo: ImAMadDev/pocketmine-stubs
"""

import concurrent.futures
import json
import os
import subprocess
import sys
import urllib.error
import urllib.request

OWNER = "ImAMadDev"
REPO = "pocketmine-stubs"
SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
UPDATE_SCRIPT = os.path.join(SCRIPT_DIR, "update-manifest.php")
MANIFEST_PATH = os.path.join(os.path.dirname(SCRIPT_DIR), "manifest.json")


def fetch_all_releases(owner: str, repo: str, token: str | None = None) -> list[dict]:
    releases = []
    page = 1

    while True:
        url = (
            f"https://api.github.com/repos/{owner}/{repo}/releases"
            f"?per_page=100&page={page}"
        )

        req = urllib.request.Request(
            url,
            headers={
                "Accept": "application/vnd.github+json",
                "User-Agent": "python-releases-fetcher",
                **({"Authorization": f"Bearer {token}"} if token else {}),
            },
        )

        try:
            with urllib.request.urlopen(req) as res:
                batch = json.loads(res.read().decode())
        except urllib.error.HTTPError as e:
            if e.code == 403:
                print("⚠ Rate limit alcanzado. Pasa un token con --token <TOKEN>.")
            elif e.code == 404:
                print(f"✗ Repositorio '{owner}/{repo}' no encontrado.")
            else:
                print(f"✗ HTTP {e.code}: {e.reason}")
            sys.exit(1)

        if not batch:
            break

        releases.extend(batch)
        print(
            f"  Página {page}: {len(batch)} releases obtenidos (total: {len(releases)})"
        )
        page += 1

    return releases


def load_manifest():
    """Cargar manifest.json actual"""
    if not os.path.exists(MANIFEST_PATH):
        return {"versions": []}

    try:
        with open(MANIFEST_PATH, "r", encoding="utf-8") as f:
            return json.load(f)
    except Exception as e:
        print(f"⚠ No se puede leer manifest.json: {e}")
        return {"versions": []}


def get_existing_versions():
    """Obtener versiones que ya están en manifest.json"""
    manifest = load_manifest()
    return {v.get("id") for v in manifest.get("versions", [])}


def run_update_script(version: str) -> bool:
    """Ejecutar update-manifest.php para una versión"""
    print(f"  [↓] Iniciando versión {version}...", flush=True)
    cmd = ["php", UPDATE_SCRIPT, f"--version={version}"]

    try:
        result = subprocess.run(
            cmd,
            capture_output=True,
            text=True,
            timeout=600,  # 10 minutos
        )

        if result.returncode == 0:
            return True
        else:
            print(
                f"    ✗ Error: {result.stderr[:100]}"
                if result.stderr
                else "    ✗ Error desconocido"
            )
            return False

    except subprocess.TimeoutExpired:
        print("    ✗ Timeout (>10 min)")
        return False
    except FileNotFoundError:
        print("    ✗ PHP no encontrado o script no existe")
        return False
    except Exception as e:
        print(f"    ✗ Error: {e}")
        return False


def print_releases(releases: list[dict]) -> None:
    if not releases:
        print("No se encontraron releases.")
        return

    print(f"\n{'─' * 70}")
    print(f"  {'TAG':<25} {'NOMBRE':<30} {'FECHA'}")
    print(f"{'─' * 70}")

    for r in releases:
        tag = r.get("tag_name", "—")
        name = r.get("name") or tag
        date = (r.get("published_at") or "")[:10]
        prerel = " [pre]" if r.get("prerelease") else ""
        draft = " [draft]" if r.get("draft") else ""
        print(f"  {tag:<25} {name:<30} {date}{prerel}{draft}")

    print(f"{'─' * 70}")
    print(f"  Total: {len(releases)} releases\n")


def save_json(releases: list[dict], path: str) -> None:
    with open(path, "w", encoding="utf-8") as f:
        json.dump(releases, f, indent=2, ensure_ascii=False)
    print(f"✓ Datos guardados en '{path}'")


def process_releases(releases: list[dict], skip_existing: bool = True, max_workers: int = 4) -> None:
    """Procesar releases en paralelo y ejecutar update-manifest.php para cada una"""

    if not os.path.exists(UPDATE_SCRIPT):
        print(f"\n✗ Script no encontrado: {UPDATE_SCRIPT}")
        return

    existing = get_existing_versions() if skip_existing else set()

    # Filtrar versiones a procesar
    versions_to_process = []
    skipped = 0
    for release in releases:
        version = release.get("tag_name")
        if not version:
            continue
        if skip_existing and version in existing:
            skipped += 1
            continue
        versions_to_process.append(version)

    print(f"\n{'─' * 70}")
    print(f"📦 Procesando {len(versions_to_process)} releases (Concurrencia: {max_workers})...")
    if skipped > 0:
        print(f"  ⊘ Saltadas (ya existen): {skipped}")
    print(f"{'─' * 70}\n")

    added = 0
    failed = 0
    completed = 0
    total = len(versions_to_process)

    if not versions_to_process:
        print("No hay versiones nuevas para procesar.")
        return

    with concurrent.futures.ThreadPoolExecutor(max_workers=max_workers) as executor:
        future_to_version = {executor.submit(run_update_script, v): v for v in versions_to_process}
        
        for future in concurrent.futures.as_completed(future_to_version):
            version = future_to_version[future]
            completed += 1
            try:
                success = future.result()
                if success:
                    print(f"  ✓ [{completed}/{total}] Versión {version} procesada correctamente", flush=True)
                    added += 1
                else:
                    print(f"  ✗ [{completed}/{total}] Versión {version} falló al procesar", flush=True)
                    failed += 1
            except Exception as e:
                print(f"  ✗ [{completed}/{total}] Versión {version} lanzó excepción: {e}", flush=True)
                failed += 1

    # Resumen
    print(f"\n{'─' * 70}")
    print("📊 Resumen:")
    print(f"  ✓ Agregadas: {added}")
    if skipped > 0:
        print(f"  ⊘ Saltadas (ya existen): {skipped}")
    if failed > 0:
        print(f"  ✗ Con error: {failed}")
    print(f"  Total: {added + skipped + failed} de {len(releases)}")
    print(f"{'─' * 70}\n")


if __name__ == "__main__":
    import argparse

    parser = argparse.ArgumentParser(
        description="Obtiene todos los releases de un repo de GitHub y ejecuta update-manifest.php para cada uno."
    )
    parser.add_argument("--owner", default=OWNER, help="Dueño del repositorio")
    parser.add_argument("--repo", default=REPO, help="Nombre del repositorio")
    parser.add_argument(
        "--token", default=None, help="GitHub Personal Access Token (opcional)"
    )
    parser.add_argument(
        "--output", default=None, help="Guardar resultados en un archivo JSON"
    )
    parser.add_argument(
        "--no-skip",
        action="store_true",
        help="No saltar versiones existentes (procesar todas)",
    )
    parser.add_argument(
        "--threads",
        type=int,
        default=4,
        help="Número de hilos para procesamiento paralelo (default: 4)",
    )
    args = parser.parse_args()

    print(f"Obteniendo releases de {args.owner}/{args.repo}...\n")
    releases = fetch_all_releases(args.owner, args.repo, args.token)
    print_releases(releases)

    if args.output:
        save_json(releases, args.output)

    # Procesar releases y ejecutar update-manifest.php
    process_releases(releases, skip_existing=not args.no_skip, max_workers=args.threads)
