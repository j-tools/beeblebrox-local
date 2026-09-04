# Installing beeblebrox-local

Everything here is done once, on the machine that will do the work. It takes about twenty minutes,
and the diagnostics page tells you which parts you have not done yet at every point along the way.

Written for XAMPP on Windows, because that is what it was built on. Anything that serves PHP 8.1+
will do — Apache, nginx, or `php -S` for a look around. There is no database server to install.

---

## 1. Put the files somewhere

```bash
git clone git@github.com:j-tools/beeblebrox-local.git
cd beeblebrox-local
```

If you work both a beta and a production instance, clone it twice — one on `main`, one on `beta` —
and give each its own vhost and its own `config.local.php`. They share nothing, which is the point.

## 2. Nothing to configure by hand

Skip this and go to section 3 — it is here to say why there is no step.

**The database creates itself.** One SQLite file, written the first time anything opens a page, with
every migration already folded in. Later versions arrive as files in `db/migrations/` and are applied
with `php tools/migrate.php`.

**`config.local.php` writes itself too.** The first thing the setup page asks is the address you
reach it on — prefilled from how you got there — and it generates the key that encrypts stored
secrets, because 32 random bytes is not a decision anybody needs to make. If the directory is not
writable by the web server it shows you the finished file to save yourself, key and all.

That file is gitignored, and it is a `.php` rather than a `.key` on purpose: it sits in the directory
being served, and a server that has stopped running PHP would hand over a plain file as text while
this one outputs nothing.

The two values it does not ask about, because they have working defaults:

| | |
|---|---|
| `db_file` | where the database goes. Default `data/local.sqlite` — see section 3 |
| `job_root` | per-job working files: the prompt, the raw output, the result. Default `jobs/`. Worth moving off a synced drive, since an agent run writes to it continuously |

`config.local.example.php` documents both if you would rather set them before you start.

## 3. Stop the world reading the database

By default the file is `data/local.sqlite`, inside the directory being served. It holds session ids
and the password hash for these pages, so **serving it hands whoever downloads it a way in**.

The shipped `data/.htaccess` denies it on Apache, and only where `AllowOverride` permits. Nothing
else honors that file — nginx does not, and neither does PHP's built-in server. So on nginx:

```
location ~ ^/(data|lib|db|tools|tests)/ { deny all; }
```

Or sidestep the question entirely by putting the file outside the directory being served, which is
the one answer a later config change cannot quietly undo:

```php
'db_file' => 'C:/beeblebrox-local/local.sqlite',
```

Do not take any of this on trust. The diagnostics page fetches the file over the web from outside and
**fails** if it comes back, naming the URL it got it from.

## 4. Serve it

Add the hostname to `C:\Windows\System32\drivers\etc\hosts` — needs an editor running as
administrator:

```
127.0.0.1  local.beeblebrox.cloud
```

Then a vhost in `C:\xampp\apache\conf\extra\httpd-vhosts.conf`:

```apache
<VirtualHost *:80>
    ServerName local.beeblebrox.cloud
    DocumentRoot "C:/path/to/beeblebrox-local"
    <Directory "C:/path/to/beeblebrox-local">
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require local
    </Directory>
    ErrorLog  "logs/beeblebrox-local-error.log"
    CustomLog "logs/beeblebrox-local-access.log" common
</VirtualHost>
```

`Require local` keeps it to this machine. Take it out only when you are deliberately exposing the
receiver, and read section 8 first if you are.

**If this is the first vhost on the server, add a catch-all above it.** Apache serves the first
`<VirtualHost>` to every request that matches no other `ServerName`, so defining one takes over
everything the plain `DocumentRoot` used to answer:

```apache
<VirtualHost *:80>
    ServerName localhost
    DocumentRoot "C:/xampp/htdocs"
</VirtualHost>
```

Point that at whatever the server's `DocumentRoot` in `httpd.conf` already is, and put it before the
one above.

Restart Apache and open it — at `http://localhost/...` if this worker is only ever used from this
machine, or at `https://local.beeblebrox.cloud` with a certificate if anybody else reaches it. Plain
HTTP on a name like `local.beeblebrox.cloud` is refused even though it resolves to 127.0.0.1 — the
paragraph below says why.

Set a password. That password is the only
thing between this machine's API key and anybody else who can reach the address. If you ever lose it,
`php tools/reset-password.php` on this machine forgets it and ends every signed-in session, and the
next visit sets a new one — there is no email here to send a link to.

