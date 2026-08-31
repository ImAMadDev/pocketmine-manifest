"""
Fetches releases from GitHub repositories using the GitHub API with multi-software (forks) support.
For each release, executes: php scripts/update-manifest.php --software=NAME --version=X.X.X
"""

import argparse
import concurrent.futures
import json
import os
import subprocess
import sys
import urllib.error
import urllib.request

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
UPDATE_SCRIPT = os.path.join(SCRIPT_DIR, "update-manifest.php")
MANIFEST_PATH = os.path.join(os.path.dirname(SCRIPT_DIR), "manifest.json")
FORKS_PATH = os.path.join(os.path.dirname(SCRIPT_DIR), "forks.json")


def load_forks_config(path: str = FORKS_PATH) -> dict:
    if not os.path.exists(path):
        return {
            "forks": {
                "pocketmine": {"name": "PocketMine-MP", "repo": "pmmp/PocketMine-MP"}
            }
        }
    try:
        with open(path, "r", encoding="utf-8") as f:
            return json.load(f)
    except Exception as e:
        print(f"⚠ No se puede leer forks.json: {e}")
        return {
            "forks": {
                "pocketmine": {"name": "PocketMine-MP", "repo": "pmmp/PocketMine-MP"}
            }
        }


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


def fetch_available_stubs(token: str | None = None) -> set[str]:
    """Obtener todos los tags de stubs disponibles en ImAMadDev/pocketmine-stubs"""
    print("Verificando stubs disponibles en ImAMadDev/pocketmine-stubs...")
    try:
        stubs_releases = fetch_all_releases("ImAMadDev", "pocketmine-stubs", token)
        available = {r.get("tag_name") for r in stubs_releases if r.get("tag_name")}
        print(f"✓ {len(available)} releases de stubs disponibles encontrados.\n")
        return available
    except Exception as e:
        print(f"⚠ No se pudieron consultar stubs ({e}), continuando sin prefiltrado de stubs.\n")
        return set()


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


def get_existing_versions(software: str = "pocketmine") -> set[str]:
    """Obtener versiones de un software específico que ya están en manifest.json"""
    manifest = load_manifest()
    return {
        v.get("id")
        for v in manifest.get("versions", [])
        if v.get("software", "pocketmine") == software
    }


def clean_tag_version(tag: str, tag_prefix: str = "") -> str:
    """Remover prefijo de tag para obtener la versión semver limpia"""
    if tag_prefix and tag.startswith(tag_prefix):
        return tag[len(tag_prefix):]
    if tag.startswith("v") and len(tag) > 1 and tag[1].isdigit():
        return tag[1:]
    return tag


def run_update_script(software: str, version: str) -> bool:
    """Ejecutar update-manifest.php para una versión y software"""
    print(f"  [↓] Iniciando {software}@{version}...", flush=True)
    cmd = ["php", UPDATE_SCRIPT, f"--software={software}", f"--version={version}"]

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
            err_msg = result.stderr.strip() or result.stdout.strip()
            print(f"    ✗ Error: {err_msg[:120]}")
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


def print_releases(releases: list[dict], software: str) -> None:
    if not releases:
        print(f"No se encontraron releases para {software}.")
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
    print(f"  Total: {len(releases)} releases para {software}\n")


def save_json(releases: list[dict], path: str) -> None:
    with open(path, "w", encoding="utf-8") as f:
        json.dump(releases, f, indent=2, ensure_ascii=False)
    print(f"✓ Datos guardados en '{path}'")


