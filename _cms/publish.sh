#!/bin/bash
# Publishes the Aeris Clean website → https://www.aerisclean.in
#
# 1. Builds index.html from _cms/template.html + the content files in _cms/data
# 2. Commits the page, product images and content to GitHub (branch main)
# 3. A GitHub Action rebuilds and publishes the site about a minute later
#    (the same happens after edits in the online admin, https://www.aerisclean.in/admin/).
# Run from Terminal:  "_cms/publish.sh"   or click "Publish to website" in the admin.
set -euo pipefail
export PATH="/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin:$PATH"

CMS="$(cd "$(dirname "$0")" && pwd)"
SITE="$(dirname "$CMS")"
cd "$SITE"

step() { printf '• %s\n' "$1"; }
fail() { printf 'ERROR: %s\n' "$1" >&2; exit 1; }

step "Getting the latest changes from GitHub (including edits made in the online admin)"
git pull -q --rebase --autostash origin main || fail "Could not merge changes from GitHub. Open Terminal in this folder and run: git status"

step "Building the page"
php "$CMS/build.php" || fail "Could not build index.html"

# Only the website files and the content are published (private files are excluded by .gitignore).
git add .gitignore index.html assets/products _cms admin .github
if git diff --cached --quiet; then
  step "No changes since the last publish — the website is already up to date."
else
  git commit -qm "Update products from admin ($(date '+%Y-%m-%d %H:%M'))"
  step "Uploading to GitHub"
  git pull -q --rebase origin main || fail "Could not merge changes from GitHub. Open Terminal in this folder and run: git status"
  git push -q origin main || fail "Could not upload to GitHub. Check your internet connection and GitHub login."
  step "Published. The website updates in about 1 minute: https://www.aerisclean.in/"
fi
date '+%Y-%m-%d %H:%M' > "$CMS/data/last-publish.txt"
