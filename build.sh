#!/usr/bin/env bash
# Сборка выпуска в dist/: pkg_wmarka_redkollegiya_v<версия>.zip (компонент и два плагина, ставится через «Расширения → Установка»),
# SHA256SUMS.txt и updates.xml (в репозиторий его записывает tools/release.sh).
set -euo pipefail
cd "$(dirname "$0")"
NAME="Webmarka Редколлегия"
PKG=pkg_wmarka_redkollegiya
REPO=bsakhanov/com_wmarka_redkollegiya
version() { sed -n 's:.*<version>\(.*\)</version>.*:\1:p' "$1" | head -1; }
VER=$(version component/wmarka_redkollegiya.xml)
for f in package/$PKG.xml plugins/editors-xtd/wmarka_redkollegiya/wmarka_redkollegiya.xml plugins/content/wmarka_redkollegiya/wmarka_redkollegiya.xml; do
    [ "$(version "$f")" = "$VER" ] || { echo "Версия в $f не $VER" >&2; exit 1; }
done
grep -q "\"version\": \"$VER\"" component/media/joomla.asset.json || { echo "Версия в joomla.asset.json не $VER" >&2; exit 1; }
if command -v php >/dev/null; then
    find component plugins package -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null
    for f in $(find component plugins package -name '*.ini'); do
        php -r 'exit(parse_ini_file($argv[1]) === false ? 1 : 0);' "$f" || { echo "Битый языковой файл $f" >&2; exit 1; }
    done
    php tests/run.php >/dev/null || { echo "Проверки ядра не прошли: php tests/run.php" >&2; exit 1; }
fi
rm -rf build dist && mkdir -p build/package/packages dist
cp LICENSE NOTICE component/administrator/
(cd component && zip -qrX ../build/package/packages/com_wmarka_redkollegiya.zip . -x '.*')
rm component/administrator/LICENSE component/administrator/NOTICE
for g in editors-xtd content; do
    (cd plugins/$g/wmarka_redkollegiya && zip -qrX ../../../build/package/packages/plg_${g}_wmarka_redkollegiya.zip . -x '.*')
done
cp package/$PKG.xml package/script.php build/package/ && cp -r package/language build/package/
(cd build/package && zip -qrX ../../dist/${PKG}_v${VER}.zip .)
rm -rf build
SHA=$(sha256sum "dist/${PKG}_v${VER}.zip" | cut -d' ' -f1)
(cd dist && sha256sum ./*.zip > SHA256SUMS.txt)
cat > dist/updates.xml <<XML
<?xml version="1.0" encoding="utf-8"?>
<!-- Сервер обновлений Webmarka Редколлегии. Пишет tools/release.sh. -->
<updates>
    <update>
        <name>$NAME</name>
        <description>$NAME $VER</description>
        <element>$PKG</element>
        <type>package</type>
        <client>site</client>
        <version>$VER</version>
        <infourl title="$NAME $VER">https://github.com/$REPO/releases/tag/v$VER</infourl>
        <downloads>
            <downloadurl type="full" format="zip">https://github.com/$REPO/releases/download/v$VER/${PKG}_v${VER}.zip</downloadurl>
        </downloads>
        <sha256>$SHA</sha256>
        <changelogurl>https://raw.githubusercontent.com/$REPO/main/changelog.xml</changelogurl>
        <maintainer>Beibit Sakhanov</maintainer>
        <maintainerurl>https://webmarka.kz</maintainerurl>
        <targetplatform name="joomla" version="6\.[0-9]+"/>
        <php_minimum>8.3</php_minimum>
    </update>
</updates>
XML
echo "dist/${PKG}_v${VER}.zip"; cat dist/SHA256SUMS.txt
