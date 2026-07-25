#!/usr/bin/env bash
#
# Local, unsigned macOS builds ship with electron-builder's default
# entitlements (build/entitlements.mac.plist in nativephp/desktop), which
# enable the hardened runtime but do not disable library validation. That
# combination is only safe when every binary in the bundle is signed with
# the same real Apple Developer ID/Team. With ad-hoc signing (no
# NATIVEPHP_*_CERTIFICATE / Apple Developer account configured), each
# component ends up with a distinct ad-hoc identity, so dyld refuses to
# load the Electron Framework at launch: "different Team IDs".
#
# Fix: re-sign the whole .app bundle ad-hoc in one pass without the
# hardened runtime, then rebuild the .dmg from the fixed .app. Skips
# straight past this when a real signing identity is present (that build
# is already consistent).
set -euo pipefail

[ "$(uname)" = "Darwin" ] || exit 0

DIST_DIR="nativephp/electron/dist"

[ -d "$DIST_DIR" ] || exit 0

for APP_DIR in "$DIST_DIR"/mac-arm64 "$DIST_DIR"/mac-x64 "$DIST_DIR"/mac; do
    [ -d "$APP_DIR" ] || continue

    APP_PATH=$(find "$APP_DIR" -maxdepth 1 -iname "*.app" | head -1)
    [ -n "$APP_PATH" ] || continue

    TEAM_LINE=$(codesign -dv "$APP_PATH" 2>&1 | grep "^TeamIdentifier=" || true)
    if [ "$TEAM_LINE" != "TeamIdentifier=not set" ]; then
        echo "Skipping $APP_PATH: already signed with a real Team ID ($TEAM_LINE)."
        continue
    fi

    echo "Re-signing $APP_PATH ad-hoc without hardened runtime..."
    codesign --sign - --deep --force "$APP_PATH"

    ARCH_SUFFIX=$(basename "$APP_DIR" | sed 's/^mac-//')
    DMG_PATH=$(find "$DIST_DIR" -maxdepth 1 -iname "*-${ARCH_SUFFIX}.dmg" | head -1)
    [ -n "$DMG_PATH" ] || continue

    echo "Rebuilding $DMG_PATH from the re-signed app..."
    STAGE=$(mktemp -d)
    cp -R "$APP_PATH" "$STAGE/"
    ln -s /Applications "$STAGE/Applications"
    rm -f "$DMG_PATH"
    hdiutil create -volname "$(basename "$APP_PATH" .app)" -srcfolder "$STAGE" -ov -format UDZO "$DMG_PATH"
    rm -rf "$STAGE"
done
