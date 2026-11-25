#!/usr/bin/env bash
# Run the PHP lint on the test VM, never locally. Copies the current working tree (tracked and
# untracked files, not git-ignored ones, so uncommitted changes come along) to a folder of its own
# on the VM, runs scripts/lint-php.sh there (php -l in a throwaway php:8.3-cli container), prints
# the result here, removes the folder and container again (also on failure or Ctrl-C) and exits
# with the lint's exit code. See docs/agents/test-vm.md.
#
#   bash scripts/test-on-vm.sh
#
# Environment:
#   RUN            name of this run: lowercase letters, digits, - (default: dev-<random>).
#                  Used for the VM folder /root/dolibarr-restoauth-$RUN and the container $RUN-lint.
#   VM_HOST        default root@192.168.178.25
#   VM_KEY         default ~/.ssh/itop-mcp-test-vm
#   VM_KNOWN_HOSTS default ~/.ssh/itop-mcp-test-vm.known_hosts
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

if [ "$#" -gt 0 ]; then
  echo "test-on-vm: takes no arguments (the lint always checks every PHP file)" >&2
  exit 2
fi

RUN="${RUN:-dev-$(od -An -N4 -tx1 /dev/urandom | tr -d ' \n')}"
if ! [[ "$RUN" =~ ^[a-z0-9][a-z0-9-]*$ ]]; then
  echo "test-on-vm: RUN must be lowercase letters, digits and - (got '$RUN')" >&2
  exit 2
fi
DIR="/root/dolibarr-restoauth-$RUN"
CONTAINER="$RUN-lint"

SSH=(ssh -i "${VM_KEY:-$HOME/.ssh/itop-mcp-test-vm}"
  -o "UserKnownHostsFile=${VM_KNOWN_HOSTS:-$HOME/.ssh/itop-mcp-test-vm.known_hosts}"
  -o BatchMode=yes -o ConnectTimeout=10 "${VM_HOST:-root@192.168.178.25}")

cleanup() {
  "${SSH[@]}" "docker rm -f $CONTAINER >/dev/null 2>&1; rm -rf $DIR" || \
    echo "test-on-vm: cleanup failed, remove $DIR and container $CONTAINER on the VM by hand" >&2
}

echo "test-on-vm: run $RUN, folder $DIR"
# One SSH call checks and creates the folder, so two runs with the same RUN cannot both pass the
# check. ssh exits 255 when it cannot connect or log in; only exit 1 comes from the remote test.
# The cleanup trap is set only after the folder is ours: exit 2 and exit 255 never remove anything.
check=0
"${SSH[@]}" "test ! -e $DIR && mkdir $DIR" || check=$?
if [ "$check" -eq 255 ]; then
  echo "test-on-vm: cannot reach the VM over SSH (${VM_HOST:-root@192.168.178.25}), see the ssh error above" >&2
  exit 255
elif [ "$check" -ne 0 ]; then
  echo "test-on-vm: $DIR already exists on the VM (another run with RUN=$RUN?), pick another RUN" >&2
  exit 2
fi
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

git ls-files -z --cached --others --exclude-standard | tar --null -T - -cf - \
  | "${SSH[@]}" "tar -xf - -C $DIR"

status=0
"${SSH[@]}" "cd $DIR && LINT_CONTAINER=$CONTAINER bash scripts/lint-php.sh" || status=$?
echo "test-on-vm: lint exit code $status"
exit "$status"
