#!/usr/bin/env bash
# Fetch the same checksum-pinned WP-CLI for validation and deployment.
set -euo pipefail
repo_root=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
mkdir -p "$repo_root/dist"
curl --fail --location --silent --show-error --retry 3 \
  https://github.com/wp-cli/wp-cli/releases/download/v2.12.0/wp-cli-2.12.0.phar \
  --output "$repo_root/dist/wp-cli.phar"
printf 'ce34ddd838f7351d6759068d09793f26755463b4a4610a5a5c0a97b68220d85c  %s\n' \
  "$repo_root/dist/wp-cli.phar" | sha256sum --check
