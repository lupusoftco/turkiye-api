#!/bin/sh
# Builds dist/api: a folder you can upload as-is into a web directory
# (e.g. httpdocs/api so the API answers at https://example.com/api/).
#
#   sh tools/build-upload.sh            # needs composer
#
# Layout:
#   api/index.php, api/.htaccess, api/docs/   public
#   api/app/                                  code, vendor, data, storage (denied by .htaccess)
set -eu
cd "$(dirname "$0")/.."
OUT=dist/api
rm -rf dist && mkdir -p "$OUT/app/storage" "$OUT/docs"

cp public/index.php "$OUT/index.php"
cp deploy/subdir/.htaccess "$OUT/.htaccess"
cp public/docs/index.html "$OUT/docs/index.html"

cp -R src bin data "$OUT/app/"
cp composer.json openapi.yaml .env.example LICENSE README.md README.tr.md "$OUT/app/"
[ -f composer.lock ] && cp composer.lock "$OUT/app/"
cp deploy/subdir/app.htaccess "$OUT/app/.htaccess"
touch "$OUT/app/storage/.gitkeep"

(cd "$OUT/app" && composer install --no-dev --optimize-autoloader --no-interaction --no-progress)
rm -f "$OUT/app/composer.lock"
echo "Built $OUT — upload its contents to the target directory."
