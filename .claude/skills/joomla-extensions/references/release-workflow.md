# Release Workflow

## How Releases Work

Releases are fully automated via `workflow_dispatch`. **Never set VERSION or plugin XML version manually.**

## Steps

1. **Merge PR to `main`** — use Conventional Commits so the workflow can auto-detect the bump type
2. **Trigger release workflow** on GitHub:
   - `Actions → Release - {Plugin Name} → Run workflow`
   - Leave `bump` empty for auto-detect, or choose `patch` / `minor` / `major`
3. The workflow:
   - Detects bump type from commit messages (`fix:` → patch, `feat:` → minor, breaking → major)
   - Updates `VERSION`, `{plugin}.xml`, `updates/update.xml`
   - Builds the ZIP via `build.sh`
   - Creates a GitHub Release with the ZIP as asset
   - Tags the commit as `{plugin}-v{version}` (e.g. `privacy-v1.5.0`)
   - Commits version files back to `main`

## Conventional Commit → Bump Mapping

| Prefix | Bump |
|--------|------|
| `fix:`, `docs:`, `refactor:`, `test:`, `chore:` | patch |
| `feat:` | minor |
| `feat!:` or `BREAKING CHANGE:` in footer | major |

## After Release

The Joomla Update Server (`updates/update.xml`) is updated automatically. Joomla installations with the plugin will see the update in `System → Update → Extensions`.

## Branch Strategy

- `main` — production, always releasable
- Feature/fix branches — short-lived, deleted automatically after merge
- Branch naming: `fix/`, `feat/`, `chore/`, `docs/` prefix
- Auto-delete head branches: enabled
