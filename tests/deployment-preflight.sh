#!/usr/bin/env bash
# Exercise Docker discovery without contacting Docker or modifying WordPress.
set -euo pipefail

repo_root=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
test_dir=$(mktemp -d)
trap 'rm -rf -- "$test_dir"' EXIT
touch "$test_dir/wpcv.zip" "$test_dir/wp-cli.phar"
export MOCK_LOG="$test_dir/docker.log"
export MOCK_CONTAINERS=''
export MOCK_REPLICAS=1

docker() {
  printf '%s\n' "$*" >> "$MOCK_LOG"
  case "$1 ${2:-}" in
    'info ') return 0 ;;
    'service inspect')
      [[ "$3" == srv-captain--wordpress-wordpress ]] || return 1
      if [[ "${!#}" == '{{.Spec.Name}}' ]]; then
        echo srv-captain--wordpress-wordpress
      else
        echo "$MOCK_REPLICAS"
      fi
      ;;
    'service ps') echo 'MOCK TASK NODE STATE' ;;
    'service ls') echo 'MOCK AVAILABLE SERVICES' ;;
    'ps -a') echo 'MOCK LOCAL CONTAINERS' ;;
    'ps --filter') printf '%s' "$MOCK_CONTAINERS" ;;
    *) echo "Unexpected Docker mutation: $*" >&2; return 99 ;;
  esac
}
export -f docker

run_failure() {
  local service=$1 expected=$2 output status=0
  : > "$MOCK_LOG"
  output=$(bash "$repo_root/scripts/remote-install.sh" "$test_dir" "$service" /var/www/html www-data 2>&1) || status=$?
  if [[ "$status" != 1 || "$output" != *"$expected"* ]]; then
    printf 'Unexpected result (exit %s):\n%s\n' "$status" "$output" >&2
    exit 1
  fi
  if grep -Eq '^(cp|exec) ' "$MOCK_LOG"; then
    echo 'Container discovery failure must not modify the container.' >&2
    exit 1
  fi
}

# Whitespace is removed before service inspection, and zero matches are explicit.
run_failure $' \tsrv-captain--wordpress-wordpress\r\n' 'No running WordPress container'
grep -q '^service ps srv-captain--wordpress-wordpress --no-trunc$' "$MOCK_LOG"
grep -q '^ps -a --filter label=com.docker.swarm.service.name=srv-captain--wordpress-wordpress ' "$MOCK_LOG"

export MOCK_CONTAINERS=$'abcdef123456\n123456abcdef'
run_failure srv-captain--wordpress-wordpress 'Multiple running WordPress containers'

export MOCK_CONTAINERS='not-a-container-id'
run_failure srv-captain--wordpress-wordpress 'Unexpected Docker container ID output'

export MOCK_REPLICAS=2
run_failure srv-captain--wordpress-wordpress 'configured replicas: 2'

run_failure unknown-service 'Cannot resolve CAPROVER_SERVICE'
grep -q '^service ls ' "$MOCK_LOG"

run_failure '   ' 'CAPROVER_SERVICE must contain a Docker service name'
[[ ! -s "$MOCK_LOG" ]]

echo 'PASS: service whitespace, zero/multiple/invalid container IDs, replicas, missing service, diagnostic output, and no container changes on failure.'
