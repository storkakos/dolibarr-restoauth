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

## Only `vm-runner` uses the VM

When several agents work at once, exactly one session, the pane **`vm-runner`**, talks to the VM. **Ticket agents never run `ssh` or `scripts/test-on-vm.sh` themselves**, not even to look. They commit, queue a request and wait for the result file. Each agent starting its own runs made them slow and fought over the VM's RAM; one runner with a queue does not.

The queue is the folder `.vm-queue/` in the workspace, next to the repos: `../.vm-queue/` from the main checkout's root. From a worktree, go through the main checkout (see [issue-tracker.md](issue-tracker.md#where-scratch-lives)):

```sh
MAIN="$(dirname "$(git rev-parse --path-format=absolute --git-common-dir)")"
QUEUE="$MAIN/../.vm-queue"
cat "$QUEUE/README.md"
```

**`../.vm-queue/README.md` is the single source of the protocol.** Read it before you queue; if this summary and the README ever disagree, the README wins. In short:

- **Request** `<agent>.md`, written only after the commit: `agent`, `repo` (`dolibarr-restoauth`), `branch`, `commit`, `target` (the branch you will be merged into), `kind` (`batch` or `single`), `needs` (`lint`), `proves` (which criteria the run proves). This repo has no test stack, so a request from here always has `needs: lint` and no `variant`, `checks` or `llm` fields. One request per agent; to re-queue, overwrite it with the new commit and delete your old result.
- **`batch`** (default): the runner may merge your branch with other queued branches of the same repo into a temporary integration branch (from `target`) and test them all in one run. **`single`**: a run that cannot be shared; you give the exact `## Steps`.
- **Result** `<agent>.result.md`, written only by `vm-runner`: `commit`, `status` (`PASS`, `FAIL`, `CONFLICT`, `ERROR`), `run` (the RUN name and what it included), `summary` and an excerpt. What to do with each status is in [issue-tracker.md](issue-tracker.md#testing-through-the-vm-queue).
- **Finding the culprit**: when a batch run fails, the runner reruns the failing check per branch. The branch that fails alone gets `FAIL` with its lines; the others get `PASS`. A branch that does not merge cleanly gets `CONFLICT` and is run alone or sent back for a rebase.
- A restoauth change that needs a test-stack run is tested in dolibarr-mcp-server's stack: write the request with `repo: dolibarr-mcp-server`, `kind: single`, `needs: stack`, name your restoauth branch and commit in `proves`, and add `## Steps` that build the stack with `RESTOAUTH_LOCAL` pointing at a checkout of that commit (dolibarr-mcp-server's `test-stack/README.md`, "restoauth from a local ZIP or checkout").

### What the runner takes care of

These are `vm-runner`'s job, not the ticket agent's:

- the VM rules in [Sharing the VM](#sharing-the-vm): at most 3 stacks at once, a VM slot per stack, enough free RAM;
- unique RUN names across repos (prefixed `it-`, `dl-`, `ra-`);
- the shared LLM key on the VM (`/root/shared/llm.env`) for stack runs in dolibarr-mcp-server: pointing runs at it, never printing it;
- the [clean-up](#clean-up) after every run: stacks, volumes, networks, images, folders and slots of its RUNs, plus its integration branches and worktrees (local only, never pushed).

The rest of this file is the reference for the runner, and for the maintainer working alone without the queue.

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
another run. If the VM cannot be reached, it stops with ssh's error and exit code 255. Without
`RUN` it picks a random `dev-<hex>` name. The lint needs no port. `VM_HOST`, `VM_KEY` and
`VM_KNOWN_HOSTS` override the connection settings in the table above.

The sections below are the manual steps, for debugging.

## Connect

```sh
SSH="ssh -i ~/.ssh/itop-mcp-test-vm -o UserKnownHostsFile=~/.ssh/itop-mcp-test-vm.known_hosts -o BatchMode=yes root@192.168.178.25"
$SSH
```

## Sharing the VM

This section is `vm-runner`'s job (see [Only `vm-runner` uses the VM](#only-vm-runner-uses-the-vm)). The VM's RAM, not the number of agents, limits how many runs fit at once. It has no swap; when RAM runs out, SSH stops answering for many minutes and the kernel kills containers of other runs.

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
