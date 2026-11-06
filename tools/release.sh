#!/usr/bin/env bash
# Публикует версию из манифеста: тег v<версия> и выпуск с архивом и SHA256SUMS.txt, затем updates.xml в main —
# чтобы Joomla не увидела версию, архива которой ещё нет. Нужны git и gh с входом (gh auth login; gh auth setup-git),
# работа закоммичена и отправлена в main.
#   tools/release.sh --dry-run   собрать и показать, что будет сделано, ничего не публикуя.
set -euo pipefail
cd "$(dirname "$0")/.."
DRY=0; [ "${1:-}" = "--dry-run" ] && DRY=1
NAME="Webmarka Редколлегия"
PKG=pkg_wmarka_redkollegiya
REPO=bsakhanov/com_wmarka_redkollegiya
VER=$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' component/wmarka_redkollegiya.xml | head -1)
TAG="v$VER"
run() { if [ "$DRY" = 1 ]; then echo "  [dry-run] $*"; else "$@"; fi; }
fail() { echo "release: $*" >&2; exit 1; }

[ -z "$(git status --porcelain)" ] || fail "сначала закоммитьте изменения"
grep -q "^## $VER " CHANGELOG.md || fail "в CHANGELOG.md нет раздела $VER"
grep -q "<version>$VER</version>" changelog.xml || fail "в changelog.xml нет записи $VER"
if [ "$DRY" = 0 ]; then
    git fetch -q origin
    [ "$(git rev-parse HEAD)" = "$(git rev-parse origin/main)" ] || fail "HEAD не совпадает с origin/main: отправьте main"
    ! git ls-remote --exit-code --tags origin "refs/tags/$TAG" >/dev/null || fail "тег $TAG уже есть"
fi

./build.sh >/dev/null
NOTES=$(mktemp)
awk -v v="## $VER " 'index($0, v) == 1 {p = 1; next} /^## / && p {exit} p' CHANGELOG.md > "$NOTES"

run git tag -a "$TAG" -m "$NAME $VER"
run git push origin "refs/tags/$TAG"
run gh release create "$TAG" --repo "$REPO" --verify-tag --latest --title "$NAME $VER" --notes-file "$NOTES" \
    "dist/${PKG}_v${VER}.zip" dist/SHA256SUMS.txt

if [ "$DRY" = 1 ]; then
    echo "  [dry-run] updates.xml станет версией $VER (sha256 $(grep -o '<sha256>[^<]*' dist/updates.xml | cut -c9-))"
else
    cp dist/updates.xml updates.xml
    git add updates.xml
    git commit -q -m "updates.xml: $VER"
    git push -q origin HEAD:main
    echo "Опубликовано: https://github.com/$REPO/releases/tag/$TAG"
fi
