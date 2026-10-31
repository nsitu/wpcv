#!/usr/bin/env bash
# Executed on the CapRover Docker host by deploy.sh.
set -euo pipefail

staging_dir=${1:?Deployment staging directory is required}
service=${2:?CapRover Docker service name is required}
wp_path=${3:?WordPress container path is required}
wp_run_user=${4:?WordPress container user is required}

[[ "$wp_path" == /* && "$wp_path" != / ]] || { echo 'WP_PATH must be an absolute container directory.' >&2; exit 1; }
[[ -f "$staging_dir/wpcv.zip" && -f "$staging_dir/wp-cli.phar" ]] || { echo 'Deployment files are missing.' >&2; exit 1; }
command -v docker >/dev/null
if docker info >/dev/null 2>&1; then
  docker_cmd=(docker)
else
  docker_cmd=(sudo -n docker)
fi

# Use the actual service name: upgraded CapRover installations may retain prefixes.
replicas=$("${docker_cmd[@]}" service inspect "$service" --format '{{if .Spec.Mode.Replicated}}{{.Spec.Mode.Replicated.Replicas}}{{else}}0{{end}}')
[[ "$replicas" == 1 ]] || { echo 'Deployment requires a single-replica WordPress service.' >&2; exit 1; }
containers=$("${docker_cmd[@]}" ps --filter "label=com.docker.swarm.service.name=$service" --filter status=running --format '{{.ID}}')
[[ "$containers" =~ ^[a-f0-9]+$ ]] || { echo 'Expected exactly one running WordPress container on this host. Check CAPROVER_SERVICE and VM_HOST.' >&2; exit 1; }
container=$containers

# Use a temporary WP-CLI PHAR inside the existing container, with its PHP,
# database environment, network, and filesystem. The image need not include wp.
actual_user=$("${docker_cmd[@]}" exec --user "$wp_run_user" "$container" id -u)
[[ "$actual_user" != 0 ]] || { echo 'WP_RUN_USER must be a non-root container user.' >&2; exit 1; }
container_dir=$("${docker_cmd[@]}" exec --user "$wp_run_user" "$container" mktemp -d /tmp/wpcv-deploy.XXXXXXXXXX)
[[ "$container_dir" =~ ^/tmp/wpcv-deploy\.[a-zA-Z0-9]+$ ]] || { echo 'Unexpected container temporary directory.' >&2; exit 1; }
cleanup() {
  local status=$?
  "${docker_cmd[@]}" exec --user "$wp_run_user" "$container" rm -rf -- "$container_dir" || true
  return "$status"
}
trap cleanup EXIT
"${docker_cmd[@]}" cp "$staging_dir/wpcv.zip" "$container:$container_dir/wpcv.zip"
"${docker_cmd[@]}" cp "$staging_dir/wp-cli.phar" "$container:$container_dir/wp-cli.phar"
"${docker_cmd[@]}" exec --user 0 "$container" chmod 644 "$container_dir/wpcv.zip" "$container_dir/wp-cli.phar"

run_wp() {
  "${docker_cmd[@]}" exec --user "$wp_run_user" --workdir "$wp_path" \
    --env "WP_CLI_CACHE_DIR=$container_dir/cache" "$container" \
    php "$container_dir/wp-cli.phar" --path="$wp_path" "$@"
}

run_wp --skip-plugins --skip-themes core is-installed
plugin_dir=$(run_wp --skip-plugins --skip-themes eval 'echo realpath(WP_PLUGIN_DIR);')
[[ "$plugin_dir" == /* ]] || { echo 'Cannot resolve the WordPress plugin directory.' >&2; exit 1; }

# Only explicitly configured service volumes/bind mounts count. An anonymous
# image VOLUME can disappear on container replacement and is not sufficient.
mounts=$("${docker_cmd[@]}" service inspect "$service" --format '{{range .Spec.TaskTemplate.ContainerSpec.Mounts}}{{if and (not .ReadOnly) (or (eq .Type "volume") (eq .Type "bind"))}}{{println .Target}}{{end}}{{end}}')
persistent=false
while IFS= read -r mount; do
  mount=${mount%/}
  if [[ -n "$mount" && ( "$plugin_dir" == "$mount" || "$plugin_dir" == "$mount/"* ) ]]; then
    persistent=true
    break
  fi
done <<< "$mounts"
if [[ "$persistent" != true ]]; then
  echo 'Plugin directory is not covered by a writable persistent service mount. Configure it in CapRover before deploying.' >&2
  exit 1
fi

# Skip old plugin code during replacement so a broken version can be repaired.
run_wp --skip-plugins --skip-themes plugin install "$container_dir/wpcv.zip" --force
run_wp plugin activate wpcv
run_wp plugin is-active wpcv
run_wp plugin get wpcv --field=version
echo 'WPCV deployed to the persistent WordPress volume and active.'