def process_software_releases(
    software: str,
    fork_config: dict,
    token: str | None = None,
    skip_existing: bool = True,
    max_workers: int = 1,
    output: str | None = None,
    include_prereleases: bool = False,
    include_drafts: bool = False,
    available_stubs: set[str] | None = None,
) -> None:
    repo_full = fork_config.get("repo", "")
    if "/" not in repo_full:
        print(f"✗ Repositorio inválido para {software}: '{repo_full}'")
        return

    owner, repo = repo_full.split("/", 1)
    tag_prefix = fork_config.get("tag_prefix", "")
    stubs_tag_prefix = fork_config.get("stubs_tag_prefix", tag_prefix)

    print(f"\nObteniendo releases para {software} ({owner}/{repo})...\n")
    releases = fetch_all_releases(owner, repo, token)
    print_releases(releases, software)

    if output:
        out_file = output if not output.endswith(".json") else output.replace(".json", f"_{software}.json")
        save_json(releases, out_file)

    if not os.path.exists(UPDATE_SCRIPT):
        print(f"\n✗ Script no encontrado: {UPDATE_SCRIPT}")
        return

    existing = get_existing_versions(software) if skip_existing else set()

    versions_to_process = []
    skipped_existing = 0
    skipped_prerelease = 0
    skipped_draft = 0
    skipped_no_stubs = 0

    for release in releases:
        tag = release.get("tag_name")
        if not tag:
            continue

        is_draft = bool(release.get("draft", False))
        is_prerelease = bool(release.get("prerelease", False))

        if is_draft and not include_drafts:
            skipped_draft += 1
            continue

        if is_prerelease and not include_prereleases:
            skipped_prerelease += 1
            continue

        clean_v = clean_tag_version(tag, tag_prefix)
        if skip_existing and clean_v in existing:
            skipped_existing += 1
            continue

        if available_stubs:
            expected_stub_tag = f"{stubs_tag_prefix}{clean_v}" if stubs_tag_prefix else clean_v
            if expected_stub_tag not in available_stubs:
                skipped_no_stubs += 1
                continue

        versions_to_process.append(clean_v)

    print(f"\n{'─' * 70}")
    mode = "Secuencial" if max_workers == 1 else f"Paralelo ({max_workers} hilos)"
    print(f"📦 Procesando {len(versions_to_process)} releases de {software} ({mode})...")
    if skipped_existing > 0:
        print(f"  ⊘ Saltadas (ya existen en manifest): {skipped_existing}")
    if skipped_prerelease > 0:
        print(f"  ⊘ Saltadas (no estables / pre-releases): {skipped_prerelease}")
    if skipped_draft > 0:
        print(f"  ⊘ Saltadas (drafts): {skipped_draft}")
    if skipped_no_stubs > 0:
        print(f"  ⊘ Saltadas (sin stubs en pocketmine-stubs): {skipped_no_stubs}")
    print(f"{'─' * 70}\n")

    if not versions_to_process:
        print(f"No hay versiones nuevas para procesar en {software}.")
        return

    added = 0
    failed = 0
    completed = 0
    total = len(versions_to_process)

    with concurrent.futures.ThreadPoolExecutor(max_workers=max_workers) as executor:
        future_to_version = {
            executor.submit(run_update_script, software, v): v
            for v in versions_to_process
        }

        for future in concurrent.futures.as_completed(future_to_version):
            version = future_to_version[future]
            completed += 1
            try:
                success = future.result()
                if success:
                    print(f"  ✓ [{completed}/{total}] {software}@{version} procesada correctamente", flush=True)
                    added += 1
                else:
                    print(f"  ⊘ [{completed}/{total}] {software}@{version} omitida (stubs o artefactos no disponibles)", flush=True)
                    failed += 1
            except Exception as e:
                print(f"  ✗ [{completed}/{total}] {software}@{version} lanzó excepción: {e}", flush=True)
                failed += 1

    print(f"\n{'─' * 70}")
    print(f"📊 Resumen ({software}):")
    print(f"  ✓ Agregadas: {added}")
    if skipped_existing > 0:
        print(f"  ⊘ Saltadas (ya existen): {skipped_existing}")
    if skipped_prerelease > 0:
        print(f"  ⊘ Saltadas (no estables): {skipped_prerelease}")
    if skipped_no_stubs > 0:
        print(f"  ⊘ Saltadas (sin stubs): {skipped_no_stubs}")
    if failed > 0:
        print(f"  ⊘ Omitidas / No disponibles: {failed}")
    print(f"  Total evaluadas: {len(releases)}")
    print(f"{'─' * 70}\n")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(
        description="Obtiene releases de repositorios de PocketMine y forks, ejecutando update-manifest.php."
    )
    parser.add_argument(
        "--software",
        default="pocketmine",
        help="Software / fork a consultar ('pocketmine', 'axolotl-pm', 'altay', o 'all')",
    )
    parser.add_argument(
        "--forks-file",
        default=FORKS_PATH,
        help="Ruta al archivo forks.json",
    )
    parser.add_argument("--owner", default=None, help="Dueño del repositorio (override)")
    parser.add_argument("--repo", default=None, help="Nombre del repositorio (override)")
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
        "--include-prereleases",
        action="store_true",
        help="Incluir releases marcados como pre-release (alpha, beta, rc)",
    )
    parser.add_argument(
        "--include-drafts",
        action="store_true",
        help="Incluir releases marcados como draft",
    )
    parser.add_argument(
        "--no-stubs-check",
        action="store_true",
        help="No prefiltrar por tags existentes en ImAMadDev/pocketmine-stubs",
    )
    parser.add_argument(
        "--threads",
        type=int,
        default=1,
        help="Número de hilos para procesamiento (default: 1 = secuencial)",
    )
    parser.add_argument(
        "--parallel",
        action="store_true",
        help="Habilitar procesamiento paralelo (4 hilos).",
    )
    args = parser.parse_args()

    forks_data = load_forks_config(args.forks_file)
    forks_dict = forks_data.get("forks", {})

    workers = args.threads
    if args.parallel and workers == 1:
        workers = 4

    available_stubs = None
    if not args.no_stubs_check:
        available_stubs = fetch_available_stubs(args.token)

    if args.software == "all":
        for s_name, s_config in forks_dict.items():
            process_software_releases(
                software=s_name,
                fork_config=s_config,
                token=args.token,
                skip_existing=not args.no_skip,
                max_workers=workers,
                output=args.output,
                include_prereleases=args.include_prereleases,
                include_drafts=args.include_drafts,
                available_stubs=available_stubs,
            )
    else:
        if args.software in forks_dict:
            s_config = forks_dict[args.software]
        else:
            if args.owner and args.repo:
                s_config = {"name": args.software, "repo": f"{args.owner}/{args.repo}"}
            else:
                print(f"✗ Software '{args.software}' no encontrado en forks.json")
                sys.exit(1)

        if args.owner and args.repo:
            s_config["repo"] = f"{args.owner}/{args.repo}"

        process_software_releases(
            software=args.software,
            fork_config=s_config,
            token=args.token,
            skip_existing=not args.no_skip,
            max_workers=workers,
            output=args.output,
            include_prereleases=args.include_prereleases,
            include_drafts=args.include_drafts,
            available_stubs=available_stubs,
        )
