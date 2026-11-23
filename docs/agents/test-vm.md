# Test VM (Docker host)

A throwaway VM on the maintainer's LAN for anything that needs PHP or Docker. This dev machine has
neither PHP, Docker nor WSL. It is the same VM the `itop-mcp-server` and `dolibarr-mcp-server`
repos use.

| | |
| --- | --- |
| Host | `192.168.178.25` (only reachable from the maintainer's network) |
| User | `root` |
| OS | Debian 13 (trixie), Docker 29, Compose v5, 4 CPUs, about 16 GB RAM, 20 GB disk |
| Private key | `~/.ssh/itop-mcp-test-vm` (on the maintainer's machine, **never commit it**) |
| Host key | `~/.ssh/itop-mcp-test-vm.known_hosts` |

No password is written down anywhere. If the key is missing or rejected, ask the maintainer. Don't
try to guess or recover a password.

## What runs where

- **PHP lint** (`scripts/lint-php.sh`): on the VM, or locally if a `php` binary is on the `PATH`.
- **End-to-end tests of the module**: in the test stack of `dolibarr-mcp-server` (Dolibarr 23,
  Keycloak, LibreChat), which installs restoauth from a pinned tag. See that repo's
  `docs/agents/test-vm.md` and `test-stack/README.md`.

## Connect

```sh
SSH="ssh -i ~/.ssh/itop-mcp-test-vm -o UserKnownHostsFile=~/.ssh/itop-mcp-test-vm.known_hosts -o BatchMode=yes root@192.168.178.25"
$SSH
```

## Your own run: folder name

Several agents may use the VM at the same time. Every run picks one name of its own (lowercase
letters, digits and `-`), e.g. `ro02` for ticket 02, and uses it for its folder and container:

```sh
RUN=ro02                                # your own name: lowercase, digits, -
DIR=/root/dolibarr-restoauth-$RUN       # your own folder on the VM
```

Never use a bare `/root/dolibarr-restoauth` folder when others may be on the VM.

## Copy the working tree over

This copies tracked and untracked files but not git-ignored ones, so uncommitted changes come
along. It replaces only your own folder:

```sh
git ls-files -z --cached --others --exclude-standard | tar --null -T - -cf - \
  | $SSH "rm -rf $DIR && mkdir $DIR && tar -xf - -C $DIR"
```

`.gitattributes` gives PHP, SQL, shell and the other text files LF line endings, also on Windows
with `core.autocrlf=true`, so the copy runs on Linux as-is.

## Run the PHP lint

```sh
$SSH "cd $DIR && LINT_CONTAINER=$RUN-lint bash scripts/lint-php.sh"
```

Without a local `php`, the script runs `php -l` in a throwaway `php:8.3-cli` container (`--rm`)
with the folder mounted read-only.

## Clean up

Remove only what you created yourself:

```sh
$SSH "docker rm -f $RUN-lint 2>/dev/null; rm -rf $DIR"
```

Keep the shared things in place, even if they look unused: the `php:8.3-cli` and
`python:3.13-slim` images and other runs' images, containers, networks and folders. Never run
`docker system prune`, `docker image prune -a`, `docker builder prune` or
`docker rm -f $(docker ps -aq)`: they hit every run on the VM, not just yours.

## Notes

- The VM exists only for testing and may be wiped or recreated at any time. If the host key
  changed, ask the maintainer before you trust the new one.
