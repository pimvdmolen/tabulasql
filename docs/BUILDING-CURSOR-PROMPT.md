# Prompt for Cursor: building the release binaries (Linux, macOS, Windows)

Paste this whole file's content into Cursor when you need it to build
release artifacts for this NativePHP/Electron app. Read `docs/BUILDING.md`
first for the general `php artisan native:build {os} {arch}` process — this
file is about what to do when that plain command doesn't just work, per
platform.

## Read this first: the default bundled PHP is broken for this app

Before touching any platform-specific instructions below: `nativephp/php-bin`
(the package NativePHP normally bundles PHP from into the shipped app) is
built **without `pdo_mysql` or `sodium`** — confirmed directly by extracting
it and reading `php-extensions.txt` inside the package, not just hearsay.
Since this app's whole purpose is connecting to MySQL/MariaDB, and it
encrypts `.dbmconn` connection exports with sodium, **a build using the
default bundled PHP installs fine and then fails at runtime** — MySQL
connections error "could not find driver", and connection export/import
breaks. This bites *every* platform, not just one, and it's easy to miss
because `native:build` completes successfully either way.

Two ways this is already handled in this repo, pick whichever fits what
you're doing:
- **CI** (`.github/workflows/build.yml`): already downloads and verifies a
  proper static-php-cli build per platform automatically — see
  `scripts/ci/fetch-static-php.php`. Nothing to do if you're just running
  that workflow.
- **Local manual builds**: `nativephp-php-bin-custom/README.md` documents
  the equivalent manual setup (a `NATIVEPHP_PHP_BINARY_PATH` override in
  `.env` pointing at a custom-fetched static PHP build). If you're building
  locally on a machine that doesn't have this set up yet, either follow that
  README, or reuse `scripts/ci/fetch-static-php.php` directly — it works
  standalone outside of CI too:
  ```bash
  php -d memory_limit=-1 scripts/ci/fetch-static-php.php \
    "https://dl.static-php.dev/static-php-cli/bulk/php-8.3.32-cli-linux-x86_64.tar.gz" \
    tar.gz linux x64 8.3 nativephp-php-bin-custom
  ```
  (swap the URL/args for macOS `.../bulk/php-8.3.32-cli-macos-{aarch64,x86_64}.tar.gz` /
  `mac` `arm64`/`x64`, or Windows `.../windows/spc-max/php-8.4.20-cli-win.zip` /
  `win` `x64` — check `dl.static-php.dev/static-php-cli/{bulk,windows/spc-max}/`
  for current version numbers, they get pruned periodically). Then set
  `NATIVEPHP_PHP_BINARY_PATH=nativephp-php-bin-custom/` in `.env` (matching
  the `php_version` argument you passed to the PHP major.minor `native:build`
  itself will run with) before building.
- **Don't** just let a build proceed on the default bundled PHP and assume
  it's fine because the build succeeded — it will look identical right up
  until someone tries to actually connect to a database.

## Linux: .deb and .AppImage

Target output: `nativephp/electron/dist/tabulasql_*.deb` and
`TabulaSQL-*.AppImage`.

### The problem

`php` on this machine is a wrapper (`~/.local/share/lerd/bin/php`) that
executes PHP **inside a container**. `php artisan native:build` spawns
`npm`/`electron-builder` as child processes of that containerized PHP, so
those children run inside the container too — but the container is missing
things the container's `node`/`npm` needs at the final packaging step:

- `mksquashfs` (needed for AppImage) fails with `no such file or directory`
  even though the file demonstrably exists on the host at
  `~/.cache/electron-builder/appimage/appimage-*/linux-x64/mksquashfs` —
  because the container doesn't have that host path mounted/available.
- A post-build hook script fails with `bash: not found` for the same reason
  (minimal container, no bash).

Meanwhile, `node`/`npm` themselves are plain host installs
(`~/.local/bin/node`, `~/.local/bin/npm`), not containerized. Running the
Vite asset build (`npm run build` at the project root) works fine directly,
proving the host toolchain itself is fine — it's specifically the
container-vs-host split for this one command that's the problem.

### The fix: run the final packaging step directly on the host

`php artisan native:build` calls, in order:
1. `Builder::preProcess()`, `copyToBuildDirectory()`, `cleanEnvFile()`,
   `pruneVendorDirectory()`, icon install — all PHP-side staging into
   `vendor/nativephp/desktop/resources/build/app`. This part works fine
   through the containerized `php artisan`.
