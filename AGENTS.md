## Repo

The Dolibarr module restoauth (GPL-3.0-or-later). The module files sit at the repo root, laid out
the way Dolibarr expects them inside `htdocs/custom/restoauth`. Only Dolibarr 23 is supported.
Docs, code comments and commit messages are in English; the module keeps its `de_DE` and `en_US`
language files.

Tests never run locally on this machine. Only the pane `vm-runner` talks to the VM. A ticket agent never runs `ssh` or `scripts/test-on-vm.sh` itself: it commits, queues a request in the workspace's `.vm-queue/` (`../.vm-queue/README.md` from the main checkout's root is the protocol) and waits for the result. See `docs/agents/test-vm.md` (Only `vm-runner` uses the VM) and `docs/agents/issue-tracker.md` (Testing through the VM queue). The script itself (`scripts/test-on-vm.sh` for the PHP lint) is described in test-vm.md for the runner.

## Agent skills

### Issue tracker

Issues and specs live as local markdown files under `.scratch/<feature>/`. `.scratch/` is git-ignored and only exists in the main checkout; from a git worktree, reach it as described in `docs/agents/issue-tracker.md`.

### Triage labels

Default vocabulary: `needs-triage`, `needs-info`, `ready-for-agent`, `ready-for-human`, `wontfix`. See `docs/agents/triage-labels.md`.

### Domain docs

Single-context: `GLOSSARY.md` and `docs/adr/` at the repo root, created lazily. See `docs/agents/domain.md`.

### Test VM

For anything that needs PHP or Docker (this machine has neither), there is a test VM reachable over SSH. See `docs/agents/test-vm.md`.
