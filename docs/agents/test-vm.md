# Test VM (Docker host)

A throwaway VM on the maintainer's LAN for anything that needs PHP or Docker. This dev machine has
neither PHP, Docker nor WSL. It is the same VM the `itop-mcp-server` and `dolibarr-mcp-server`
repos use.

| | |
| --- | --- |
| Host | `192.168.178.25` (only reachable from the maintainer's network) |
| User | `root` |
| OS | Debian 13 (trixie), Docker 29, Compose v5, 4 CPUs, 12 GB RAM minimum with ballooning (about 11 GB visible), no swap, 59 GB root disk |
| Private key | `~/.ssh/itop-mcp-test-vm` (on the maintainer's machine, **never commit it**) |
| Host key | `~/.ssh/itop-mcp-test-vm.known_hosts` |

No password is written down anywhere. If the key is missing or rejected, ask the maintainer. Don't
try to guess or recover a password.

## Tests always run on the VM

Run every test on the VM, never locally on the Windows dev machine: it is slow, has no admin
rights and a heavy virus scanner.

- **PHP lint** (`scripts/lint-php.sh`): on the VM, with `scripts/test-on-vm.sh` (below).
- **End-to-end tests of the module**: in the test stack of `dolibarr-mcp-server` (Dolibarr 23,
  Keycloak, LibreChat), which installs restoauth from a pinned tag. See that repo's
  `docs/agents/test-vm.md` and `test-stack/README.md`.

## One command: `scripts/test-on-vm.sh`

From Git Bash in your checkout or worktree:

```sh
RUN=ro16 bash scripts/test-on-vm.sh
```

The script does the steps below for you: it copies the working tree to
`/root/dolibarr-restoauth-$RUN`, runs `scripts/lint-php.sh` there (container `$RUN-lint`), prints
the output, removes the folder and the container again, also on failure or Ctrl-C, and exits with
the lint's exit code. It refuses to start if the folder already exists, so it never touches
another run. Without `RUN` it picks a random `dev-<hex>` name. The lint needs no port. `VM_HOST`,
`VM_KEY` and `VM_KNOWN_HOSTS` override the connection settings in the table above.

The sections below are the manual steps, for debugging.

## Connect

```sh
SSH="ssh -i ~/.ssh/itop-mcp-test-vm -o UserKnownHostsFile=~/.ssh/itop-mcp-test-vm.known_hosts -o BatchMode=yes root@192.168.178.25"
$SSH
```

## Sharing the VM

The VM's RAM, not the number of agents, limits how many runs fit at once. It has no swap; when RAM runs out, SSH stops answering for many minutes and the kernel kills containers of other runs.

- A **ticket stack** is a full test stack started for one ticket under its own RUN name. Starting one takes a **VM slot**.
- This repo starts no ticket stack (the PHP lint is one small container), but these rules hold for everyone on the VM.
- At most 3 ticket stacks run at the same time. Start one only if at least 3 GB are available: `$SSH "free -m | awk '/^Mem:/{print \$7}'"` must print 3072 or more. If not, wait or ask the orchestrator.
- Take your stack down right after the run, also when it failed: `docker compose -p $RUN down -v --remove-orphans`, then remove your images (see Clean up).
- Never stop, change or read stacks, folders or files you did not create, including other stacks' `.env` and key files. Some stacks on the VM run permanently on purpose.

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
