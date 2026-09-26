# PHP Agent — install on your VPS

A small self-hosted web app: you give it a GitHub repo and a task, it clones the
repo on the server, works on it with an AI model (tool-calling: read/write files,
run shell commands), and opens a pull request when it's done — or when you tap
"Force finish". A background dispatcher (driven by cron) keeps the work going
even after you close the browser tab.

This is **not** a port of OpenCode's internals (that's a large Effect/TypeScript
codebase — a real port isn't feasible). It's a small, independent tool that
covers the workflow you asked for: chat with an agent from your phone, let it
act on a folder, clone repos, open PRs, and keep running unattended.

## Requirements

- PHP 8.1+ CLI and a web server (Apache/nginx + PHP-FPM, or just `php -S` behind
  a reverse proxy). Extensions: `pdo_sqlite`, `curl`.
- `git` installed.
- Cron access (any normal Linux user account has this).
- An OpenCode Zen API key (free — sign up at https://opencode.ai, the free
  models cost $0/token but still need an account key).
- A GitHub personal access token with `repo` scope (classic) or Contents +
  Pull requests read/write (fine-grained), scoped to the repos you'll use.

Disk footprint is tiny: the app itself is a few hundred KB, SQLite db stays
under a few MB. The only thing that can grow is repo checkouts under
`data/workspaces/` — by default they're deleted as soon as a session finishes
(see Settings), so budget space mainly for however many repos you work on
*concurrently*.

## 1. Upload the code

Copy this whole `agent-php` folder to your VPS, e.g. `/home/youruser/agent-php`.

```bash
scp -r agent-php youruser@your-vps:/home/youruser/agent-php
```

## 2. Point the web server at `public/`

**Important: the web server's document root must be the `public/` subfolder,
not the project root.** `data/` and `src/` must not be reachable over HTTP.

### Quick option: PHP's built-in server (fine for a single-user tool on a small VPS)

```bash
cd /home/youruser/agent-php
nohup php -S 127.0.0.1:8080 -t public > data/logs/server.log 2>&1 &
```

Then put nginx or Apache in front of it as a reverse proxy on port 80/443 (for
TLS and so it survives reboots via your normal web server), or just access
`http://your-vps-ip:8080` directly if this is for personal use only over a VPN/
firewalled port.

### nginx (with php-fpm) — example server block

```nginx
server {
    listen 80;
    server_name agent.example.com;
    root /home/youruser/agent-php/public;
    index index.php;

    location / {
        try_files $uri /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

Get HTTPS with `certbot --nginx` so you can safely use this from your phone
over the open internet.

### Apache

Set `DocumentRoot` to the `public/` folder and enable `mod_php` or PHP-FPM via
`mod_proxy_fcgi`. `data/.htaccess` already denies all access to that folder as
a second layer of protection in case the vhost is ever misconfigured.

## 3. Make the CLI scripts executable and set permissions

```bash
cd /home/youruser/agent-php
chmod +x bin/worker.php bin/dispatcher.php
chown -R youruser:www-data data
chmod -R 775 data
```

The PHP web process (e.g. `www-data`) and the cron user need to both be able
to write into `data/`.

## 4. Set up cron

This is what makes sessions keep running after you close your browser tab —
without it, work only continues while an HTTP request happens to trigger it.

```bash
crontab -e
```

Add:

```
* * * * * php /home/youruser/agent-php/bin/dispatcher.php >> /home/youruser/agent-php/data/logs/dispatcher.log 2>&1
```

The web app also fires an immediate background dispatch whenever you create a
session, send a message, or hit "Force finish", so you don't have to wait for
the next cron minute to see it start — cron is just the safety net that keeps
things moving if the process dies or the request that would have triggered it
never happens.

## 5. First run

Open the site in your phone's browser. You'll be asked to set an admin
password on first visit — do this immediately, before anyone else can reach
the URL. Then go to **Settings** and add:

- Your OpenCode Zen API key
- Your GitHub personal access token

## Features (mirroring real OpenCode)

- **build vs. plan agents** — `build` can edit files, run commands, and opens a
  PR when done. `plan` is read-only (no writes, no shell) and just produces
  an answer/plan when it finishes, same split as OpenCode's built-in agents.
- **Permission modes** — `auto` runs everything without asking (like OpenCode
  in yolo mode). `ask` pauses the session (state `awaiting_approval`) before
  every file write or shell command and shows you the exact call in the UI
  with Approve/Deny buttons, mirroring OpenCode's permission prompts.
- **Todo list** — the model calls a `todo_write` tool to keep a live checklist
  of its plan, shown above the transcript, same idea as OpenCode's TodoWrite.
- **File browser** — "Browse files" opens a read-only tree of the repo
  checkout so you can see the folder the agent is working in from your phone.
- **Diff viewer** — "View diff" shows `git diff HEAD` for the current
  workspace at any time, without waiting for the PR.
- **Token usage tracking** — prompt/completion token counts are recorded per
  session and shown live.
- **Session management** — archive or permanently delete a session from the
  dashboard; deleting also frees its workspace directory on disk.
- **Persistent background execution & multi-session chat** — as before:
  sessions keep running via cron after you close the tab, and you can run
  several independent sessions/repos side by side.

## 6. Start a task

From the dashboard: enter a repo (`owner/repo` or a full GitHub URL), pick a
free model, describe the task, hit Start. You'll land on the session page,
which polls for new activity every 2 seconds. Close the tab whenever — cron
keeps the agent going. Come back later (from your phone or anywhere) and the
transcript will have continued. Send follow-up instructions any time from the
same page; tap "Force finish & open PR" to make it wrap up and open the PR
immediately.

## How the "keeps running after you leave" part actually works

- Each unit of work is a single `php bin/worker.php <session-id>` process. It
  does a bounded batch of agent turns (see `max_steps_per_tick` in Settings'
  underlying `data/settings.json`, default 8) then exits — it never runs
  forever, so it can't wedge your VPS.
- `bin/dispatcher.php` (cron, every minute) finds sessions that still need
  work and aren't already being processed (checked with `flock`, so the
  cron tick and any "just fire once now" trigger from the web app never double
  -run the same session) and launches a worker for each.
- The browser only ever *reads* state via polling; it never needs to stay
  connected for the agent to make progress.

## Security notes

This app lets an AI model run arbitrary shell commands on your VPS inside the
repo checkout (that's how it builds/tests/edits code). That's inherent to what
you asked for ("the website can execute commands on the server"). Mitigations
built in:

- Single admin password gate on the whole app.
- File tool paths (`read_file`/`write_file`/`list_dir`) are sandboxed to stay
  inside the session's own workspace directory.
- `run_command` still runs as whatever OS user runs PHP — **run this under a
  low-privilege, dedicated user, not root**, and ideally not on a box with
  anything else sensitive on it.
- A GitHub token scoped only to the repos you intend to use limits the blast
  radius if something goes wrong.

## Free models available

These use OpenCode Zen's free tier via the OpenAI-compatible endpoint (subject
to change — check https://opencode.ai/docs/zen for the current list):

- `big-pickle`
- `space-bunny-free`
- `mimo-v2.6-flash-free`
- `mimo-v2.5-free`
- `ling-3.0-flash-fin-free`
- `nemotron-3-ultra-free`
- `nemotron-3.5-lightning-free`
