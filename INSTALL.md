# Installing beeblebrox-local

Everything here is done once, on the machine that will do the work. It takes about twenty minutes,
and the diagnostics page tells you which parts you have not done yet at every point along the way.

Written for XAMPP on Windows, because that is what it was built on. Anything that serves PHP 8.1+
with a MySQL or MariaDB to talk to will do — Apache, nginx, or `php -S` for a look around.

---

## 1. Put the files somewhere

```bash
git clone git@github.com:j-tools/beeblebrox-local.git
cd beeblebrox-local
```

If you work both a beta and a production instance, clone it twice — one on `main`, one on `beta` —
and give each its own database and its own vhost. They share nothing, which is the point.

## 2. Make a database

```sql
CREATE DATABASE beeblebrox_local_zaphod CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'id_beeblebrox_local_zaphod'@'%' IDENTIFIED BY '<a long random password>';
GRANT ALL ON beeblebrox_local_zaphod.* TO 'id_beeblebrox_local_zaphod'@'%';
```

Then load the schema:

```bash
mysql -h 127.0.0.1 -u id_beeblebrox_local_zaphod -p beeblebrox_local_zaphod < db/schema.sql
```

`db/schema.sql` is the whole thing with every migration folded in, so a fresh install loads it alone.
Later changes arrive as files in `db/migrations/` and are applied with `php tools/migrate.php`.

## 3. Configure it

```bash
cp config.local.example.php config.local.php
php -r "echo bin2hex(random_bytes(32));"
```

Put that string in as `secret_key`. It wraps the API key and the signing secret in the database, so a
database dump on its own is not a credential breach — and losing it means entering both again.

Set `site_url` to the address you will serve this on, and `job_root` to somewhere writable and, if
you can, off a synced drive: an agent run writes to it continuously.

`config.local.php` is gitignored. Nothing else in the repository holds a secret.

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

Restart Apache, open `http://local.beeblebrox.cloud`, and set a password. That password is the only
thing between this machine's API key and anybody else who can reach the address.

Setting it drops you straight into a four-question setup: which Beeblebrox this machine works for, a
key to talk to it with, how work should arrive, and what runs it. Each answer is checked before it
moves on, so a wrong instance name or a refused key is caught while you are still looking at it. The
next three sections are the same four questions in longer form — read them if a choice is not
obvious, skip them if it is.

For a quick look without Apache, `php -S 127.0.0.1:8774 -t .` serves it just as well.

## 5. Get an API key from the instance

Set the **instance URL** on the settings page here first and press Save. A link to that instance's key
page appears under the API key box, which saves assembling the address by hand.

Then, on the instance:

1. Sign in to it as a **company admin** — that is the only permission that can issue a key.
2. **API keys** in the menu, or `<instance>/keys.php` directly.
3. **New key**. Name it after this machine, so a year from now it is obvious which one it is.
4. Permission **task creator** — read everything, claim, and report, which is exactly what a worker
   needs and nothing more.
5. Optionally pin it to one project. Only do that if this machine works that project and nothing
   else: a pinned key cannot touch work that has no project yet, which is everything at triage.

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

**Polling** is on by default and needs nothing inbound. Each pass of the runner asks the instance
what is open and takes what matches the roles you listed. If this is the only worker you can leave
the role list empty, but as soon as there is a second, name them.

**Webhooks** are faster and need the instance to be able to reach this machine. On the instance, add
a dispatcher:

| | |
|---|---|
| kind | `webhook` |
| URL | `http://local.beeblebrox.cloud/hook.php` |
| secret | the same string you put in **Signing secret** here |
| timeout | 10 seconds — accepting is all this has to do |

Then point the roles you want run here at that dispatcher, and use its test button: it sends a real
signed envelope naming task 0, which no task ever is, and the result shows on the diagnostics page
either way.

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

**Nothing arrives at all.** Diagnostics will say if the runner has not run. If it has, and polling is
on, check that the roles you listed match the instance's slugs exactly, and that **Which work** is
not set to something narrower than you meant.

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
