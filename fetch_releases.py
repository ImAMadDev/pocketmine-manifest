"""
Fetches all releases from a GitHub repository using the GitHub API.
Repo: ImAMadDev/pocketmine-stubs
"""

import json
import sys
import urllib.error
import urllib.request

OWNER = "ImAMadDev"
REPO = "pocketmine-stubs"


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


if __name__ == "__main__":
    import argparse

    parser = argparse.ArgumentParser(
        description="Obtiene todos los releases de un repo de GitHub."
    )
    parser.add_argument("--owner", default=OWNER, help="Dueño del repositorio")
    parser.add_argument("--repo", default=REPO, help="Nombre del repositorio")
    parser.add_argument(
        "--token", default=None, help="GitHub Personal Access Token (opcional)"
    )
    parser.add_argument(
        "--output", default=None, help="Guardar resultados en un archivo JSON"
    )
    args = parser.parse_args()

    print(f"Obteniendo releases de {args.owner}/{args.repo}...\n")
    releases = fetch_all_releases(args.owner, args.repo, args.token)
    print_releases(releases)

    if args.output:
        save_json(releases, args.output)
