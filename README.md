# pocketmine-manifest

![Automated](https://img.shields.io/badge/Automated-✅-brightgreen?style=flat-square) ![Status](https://img.shields.io/badge/Status-Ready-blue?style=flat-square) ![PocketMine](https://img.shields.io/badge/PocketMine-MP-orange?style=flat-square)

Repositorio de soporte para **PocketIDE** — contiene el `manifest.json` maestro
que el IDE consulta al arrancar para saber qué versiones de PocketMine-MP están
disponibles, con sus URLs de descarga y checksums verificados.

```
https://raw.githubusercontent.com/ImAMadDev/pocketmine-manifest/main/manifest.json
```

---

## 🤖 ¡AUTOMATIZACIÓN ACTIVADA!

### ✨ Lo Nuevo

**Ahora el repositorio se actualiza automáticamente cada 6 horas:**

```
✅ Detecta nuevas versiones de PocketMine-MP
✅ Descarga y verifica artefactos
✅ Calcula SHA256 checksums
✅ Crea Pull Request automático
✅ Todo listo para mergear
```

**Antes**: Detección manual + actualización manual + PR manual (~15 minutos)  
**Ahora**: Todo automático (~2-3 minutos)

---

## 🎯 Cómo Funciona

### Automático (Default)

El workflow se ejecuta **cada 6 horas automáticamente**:

```
06:00 → Detecta nueva versión
06:30 → Descarga y verifica
07:00 → Actualiza manifest.json
07:15 → Crea PR automático ← Tú solo mergeas
```

### Manual (Cuando quieras)

```
GitHub → Actions
→ "Check New PocketMine-MP Releases & Auto-Update Manifest"
→ "Run workflow"
→ (Opcional: force_version=5.43.1)
```

---

## 🚀 Scripts - 2 solo, simplificado

### 1. `update-manifest.php` - Agregar versión

**Una línea para agregar versión nueva (auto-detecta todo desde `build_info.json`):**

```bash
php scripts/update-manifest.php --version=5.43.1
```

**Qué hace:**
- Descarga `build_info.json` de GitHub
- Auto-detecta: PHP version, Minecraft version, stability, PHP tag
- Descarga binarios de 5 plataformas
- Calcula SHA256 de cada archivo
- Actualiza manifest.json

**Opciones:**

```bash
# Ver qué haría sin escribir
php scripts/update-manifest.php --version=5.43.1 --dry-run

# Override de datos si es necesario
php scripts/update-manifest.php --version=5.43.1 --api-version=5.43.0 --mc-version=1.26.20
```

---

### 2. `validate-manifest.php` - Validar integridad

```bash
# Validación básica
php scripts/validate-manifest.php

# Verificar URLs
php scripts/validate-manifest.php --check-urls

# Modo estricto (para CI/CD)
php scripts/validate-manifest.php --strict
```

---

## 📋 Flujo Típico (30 segundos)

```bash
# 1. Agregar versión nueva
php scripts/update-manifest.php --version=5.43.1

# 2. Validar
php scripts/validate-manifest.php

# 3. Commit
git add manifest.json
git commit -m "Add PocketMine-MP 5.43.1"
git push
```
---
## 📊 Datos Auto-Detectados

El `build_info.json` (en el PHAR de cada release) contiene:

```json
{
    "php_version": "8.2",
    "mcpe_version": "1.26.20",
    "channel": "stable",
    "is_dev": false,
    "php_download_url": "https://github.com/pmmp/PHP-Binaries/releases/tag/pm5-php-8.2-latest"
}
```

De aquí se extrae automáticamente:
- `api_version` → versión con patch=0
- `minecraft_version` → mcpe_version
- `php_version` → php_version
- `php_tag` → de php_download_url
- `stability` → channel (o "alpha" si is_dev=true)

---

## 📝 Formato de manifest.json

```jsonc
{
  "manifest_version": 1,
  "updated_at": "2026-05-22T22:35:00Z",
  "versions": [
    {
      "id": "5.43.1",
      "api_version": "5.43.0",
      "release_date": "2026-05-22",
      "stability": "stable",
      "minecraft_version": "1.26.20",
      "min_php": "8.2",
      "changelog_url": "https://github.com/pmmp/PocketMine-MP/releases/download/5.43.1/changelogs/5.43.md",
      "downloads": {
        "pocketmine_phar": "https://github.com/pmmp/PocketMine-MP/releases/download/5.43.1/PocketMine-MP.phar",
        "pocketmine_phar_sha256": "abc123...",
        "php_windows_x64": "https://...",
        "php_windows_x64_sha256": "def456...",
        // ... más plataformas
      },
      "stubs": {
        "url": "https://github.com/ImAMadDev/pocketmine-stubs/releases/download/5.43.1/stubs.zip",
        "checksum_sha256": "jkl012..."
      }
    }
  ]
}
```

---

## 🔄 El PR Automático que se Crea

```
🎉 Update: Add PocketMine-MP 5.43.1

🚀 Automated Version Update

📋 Details
├─ Version: 5.43.1
├─ MC Version: 1.21.0
├─ API Version: 5.43.0
├─ PHP Version: 8.2.x
└─ Release Date: 2026-05-22

✅ Automated Checks
├─ [x] update-manifest.php executed
├─ [x] validate-manifest.php passed (strict)
├─ [x] SHA256 checksums verified
└─ [x] Build info extracted from GitHub

📦 Artifacts Downloaded & Verified
├─ PocketMine-MP PHAR (all 5 platforms)
├─ PHP binaries (recommended version)
└─ All checksums computed and validated

🔍 Review Checklist
├─ [ ] Verify MC/API versions are correct
├─ [ ] Check if stubs need updating
└─ [ ] Validate against official changelog
```

---

## 🔐 Seguridad

- ✅ Los SHA256 protegen contra descargas corruptas. Nunca dejes `NEEDS_SHA256_COMPUTE` sin calcular.
- ✅ Branch protection en `main` (recomendado)
- ✅ GitHub Actions solo tiene permisos mínimos necesarios
- ✅ Nunca pongas secrets en `manifest.json` (es público)
- ✅ `github-actions[bot]` realiza commits (no usa credenciales personales)
- ✅ Solo crea ramas nuevas (nunca toca `main` directamente)
- ✅ PRs requieren review manual antes de mergear
- ✅ Descarga solo desde repositorios oficiales (pmmp)

---

## 🎯 Estado del Sistema

```
✅ Workflow automatizado
✅ Detección: cada 6 horas (o manual)
✅ Auto-descarga: PocketMine PHAR + PHP binaries
✅ Auto-checksum: SHA256 para todos los artefactos
✅ Auto-PR: Rama feature → main
✅ Validación: Strict mode antes de PR
✅ Permisos: Configurados correctamente
✅ Logs: Detallados en GitHub Actions
✅ Listo: Para producción
```

---

## ⚡ Comienza Aquí

### Para Activar (One-time setup)

```bash
# 1. Verificar permisos
#    Settings → Actions → General
#    → Workflow permissions: Read and write ✅

# 2. Proteger main (recomendado)
#    Settings → Branches → Add rule → main
#    → Require pull request before merging ✅

# 3. Probar manualmente
#    GitHub → Actions → "Check New PocketMine-MP Releases"
#    → Run workflow → Observar logs
```

### Para Usar

```bash
# La automatización se ejecuta cada 6 horas
# Si hay nueva versión:
#   1. PR se crea automáticamente
#   2. Tú revisa los cambios
#   3. Mergea si todo OK

# O prueba manualmente:
# GitHub → Actions → Run workflow → force_version=5.43.1
``` 

---

## 🏗️ Ecosistema Deepslate

| Repositorio | Propósito |
|-------------|-----------|
| [`ImAMadDev/pocketmine-manifest`](https://github.com/ImAMadDev/pocketmine-manifest) | **Este repositorio** |
| [`ImAMadDev/pocketmine-stubs`](https://github.com/ImAMadDev/pocketmine-stubs) | Stubs PHP por versión |

---

---

## 🚀 Próximos Pasos

1. **Verifica permisos en GitHub** (1 minuto)
2. **Prueba el workflow manualmente** (si quieres)
3. **¡Listo!** El workflow se ejecutará cada 6 horas automáticamente

---

## 💡 Tips

- 📌 La automatización **no toca `main` directamente**
- 📌 Los PRs deben ser **revisados antes de mergear**
- 📌 Los logs en Actions **son tus amigos para debugging**
- 📌 El workflow es **idempotente** (puedes ejecutarlo infinitas veces)
- 📌 **Stubs checksum** aún se hace manualmente (mejora futura)

---

## ❓ ¿Preguntas?

- 📖 Guías: Ver documentación arriba
- 🔍 Logs: GitHub Actions → Workflow → Logs
- 🐛 Bugs: Abre un issue con los logs

---

**Sistema**: Automated PocketMine Version Manager  
**Estado**: ✅ Completamente Operativo y Listo para Producción  
**Última actualización**: 2026-07-01

*Deepslate Ecosystem — ImAMadDev/pocketmine-manifest*
