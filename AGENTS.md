# Agent Instructions

## Git Commits

### Verified signatures (branch protection requirement)

All commits must be signed with a verified GPG signature. The signing key UID must match the commit author e-mail exactly, otherwise GitHub rejects the merge.

Always use:
```
git config user.email "89843389+advansit@users.noreply.github.com"
git config user.name "Advans IT Solutions GmbH"
```

The signing key (`BBB1295FE1391E99`) is bound to `89843389+advansit@users.noreply.github.com`. Using any other e-mail (e.g. `pascal.raphael@users.noreply.github.com`) produces a signature GitHub cannot verify.

### Conventional commits

Release CI workflows auto-detect the version bump from commit prefixes:

| Prefix | Bump |
|---|---|
| `fix:` / `fix(...):` | patch |
| `feat:` / `feat(...):` | minor |
| `feat!:` / `BREAKING CHANGE:` | major |

Any other prefix (e.g. `i18n:`, `docs:`, `chore:`) is **not** recognized — the release workflow skips with "No conventional commit found". Use `fix(...):`  or `feat(...):` with a scope for non-standard change types (e.g. `fix(i18n):`, `fix(security):`).

### No Co-authored-by trailer

Do not add `Co-authored-by` trailers to commits in this repository.
