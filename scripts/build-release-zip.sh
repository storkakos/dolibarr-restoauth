#!/usr/bin/env bash
# Build the release ZIP module_restoauth-<version>.zip from a git tag, in the format Dolibarr's
# module upload expects (Setup → Modules → Deploy/install external module): one top-level folder
# `restoauth/` with the module files inside.
#
#   bash scripts/build-release-zip.sh v0.1.0          # writes dist/module_restoauth-0.1.0.zip
#
# The tag must be named v<version>, and <version> must equal $this->version in the module
# descriptor at that tag. The ZIP is built with `git archive` from the tagged tree only, so
# uncommitted changes never end up in it, and the same tag always gives the same bytes (file times
# come from the tagged commit). Repo-only files are left out via export-ignore in .gitattributes.
#
# Environment:
#   OUT_DIR   folder for the ZIP (default dist/ in the repo root)
set -euo pipefail

if [ "$#" -ne 1 ]; then
  echo "usage: bash scripts/build-release-zip.sh <tag>   (for example v0.1.0)" >&2
  exit 2
fi
TAG="$1"

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

if ! git rev-parse -q --verify "refs/tags/$TAG" >/dev/null; then
  echo "build-release-zip: tag '$TAG' not found" >&2
  exit 1
fi

if [[ ! "$TAG" =~ ^v([0-9]+\.[0-9]+\.[0-9]+)$ ]]; then
  echo "build-release-zip: tag '$TAG' is not of the form vX.Y.Z" >&2
  exit 1
fi
VERSION="${BASH_REMATCH[1]}"

DESCRIPTOR="core/modules/modRestOAuth.class.php"
DESC_VERSION="$(git show "$TAG:$DESCRIPTOR" \
  | sed -n "s/^[[:space:]]*\$this->version[[:space:]]*=[[:space:]]*'\([^']*\)'.*/\1/p" | head -n 1)"
if [ -z "$DESC_VERSION" ]; then
  echo "build-release-zip: no \$this->version found in $DESCRIPTOR at $TAG" >&2
  exit 1
fi
if [ "$DESC_VERSION" != "$VERSION" ]; then
  echo "build-release-zip: tag $TAG says $VERSION, but $DESCRIPTOR at $TAG says $DESC_VERSION" >&2
  exit 1
fi

OUT_DIR="${OUT_DIR:-$ROOT/dist}"
mkdir -p "$OUT_DIR"
ZIP="$OUT_DIR/module_restoauth-$VERSION.zip"
rm -f "$ZIP"

git archive --format=zip --prefix=restoauth/ -o "$ZIP" "$TAG"

# Sanity check: the descriptor must sit where Dolibarr looks for it.
# (List first, then grep: `grep -q` in the pipe would end it early and trip pipefail.)
LISTING="$(git archive --format=tar --prefix=restoauth/ "$TAG" | tar -tf -)"
if ! grep -qx "restoauth/$DESCRIPTOR" <<<"$LISTING"; then
  echo "build-release-zip: restoauth/$DESCRIPTOR missing from the archive" >&2
  rm -f "$ZIP"
  exit 1
fi

echo "build-release-zip: $ZIP"
if command -v sha256sum >/dev/null 2>&1; then
  sha256sum "$ZIP"
fi