**It has to be `https://`, unless it is `localhost`.** Setup refuses anything else. These pages hold
this worker's password, your instance's API key and the signing secret, and a worker is a thing that
runs commands — so on any address other people can reach, all of that has to be encrypted. A worker
on your own machine opened at `http://localhost` is exempt, because that traffic never leaves the
machine; it is the same test a browser uses to decide what counts as a secure context, and it is the
ordinary answer for a worker on a laptop.

A name that merely resolves to `127.0.0.1` is not exempt. Whether it does is a DNS answer that can
change, and `worker.example.internal` is exactly the case where somebody believes it is local and it
is not.

**Reach it at the address you will keep using.** The session cookie takes its `Secure` flag from the
address confirmed in setup, never from the request, so that a forged `Host` header cannot weaken it.
The cost is that visiting an install configured for `https://` over plain HTTP produces a cookie the
browser is right to refuse to send back — and the only symptom is the sign-in form returning as
though the password were wrong. The sign-in page says so when it detects it, but it is easier not to
cause.

Setting it drops you straight into setup. It confirms the address you reached it on and writes
`config.local.php` for you, then asks four things: which Beeblebrox this machine works for, a key to
talk to it with, how work should arrive, and what runs it. Each answer is checked before it moves on,
so a wrong instance name or a refused key is caught while you are still looking at it. The next three
sections are those four questions in longer form — read them if a choice is not obvious, skip them if
it is.

For a quick look without Apache, `php -S 127.0.0.1:8774 -t .` serves it just as well.

## 5. Tell the instance this worker exists, and get a key

Set the **instance URL** on the settings page here first and press Save. A link to that instance's key
page appears under the API key box, which saves assembling the address by hand.

Then, on the instance, sign in as a **company admin** — the only permission that can do either of the
next two things.

**Make a dispatcher for this machine.** Dispatchers in the menu → **New dispatcher**. Name it after
the machine, give it a short name like `laptop`, and set **How it is reached** to **pull**: this
machine comes and asks, which is what a machine behind a router has to do. A pull worker needs no URL
and no signing secret. Choose **webhook** only if the instance can actually reach this address — see
section 8.

**Then issue it a key.** API keys in the menu → **New key**:

1. Name it after this machine, so a year from now it is obvious which one it is.
2. Permission **task creator** — read everything, claim, and report, which is exactly what a worker
   needs and nothing more.
3. **Belongs to a worker**: the dispatcher you just made. This is the important one. A key that
   belongs to a worker is shown only that worker's tasks, so this machine never has to be told what
   to ignore — and a key that belongs to none is shown every machine's work, which looks identical to
   working correctly right up until there are two workers.
4. Optionally pin it to one project. Only do that if this machine works that project and nothing
   else: a pinned key cannot touch work that has no project yet, which is everything at triage.

Which work this worker actually gets is then decided entirely on the instance, by pointing roles at
that dispatcher — per role, and per project if you take a project's own copy of a role. Nothing here
needs changing when that changes, and deactivating the dispatcher stops the worker without touching
this machine at all.

The key is shown once, on that page, and only a hash is kept — so copy it straight into the API key
box here. If it goes missing, revoke it there and issue another.

Then press **Test the connection**. It saves first, then says whether the instance answered and
whether the key was accepted, so you find out now rather than at the next runner pass.

There is deliberately no way to generate a key from this side. Asking the instance for one would need
a credential to authenticate the request, which is the problem being solved.

If you are running the instance and no admin account exists yet, that is a provisioning step on the
instance itself — `tools/init-instance.php` there — not something this end can do.

## 6. Map your projects

The instance says a task belongs to project 7. Only this machine knows that project 7 is a checkout
in `C:\work\invoicing`. Add each on the projects page, using the project id from the instance's
project page URL.

A **prepare command** runs in the workspace before every agent run, so each one starts from a known
state rather than from whatever the last one left behind. It is split like a command line, not run
through a shell — no pipes, no `&&`. If it fails, the agent is not started.

```
git fetch --all
```

Nothing is guessed here on purpose. A project with no row stops and says so, because running an agent
in the wrong directory is expensive to undo.

## 7. Choose how the agent runs

The shipped command is:

```
claude -p --output-format json --model {model} --permission-mode acceptEdits
```

Use the full path to the executable if it is not on PATH for the account the scheduled task runs as
— that is the usual reason a run works by hand and does nothing on a schedule. On Windows that is
normally `C:/Users/<you>/.local/bin/claude.exe`, in quotes if the path has a space in it.

The command is started directly, without a shell. On Windows that means a `.cmd` or `.bat` wrapper —
which is what an npm-installed tool gives you — cannot be the first word. Point at the real
executable, or put `cmd /c` in front of it.