2. `npm run build:{os}` inside `vendor/nativephp/desktop/resources/electron`,
   run via Symfony `Process` with a specific set of env vars set by
   `BuildCommand::getEnvironmentVariables()`. **This is the step that needs
   to run on the host instead.**

So: let the containerized `php artisan` do the staging, but invoke the
actual `electron-builder` packaging yourself, directly, with the same env
vars it would have set. Concretely:

```bash
cd /path/to/project

# 1. Let NativePHP do everything through step 1 above by running the normal
#    build command — it WILL fail at packaging, that's expected and fine;
#    the staging step it does first still succeeds and that's what we need.
npm run build              # rebuild Vite assets first
php artisan native:reset   # clear previous dist/build output
php artisan native:build linux x64 --no-interaction -v || true

# 2. Dump the exact env vars NativePHP would have used for step 2, via
#    reflection (getEnvironmentVariables() is protected), as `export` lines:
cd /path/to/project
eval "$(php artisan tinker --execute='
$cmd = app(\Native\Desktop\Drivers\Electron\Commands\BuildCommand::class);
$ref = new ReflectionMethod($cmd, "getEnvironmentVariables");
$ref->setAccessible(true);
$vars = $ref->invoke($cmd);
foreach ($vars as $k => $v) {
    if (is_bool($v)) $v = $v ? "true" : "false";
    echo "export " . $k . "=" . escapeshellarg((string) $v) . "\n";
}
' 2>/dev/null | grep '^export')"

# 3. In THE SAME shell/command (env vars set in step 2 must stay exported —
#    do not split this across separate tool calls / new shells), run the
#    packaging step directly via the host's node/npm:
cd vendor/nativephp/desktop/resources/electron
npm run build:linux-x64
```

Artifacts land in `nativephp/electron/dist/`:
`tabulasql_{version}_amd64.deb` and `TabulaSQL-{version}.AppImage`.

### Important gotchas

- **Do not filter out empty/null env vars** when dumping them in step 2 —
  export every key even if its value is an empty string. Skipping
  `NATIVEPHP_DEEPLINK_SCHEME` entirely (instead of exporting it as `''`)
  makes `process.env.NATIVEPHP_DEEPLINK_SCHEME` `undefined` in the Node
  config instead of an empty string, which fails electron-builder's config
  validation (`configuration.protocols.schemes[0] should be a string`).
- **Steps 2 and 3 must run in the same shell invocation.** Exported env vars
  don't survive between separate tool calls/terminal commands — if you're
  using an agent/tool that runs each command in a fresh shell, chain steps 2
  and 3 together with `&&` or in one script, not as separate calls.
- Verify the output afterwards, don't just trust a clean exit:
  ```bash
  file nativephp/electron/dist/*.AppImage   # should say "ELF 64-bit ... executable"
  file nativephp/electron/dist/*.deb        # should say "Debian binary package"
  ./nativephp/electron/dist/TabulaSQL-*.AppImage --appimage-extract-and-run --version
  # should extract cleanly (AppRun, chrome-sandbox, etc.) without erroring
  ```
- This whole workaround is specific to *this* container/host split. If a
  future environment runs `php` as a normal host binary (no lerd/podman
  wrapper), just use the plain `php artisan native:build linux x64` command
  from `docs/BUILDING.md` — none of the above is needed there.
- **The general technique generalizes to any OS.** If `php` is wrapped
  through a container/VM/remote-exec setup on macOS or Windows too (WSL,
  Docker Desktop, a devcontainer, etc.) and `php artisan native:build` fails
  at the packaging step for a similar reason, use the exact same two-part
  approach: let the wrapped `php artisan` do the PHP-side staging (step 1),
  then dump `BuildCommand::getEnvironmentVariables()` via reflection and run
  `npm run build:{target}` yourself from
  `vendor/nativephp/desktop/resources/electron` on whatever the *un*wrapped
  host shell is. The env var dump snippet in step 2 is OS-agnostic — it's
  plain PHP reflection, and the resulting `export KEY=value` lines work in
  bash/zsh identically on Linux and macOS. On Windows without a real POSIX
  shell (no Git Bash / WSL), translate the `export` lines to
  `$env:KEY = 'value'` for PowerShell instead — same values, different
  syntax.

## macOS: .dmg

Target output: `nativephp/electron/dist/TabulaSQL-{version}-{arch}.dmg`.

```bash
php artisan native:build mac arm64   # Apple Silicon
php artisan native:build mac x64     # Intel
```

