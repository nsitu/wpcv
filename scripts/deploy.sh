#!/usr/bin/env bash
# Run on the GitHub-hosted Linux runner, after building dist/wpcv.zip.
set -euo pipefail

for name in VM_HOST VM_USER VM_SSH_KEY VM_KNOWN_HOSTS CAPROVER_SERVICE; do
  [[ -n "${!name:-}" ]] || { echo "Missing required configuration: $name" >&2; exit 1; }
done

VM_PORT=${VM_PORT:-22}
WP_PATH=${WP_PATH:-/var/www/html}
WP_RUN_USER=${WP_RUN_USER:-www-data}
[[ "$VM_HOST" =~ ^[a-zA-Z0-9][a-zA-Z0-9.-]*$ ]] || { echo 'VM_HOST must be an IPv4 address or hostname.' >&2; exit 1; }
[[ "$VM_USER" =~ ^[a-z_][a-zA-Z0-9_-]*$ ]] || { echo 'Invalid VM_USER.' >&2; exit 1; }
if [[ ! "$VM_PORT" =~ ^[0-9]{1,5}$ ]]; then
  echo 'Invalid VM_PORT.' >&2
  exit 1
fi
VM_PORT=$((10#$VM_PORT))
if (( VM_PORT < 1 || VM_PORT > 65535 )); then
  echo 'Invalid VM_PORT.' >&2
  exit 1
fi

repo_root=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
[[ -f "$repo_root/dist/wpcv.zip" ]] || { echo 'Build dist/wpcv.zip first.' >&2; exit 1; }
[[ -f "$repo_root/dist/wp-cli.phar" ]] || { echo 'Fetch the verified WP-CLI PHAR first.' >&2; exit 1; }

ssh_dir=$(mktemp -d)
remote_dir=''
cleanup() {
  local status=$?
  if [[ "$remote_dir" =~ ^/tmp/wpcv-deploy\.[a-zA-Z0-9]+$ ]]; then
    ssh "${ssh_options[@]}" -p "$VM_PORT" "$destination" "rm -rf -- '$remote_dir'" || true
  fi
  rm -rf -- "$ssh_dir"
  return "$status"
}
trap cleanup EXIT
chmod 700 "$ssh_dir"
printf '%s\n' "$VM_SSH_KEY" | tr -d '\r' > "$ssh_dir/key"
printf '%s\n' "$VM_KNOWN_HOSTS" | tr -d '\r' > "$ssh_dir/known_hosts"
chmod 600 "$ssh_dir/key" "$ssh_dir/known_hosts"

ssh_options=(
  -i "$ssh_dir/key"
  -o "UserKnownHostsFile=$ssh_dir/known_hosts"
  -o StrictHostKeyChecking=yes
  -o BatchMode=yes
  -o IdentitiesOnly=yes
  -o ConnectTimeout=15
  -o ServerAliveInterval=15
  -o ServerAliveCountMax=3
)
destination="$VM_USER@$VM_HOST"
remote_dir=$(ssh "${ssh_options[@]}" -p "$VM_PORT" "$destination" 'mktemp -d /tmp/wpcv-deploy.XXXXXXXXXX')
[[ "$remote_dir" =~ ^/tmp/wpcv-deploy\.[a-zA-Z0-9]+$ ]] || { echo 'Unexpected remote temporary directory.' >&2; exit 1; }
scp "${ssh_options[@]}" -P "$VM_PORT" \
  "$repo_root/dist/wpcv.zip" "$repo_root/dist/wp-cli.phar" "$destination:$remote_dir/"

# SSH runs a remote shell: quote each argument for the explicit Bash command.
printf -v remote_command 'bash -s -- %q %q %q %q' \
  "$remote_dir" "$CAPROVER_SERVICE" "$WP_PATH" "$WP_RUN_USER"
ssh "${ssh_options[@]}" -p "$VM_PORT" "$destination" "$remote_command" < "$repo_root/scripts/remote-install.sh"
