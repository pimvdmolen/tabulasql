# Custom PHP binary (with MySQL support)

`nativephp/php-bin` (the package NativePHP normally downloads its bundled
PHP from) is built without `pdo_mysql`/`mysqlnd` — see
[php-extensions.txt](https://github.com/NativePHP/php-bin/blob/main/php-extensions.txt).
Since this app connects to MySQL/MariaDB databases, that build is unusable
here: every connection fails with `could not find driver`.

`bin/linux/x64/php-8.5.zip` is a custom PHP 8.5 CLI build (via
[static-php-cli](https://static-php.dev) 2.8.5) with the same extension set
plus `pdo_mysql`. It's wired in via `NATIVEPHP_PHP_BINARY_PATH` in `.env`,
which `Native\Desktop\Builder\Concerns\LocatesPhpBinary` and
`resources/electron/php.js` both read instead of the default
`vendor/nativephp/php-bin/`.

Built with:
```
spc build --build-cli "bcmath,bz2,ctype,curl,dom,fileinfo,filter,gd,iconv,intl,mbstring,mbregex,opcache,openssl,pdo,pdo_mysql,pdo_sqlite,phar,session,simplexml,sockets,sodium,sqlite3,tokenizer,xml,zip,zlib" --with-libs="libavif,freetype"
```

Note: built with `SPC_LIBC=glibc` (dynamically linked against this
machine's glibc/libs), not the fully static musl build NativePHP normally
ships. That's fine for local development, but **not portable** — a
`.deb`/`.AppImage`/`.exe` built for distribution to other machines should
use a proper static musl build instead (or wait for `pdo_mysql` to land in
`nativephp/php-bin` upstream).

`bin/mac/arm64/php-8.5.zip` is the same build, done natively on macOS
(arm64) via static-php-cli 2.8.5:
```
spc build --build-cli "bcmath,bz2,ctype,curl,dom,fileinfo,filter,gd,iconv,intl,mbstring,mbregex,opcache,openssl,pdo,pdo_mysql,pdo_sqlite,phar,session,simplexml,sockets,sodium,sqlite3,tokenizer,xml,zip,zlib"
```
Static (Mach-O, no external lib dependencies besides system frameworks),
so it is portable across Macs of the same architecture — build an x64 zip
separately on an Intel Mac (or via cross-compile) if you need it there too.

### macOS build caveat: unsigned app won't launch as-is

Without a paid Apple Developer account there's no code signing identity to
give electron-builder, so `php artisan native:build mac <arch>` ad-hoc
signs the app and skips notarization. That alone only causes a Gatekeeper
warning — normally fine, tell users to right-click → Open. But
`vendor/nativephp/desktop`'s default `build/entitlements.mac.plist`
enables the hardened runtime *without* disabling library validation. That
combination requires every binary in the bundle (main executable, Electron
Framework, helpers) to share one real Team ID; with ad-hoc signing each
ends up with a distinct identity, so **dyld refuses to launch the app at
all** ("Library not loaded: ... different Team IDs") — this happens
regardless of Gatekeeper being bypassed.

Fixed via [scripts/fix-mac-adhoc-signing.sh](../scripts/fix-mac-adhoc-signing.sh),
wired in as a `postbuild` step in [config/nativephp.php](../config/nativephp.php):
it re-signs the built `.app` ad-hoc in one pass without the hardened
runtime (which is what a real Developer ID + notarized build wouldn't
need), then rebuilds the `.dmg` from the fixed app. It's a no-op once a
real signing identity/Team ID is configured.
