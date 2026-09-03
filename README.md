# beeblebrox-local

A worker for a [Beeblebrox](https://beeblebrox.cloud) instance that runs on your own machine.

The instance runs the pipeline — tickets, roles, flows, who does what next. This runs the work: in
your own checkouts, with your own agent, under your own keys. The only thing that leaves the machine
is the result.

It is one small PHP application with no build step, no database server and no dependencies beyond
PHP itself. It is meant to be handed to a customer as it is.

## What it does

```
  instance                                  this machine
  ────────                                  ────────────
  dispatcher ──── signed envelope ────────▶ hook.php        writes down that a task exists,
                                                            and answers. Nothing else.
             ◀─── "what is open?" ───────── tools/run.php   the other way in, and the one that
                                                            needs nothing inbound at all

             ◀─── claim ──────────────────  tools/run.php   succeeds exactly once
             ──── the whole ticket ───────▶
                                            the agent       runs in the directory you mapped to
                                                            that project, writes a result file
             ◀─── status + output ────────
```

Two ways in, and you can use either or both:

- **Webhooks.** The instance posts a signed envelope naming a task. Needs the instance to be able to
  reach this machine — a port forward, or a tunnel.
- **Polling.** This asks the instance for its own work. Needs no inbound networking, which is why it
  is on by default.

**One instance can drive several of these.** Each machine is a dispatcher on the instance and holds a
key that belongs to it, so asking for open tasks returns that machine's work and nobody else's. Which
work that is gets decided there, per role and per project — this end is never told what to ignore,
which means there is no second copy of the answer to keep in step.

Either way the envelope names the task and never carries the work. The briefing is fetched
separately with this machine's own API key, which keeps it out of transit and out of anybody's logs.

## What it will not do

- **Guess where to run.** A project with no directory mapped on the projects page stops and says so.
  Running an agent in a plausible-looking directory is the one mistake that is expensive to undo.
- **Name a role.** A worker reports a status; the flow on the instance decides what follows. A status
  the flow does not accept is caught here, with what was said and what was on offer, rather than sent
  and refused.
- **Lose a result.** Reporting is retried on its own schedule for hours. If it finally gives up, the
  output stays on the job page and the task stays claimed on the instance, where it shows as stale.
- **Accept an unsigned envelope.** Without a signing secret every delivery is refused. An envelope
  eventually starts an agent with file and command access on your machine, and that is not something
  to be casual about.
- **Send its API key anywhere but the instance.** Every URL in an envelope is checked against the
  configured instance before it is fetched, and redirects are never followed. The signature proves
  who sent it; this is what still holds if the signing secret ever leaks.

## Getting started

`INSTALL.md`, which covers the vhost, the API key, the dispatcher and the scheduled
task that makes any of it happen on its own.

The short version:

```bash
cp config.local.example.php config.local.php   # set site_url and a secret_key
```

The database is one SQLite file that writes itself the first time you open a page. There is nothing
to create and nothing to import — but it holds session ids and your password hash, so read
`INSTALL.md` section 3 about not serving it.

Then open the site and set a password. That drops you into a four-question setup — which Beeblebrox
this machine works for, a key to talk to it with, how work arrives, and what runs it — each answer
checked against the instance before it moves on.

## Layout

| | |
|---|---|
| `index.php` | landing page signed out, dashboard signed in |
| `setup.php` | the four questions, in the order they depend on each other |
| `hook.php` | the webhook receiver — the only thing the instance talks to |
| `jobs.php`, `job.php` | what this machine has been asked to do, and one of them in full |
| `projects.php` | which directory each upstream project's work happens in |
| `settings.php` | everything a person configures |
| `diagnostics.php` | every check, plus the envelopes that arrived |
| `tools/run.php` | the runner. Nothing happens until this is on a schedule |
| `tools/selftest.php` | the same checks from a terminal, as the account the schedule uses |
| `tools/migrate.php` | applies `db/migrations/*.sql` once each |
| `lib/` | the pieces: `runner`, `agent`, `upstream`, `security`, `jobs`, `settings` |
| `tests/` | `smoke.php` needs nothing; `hook.php` needs a running server |

## Branches

`main` and `beta`, matching the instance each talks to. There is no deployment: a checkout on a
machine is the install. Run two if you work both a beta and a production instance — each with its own
vhost and its own `config.local.php`, which is enough to give each its own database file.

## Tests

```bash
timeout 60 php tests/smoke.php
timeout 120 php tests/hook.php http://127.0.0.1:8774
timeout 120 php tools/selftest.php
```

`tests/hook.php` posts real signed envelopes at a running server, good and bad, and checks what is
refused as carefully as what is accepted. It borrows the configured signing secret and puts back what
it found, and cleans up after itself.
