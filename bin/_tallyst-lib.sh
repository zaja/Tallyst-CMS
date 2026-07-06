#!/usr/bin/env bash
#
# Shared helpers for bin/tallyst-setup and bin/tallyst-upgrade.
#
# Server-agnostic: NOTHING about the host is hardcoded (no php8.5, no /usr/local/bin/composer).
# Every install runs on an unknown server (varying PHP paths, Composer setups) — so we DETECT.
# Escape hatches: set PHP=/path/to/php or COMPOSER="/path/to/composer" (or a full command) to
# override the probes.

# Locate a PHP CLI binary satisfying the app's requirement (>= 8.5); sets $PHP. Errors + returns 1.
detect_php() {
    local candidate
    for candidate in "${PHP:-}" php8.5 php8.6 php8.7 php php-cli; do
        [ -n "$candidate" ] \
            && command -v "$candidate" >/dev/null 2>&1 \
            && "$candidate" -r 'exit(PHP_VERSION_ID >= 80500 ? 0 : 1);' 2>/dev/null \
            && { PHP="$candidate"; return 0; }
    done
    echo "Error: no PHP >= 8.5 found on PATH." >&2
    echo "Set PHP to your PHP 8.5 binary and retry, e.g.  PHP=/usr/bin/php8.5 $0" >&2
    return 1
}

# Locate a Composer command; sets $COMPOSER (may be multi-word, e.g. "php composer.phar").
# Runs Composer THROUGH the detected $PHP when it can, so Composer's own platform check uses
# PHP >= 8.5 (a global `composer` may run under an older default PHP → a false platform error).
# Requires $PHP to be set first (call detect_php before detect_composer).
detect_composer() {
    [ -n "${COMPOSER:-}" ] && return 0
    if [ -f composer.phar ]; then
        COMPOSER="$PHP composer.phar"
        return 0
    fi
    local found
    found="$(command -v composer 2>/dev/null || true)"
    if [ -n "$found" ]; then
        # If it's a PHP phar, drive it with $PHP; otherwise call it directly.
        if "$PHP" "$found" --version >/dev/null 2>&1; then
            COMPOSER="$PHP $found"
        else
            COMPOSER="$found"
        fi
        return 0
    fi
    echo "Error: Composer not found (no 'composer' on PATH, no ./composer.phar)." >&2
    echo "Install Composer (https://getcomposer.org) or set COMPOSER=/path/to/composer and retry." >&2
    return 1
}

# Ensure git is available (Tallyst is installed + updated via git clone / checkout).
require_git() {
    command -v git >/dev/null 2>&1 && return 0
    echo "Error: git is required to install/update Tallyst. See docs/INSTALL.md." >&2
    return 1
}