**This can only be done on a real Mac (or a macOS CI runner, e.g. GitHub
Actions' `macos-latest`).** Electron cannot cross-compile a macOS target from
Linux or Windows — there is no workaround for that, unlike the
Linux-container issue above. If Cursor is running on Linux/Windows and asked
to produce a `.dmg`, it should say so plainly rather than attempt it; the
right move is to run this on macOS hardware or wire up a `macos-latest` job
in CI (see `docs/BUILDING.md`'s CI notes).

On an actual Mac, none of the Linux container workaround applies — a normal
local PHP install has no lerd-style wrapper, so the plain `native:build`
command should just work. If it doesn't (e.g. Cursor's own sandbox wraps
`php` similarly to the Linux case), apply the same two-part
staging-then-manual-packaging technique described above, adapted to
`npm run build:mac-arm64` / `build:mac-x64`.

Two things that matter for a `.dmg` that other people can actually open
without Gatekeeper blocking it:

- **Code signing + notarization.** Unsigned builds work for local testing
  only; anyone else downloading one gets a "damaged/can't be opened"
  Gatekeeper warning. Needs an Apple Developer ID certificate and:
  `CSC_LINK` / `CSC_KEY_PASSWORD` (the signing cert), plus
  `config('nativephp-internal.notarization.apple_id')` /
  `apple_id_pass` (an app-specific password, not the real Apple ID password)
  / `apple_team_id`, surfaced to the build as `NATIVEPHP_APPLE_ID` /
  `NATIVEPHP_APPLE_ID_PASS` / `NATIVEPHP_APPLE_TEAM_ID` (see
  `BuildCommand::getEnvironmentVariables()` if you need the exact env var
  names again).
- **Two local fixes get reapplied automatically, no action needed here.**
  `vendor/nativephp/desktop/resources/electron/src/main/index.js` isn't
  committed to git and gets regenerated fresh by `native:install` on every
  `composer install`/`update`. A maintained copy of that file (disabling
  hardware acceleration to dodge a Linux GPU-process crash, plus opening
  external links in the system browser instead of a new Electron window)
  lives in `resources/nativephp-patches/electron-main-index.js` (tracked in
  git), and `composer.json`'s `post-install-cmd`/`post-update-cmd` already
  run `php artisan native:patch-electron` to copy it back into place after
  every install. This runs identically on every OS — the GPU fix is
  harmless on macOS/Windows too (same "simple CRUD UI, software rendering
  is fine" reasoning), so there's nothing platform-specific to strip out.
  If `native:install` gets run manually outside Composer's lifecycle for
  some reason, just run `php artisan native:patch-electron` again
  afterwards.

## Windows: NSIS installer (.exe)

Target output: `nativephp/electron/dist/TabulaSQL-{version}-setup.exe`.

```bash
php artisan native:build win x64
```

Two ways to produce this:

1. **Natively on Windows** (or WSL with a Windows PHP toolchain) — simplest,
   works the same as `docs/BUILDING.md` describes for Linux/macOS, no special
   tooling needed beyond what `composer install`/`npm install` already pull
   in.
2. **Cross-built from Linux/macOS using Wine** — electron-builder's NSIS
   target shells out to Wine to run the Windows-only installer-building
   tools. Requirements:
   - `wine` (or `wine64`) installed and on `PATH`. On Debian/Ubuntu:
     `sudo apt install wine`. On Arch/CachyOS: `sudo pacman -S wine`.
   - First run downloads NSIS/rcedit binaries into
     `~/.cache/electron-builder/` the same way the AppImage tooling did for
     Linux — if *those* downloads fail to execute for the same
     container-vs-host reason described above, the identical workaround
     applies: run the final `npm run build:win-x64` step directly on the
     host (outside whatever wraps `php`), after dumping the env vars via the
     reflection snippet.
   - Wine-based cross-builds are the flakiest of the three platforms in
     practice (version-sensitive, occasional silent failures) — if it
     misbehaves, prefer building on an actual Windows machine or a
     `windows-latest` GitHub Actions runner instead of debugging Wine.

Signing: an Authenticode certificate via `CSC_LINK` / `CSC_KEY_PASSWORD`
(same env vars as macOS use for their own certificate); unsigned `.exe`s work
locally but trigger a SmartScreen "unknown publisher" warning for anyone
who downloads one.

SSH tunneling on Windows uses the OpenSSH client built into Windows 10+
(`ssh.exe`), already on `PATH` in a normal Windows install — no extra
dependency to bundle there, unlike `sshpass` on Linux/macOS for
password-auth tunnels.