**About `--permission-mode`.** `acceptEdits` lets the agent edit files but not run commands. A denied
tool call does not stop it — it quietly does not happen — so a role that has to build or test will
report success having done neither, and nothing will say so. `bypassPermissions` is what makes those
roles work, and it means the agent can run any command in that workspace as you. Change it once you
are happy with the workspace it runs in, not before.

The prompt goes in on stdin and the template is split into arguments without a shell, so nothing in a
ticket can become part of a command line. Placeholders: `{model}`, `{workspace}`, `{job_dir}`,
`{result_file}`, `{task_id}`, `{role}`.

## 8. Decide how work arrives

**Polling** is on by default and needs nothing inbound. Each pass of the runner asks the instance one
question and gets back this worker's own open tasks. There is nothing to configure: the key says
which worker this is, and which work is that worker's was decided on the instance in section 5.

**Webhooks** are faster and need the instance to be able to reach this machine. If it can, change the
dispatcher you made in section 5 from `pull` to `webhook` and fill in:

| | |
|---|---|
| URL | `https://local.beeblebrox.cloud/hook.php` |
| secret | the one shown on the **how work arrives** step here — copy it, do not invent one |
| timeout | 10 seconds — accepting is all this has to do |

The secret is generated on this machine when you switch webhooks on, and travels outwards only: the
instance never shows a dispatcher's secret back, printing just "set" or "not set", so this end is the
only copy worth trusting. There is nothing to type at either end.

**Going through a Beeblebrox Proxy?** It does not need the secret. The proxy passes the envelope
through byte for byte and the signature is checked here, on the machine that acts on it. Giving the
proxy a copy is optional and only lets it turn obvious rubbish away at your edge — worth doing, but
not the thing that keeps you safe.

Its test button then sends a real signed envelope naming task 0, which no task ever is, and the
result shows on the diagnostics page either way.

You can leave polling on as well. A pushed envelope and a poll that finds the same task land on one
job — the task id is unique here — so the two cannot start the same work twice.

For the instance to reach a laptop you need a port forward or a tunnel. If you use a tunnel, leave
**Allowed addresses** empty — the address envelopes arrive from belongs to the tunnel and is not
stable enough to pin. Serving the receiver over plain HTTP across a network means the envelope is
readable in transit; the signature stops it being forged, not read. It names a task and nothing more,
but put TLS in front of it anyway if it is leaving the machine.

## 9. Put the runner on a schedule

**Nothing happens until this runs.** One pass reports anything finished, asks for work, and runs what
is ready. It is safe to fire every minute: a second copy finds the lock held and exits.

Windows Task Scheduler, from an administrator prompt:

```
schtasks /create /tn "beeblebrox-local" /sc minute /mo 1 /ru "%USERNAME%" ^
  /tr "\"C:\xampp\php\php.exe\" \"C:\path\to\beeblebrox-local\tools\run.php\""
```

Run it as **your own account**, not SYSTEM. The agent needs your credentials, your PATH and your
checkouts, and a service account has none of those.

To watch it work instead, from a terminal you leave open:

```bash
php tools/run.php --watch --interval=60
```

## 10. Check it

```bash
timeout 120 php tools/selftest.php
```

Run this as the account the scheduled task uses. Half the answers — whether the agent is on PATH,
whether the workspaces are readable — are different for a different account, and that difference is
the usual reason a run works by hand and does nothing on a schedule.

The diagnostics page asks the same questions from the same code, and lists the envelopes that have
arrived with the reason each was accepted or refused.

---

## When something does not happen

**Nothing arrives at all.** Diagnostics answers this in order. It says whether the runner has run at
all; then which worker this machine is, which is the line to read — if it says the key belongs to no
worker, that is the problem. If it names the right worker and still nothing comes, the work is not
pointed at it: on the instance, check that the roles you expect are set to that dispatcher, and that
the dispatcher is in service rather than retired.

**Envelopes are refused.** The diagnostics page gives the reason for each. `the signature does not
match` means the two secrets differ. `the timestamp is Ns away from this machine's clock` means the
clocks do, and a laptop waking from sleep is the usual cause.

**A job says it needs a person.** Open it. The reason is at the top and the agent's own output is
below it. If the only thing wrong was the status it chose, put the right one in **Send it anyway**
and hand the work back rather than paying for the run twice.

**A run does nothing useful and reports success.** Almost always `--permission-mode`. See section 7.

**It works by hand but not on a schedule.** Run `tools/selftest.php` as the scheduled task's account.

## Upgrading

```bash
git pull
timeout 120 php tools/migrate.php
```

Migrations are re-runnable, and applying one by hand and leaving `schema_migrations` empty is a normal
thing to have happened — the runner survives it and tells you how to record it.
