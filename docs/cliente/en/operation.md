# Operating KronoQR — what needs attention, and how often

> **Status.** Sections 1 to 6 come from **task 2.10**: retention and purge,
> the only operation in the product that deletes data. Section 7 comes from
> **5.3** (licence). Sections **8, 9 and 10** come from **5.4**: the exit codes
> of the five scripts, the custody of secrets and what you lose if you switch
> observability off. Section **11** comes from **5.7**: updating. Sections
> **12 and 13** come from **5.9** and **5.10**: diagnostics, support and the
> full data export. Section **15** comes from **5.12**: the `error_events`
> history. Section **16** comes from **3.3**: the kiosks screen in the panel,
> the service code and the tablet's diagnostic screen.

---
> **The commands in this guide are run from the package directory**, which is
> where `docker-compose.yml` and the `.env` live. If you run them from anywhere
> else, add `-f /path/to/package/docker-compose.yml`.


## 1. The calendar, in one table

All of this runs on its own in the `scheduler` container. What appears in the
"you" column is what **no** machine does for you.

| When | What happens | You |
| --- | --- | --- |
| 02:45 UTC, daily | The yearly audit partition is checked to exist | Nothing, unless it warns you |
| 03:15 UTC, daily | Logical backup | Take it off the server |
| 04:05 UTC, daily | The audit hash chain is verified | Act on the alert if it fires: it is critical |
| 04:30 UTC, daily | Record review: open shifts, rest periods, anomalous working days | Resolve the incidents in the panel |
| Monday 05:10 UTC | **Retention proposal**: report of what would be purged | Read it once something has expired |
| Hourly | Credential metrics and clean-up of temporary files | Nothing |
| Hourly | Expired full data exports are purged: the ZIP is deleted, the record of it stays (§13) | Nothing |
| 04:25 UTC, daily | Expired reports generated in the background are purged: the file is deleted, the record of it stays (§6 and §13) | Nothing |
| Monday 05:40 UTC | **Telemetry**, only if you have enabled it (§13.4): the weekly report is sent to the destination you set | Nothing |
| Quarterly | — | **Restore drill** of the backup |
| Quarterly | — | **Go through the hardening checklist** ([`hardening.md`](hardening.md), last section): network, certificate, accounts, tablets, backups off the server |

---

## 2. The retention proposal (weekly, deletes nothing)

Every Monday a report is left at:

```
storage/app/retention-reports/retencion-propuesta-AAAAMMDD-HHMMSS.txt
```

You can ask for it by hand at any time, and **it is safe**: it does not modify
a single row.

```bash
docker compose -f docker-compose.yml exec app \
  php artisan compliance:apply-retention --dry-run
```

What the report says:

- **The cut-off date** of the working-time record —"earlier than YYYY-MM-DD"— and
  the retention years it was calculated with.
- **How many expired records there are**, table by table, with their date range.
- **Which audit partitions** have expired in their entirety.
- **The confirmation phrase** that would be needed to execute **that** report.

**As long as the total is 0, there is nothing to do.** That is the normal state
during the first four years of the installation.

**It contains no personal data**: counts, tables and dates. It can be archived
and attached with no further precaution.

---

## 3. Running the purge (manual, confirmed and audited)

**When:** when the proposal says there are expired records and the person in
charge authorises it. It is a once- or twice-a-year operation.

**Before you start:**

1. Check that **last night's backup is done and off the server**. The purge is
   irreversible.
2. Check that the **audit chain verification** finished green this morning. If
   it did not, stop: the purge would abort anyway, and what you need is
   [`rotura-cadena-auditoria.md`](../../runbooks/rotura-cadena-auditoria.md) (in Spanish).
3. Have the password of the **`fichaje_maintenance`** role at hand. It is not in
   the application's `.env` on purpose: it is the only role that can drop an
   audit partition, and if it lived there, the split of permissions would be
   decorative.

**The order**, with the exact phrase the report printed:

```bash
docker compose -f docker-compose.yml run --rm \
  -e DB_MAINTENANCE_PASSWORD='<contraseña de fichaje_maintenance>' app \
  php artisan compliance:apply-retention \
    --confirm=PURGAR-AAAA-MM-DD-xxxxxx \
    --responsible=<id de la cuenta de gestión que autoriza>
```

(The two placeholders are the `fichaje_maintenance` password and the id of the
management account that authorises the purge.)

**What happens, in order:**

1. The phrase is checked. If it does not match what would be purged **now**, it
   goes no further: a report from three months ago cannot be executed.
2. **The chain is verified** for every audit partition that was going to be
   dropped. If one does not verify, **it aborts without deleting anything**.
3. The expired working-time record is purged, with its entry in `audit_log` in
   the same transaction.
4. Each expired partition is **sealed** in `audit_chain_anchors` and **dropped
   whole**. The audit trail is never deleted row by row.
5. The technical log and the error history older than 90 days are cleaned up.
6. The report of what was purged is left at
   `storage/app/retention-reports/retencion-purga-*.txt`.

**Afterwards:**

- **Archive the report** together with the written authorisation.
- Run `php artisan compliance:verify-audit-chain`. It has to finish green and
  say «Purga sellada reconocida: particion AAAA» (sealed purge recognised for
  partition YYYY). If it said anything else, that is a security incident.

---

## 4. If something goes wrong

The messages below are the ones the commands print, which are in Spanish; the
meaning is given next to each.

| Symptom | What it means | What to do |
| --- | --- | --- |
| «La frase de confirmación no corresponde…» (the confirmation phrase does not match) | The report expired, or the compliance profile changed | Run `--dry-run` again and use the new phrase |
| «La cadena de la partición audit_log_AAAA NO verifica» (the chain of partition audit_log_YYYY does NOT verify) | Someone tampered with the audit trail | **Security incident.** `rotura-cadena-auditoria.md`. Do not repeat the purge |
| «La purga no ha podido completarse contra la base de datos» (the purge could not be completed against the database) | The `fichaje_maintenance` credential is missing, or the role does not have it set | Provision it with `infra/docker/postgres/initdb/02-application-roles.sh` and try again |
| «La instalación no tiene centro de trabajo» (the installation has no site) | Setup has not been completed | Finish the wizard; without a site there is no compliance profile and no retention period |
| The weekly proposal stops appearing | The scheduler is not running | Check the `scheduler` container; the `retention_last_run_timestamp_seconds` metric gives it away |

---

## 5. What to look at without waiting for anything to fire

Metrics published for the `node-exporter` collector
(`kronoqr_retention.prom`):

| Series | What it says | When to worry |
| --- | --- | --- |
| `retention_pending_rows{scope}` | Expired records that are still there | If it grows and stays: there is a purge waiting for authorisation |
| `retention_purged_rows{scope}` | What the last real purge removed | Compare it with the report |
| `retention_last_run_timestamp_seconds{mode}` | When the last pass ran | If the `simulation` one is older than a week, the scheduler is not running |
| `retention_cutoff_timestamp_seconds` | Cut-off date in force | If it jumps backwards or forwards, someone changed the compliance profile |

---

## 6. Parameters that govern all of this

| Variable | Default | What it does |
| --- | --- | --- |
| *(site profile)* `retention_years` | 4 | Years of the working-time record and of the audit trail. **It is not an environment variable**: it is a row in `compliance_profiles`, because the jurisdiction sets it |
| `TECHNICAL_LOG_RETENTION_DAYS` | 90 | Days of technical log |
| `ERROR_HISTORY_RETENTION_DAYS` | 90 | Days of error history |
| `COMPLIANCE_RETENTION_BATCH_SIZE` | 1000 | Rows per delete statement. Raise it only if the purge takes too long |
| `COMPLIANCE_RETENTION_REPORT_PATH` | `storage/app/retention-reports` | Where the reports are left. **They are not cleaned up on their own**: they are the evidence of the purge |
| `DB_MAINTENANCE_USERNAME` | `fichaje_maintenance` | Role that runs the audit purge |
| `DB_MAINTENANCE_PASSWORD` | *(empty)* | **Not set in the `.env`.** It is supplied when the purge is run |

**Reports generated in the background** (HR asks for them from the panel:
[`hr-guide.md`](hr-guide.md) §6.3) have their own six parameters and their own
purge. The files are **per requesting person**, they live in
`REPORTING_EXPORT_PATH` and the scheduler deletes them at 04:25 UTC as soon as
they expire; the record that they existed is always kept. If you ever need to bring
it forward:

```bash
docker compose exec app php artisan reporting:purge-expired-exports
```

| Variable | Default | What it does |
| --- | --- | --- |
| `REPORTING_EXPORT_PATH` | `storage/app/reports` | Where those files are written. **Never inside `BACKUP_PATH`**: they expire on their own and must not go into the backup |
| `REPORTING_EXPORT_RETENTION_DAYS` | `7` | Days the file can be downloaded before the daily purge deletes it |
| `REPORTING_EXPORT_LINK_TTL_MINUTES` | `15` | Minutes the download link is valid for; it is also **single-use** |
| `REPORTING_EXPORT_TIMEOUT_SECONDS` | `600` | Limit on the deferred report's query. Raise it if a large export fails on time |
| `REPORTING_EXPORT_STALE_AFTER` | `3600` | Seconds after which an interrupted generation is given up as failed and lets another one be requested. Do not lower it below what your largest report takes |
| `REPORTING_EXPORT_DOWNLOAD_RATE_LIMIT` | `30` | Downloads per minute and per IP address on the download route, which is opened without a session |

---

## 7. The licence, in two commands

Nothing is scheduled to check the licence: you look at it when you want to.

```bash
docker compose exec app php artisan license:show
```

**Exit codes**, in case you want to monitor it from your own system:

| Code | Meaning |
| --- | --- |
| `0` | Licence valid and within plan limits. Nothing to do |
| `1` | **Something to look at**: no licence, expired, expiring soon, cannot be verified, or a plan figure has been exceeded |

> **`1` does not mean the system is down**, and the command itself says so.
> Clocking in and out, consulting the record, exporting for the Labour
> Inspectorate and taking backups carry on exactly the same.

To activate a new key:

```bash
docker compose exec app php artisan license:activate "KQL1...."
```

| Code | Meaning |
| --- | --- |
| `0` | Activated and valid |
| `1` | Activated, but **not valid** yet: expired, or its validity starts later. It was stored all the same |
| `2` | **Nothing was activated.** The key does not verify, or you did not supply one. The previous licence stays as it was |

The status also shows in the health probe, so you can monitor it without going
in over SSH:

```bash
curl -sS https://TU-SERVIDOR/api/v1/health
# {"status":"ok","version":"1.4.2","license":"valid"}
```

That field can say `unknown`: it means the probe could not find out **without
touching the database**, which is its rule —a liveness probe that queries
PostgreSQL restarts the service when what is down is PostgreSQL—. The
authoritative answer is `license:show`.

Everything else about the licence —what is degraded when it expires, what is
never degraded, the plan limits and what to do if a key will not activate— is
in [`configuration.md`](configuration.md), section 3 bis.

---

## 8. The five scripts and their exit code table

`install.sh`, `update.sh`, `doctor.sh`, `backup.sh` and `restore.sh` **share a
single table**. The same person runs them, sometimes chained in a cron job, and
a `3` that meant one thing in one and something else in another would be a trap.

**The code says at which phase it stopped and what was left written. The detail
is in the message, which is what you have to read.**

| Code | Always means | In each script |
| --- | --- | --- |
| `0` | Success | — |
| `1` | **Incorrect usage.** Nothing touched | An argument that does not exist, or a missing value |
| `2` | **Requirements not met. NOTHING written.** The machine is as it was | `install.sh`: Docker missing, disk, port in use. `backup.sh`/`restore.sh`: `pg_dump` missing, destination not writable, no space, or `BACKUP_ENCRYPTION_KEY` missing. `update.sh`: a precondition is not met, or **the pre-update backup failed**; the installation stays on its version. `doctor.sh`: **Docker is not responding**, or the Compose version is not the supported one; nothing else could be checked |
| `3` | **Incompatible prior state. NOTHING written** | `install.sh`: there is already an installation (use `update.sh`). `backup.sh`: there is no backup to verify, or the destination already exists. `restore.sh`: there are still open connections against the database. `update.sh`: already on the target version, or there is no installation to update. `doctor.sh`: **there is no installation to diagnose** on this server — if it is a new one, what you need is `install.sh` |
| `4` | **Failed and everything done was undone** in that run. Can be retried | `install.sh`: containers, volumes and `.env` returned to their state. `backup.sh`: half-written files swept away, the previous backup intact. `restore.sh`: working database removed, the production one untouched. `update.sh`: pre-update backup restored and **previous version running and verified**. `doctor.sh`: **does not use it**, it neither writes nor undoes anything |
| `5` | **Failed and NOT everything could be undone. Manual intervention required.** The message says exactly what is left and which order removes it | It is the only code that requires a person present. `doctor.sh`: **does not use it**, it neither writes nor undoes anything |
| `6` | **The work was done but the subsequent verification failed.** Nothing is undone | `install.sh`: the services are up, check the certificate and the logs. `backup.sh`: the backup exists but **does not verify: treat it as non-existent**. `restore-drill.sh`: today the record could not be recovered. `update.sh`: **almost never** (every failed verification of the new version rolls back); the only exception is that the `system.updated` entry in `audit_log` could not be written after an update that did finish — the work was done and is not undone because of that. `doctor.sh`: **the diagnosis has found at least one failure** — with the application running, in its own report (`product:doctor`); with the application stopped, in one of the external checks. The message says what to read |

`install.sh` and `update.sh` invoke `product:doctor` in their verification
phase (RF-PD-13): in `install.sh` a warning (`product:doctor` code `1`) is
shown and does not block, and only a failure (`2`) translates into the `6` of
the table above. In `update.sh` the diagnostic never translates into `6`: it
is informational, and a `product:doctor` failure on the new version triggers
the rollback (code `4`) just like any other failure in step 5, never a `6`.
The only translation into `6` that does exist in `update.sh` is something
else entirely: the `system.updated` entry that step 5 leaves in `audit_log`
(section 11).

### If you had a cron job written against the previous `backup.sh` table

Up to version 2.0.0, `backup.sh` and `restore.sh` used a table of their own.
Equivalence:

| Before | Now |
| --- | --- |
| `1` the operation failed | `4` if what was done was undone · `5` if something was left half-done · `6` if verification failed · `3` if there was nothing to operate on |
| `2` usage error | `1` |
| `3` a tool or precondition missing | `2` |
| `4` destination not writable or no space | `2` |
| `5` key missing or incorrect | `2` when checking the precondition · `6` when decrypting a backup fails |

**What a cron job needs to know is still the same: `0` is good, anything else is
bad.** The difference is that now the number tells you whether you can retry
without looking (`2`, `3`, `4`) or whether you have to look (`5`, `6`).

---

## 9. Custody of secrets

The installer generates every secret **on your server** and transmits them to
nobody. **The vendor does not know them and cannot recover them.** The full
list, with the consequence of losing each one, is in
[`installation.md`](installation.md), section 3.

### `BACKUP_ENCRYPTION_KEY`: this one leaves the server

It is the only one that has to be kept **off** the machine, and the reason is
straightforward: if the server is lost and the key was only there, the
encrypted backups are worthless bytes and the working-time record —which has to
be kept for four years— is gone.

**Do it on installation day, before closing the session:**

```bash
cd /opt/kronoqr-2.1.0
sudo sed -n 's/^BACKUP_ENCRYPTION_KEY=//p' .env
```

Copy that value into the company's password manager, or into a sealed envelope
in the safe alongside the hotel's other critical credentials. Note down **the
date** and **the installation** it belongs to.

Three things not to do:

- **Do not send it by email or by messaging.** The vendor does not need it and
  does not want it.
- **Do not leave it in a file on the same server.** If the server is lost, both
  are lost.
- **Do not rotate it without first reading** [`../../runbooks/rotacion-secretos.md`](../../runbooks/rotacion-secretos.md) (in Spanish):
  earlier backups **can only be decrypted with the key they were made with**.

Clear the shell history when you are done:

```bash
history -c
```

### `fichaje_maintenance`: the role that is born without a password

It is the only database role that can drop an expired partition of the audit
trail, and that is why **its password does not live in the `.env`**: if the
running application could authenticate as it, the three-role split would be
decorative.

It is born without a credential —it exists and cannot be used over the
network— and is given one **at the moment** of the annual purge, using the
migration role, which is in the `.env`:

```bash
# 1. Generate a password and assign it to the role, for this operation only
clave="$(openssl rand -base64 24)"
docker compose exec -T postgres psql -U fichaje_migrator -d fichaje \
  -c "ALTER ROLE fichaje_maintenance PASSWORD '${clave}'"

# 2. Run the purge supplying the credential, without writing it to any file
docker compose run --rm -e DB_MAINTENANCE_PASSWORD="${clave}" app \
  php artisan compliance:apply-retention --confirm=PURGAR-...

# 3. Take it away as soon as you finish
docker compose exec -T postgres psql -U fichaje_migrator -d fichaje \
  -c "ALTER ROLE fichaje_maintenance PASSWORD NULL"

unset clave
history -c
```

The **simulation** (`--dry-run`), which is the one that runs on its own every
Monday, **needs none of this**: it only counts, and it counts with the
application role.

### The panel accounts: creating, deactivating and the password

Management accounts are created from the console and **are withdrawn from the
console**. This version has **no management-accounts screen in the panel**, which
is why these four orders are the whole life cycle of an account. All four leave a
record in the audit log, with their author, their date and their reason.

```bash
# Alta de una cuenta con su rol. Pide la contraseña por consola, sin eco
docker compose exec app php artisan identity:create-user --role=rrhh

# Baja. Deja de poder entrar, y sus sesiones abiertas dejan de valer al instante
docker compose exec app php artisan identity:deactivate-user persona@tuhotel.example --reason="Baja del hotel"

# Contraseña nueva, generada y mostrada UNA sola vez
docker compose exec app php artisan identity:reset-password persona@tuhotel.example

# Retirar el segundo factor a quien perdió el móvil, para que lo dé de alta otra vez
docker compose exec app php artisan identity:2fa-reset 0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90
```

Three things worth knowing about deactivation:

- **It deletes nothing.** The account keeps its whole history, which is exactly
  what makes it possible to answer, months later, "who corrected this working
  day?". The only thing it loses is the ability to sign in.
- **It takes effect on the next request**, not when the session expires: if that
  person had the panel open on a tablet, it stops working immediately.
- **It does not reopen the creation of the first administrator.** Even if you
  deactivate the last account left, that door stays closed — if it reopened,
  removing someone would be a way of creating an administrator without
  credentials.

The password that `identity:reset-password` generates **cannot be looked up
again**: the product stores its digest, not the password. Write it down when you
run it and hand it over in person, never by email or messaging.

---

## 10. Observability, and what you lose if you switch it off

The `.env` ships with `COMPOSE_PROFILES=observability`. It brings up seven more
services —Prometheus, node-exporter, Alertmanager, Grafana, Loki, Tempo and
blackbox-exporter— and takes up about 850 MiB.

**It is on by default because it is what warns you of the two failures that
turn a healthy installation into data loss without anyone noticing by looking
at the screen:**

1. **Last night's backup failed.** The result of every backup is published as a
   metric and there is an alert on it. Without the profile, nobody tells you.
2. **WAL archiving has stopped.** PostgreSQL keeps working and the WAL piles up
   in its own volume until it fills the disk; then the whole thing stops. The
   corresponding alert is one of the critical ones.

You can switch it off by leaving `COMPOSE_PROFILES=` empty and restarting the
services. It is a supported configuration, but **take on this manual task**:

| Every | What to check |
| --- | --- |
| Week | `docker compose exec app php artisan backup:verify` — that the latest backup exists and verifies |
| Week | `df -h` on the Docker disk and on `BACKUP_PATH` |
| Quarter | The restore drill (section 1), which does not change |

Grafana listens **only on `127.0.0.1:3000`**: you reach it over an SSH tunnel or
from the server itself, **never from the internet**.

### 10.1 The three records the system keeps

They are easy to confuse and serve different purposes; mixing them up is a
mistake you pay for later, usually in front of an inspector or an angry
client.

| Record | Where it lives | Retention | What it is for | Who reads it |
| --- | --- | --- | --- | --- |
| **Technical log** | JSON file on `stderr`, and in Loki if `LOKI_URL` has a value (not `LOG_STACK`: see below) | 90 days (`TECHNICAL_LOG_RETENTION_DAYS`) | Debugging one specific request in detail: what happened, in what order, with what technical context | Whoever has the observability dashboard in front of them (usually vendor support, with your diagnostics package, or your own IT if they can read Grafana) |
| **`error_events`** | Table in your PostgreSQL | 90 days (`ERROR_HISTORY_RETENTION_DAYS`) | So **you** can see what is failing and since when, without needing to know the system from the inside | You, from the panel ("Support" → error history, section 15) |
| **`audit_log`** | Table in your PostgreSQL, append-only and hash-chained | Years, per your compliance profile | Evidentiary value in front of an inspection: who did what and when | You, an auditor, the Labour Inspectorate |

**The `observability` profile starts the Loki container; `LOKI_URL` decides
whether the application sends it anything.** These are two separate
decisions, on purpose, with the same criterion as
`OTEL_EXPORTER_OTLP_ENDPOINT` (§10.2): `LOKI_URL` ships **empty** in
`.env.example`, so a freshly installed site has Loki running with nobody
writing to it, until you set `LOKI_URL=http://loki:3100` in the `.env` and
restart `app`, `horizon` and the scheduler. `LOG_STACK` plays no part in this
decision: naming `loki` there without `LOKI_URL` turns nothing on.

**Why `error_events` is not redundant with Loki.** Loki is part of the
`observability` profile, which is optional: you can switch it off, nobody
might be watching it, and you lose it if you reinstall without keeping its
volume. `error_events` lives in the same database you back up daily and
travels in the diagnostics package, so it stays there even if Loki does not
exist.

**Nginx, PostgreSQL and Redis logs do not travel to Loki.** Only the
application's technical log does (Laravel, Horizon, the scheduler). If you
need Nginx's, they are in `docker compose logs nginx`; PostgreSQL's and
Redis's follow the same pattern.

### 10.2 What Tempo and blackbox-exporter add

**Tempo** stores traces: the complete path of a request, from the tablet's
`fetch` to the SQL query that wrote the clock-in, with the same 90-day
retention as the technical log (changing the period means touching
`infra/observability/loki/loki.yaml` **and**
`infra/observability/tempo/tempo.yaml` together: see `configuration.md`
§6.20). It does not turn itself on: you also need
`OTEL_EXPORTER_OTLP_ENDPOINT` to point to `http://tempo:4318` in your `.env`
(empty by default, even with the profile on).

**blackbox-exporter** makes a real HTTP request to `/api/v1/health` and
`/api/v1/ready` every 30 seconds, from outside the process: that is the
difference between "the process is alive" (which Docker already checks) and
"the edge is actually serving traffic." It is the same exporter used for the
TLS certificate expiry check.

### 10.3 Following one specific clock-in: from `scan_id` to the full trace

When someone says "I clocked in at 07:02 and the system does not have it",
the way to check is:

1. In Grafana, open **Explore** with the **Loki** data source and filter by
   `scan_id` (it is on every technical-log line that touched that clock-in).
2. Every log line that has an associated trace shows a **"Tempo"** link next
   to the `trace_id` field: open it and you will see the whole request —every
   step, every database query— with its timings.
3. If the line has no `trace_id` (for example, because traces were off that
   day), the technical log still has the detail: the trace is a shortcut, not
   the only source.

This only works with the `observability` profile on and
`OTEL_EXPORTER_OTLP_ENDPOINT` configured; without traces, step 1 (Loki) is
still available.

### 10.4 Dashboards and alerts

Prometheus evaluates the alert catalogue from document 01 §9.3 against the
metrics from §8.2, and Grafana loads **five dashboards versioned as code**
in `infra/observability/grafana/dashboards/` of your installation — they are
not edited from the interface (`allowUiUpdates: false`): a change is made in
the file and deployed, like any other configuration.

| Dashboard | Audience | Question it answers |
| --- | --- | --- |
| **Kiosk operation** (`kronoqr-kiosks`) | Support / your IT | The dashboard shows the status of each device, its last heartbeat, the size of its pending queue, the deployed version and clock-ins per hour: it answers "does any clock-in point need attention right now?" |
| **API health** (`kronoqr-api`) | Development | The dashboard shows the RED pattern (rate, errors, duration) per route, and the state of the queues and the database: it answers "is the system serving traffic normally?" |
| **Data integrity** (`kronoqr-integrity`) | Development and compliance | The dashboard shows the nightly reconciliation's divergences, the audit chain verification result, manual corrections and incidents by age: it answers "is the record still trustworthy?" — and the two series that must always stay at zero, `projection_divergence_total` and `audit_chain_verification_failures_total`, are the first thing it shows |
| **Business** (`kronoqr-business`) | HR and management | The dashboard shows hours worked by department, open shifts, incidents by type and compliance alerts: it answers "how is the workforce doing this week?" |
| **Impact and adoption** (`kronoqr-adoption`) | Management, and the vendor itself at the point of sale | The dashboard shows workdays with a complete record, the breakdown of clock-ins by origin (QR, PIN, manual) and detected anomalous patterns: it answers "is this actually delivering value?" |

None of them carries a brand or a client name, and none identifies a person:
at most, `device` or `employee_uuid` (hard rule 21). **What the Business
dashboard cannot show yet** — contracted versus worked hours, absenteeism,
lateness — is stated in the dashboard's own text panel: those are
indicators for later tasks that do not yet have a series to feed them.

**How alerts reach you.** Alertmanager sends email, **through your own
SMTP** (the `MAIL_*` values you already filled in when installing, §10.1),
to three recipients — IT, HR and security — and, if you configure it, also
to a **webhook** for each one. The nine variables (`ALERT_EMAIL_IT`,
`ALERT_EMAIL_RRHH`, `ALERT_EMAIL_SEGURIDAD`, `ALERT_WEBHOOK_IT`,
`ALERT_WEBHOOK_RRHH`, `ALERT_WEBHOOK_SEGURIDAD`, `ALERT_MAINTENANCE_*`)
start out empty or with their default: an empty destination does not
generate that delivery, so fill in at least the three email ones —
`product:doctor` warns **for each role left with no delivery path at all**,
one by one: IT, HR and security. Filling in just one and calling it done is
not enough — with only IT filled in, a `RoturaDeCadenaDeAuditoria` (which is
routed to security) would reach nobody, and before this review `doctor`
would not have said so. An installation with alerts that reach nobody is
worse than one with no alerts at all: it believes itself watched when it is
not. Full table in [`configuration.md`](configuration.md) §6.20.

**The Alertmanager interface**, to see the routing and the active silences,
listens the same way Grafana does — **only on `127.0.0.1:9093`, never from
the internet** —, and you reach it the same way, over an SSH tunnel:

```bash
ssh -L 9093:127.0.0.1:9093 tu-usuario@fichaje.tuhotel.local
```

And then `http://127.0.0.1:9093` in your browser. **Alertmanager monitors
itself** with the first two rows of the table below
(`EnrutadoDeAlertasCaido`, `EntregaDeAlertasFallando`): if the service
itself goes down, or if something prevents delivery (email down, a webhook
not responding), you will know — the second one might not reach you by
email if email is exactly what is broken, but it shows up the same way on
the "API health" dashboard and in this same interface. Procedure in
[`entrega-de-alertas.md`](../../runbooks/entrega-de-alertas.md) (in Spanish).

**The full catalogue**, with what to do when each one arrives — it includes
the alerts of document 01 §9.3's own catalogue and the ones that watch each
alert's silence (so that no alert goes blind because the command feeding it
stopped running):

| Alert | Threshold | Severity | Recipient | Runbook | At 06:30 |
| --- | --- | --- | --- | --- | --- |
| `EnrutadoDeAlertasCaido` | Alertmanager not responding, `for: 5m` | Critical | IT | [`entrega-de-alertas.md`](../../runbooks/entrega-de-alertas.md) (in Spanish) | `docker compose ps alertmanager` and its logs first |
| `EntregaDeAlertasFallando` | Failed notifications in 15 min | High | IT | [`entrega-de-alertas.md`](../../runbooks/entrega-de-alertas.md) (in Spanish) | Check the configured SMTP and webhook; it may not reach you by email if email is what is broken |
| `QuioscoSinLatido` | > 10 min without a heartbeat | Critical | IT | [`quiosco-no-responde.md`](../../runbooks/quiosco-no-responde.md) (in Spanish) | Check whether the tablet powers on and has network; if it does, wait for one heartbeat; if not, attend to it in person |
| `ColaOfflineAtascada` | A device's queue > 50 items | High | IT | [`cola-offline-atascada.md`](../../runbooks/cola-offline-atascada.md) (in Spanish) | Check `kiosk:health`: nothing is lost, but do not unpair that tablet yet |
| `ColaOfflineSinVaciar` | The queue has not dropped to 0 in 2 h | High | IT | [`cola-offline-atascada.md`](../../runbooks/cola-offline-atascada.md) (in Spanish) | Same: check the network or the certificate at that point |
| `ErroresDeServidorEnElFichaje` | > 1 % of `5xx` on `/api/v1/scan*`, 5 min | Critical | IT | [`errores-en-el-panel.md`](../../runbooks/errores-en-el-panel.md) (in Spanish) | `product:doctor` first: the database and disk are the most frequent cause |
| `LatenciaDelFichajeAlta` | p95 of clock-ins > 500 ms, 10 min | High | IT | [`errores-en-el-panel.md`](../../runbooks/errores-en-el-panel.md) (in Spanish) | Check whether it coincides with the shift change or with a recent update |
| `SondaDelBordeFallida` | The server is not responding, `for: 5m` | Critical | IT | [`errores-en-el-panel.md`](../../runbooks/errores-en-el-panel.md) (in Spanish) | `docker compose ps` and the `postgres`/`redis` logs, before the panel |
| `CertificadoTlsProximoACaducar` | < 21 days | High | IT | [`renovacion-certificado-tls.md`](../../runbooks/renovacion-certificado-tls.md) (in Spanish) | Start the renewal process with your certificate's issuer |
| `CertificadoTlsCaducado` | Expired | Critical | IT | [`renovacion-certificado-tls.md`](../../runbooks/renovacion-certificado-tls.md) (in Spanish) | All kiosks affected: place the renewed certificate and reload Nginx |
| `CertificadoTlsNoVerificable` | Does not verify against `APP_URL` (chain, name or issuer), `for: 15m` | Critical | IT | [`renovacion-certificado-tls.md`](../../runbooks/renovacion-certificado-tls.md) §3.4 (in Spanish) | Check chain, name and issuer with `openssl -verify_return_error`; does not fire if you declared `TLS_ALLOW_SELF_SIGNED=true` |
| `SaturacionDelBordeEnElFichaje` | > 20 `429` responses in 5 min on the clock-in routes | High | IT | [`saturacion-del-borde.md`](../../runbooks/saturacion-del-borde.md) (in Spanish) | Only measures the application layer (no Nginx exporter yet); check whether it is one device or an origin outside the VLAN |
| `RechazoDeFirmaQr` | > 20 scans with an invalid signature in 15 min | Critical | Security | [`ataque-a-credenciales.md`](../../runbooks/ataque-a-credenciales.md) §8 (in Spanish) | It is an incident, not an outage: preserve evidence before touching anything |
| `EspacioEnDiscoBajo` | < 20 % free on `/` | High | IT | [`espacio-en-disco.md`](../../runbooks/espacio-en-disco.md) (in Spanish) | `docker system df`; free up old images before touching anything in the record |
| `MetricasDelAnfitrionAusentes` | No host metrics | Medium | IT | [`espacio-en-disco.md`](../../runbooks/espacio-en-disco.md) (in Spanish) | Check that `node-exporter` is still up |
| `TurnoAbiertoProlongado` | Shift open > 12 h | Medium | HR | [`turno-abierto-prolongado.md`](../../runbooks/turno-abierto-prolongado.md) (in Spanish) | Ask the person what time they left; the system never closes the shift on its own |
| `DescansoEntreJornadasInsuficiente` | Rest below the legal minimum | Medium | HR | [`turno-abierto-prolongado.md`](../../runbooks/turno-abierto-prolongado.md) (in Spanish) | Check that the hours are correct before treating it as a scheduling matter |
| `MetricaDeIncidenciasAusente` / `DeteccionDeIncidenciasAusente` | Silence of the nightly detection | Medium | IT | [`turno-abierto-prolongado.md`](../../runbooks/turno-abierto-prolongado.md) (in Spanish) | Check that the `scheduler` is still alive |
| `DeteccionDeIncidenciasConFallos` | Last night's run failed | High | IT | [`errores-en-el-panel.md`](../../runbooks/errores-en-el-panel.md) (in Spanish) | `product:doctor`, and if it points at the reconciliation, follow that runbook instead |
| `ErroresCriticosNuevos` | New or reopened `critical` group in 5 min | High | IT | [`errores-en-el-panel.md`](../../runbooks/errores-en-el-panel.md) (in Spanish) | Open "Errors" in the panel and follow the "What to do" column for that row |
| `DivergenciaEnReconciliacionNocturna` | Any | Critical | IT | [`divergencia-proyeccion.md`](../../runbooks/divergencia-proyeccion.md) (in Spanish) | The fix is already applied; find out who wrote outside the recalculation |
| `ReconciliacionConFallos` | Last night's run failed | High | IT | [`divergencia-proyeccion.md`](../../runbooks/divergencia-proyeccion.md) (in Spanish) | Run `attendance:reconcile` by hand and check the reason for the failure |
| `ReconciliacionDeProyeccionAusente` | > 26 h without reconciling | Medium | IT | [`divergencia-proyeccion.md`](../../runbooks/divergencia-proyeccion.md) (in Spanish) | Check that the `scheduler` is still alive and run `attendance:reconcile` by hand |
| `RoturaDeCadenaDeAuditoria` | Any | Critical | Security | [`rotura-cadena-auditoria.md`](../../runbooks/rotura-cadena-auditoria.md) (in Spanish) | Preserve the evidence (§2 of that runbook) before touching anything |
| `VerificacionDeAuditoriaAusente` | > 26 h without verifying | Critical | Security | [`rotura-cadena-auditoria.md`](../../runbooks/rotura-cadena-auditoria.md) (in Spanish) | Check that the `scheduler` is still alive |
| `ParticionDeAuditoriaAusente` | The current year's partition is missing | Critical | IT | [`rotura-cadena-auditoria.md`](../../runbooks/rotura-cadena-auditoria.md) (in Spanish) | **Clock-ins are down**: run `compliance:ensure-audit-partitions` now |
| `ParticionDeAuditoriaDelProximoAnoSinPreparar` | Next year's is missing, from November on | Medium | IT | [`rotura-cadena-auditoria.md`](../../runbooks/rotura-cadena-auditoria.md) (in Spanish) | Check the `scheduler` and `DB_MIGRATION_USERNAME` in the `.env` |
| `CopiaDeSeguridadFallida` / `CopiaDeSeguridadSinVerificar` | Any | Critical | IT | [`restaurar-backup.md`](../../runbooks/restaurar-backup.md) (in Spanish) | `backup:verify`, then retry with `backup:run` |
| `CopiaDeSeguridadAusente` | No metric in 30 min | Critical | IT | [`restaurar-backup.md`](../../runbooks/restaurar-backup.md) (in Spanish) | Check the `scheduler` and that `BACKUP_PATH` is mounted |
| `ArchivadoDeWalDetenido` | > 30 min without archiving | Critical | IT | [`restaurar-backup.md`](../../runbooks/restaurar-backup.md) (in Spanish) | Urgent: without space, PostgreSQL ends up stopping entirely |
| `DiscoDeCopiasCasiLleno` | < 20 % free on the backup volume | Medium | IT | [`restaurar-backup.md`](../../runbooks/restaurar-backup.md) (in Spanish) | Expand the disk, or lower `BACKUP_RETENTION_DAYS` |
| `SimulacroDeRestauracionNuncaEjecutado` | None recorded yet | Medium | IT | [`restaurar-backup.md`](../../runbooks/restaurar-backup.md) (in Spanish) | Run it: without it, nobody has checked that the backups actually restore |
| `SimulacroDeRestauracionCaducado` | Failed, or > 100 days | Medium | IT | [`restaurar-backup.md`](../../runbooks/restaurar-backup.md) (in Spanish) | Repeat the drill with the previous backup if the latest one fails |
| `KronoqrAuthFailureBurst` | > 20 failures in 5 min, one channel | Medium | Security | [`ataque-a-credenciales.md`](../../runbooks/ataque-a-credenciales.md) (in Spanish) | Work out whether it is a person mistyping or an automated attempt |
| `KronoqrAuthLockouts` | ≥ 3 distinct lockouts in 15 min, one channel | Medium | Security | [`ataque-a-credenciales.md`](../../runbooks/ataque-a-credenciales.md) (in Spanish) | Scope how many accounts, and whether any got in before locking out |
| `KronoqrAuthFailureSpike` | > 100 failures in 5 min, one channel | Critical | Security | [`ataque-a-credenciales.md`](../../runbooks/ataque-a-credenciales.md) (in Spanish) | Preserve evidence before blocking the origin at the edge |
| `VentanaDeMantenimientoActiva` | While an update lasts, capped at 2 h | Info (does not notify) | — | [`actualizacion-cliente.md`](../../runbooks/actualizacion-cliente.md) (in Spanish) | Nothing: it only silences other alerts while it lasts |

**What the certificate alerts watch, and since when.**
`CertificadoTlsProximoACaducar` and `CertificadoTlsCaducado` look only at the
**expiry date** — that is all `probe_ssl_earliest_cert_expiry` measures. The
probe that feeds it uses `insecure_skip_verify: true` (task 3.1, on purpose: a
hotel's server certificate can be self-signed or from your own CA, and
verifying the chain or the name from **inside** the container network would
always give a false negative), so those two alerts never see the chain, the
issuer or the name.

**Since task 3.8, a third alert does watch that: `CertificadoTlsNoVerificable`.**
It probes `APP_URL` — the real public domain your clients reach — with
`insecure_skip_verify: false`: it requires a complete chain, a trusted issuer
and a matching name, exactly what a real browser requires. If someone
replaces your certificate with one that does not belong, or an intermediate
is missing from the chain, you now **do** get an alert for it, without
waiting for the tablets to stop connecting (`QuioscoSinLatido`, and over time
`ColaOfflineAtascada` — that remained the only signal before task 3.8). This
third alert needs `APP_URL` to be configured: if your installation declared
`TLS_ALLOW_SELF_SIGNED=true` on purpose (only valid in test environments),
the probe does not even come up — a deliberately chosen self-signed
certificate would never pass this verification, so probing it anyway would
only produce a permanent alert for something already known and accepted.
Checking the chain by hand still remains part of the quarterly hardening
checklist ([`hardening.md`](hardening.md)), as an extra safety net.

**If you point a webhook at an external service** (`ALERT_WEBHOOK_IT` and
the other two), the labels and text of every alert it fires — the site
name, the device, the affected component, the summary and the description —
leave the hotel's server towards that service. **It never carries data
about people** (hard rule 21: at most `device` or `employee_uuid`), but it
does identify your organisation and its installation. Deciding whether that
external service is appropriate, and contracting it as a data processor if
needed, is your decision and your DPO's — the product does not make it for
you (see [`legal-obligations.md`](legal-obligations.md)).

**The weekly maintenance window.** `ALERT_MAINTENANCE_WEEKDAY`,
`ALERT_MAINTENANCE_START` and `ALERT_MAINTENANCE_END` (Sunday 02:00–04:00 by
default, in the server's time zone — never during the 06:00 shift change)
silence kiosk, API, certificate and disk alerts: a server restarting in its
own window does not have to wake anyone up. **What is never silenced,
neither in this window nor in the automatic one from `update.sh`** (which
opens the same flag while an update lasts, capped at **2 hours** in case the
updater dies without clearing it — a real update is measured in minutes):
backup, data integrity, audit, authentication and incidents. Those are
exactly the ones you need to be able to see during a maintenance window.

**Anti-fatigue: five silent kiosks at once arrive as a single
notification**, not five. Kiosk alerts are grouped by the alert's name (not
by device), so a network outage affecting several tablets at once produces
one email with all five devices inside, instead of five separate
notifications — this is the practical reading of "a single kiosk restarting
must not wake anyone up" from document 02 §8.4.

**The thresholds live in the installation, not in the vendor's
repository.** `infra/observability/` travels in your package and you can
edit it. Only one threshold is tied to another part of the system and
**has to change at the same time**: `KIOSK_HEALTH_SILENT_AFTER_SECONDS`
([`configuration.md`](configuration.md) §6.14) and the `QuioscoSinLatido`
threshold are the same number — separate them and the console
(`kiosk:health`) and the alert will say different things about the same
kiosk.

**Two limits worth knowing.** There is no shared Alertmanager across
different clients' installations: each installation has its own, with its
own recipients, and that is deliberate (ADR-016 — the vendor receives no
alert, ADR-020). And the series that feeds `QuioscoSinLatido` lives in
Redis: if Redis is flushed, a kiosk that was already silent can lose its
series — and with it, its ability to trigger the alert — until its next
heartbeat, which by definition will not arrive; `kiosk:health` does not
depend on Redis and is the second safety net. The full detail is in
[`quiosco-no-responde.md`](../../runbooks/quiosco-no-responde.md) (in Spanish).

Grafana, like Alertmanager, **is not exposed to the internet** — see the
start of this section.

---

The obligations that go with all of this —what to report, what to archive, who
authorises— are in
[`legal-obligations.md`](legal-obligations.md).

What can be **changed** —operational thresholds, branding and languages— and
what consequences each change has is in
[`configuration.md`](configuration.md).

---

## 11. Updating to a new version

> **Task 5.7.** The full procedure, with the manual rollback, is in
> [`../../runbooks/actualizacion-cliente.md`](../../runbooks/actualizacion-cliente.md) (in Spanish).
> Here, what you need to know every time.

**Clocking does not stop.** During the update the panel, the portal and the
management API answer "under maintenance" (503), but the kiosks keep confirming
locally and queueing; when it finishes they sync with the real time of each
clock-in. If the window was long, the inbox will show sync incidents: they are
not a fault.

```bash
cd /opt                                   # el paquete nuevo, AL LADO del actual
tar xzf kronoqr-<version>.tar.gz
cd kronoqr-<version>
sudo ./update.sh --check-only             # sin tocar nada: qué falta, si falta algo
sudo ./update.sh                          # actualiza, verifica y vuelve atrás sola si falla
```

(Unpack the new package **next to** the current one; `--check-only` touches
nothing and says what is missing, if anything; the plain run updates, verifies
and rolls back on its own if it fails.)

The seven steps and what happens if each one fails:

| Step | If it fails | State it leaves | What to do |
| --- | --- | --- | --- |
| 1 · Preconditions | Exits `2` | **Nothing touched.** Stays on its version | The «Que hacer» (what to do) line of each `[FALLA]` (failure). If it says the installed version is outside the matrix, update first to the intermediate version it names |
| 2 · Maintenance | Exits `4` | Maintenance lifted; nothing touched | Retry. If it happens again, `docker compose logs app` |
| 3 · Pre-update backup | Exits `2` | Maintenance lifted; nothing touched. **No backup, no update** | [`../../runbooks/restaurar-backup.md`](../../runbooks/restaurar-backup.md) (in Spanish) §2 and run again |
| 4 · Migrations | Automatic rollback → `4` | Backup restored, previous version running and verified | Send the report to the vendor before retrying: it says at which intermediate version it stopped |
| 5 · Start-up and verification | Automatic rollback → `4` | Same as above. **The new version never received traffic**: it is verified without the edge | Same as above |
| 6 · Rollback | Exits `5` | **Requires a person.** The message distinguishes two cases: only maintenance mode was left on (lift it with `artisan up`, **without restoring anything**) or the restore was left half-done (three orders and the path of the backup) | Runbook §5. The kiosks keep queueing meanwhile |
| 7 · Report | — | `BACKUP_PATH/reports/update-<fecha>.log`, always; next to it, `update-<fecha>.detalle.log` (root only, raw output, **may contain personal data**) | Attach the report to the diagnostic bundle if you open a case; the detail file, only after reviewing it and if asked for |

Steps 5 and 6 also leave their own entry in `audit_log` (`system.updated` or
`system.restored_from_backup`): if for whatever reason it cannot be written,
the update is not rolled back because of that —the fact already happened—,
but an update that otherwise finished fine exits `6` instead of `0`, and the
report says so on its own line. The rollback entry is only written if the version
you roll back to already knows that action (from 2.2.0): an earlier one could not
verify the chain with it, so the report keeps the data and you write it with
`compliance:record-system-event` after the next update.

**What does not change:** your secrets (the `.env` is copied as is and only
`IMAGE_TAG` changes), the data, the licence (an expired licence **does not
prevent updating**), and clocking. **What is needed:** `BACKUP_ENCRYPTION_KEY`
in the `.env` and space for the backup and for the migration; step 1 says so
with figures.

**Which versions you can jump from** to the package's, without touching
anything: `./update.sh --supported-sources`. The rule is the current minor
version and the two before it; from an older one, the script tells you which
one to go to first.

**A second run** on an already updated installation: exits `3`, "already on the
version", and touches nothing.

---

## 12. Diagnostics and support: `doctor`, the bundle and the grants

Three tools, and all three work with the licence expired or not activated: they
are precisely what you need when something goes wrong.

### 12.1 `doctor`: the health check in one command

```bash
docker compose exec app php artisan product:doctor          # informe legible
docker compose exec app php artisan product:doctor --json   # el mismo informe, para máquinas
docker compose exec app php artisan product:doctor --lang=en
```

(The first form prints a readable report; `--json` prints the same report for
machines.)

It checks the database (connection, pending migrations, that the application
user **cannot** modify the audit trail, audit chain), queues (Redis, backlog,
that there is a live worker), mail (transport configured and server
reachable), TLS certificate (expiry and self-signed), permissions (working
directories, backups, logo), disk space (application and backups) and settings
(time zone in UTC, debug mode, invalid keys, differences between the `.env` and
what is stored, licence and branding). **Every red line says what to do**,
written for someone who does not know the system.

| Code | Meaning |
| --- | --- |
| `0` | All correct |
| `1` | **Warnings only.** Nothing is broken; worth reading when you can. The licence status never goes beyond this |
| `2` | **At least one failure** that has to be fixed. The installation stays up and clocking carries on |

`install.sh` runs it at the end and only a `2` stops it (it exits with `6`,
with the installation up); a `1` is shown and does not block. `update.sh` also
runs it, but **only reports**: its result goes into the update report and to
the screen, and an environmental failure —disk, expired certificate— does not
roll back an update that has already been verified by other means.

If the application **will not start** and you cannot run `artisan`, there is
`./doctor.sh` (§8): it does what it can from outside —Docker, the state of each
service, `.env`, disk, certificates, ports— and tells you how to start it.

### 12.2 The diagnostic bundle: what it is and how it is generated

When you open a ticket with support, the first thing they will ask for is the
bundle. You generate it yourself, with one click or one command, and send it
through your contract's channel. **Support does not go into your server**
(ADR-020).

- **From the panel:** "Support" → "Diagnostics package" → "Generate and
  download". Only the administration account sees it.
- **From the console:**

  ```bash
  docker compose exec app php artisan product:diagnostics
  ```

  It leaves the file in `storage/app/diagnostics/` inside the container and
  tells you the path, the size and the fingerprint. Copy it out with
  `docker compose cp app:/var/www/html/storage/app/diagnostics/<fichero> .`
  and **delete it from the server once you have sent it**: it is disposable
  material. In case that is forgotten, the command itself deletes on start-up
  any bundle older than `PRODUCT_DIAGNOSTICS_RETENTION_DAYS` days (7 by
  default) and says so.

It is **a single readable JSON file**, `kronoqr-diagnostics-<versión>-<fecha>.json`,
unencrypted on purpose: **open it before sending it** and check that it carries
nothing you do not want to send. Its header (`manifest`) carries the
fingerprint of the rest of the document; whoever receives it can check that it
was not altered on the way with `php artisan product:diagnostics --verify=<fichero>`.

**What it carries:** version and environment, configuration **only from an
allow-list of keys** (never `LICENSE_KEY`, QR signing keys, backup encryption
keys, Reverb keys, or database or mail credentials), the state of the services
and the queues, the `doctor` report, the licence status **without your company
name**, the health of each tablet **without its name**, the grouped error
history (from the version that adds it), aggregate counters, the report of the
last update (only the lines in the report format, and never its
`.detalle.log`) and **only counts** of the audit trail.

**What it does not carry, by default:** anyone's names, emails, documents,
clock-ins or working days. Employees appear only as an identifier. An
automated test of the product checks this on a bundle generated with 500
employees and 90 days of clock-ins.

### 12.3 Including personal data is a separate action

If the ticket requires seeing specific clock-ins —one person's payroll
discrepancy, for example— you can include them. **It is your decision, explicit
and audited**, never the default:

- Panel: tick "Include personal data"; the screen tells you what will be
  included and that it is recorded, and only then lets you generate.
- Console: `php artisan product:diagnostics --with-personal-data --period-days=7`
  (31 days at most).

It adds the workforce —**only the people with activity in the period or with an
open incident**, never the whole workforce— with code, name, status and
department, the clock-ins and shift entries of the period, and the open
incidents. The bundle is marked as **not anonymised** and
`diagnostics.personal_data_included` appears in your audit trail. By sending it
you are communicating personal data to a third party: read
[`legal-obligations.md`](legal-obligations.md) §8 first, and have the
processing agreement signed.

### 12.4 Granting support temporary access

It is the exception, not the rule: only when the bundle is not enough. You
grant it, with a reason, a scope and a duration, and you can revoke it at any
time.

- **Panel:** "Support" → "Support access grants" → reason, scope, hours →
  "Grant access". The token is shown **once only**: copy it and hand it to
  support through the contract's channel. If it is lost, revoke and create
  another.
- **Console:**

  ```bash
  docker compose exec app php artisan support:grant --hours=24 --reason="Incidencia #123"
  docker compose exec app php artisan support:grant --hours=8 --reason="Incidencia #124" --scope=read_only
  docker compose exec app php artisan support:revoke <uuid>     # una concesión
  docker compose exec app php artisan support:revoke --all      # todas las activas
  ```

  (`support:revoke <uuid>` revokes one grant; `--all` revokes every active one.)

| Scope | Support can | Cannot |
| --- | --- | --- |
| `diagnostics` (default) | Generate the **anonymised** bundle and consult errors | See anyone |
| `read_only` | In addition, **read** working days, workforce and audit trail | Change anything |
| `configuration` | In addition, **change** the operational settings and pair or unlink kiosks | See working days or the workforce, or touch the compliance profile (legal thresholds and retention years are yours), **or turn break clocking on or off** (`ATTENDANCE_BREAK_CLOCKING`: it decides what counts as an incident, just like the profile; any attempt gets a 403), **or see or change the kiosk service code**: it arrives empty and marked as redacted, and any attempt to change it gets a 403 (§16.5) |

With no scope can it activate licences, grant or revoke access, issue or revoke
cards, correct clock-ins, generate payroll reports or the export for the
Labour Inspectorate, or include personal data in a bundle.

**What is recorded**, and you see it on the same screen and in your audit
trail: who granted, why, with which scope, until when, **when it was last
used** and when it was revoked (`support_grant.granted`, `support_grant.used`,
`support_grant.revoked`). The access **expires on its own**: when the time
comes, the token stops working without anyone doing anything. 72 hours at most
per grant (`PRODUCT_SUPPORT_GRANT_MAX_HOURS`).

During that intervention the vendor is the data processor for that specific
case: [`legal-obligations.md`](legal-obligations.md) §8.

### 12.5 The parameters

None of them is edited from the panel: they live in the `.env` and whoever
administers the server changes them.

| Variable | Default | What it governs |
| --- | --- | --- |
| `PRODUCT_DIAGNOSTICS_MAX_BYTES` | `8388608` (8 MiB) | Maximum size of the bundle. Above it, sections are trimmed, starting with personal data, and the trimming is noted |
| `PRODUCT_DIAGNOSTICS_RATE_LIMIT` | `3` | Bundles per minute and per account from the panel. Generating one walks the whole installation |
| `PRODUCT_DIAGNOSTICS_PERSONAL_DATA_MAX_PERIOD_DAYS` | `31` | Maximum days of clock-ins that fit with "Include personal data". Raising it widens what leaves your server in one file |
| `PRODUCT_DIAGNOSTICS_RETENTION_DAYS` | `7` | Days a bundle generated from the console stays in `storage/app/diagnostics` before the next `product:diagnostics` deletes it |
| `PRODUCT_SUPPORT_GRANT_DEFAULT_HOURS` | `24` | Duration of a grant if none is given |
| `PRODUCT_SUPPORT_GRANT_MAX_HOURS` | `72` | Maximum duration accepted; anything longer is rejected |
| `PRODUCT_SUPPORT_USE_AUDIT_WINDOW_SECONDS` | `900` | How often, at most, a new `support_grant.used` is recorded per grant, so that a support session does not flood your audit trail |


---

## 13. Taking all your data with you: the full data export

> **This is not the reports HR generates in the background**, and it is worth not
> mixing them up when somebody asks about "an export that never arrives". They
> are two different mechanisms, with two different folders and two different
> purges:
>
> | | **Full data export** (this section) | **Background report** (§6) |
> | --- | --- | --- |
> | What it is | A ZIP with the **whole** installation | A CSV, Excel or PDF of a report or of the payroll export |
> | Who asks for it | Only the installation administrator | Whoever generates reports: HR, administration and managers |
> | How many at a time | One **in the whole installation** | One **per requesting person**: nobody gets in anybody's way |
> | Who downloads it | The administrator, with their panel session | **Only the person who asked for it**, with a **single-use** link that expires in minutes and carries no session |
> | How long the file lasts | `PRODUCT_DATA_EXPORT_RETENTION_DAYS` (7 days) | `REPORTING_EXPORT_RETENTION_DAYS` (7 days) |
> | Who purges it | The hourly task | The daily task |
>
> What they do share: **the file is deleted and the record that it existed is
> not**, and every download is logged.
>
> With one detail specific to the background report: when the file is deleted,
> the record **also loses the employee and the scope that were queried**. That
> detail is not lost —it lives in the audit trail, which is where it has a legal
> retention period and write protection— but it stops sitting in an operational
> table where nobody needs it any more.

### 13.1 What it is

A single ZIP file, `kronoqr-export-<versión>-<fecha UTC>.zip`, with
**everything** in your installation in open formats: one CSV per table
(workforce, contracts, absences with all their versions, cards, kiosks, shift
entries with all their versions, corrections with author and reason, totals,
incidents, scans, reports generated in the background, the complete
audit trail with its hash chain, management accounts, support access grants),
JSON for the configuration, the compliance profile and the licence, a
`manifest.json` with the row count and the `sha256` fingerprint of each file,
and a `README.md` that explains each file and each column in the language of
the installation. Dates and times are in **UTC**; the `README` states the
site's time zone and how to convert them.

**No secret leaves**: no passwords, no PINs, no hashes, no licence key. No
internal numbers: references between files go by `uuid`. Confidential
configuration keys travel **marked as redacted and without their value**
(`value_redacted`), just as in the audit entry that records their change: **the
kiosk service code travels marked as redacted, not in the clear** (§16.5).

It is your continuity guarantee (RL-20): **it works with the licence expired,
absent or unreadable**, only the installation administrator generates it, and
**the vendor's support can never generate it**, with any scope. What holding
that file in your hand implies is in
[`legal-obligations.md`](legal-obligations.md) §7 quater.

### 13.2 How it is generated

**From the panel:** Licence → "Your data is yours" → "Generate a full export".
You confirm the notice, the export is queued and the screen follows it
(`Queued` → `Generating` → `Ready to download`); when it finishes, "Download"
appears. There can only be **one in progress** at a time: if someone has
already requested one, the panel shows you that one instead of starting
another.

**From the console**, on the spot and in the foreground:

```
docker compose --env-file .env -f compose.prod.yaml exec app php artisan product:export-all
```

It leaves the file in `storage/app/exports/` inside the container, prints the
path, the size, the fingerprint and the row count of each file, and records the
export just as if you had requested it from the panel (it appears in the same
list and can be downloaded from there). To get it out of the container:

```
docker compose --env-file .env -f compose.prod.yaml cp app:/var/www/html/storage/app/exports/<fichero> .
```

**When the console is the better choice.** The panel downloads the whole ZIP
into the browser's memory before saving it. Above ~1 GB —several years of a
large workforce— generate from the console and get the file out with `cp`. The
`X-Kronoqr-Export-Sha256` header of the download and the fingerprint the
command prints are the same: they serve to check that the file arrived whole.

### 13.3 How long it lasts and what is recorded

The ZIP **expires** after `PRODUCT_DATA_EXPORT_RETENTION_DAYS` days (7 by
default): every hour the expired ones are deleted and the export moves to
`Expired` in the list; the record that it existed, with its counts and its
fingerprint, is never deleted. If you need the file later, generate another.

Your audit trail keeps `data_export.requested` (who requested it and through
which channel), `data_export.generated` (counts, fingerprint and size) and
**`data_export.downloaded` for every download**: in the event of a breach you
can answer who took what and when.


**If it gets stuck on "Generating".** An export that is interrupted halfway
—because you stopped the containers to update, or because the queue worker
restarted— blocks nothing: once the maximum generation time (one hour) has
passed, the system marks it as **failed** with reason `stale` as soon as
someone requests another or in the next hourly purge, and you can generate
again. There is no need to touch the database; if you really see one in
progress for more than an hour without it moving to failed, run
`php artisan product:export-all --purge` and request it again.

**If it shows as "Could not be generated".** The reason the panel shows is a
code, not free text, so that no data from a row is ever put on the screen or in
the log: `write_failed` (could not write to `PRODUCT_DATA_EXPORT_PATH`: check
the space and permissions of the directory), `database_error` (the database
failed partway through: look at `product:doctor`), `stale` (interrupted, see
above) or `unexpected` (anything else: the technical detail is in the
application log with the export's `uuid`). Fix the cause and generate another.

### 13.4 Telemetry, if you enable it

It ships **disabled** and the system works exactly the same without it. It is
only sent if **three** conditions are met at once: `TELEMETRY_ENABLED=true`,
an `https://` destination in `TELEMETRY_ENDPOINT` and a licence that includes
the `telemetry` feature (`php artisan license:show` shows it). If any of the
three is missing, `product:telemetry` says so and nothing is sent. When they
are met, on Mondays at 05:40 UTC a report is sent with versions, licence
status, installation size in bands and aggregate counters; **never** data
about people or working days. The exact list of fields, and the command that
shows you the document that would be sent before enabling anything, are in
[`configuration.md`](configuration.md) §3 quinquies. If the destination does
not respond, you will see no warning: it is noted in the technical log and
retried the following week.

### 13.5 The parameters

| Variable | Default | What it governs |
| --- | --- | --- |
| `PRODUCT_DATA_EXPORT_PATH` | `storage/app/exports` (in the container) | Where the ZIPs are written. Outside `BACKUP_PATH` on purpose: it is material that expires |
| `PRODUCT_DATA_EXPORT_RETENTION_DAYS` | `7` | Days the ZIP can be downloaded before it is purged. The record is kept |
| `PRODUCT_DATA_EXPORT_RATE_LIMIT` | `30` | Requests per minute **per account** to the list and the download; the per-IP-address bucket is four times larger (120), so that several administrators behind the same internet gateway do not block each other. The panel polls every 5 s while one is in progress |
| `PRODUCT_DATA_EXPORT_STALE_AFTER` | `3600` | Seconds after which an export left half-done (container stopped, queue restarted) is marked as failed with reason `stale`, freeing up the next one. Do not lower it below what your largest export takes |
| `TELEMETRY_ENABLED` | `false` | Whether telemetry is sent. `TELEMETRY_ENDPOINT` is also needed, and the licence has to include it |
| `TELEMETRY_ENDPOINT` | empty | Where it is sent. Empty by default: you set it |

---

## 14. What must never be touched

Six things a system administrator does every day on other products and that
here destroy the legal value of the record or leave the installation unable to
recover:

| Never | Why | What to do instead |
| --- | --- | --- |
| **Modify data with direct SQL** (`UPDATE`, `DELETE` or `INSERT` on the application tables) | Every correction keeps the previous version with author, moment and reason. A change by SQL leaves no trace and makes the record unreliable before the Labour Inspectorate | Corrections are made from the panel, and are traced |
| **Delete or alter rows of `audit_log`** | It is append-only and every entry is hash-chained to the previous one. The application's database user **does not have** `UPDATE` or `DELETE` on that table, on purpose; only the maintenance role can drop partitions already expired, and only in the confirmed purge (§3) | If the chain does not verify: [`../../runbooks/rotura-cadena-auditoria.md`](../../runbooks/rotura-cadena-auditoria.md) (in Spanish) |
| **Touch `daily_totals` by hand** | It is a rebuildable projection: it is recalculated in full every time a shift entry changes. A total corrected by hand goes back to its value on the next recalculation, without anyone understanding why | If a total does not add up, recalculate it: `docker compose exec app php artisan attendance:reconcile --from=2026-09-01 --to=2026-09-30` |
| **Edit a generated secret in the `.env`** (`APP_KEY`, `QR_SIGNING_KEY_*`, `BACKUP_ENCRYPTION_KEY`) | Changing `APP_KEY` makes everything encrypted unreadable; changing the QR key invalidates every card; changing the backup key leaves the previous backups impossible to restore | Rotate with its procedure: [`../../runbooks/rotacion-secretos.md`](../../runbooks/rotacion-secretos.md) and [`../../runbooks/rotacion-clave-qr.md`](../../runbooks/rotacion-clave-qr.md) (in Spanish) |
| **`migrate:rollback`, deleting volumes or reinstalling on top** | A rollback is always restoring the previous verified backup; the installer refuses to reinstall over an existing installation | `update.sh` (§11) and [`../../runbooks/restaurar-backup.md`](../../runbooks/restaurar-backup.md) (in Spanish) |
| **Unpairing a kiosk with pending clock-ins, or clearing its site data**, so that the alert stops firing | The clock-ins in the local queue live only on that tablet until they are sent: unpairing it or wiping it loses them, and they are working days of people who did clock in | Empty the queue first (§16.4) and check that it is at 0. If the tablet is lost and there is no alternative, unpair it and tell HR: those hours have to be rebuilt by manual correction with `FALLO_TECNICO_QUIOSCO` |

---

## 15. The error history: what is failing and since when

> **Task 5.12.** How to read it and what to do with each severity, step by
> step, is in [`../../runbooks/errores-en-el-panel.md`](../../runbooks/errores-en-el-panel.md)
> (in Spanish): this section is the summary for knowing what it is and how it
> is used day to day, not the diagnostic procedure.

### 15.1 What it is

Every error in the application —from a request, a queue job, a scheduled
task or a console command— and every error reported by the three client
applications (kiosk, panel, portal) is kept in `error_events`, **grouped by
fingerprint**: the same repetition does not create a new row, it raises
`occurrences` and updates the date of the last time. It is kept for 90 days
and purges itself (§15.4).

It is not your audit trail (`audit_log`, four years, evidentiary value) nor
your technical log (Loki, optional, you may not have it). It is the one thing
that always exists, in the same database you back up daily, to answer
**"what is failing, and since when?"** without having to know the system
from the inside. **It never carries anyone's name, email or clock-ins**: only
technical identifiers. This is not a promise without a mechanism — the server
sanitises the message (emails, national ID numbers, phone numbers, times, and
any text between quotes, which is where an exception interpolates a variable
value), a database failure never prints what it was trying to save, and the
context only accepts a closed list of technical keys. The full mechanism, one
by one, is in
[`../../runbooks/errores-en-el-panel.md`](../../runbooks/errores-en-el-panel.md)
(in Spanish) §2.

One thing about level: **the `critical` level for a client error is only
ever produced by a kiosk** — the panel and the portal never generate a
`critical` row, and the possible codes are a closed catalogue per origin
that the server validates.

### 15.2 The panel screen

**Errors**, with filters for origin, severity, status and period. Each row
carries the severity, the origin, the message, how many times it has
happened (`occurrences`), the first and the last time, and a copyable
`trace_id`. Each row's detail includes a **what to do** text, written for
someone who does not know the system. A **"Mark as resolved"** button closes
it; if the same error happens again, the row reopens on its own, without you
having to do anything — that is the sign that the fix was not one. **Who
resolved it** is shown with a name to a regular management account, but not
to a support access granted to the manufacturer: that access sees that the
row is resolved, never who resolved it.

### 15.3 The commands

To look it up from the console, or so a script can ask on your behalf:

```bash
docker compose exec app php artisan product:errors --since=24h --level=critical
```

It exits `0` if none are open, `1` if there is one of level `error` and `2`
if there is one `critical` — the same criterion as `product:doctor`. With
`--json` it gives the same for machines; with `--source=` it narrows to one
origin (`api`, `worker`, `scheduler`, `console`, `kiosk`, `admin`, `portal`).

### 15.4 The 90-day purge and `ERROR_HISTORY_RETENTION_DAYS`

Every day, at 03:35 UTC, rows whose last occurrence is older than
`ERROR_HISTORY_RETENTION_DAYS` days (90 by default) are deleted. **It asks
for no confirmation and leaves no record**: it is a purge of technical data
with no legal value (RL-11), not the retention of the working-time record
(RF-PR-03, section 3, which does require the confirmation phrase). To check
what it would delete without deleting anything:

```bash
docker compose exec app php artisan product:errors:prune --dry-run
```

And, as with the rest of the record, **`error_events` is not edited by
hand**: neither by direct SQL nor by touching the row from outside the panel
or these two commands. Marking an error as resolved, or letting the
automatic purge remove it after 90 days, are the only two correct ways for a
row to disappear from the open list.

### 15.5 The parameters

| Variable | Default | What it governs |
| --- | --- | --- |
| `ERROR_HISTORY_RETENTION_DAYS` | `90` | Days a row is kept from its last occurrence. Same as the technical log (RL-11) |
| `PRODUCT_CLIENT_ERRORS_RATE_LIMIT` | `12` | Requests per minute and per session from the panel or the portal to report errors; four times more per IP address |
| `PRODUCT_ERRORS_MAX_OPEN_GROUPS_PER_SOURCE` | `500` | Ceiling of **open** groups per origin. Above it, the next occurrence that does not match an existing group goes into an overflow group for that origin (`overflow`) instead of creating a row; `product:doctor` warns about the size of the table |

---

## 16. The "Kiosks" screen in the panel

> **Task 3.3.** What to do, step by step, when a kiosk stops sending signals is
> in [`../../runbooks/quiosco-no-responde.md`](../../runbooks/quiosco-no-responde.md)
> (in Spanish); registering and replacing a tablet is in
> [`../../runbooks/alta-nuevo-quiosco.md`](../../runbooks/alta-nuevo-quiosco.md)
> (in Spanish). This section is what you need in order to **read** the screen
> and to **keep custody of the service code**, not the diagnostic procedure.

**Panel → "Kiosks"** (`/devices`). It is opened by whoever holds the
`settings:*` scope —the same one as the compliance profile and the branding—
and the real authorisation is enforced by the server: **only the administrator
role** can list, pair or unpair. Registering a kiosk means creating a source of
clock-ins, and unpairing it means withdrawing one; this is not a read-only
screen.

### 16.1 What each row shows

| Column | What it says | How to read it |
| --- | --- | --- |
| **Name and status** | The name the tablet was paired with, and whether it is still paired or has been unpaired | An unpaired kiosk stays in the list with its history: the replacement tablet is paired **with the same name** to keep it |
| **Application version** | The version of the PWA that tablet has loaded | If a tablet falls behind after an update, you see it here. It catches up when the PWA is reloaded |
| **Last contact** | The instant of the last heartbeat, **in the site's time zone**, and how old it is ("40 s ago") | The heartbeat arrives **every 60 seconds**. The age is measured against the **server's clock**, which travels in the response, never against the clock of the computer you are looking from: a panel with the wrong time does not invent dead kiosks |
| **Pending** | How many clock-ins the tablet holds in its local queue without sending, and **how old the oldest one is** | "37 pending, the oldest 3 h ago" is a tablet that has been without network for three hours, not an error. The clock-ins are safe as long as the tablet is not unpaired and its site data is not cleared |
| **Battery** | The level and whether it is charging; **"not reported"** when the tablet does not publish the figure | Only Chrome on Android offers the battery level to the browser: a tablet that does not report it **is not faulty**, it simply does not tell. One that is draining **while not charging** is almost always an unplugged charger |
| **Status** | The **verdict** (up to date, warning, failure, unpaired) and **its reason** | It is never told apart by colour alone: every row carries its text and its icon (§16.2) |

The list is not paginated: an installation is one hotel with a handful of
kiosks, and they all fit on the screen.

### 16.2 The verdict: the same rule in the panel, in the console and in the alert

The verdict **is calculated by the server**, with the same rule and the same
thresholds used by `php artisan kiosk:health` and by the `QuioscoSinLatido`
alert (§10.4). There are not three criteria: there is one. If the panel says
"failure", the console says `FALLO` and the alert fires, for the same kiosk and
at the same time.

| Verdict | Reason shown | What it means |
| --- | --- | --- |
| **Up to date** | *Beating* | Heartbeat less than `KIOSK_HEALTH_FRESH_WITHIN_SECONDS` (120 s) old and nothing pending |
| **Warning** | *Late heartbeat* | More than 120 s without a heartbeat, but less than 10 minutes. A missed heartbeat is not a fault |
| **Warning** | *Pending clock-ins* | **It has network and still has clock-ins left to send.** See [`../../runbooks/cola-offline-atascada.md`](../../runbooks/cola-offline-atascada.md) (in Spanish) |
| **Warning** | *Low battery* | Level at or below `KIOSK_HEALTH_BATTERY_LOW_PERCENT` (15 %) **and not charging**. A wall-mounted tablet that is draining is a tablet whose charger someone removed |
| **Warning** | *Awaiting the first heartbeat* | Just paired and not talking yet. It resolves itself within a minute |
| **Failure** | *No signal* | More than `KIOSK_HEALTH_SILENT_AFTER_SECONDS` (600 s, 10 minutes) without a heartbeat |
| **Failure** | *Never seen* | Paired more than ten minutes ago and not one heartbeat |
| **Unpaired** | *Unpaired* | No longer a source of clock-ins. It counts for no alert |

When there is more than one reason, the row shows **the most serious one**, in
this order: unpaired, never seen, no signal, late heartbeat, low battery,
pending clock-ins, beating.

The legend at the foot of the screen **states your installation's real
thresholds**, not assumed ones: if you change one, the legend changes with it.
And if you change `KIOSK_HEALTH_SILENT_AFTER_SECONDS`, you have to change the
threshold of the `QuioscoSinLatido` alert in `infra/observability/` **at the
same time** (§10.4): they are the same number, and separating them is precisely
what breaks the coherence this screen exists to provide.

### 16.3 "No heartbeat" is not "no clocking in"

It is the first thing to know when you look at a row in failure. That a kiosk
is not talking to the server **does not mean nobody can clock in on it**: the
kiosk never blocks the employee. If the tablet is switched on, it keeps
accepting cards, confirming on screen and saving every clock-in in its local
queue with its real time; when the network comes back, it sends everything with
the time it actually happened.

What does leave people unable to clock in is a tablet that is **switched off,
without power or broken**. That is why the first question of the diagnosis is
not about the network: it is "is the screen on?".

The hours of whoever could not clock in are corrected afterwards from the panel
with the reason `FALLO_TECNICO_QUIOSCO`, by asking the person. **A time is
never invented.**

### 16.4 What to do when a row is not up to date

**When —and only when— the verdict is not "up to date"**, the row carries a
**"What to do"** block written for someone who does not know the system, with
the next step for its reason. A kiosk that is fine asks for nothing. The
summary:

| What you see | Where to start |
| --- | --- |
| **Failure, no signal** | [`../../runbooks/quiosco-no-responde.md`](../../runbooks/quiosco-no-responde.md) (in Spanish) §2, which starts on this very screen |
| **Warning, pending clock-ins** — the tablet **has network and still has clock-ins left to send** | [`../../runbooks/cola-offline-atascada.md`](../../runbooks/cola-offline-atascada.md) (in Spanish). **Do not unpair that tablet**: you would lose the queue |
| **Warning, low battery** | Go to the mounting point: unplugged charger, switched-off power strip or a broken cable |
| **Warning, late heartbeat** | Nothing yet. If it does not return to "up to date" within ten minutes it becomes a failure and the alert fires |
| **The tablet went back to the pairing screen on its own** | Someone unpaired it or rotated its token: [`../../runbooks/alta-nuevo-quiosco.md`](../../runbooks/alta-nuevo-quiosco.md) (in Spanish) §6 |

**If the queue does not go down even though the network at that point is
fine**, and the "oldest" in the "Pending" column stays stuck at the same time
day after day, there may be a clocking in it that will never be able to add up:
that is covered by §4 of
[`../../runbooks/cola-offline-atascada.md`](../../runbooks/cola-offline-atascada.md)
(in Spanish), "Un elemento que jamás podrá cuadrar" —an item that will never be
able to add up—. There is nothing to touch on the tablet.

The same information, from the console and without opening the panel —and the
only one that still works if Redis has been emptied (§10.4)—:

```bash
docker compose exec app php artisan kiosk:health
```

It exits `0` if everything is up to date, `1` with warnings and `2` with any
failure; `--json` gives the same for a script and `--lang=es|en` sets the
language of the report.

### 16.5 The service code and the tablet's diagnostic screen

When the problem is **in the tablet**, the tablet tells you itself. It has a
diagnostic screen that opens with a **three-second long press on the clock** of
the clock-in screen (and of the pairing screen, for a tablet that is not a
kiosk yet). It is protected with the installation's **service code**.

**Where the code is set.** Panel → **Operational settings** (`/settings`),
field "Kiosk service code" (`KIOSK_SERVICE_CODE`), administrator role. **8 to
12 digits** —digits only, because the tablet has nothing but the on-screen
numeric keypad—. **It is born empty**: until you set one, the screen opens
without a code and says so in its header; `product:doctor` (§12.1) reminds you
as a warning, never as a failure.

**How it reaches the tablets.** The code **never travels in the clear**: the
server sends its fingerprint inside the heartbeat and the tablet checks the
code against it **locally**. Two practical consequences: the diagnostic screen
**works without network** —which is exactly when you need it— and a code
changed in the panel is on every tablet **in under a minute**, without touching
any of them. Five consecutive failed attempts lock the keypad for 60 seconds,
and the lock **is stored on the tablet**: leaving the screen or reloading the
application does not skip it.

**A tablet that has not yet received any heartbeat later than the moment the
code was configured opens the screen without it.** That is the case of a paired
tablet that has been without network since before you set the code: there is no
way for it to know the code, and the alternative —refusing it the diagnosis—
would leave undiagnosed precisely the tablet in the worst shape. The screen's
own header says whether it was opened with a code or without one.

**Custody.** Your IT keeps the code, like any other maintenance credential: it
is not stuck on a label behind the tablet and it is not shared with reception.
**Only whoever can edit it can see it**: the product does not show it anywhere
except in its own field under "Operational settings", which only the `admin`
role opens; if nobody remembers it, you set another one. Nor does it appear in
the audit trail —it is recorded **that** it changed, never the value—, in the
diagnostic bundle, or in the technical logs, and in the full data export it
comes out **marked as redacted and without its value** (§13.1).

**A vendor support access neither sees it nor changes it**, with any scope —not
even with `configuration`, which can touch the rest of the operational
settings—: it arrives empty and marked as redacted, and an attempt to change it
is refused with a 403 (§12.4). The code is yours, like the compliance profile.

**What the screen shows**, and what it does not:

| Block | What it says |
| --- | --- |
| **Camera** | Permission, chosen camera, real resolution, focus and zoom. **It warns without blocking** if the background comes out blurred, if focus is not continuous or if the resolution is below 1280×720: the three causes of "the code will not read" |
| **Network** | Whether there is a connection, whether the server responds, when the last correct heartbeat was and **the tablet's clock skew** in seconds, with its sign. The screen sends a heartbeat as soon as it opens and keeps beating while it is open, so "server reachable" is a **live** signal, not the memory of the last heartbeat |
| **Queue** | Pending clock-ins, how old the oldest is, whether the tablet's storage is durable and whether it is syncing right now |
| **Roster** | How old the local copy of the workforce is and how many entries it has |
| **Token** | Whether the tablet is paired, when its credential expires, its device identifier and the kiosk name. **The token is never shown**: only eight characters of its fingerprint, so you can compare it with the panel |
| **Version** | The version of the PWA and the state of its background update |
| **Battery and screen** | Level, whether it is charging and whether the screen is being kept awake |
| **Errors to send** | How many errors the tablet has stored without being able to report |

**Not one name, not one clock-in, not the token in the clear**: the screen
identifies by device and shows counts. It is your information and it does not
leave the installation on its own; it reaches the manufacturer only inside the
anonymised diagnostic bundle, and only if you send it (§12.2).

**It does not get in the way of clocking in.** While it is open there is no
scanning, so it has a large "Back to clocking in" button and, if nobody touches
it, it **returns to the clock-in screen on its own after two minutes**. Opening
it unpairs nothing, deletes no queue, does not interrupt the sending of what is
pending and **does not stop the heartbeat**: in the panel, a kiosk whose IT is
looking at its diagnostic screen still shows as up to date.

### 16.6 The parameters

| Variable | Default | What it governs |
| --- | --- | --- |
| `KIOSK_HEALTH_FRESH_WITHIN_SECONDS` | `120` | Up to how many seconds without a heartbeat a kiosk is **up to date** |
| `KIOSK_HEALTH_SILENT_AFTER_SECONDS` | `600` | From how many seconds without a heartbeat it is in **failure**. **It is the same number as the `QuioscoSinLatido` alert**: both are changed together, or neither is |
| `KIOSK_HEALTH_BATTERY_LOW_PERCENT` | `15` | Level below which, **and while not charging**, the kiosk raises a battery warning |
| `KIOSK_SERVICE_CODE` | *(empty)* | The 8 to 12 digit code for the diagnostic screen. **It is not a `.env` variable**: it is changed in the panel, under "Operational settings", and takes effect on the next heartbeat |

---

## 17. Server sizing and load test

> **What this section is for.** To answer, with a measurement of your own rather
> than a promise of ours, two questions that are only asked once: "does this
> server cope with my shift change?" and "what do I change if it does not?".

### 17.1 What the product promises, and what it means in a hotel

The product is published with a written threshold: **50 clock-ins per second
sustained on the server, with 95 % of the responses under 150 ms** (`RNF-P-06`
and `RNF-P-02`). **It is measured before every major version on reference
hardware and with this very command, `make load-test`, as a step of the release
procedure**, and the result travels with the version. It is the same procedure
you can repeat on your own server.

When the version is tagged there is also an automatic check on the vendor's
infrastructure, but **that one does not judge the threshold and should not be
read as if it did**: it runs on a shared machine where the server and the load
generators share the same CPU, so its latency is not comparable with that of a
dedicated server. What it does check there, which is what it contributes, are
two different things and both are needed: that the new version **has not got
worse** than the previous one measured on the same machine, and that **under
overload the record is still correct** — no duplicated entry, totals adding up
and the audit chain intact.

Translated to your hotel these are **two different limits**, and they should not
be confused:

| Where | What the limit is | Why |
| --- | --- | --- |
| **At the edge, per origin** (the web server) | From `KIOSK_VLAN_CIDR`: **a burst of 50 clock-ins straight away** and then **10 per second** (600 per minute). From any other origin, 30 per minute with a burst of 10 | Every kiosk in a hotel goes out through the same IP. See [`installation.md`](installation.md) §6 |
| **On the server, in total** | **50 clock-ins per second sustained** across all origins, with p95 < 150 ms | It is what the load test measures and what decides whether a major version ships |

**The shift change of a whole workforce fits inside the burst.** The first 50
cards go through at once; from then on the edge lets ten clock-ins per second
per origin through, which is more than a queue of people scans. The edge limit
is not there to slow you down: it is there so that a compromised machine plugged
into the kiosk VLAN is not left without a ceiling.

**What the test proves**, and it says so requirement by requirement in its
output: that the server sustains 50 clock-ins per second **from several origins
at the same time** with p95 under 150 ms; that **no entry is duplicated** even
if the kiosk resends the same clock-in; that the daily totals match the
clock-ins that produced them; and that **a rejection reveals nothing** — an
unknown card, a revoked one and one with an altered signature take the same time
and answer the same.

> **If somebody tells you "the kiosk is slow at 06:00", do not start here.**
> Start with `KIOSK_VLAN_CIDR`: it is the most frequent silent failure and it is
> checked in a minute ([`installation.md`](installation.md) §6). A kiosk outside
> that range falls under the limit meant for the internet, and no amount of CPU
> fixes that.

### 17.2 How it is run

**Where: on a test environment, never on production.** The test **creates
synthetic staff** — employees named "Carga k6 NNNN" in a department called
"Carga k6", their cards and several kiosks — and clocks in with them thousands
of times. Measure on a copy of the environment: the same machine you are about
to buy, or an equivalent one.

**Three locks, and none of them is an obstacle to be worked around:**

1. Provisioning **refuses to run against a production installation**.
2. It makes you **say out loud that this database is a test one**, through a
   variable that has no default value.
3. It only treats as its own the employees **in that department and with a
   `K6…` code**. If it finds a `K6…` code outside the "Carga k6" department it
   stops: that means the database is not the one it thinks it is, and it would
   rather touch nothing.

**What you need.** The load test **does not travel in the delivery package** —
the package carries the product, not the test bench —: it is run from a copy of
the product repository, which the vendor provides if you want to measure your
own hardware. On that machine you need **Docker with Compose v2**, **Node 20 or
newer** and the test environment's stack up.

```bash
make load-test K6_ACKNOWLEDGE_TEST_DATABASE=yes
```

That brings up **eleven load generators: ten kiosk ones and one panel one**.
Each is a separate origin — an IP — because the edge limits per IP. Ten origins
at **6 clock-ins per second** are 60 per second, 20 % above the 50 of the
threshold: that headroom is not spare. The edge is a leaky bucket — it releases
one permit every 100 ms, with an initial burst of 50 — and without slack the
test would be measuring the edge limit instead of the server's capacity.

The duration and the number of origins are changed without touching anything:

```bash
INSTANCES=10 DURATION=120s make load-test K6_ACKNOWLEDGE_TEST_DATABASE=yes
```

With fewer origins the peak drops proportionally: **ten kiosk ones is what it
takes** to reach the 50 clock-ins per second with headroom.

**If the test environment uses a self-signed certificate**, you have to say so;
against an environment with a real certificate nothing is needed:

```bash
K6_INSECURE_TLS=1 make load-test K6_ACKNOWLEDGE_TEST_DATABASE=yes
```

**What it prints.** A verdict per requirement — met or not met, with the
measured figure beside it — and the full detail in a machine-readable file, in
case you want to keep it with the go-live record:

```bash
cat load-tests/k6/.results/summary.json
```

That file also records **what it was measured with**: the `git_sha` of the
version, the `runner` (the machine), `k6_version` and `instances`. Without those
four, one figure cannot be compared with another.

**Exit codes**, so you can chain it in a script of your own:

| Code | What it means |
| --- | --- |
| `0` | Every verdict is met |
| `1` | **Some requirement is not met.** Which one, and with what figure, is in the output and in `summary.json`. This is the case to look into |
| `2` | **The measurement is not reliable, so no verdict is given.** The stack is not up, `node` is missing, the load actually offered stayed below 50 clock-ins/s, there were not enough comparable samples, or k6 could not write its results. **A `2` does not say your server is doing badly**: it says this run is no good and has to be repeated |

### 17.3 How to read the verdict and what to change

First of all: **there is no latency figure of yours written in this guide, and
there is not going to be one.** It depends on your hardware, your disk and your
network. The figure for your installation comes from your own run of
`make load-test` on your own server.

**What the vendor's "baseline" is — and what it is not.**
`load-tests/k6/baseline.json` keeps the result of one run on the vendor's
continuous integration machine: p95 and entries per second, along with the
`git_sha` of the version measured, the machine and the version of the tool. It
serves one purpose only: comparing the next version **with itself on that same
machine** and detecting that it has got worse — up to 25 % more p95 and up to
20 % fewer entries per second are allowed. **It is not a latency promise, nor
the p95 your server should give**: on that machine the server shares CPU with
the eleven load generators, so its milliseconds mean nothing outside it. If the
file is not there, the tool simply applies the budget — the 150 ms and the 50
clock-ins/s — and compares with nothing.

| What you see | What is happening | What to do |
| --- | --- | --- |
| **High p95 and spare CPU on the server**, with requests waiting their turn in PHP-FPM | Workers are missing: there is free CPU and nobody using it | Raise `PHP_FPM_MAX_CHILDREN` (§17.7). The default is **20**, the pool of the minimum server; with 4 cores and 8 GB, **40** is the recommended value |
| **High p95 and CPU at its limit** | The server really is saturated | **Do not raise `PHP_FPM_MAX_CHILDREN`**: more workers on the same CPU make p95 worse. What is missing is cores |
| **RAM at its limit** | Each PHP-FPM worker takes about **60 MB** | **Do not raise `PHP_FPM_MAX_CHILDREN`.** Forty workers are about 2.4 GB of application alone, and room has to be left for PostgreSQL and Redis. If what is missing is RAM, this is not the control to use |
| **You cannot tell where it is getting stuck** | It has to be watched while it happens | **During the run**, open the **"API health"** dashboard in Grafana (`kronoqr-api`, §10.4) and look at three things: `db_query_duration_seconds{operation}` (is it the database?), `scan_processing_duration_seconds` (is it clocking in itself?) and `queue_jobs_pending{queue}` (is background work piling up?). If the tool could read `/metrics`, those same series come out already subtracted — before and after the load — in the `server_metrics` block of `summary.json` |
| **Isolated clock-ins failing with a server error**, while the rest goes fine | A transaction got stuck and the others were waiting behind it; the database timeouts (§17.4) cut it off | Nothing urgent: the kiosk **queues and resends**, and nobody is left unable to clock in. If it repeats, follow the `trace_id` of one of those requests in the technical log (§10.3) |
| **`429` responses on valid clock-ins** | The edge limit or the per-device one is throttling | Check that the kiosks fall inside `KIOSK_VLAN_CIDR` ([`installation.md`](installation.md) §6). That is the cause in the vast majority of cases |
| **One kind of rejection clearly takes more or less time than another** | It is a product defect, not a problem with your server | Open an incident with the vendor and attach `summary.json`: a rejection that can be told apart by timing would allow working out from outside which cards exist |
| **`reject_out_of_order` comes out with a large separation** (verdict `RS-03-RN-18`) | **It is information, not a verdict that blocks.** That key measures how far a clocking rejected for arriving out of order sits from the three card rejections — signature, unknown card and revoked card —, with the signed difference: positive if the former takes longer | Nothing. Keep it with the record. That difference is accepted on purpose: a clocking out of order says nothing about which cards exist, which is what constant timing protects. The figure is there so that the decision can be reviewed with data the day it is needed |

**Raising `PHP_FPM_MAX_CHILDREN` does not move the figure if the CPU is
saturated**, and it is worth seeing that with numbers before spending an
afternoon on it. These measurements come from a four-core machine that was also
running the eleven load generators — that is, with the server and the load
fighting over the same CPU — and for that very reason they illustrate the case
well:

| Load offered | Pool | What was sustained | p95 |
| --- | --- | --- | --- |
| 12 clock-ins/s | 20 workers | 12 clock-ins/s | 157 ms (p50: 86 ms) |
| 60 clock-ins/s | 20 workers | 29 entries/s | 26 s |
| 60 clock-ins/s | **40 workers** | **27.8 entries/s** | no appreciable change |

Doubling the pool improved nothing: workers were not what was missing, CPU was.
Under the gentle load, the same machine gave a p95 of 157 ms. And under
saturation the rejections did not come from the clock-ins-per-minute limit but
from the web server's **simultaneous connection limit**, which is the typical
symptom of a server that can no longer keep up.

**The important part: in every one of those runs the post-load check came out
intact** — no duplicated entry, totals adding up and the audit chain intact. A
server at its limit **answers late; it does not write badly**. And what the
employee sees is the usual thing: the kiosk confirms, queues and resends.

**The hardware, for reference.** The published minimums are **2 cores and
4 GB**; the recommended, **4 cores and 8 GB**
([`installation.md`](installation.md) §0). The minimum sustains a workforce of
up to 100 people with the default pool; beyond that, the conversation is about
cores and RAM before it is about parameters.

**A `429` leaves nobody unable to clock in.** The kiosk never blocks the
employee: it confirms on screen, stores the clock-in in its local queue with the
real time and resends it when the server catches its breath. That is why the
test tolerates a small percentage of `429` on valid clock-ins — it is queueable
degradation — and **fails** on any rejection that would actually reach the
employee.

### 17.4 The two database timeouts

The installation applies them by default and almost nobody will have to change
them. They are here because, when they fire, the symptom shows up in this test.

- **`DB_LOCK_TIMEOUT` (5 s).** How long a query waits for a lock to be released
  before giving up. Without it, a clock-in can wait **forever** behind a stuck
  transaction, and with it everybody else waits too: the whole shift change
  stops without a single error in the record.
- **`DB_IDLE_IN_TRANSACTION_TIMEOUT` (60 s).** How long an open transaction that
  is doing nothing is tolerated. It cuts off precisely the session that left the
  lock in place.

**What they reach, and what they do not.** Both timeouts apply **only to the
service that serves requests** (`app`): clocking in, the panel, the portal and
the migrations. They do **not** reach the background jobs, the scheduler of
nightly tasks or live presence, and **they do not reach the backup**. That is
deliberate: a `pg_dump` of a large database legitimately takes far more than a
minute with a transaction open, and it cannot be aborted by a timeout meant to
stop anyone waiting in front of a kiosk. **A slow backup is not cut short by
these two values.**

**What happens when one fires:** that particular request fails, the kiosk queues
it and resends it, and the employee never notices. That is the change that
matters: **from "the whole hotel stops clocking in" to "a few clock-ins arrive a
few seconds later"**.

Lowering them makes them fire sooner and more often; raising them returns the
system to waiting indefinitely. If you change them, measure before and after
with this same test.

### 17.5 What this test does NOT do

Said so that nobody reads more than there is into its verdict:

- **It does not measure the tablet.** Neither the time from presenting the card
  to the greeting appearing on screen, nor the start-up of the kiosk
  application. Those are other requirements and the vendor's user-journey tests
  check them, in a real browser.
- **It does not replace the nightly review.** The reconciliation of the record
  (§1, 04:30 UTC) and the divergence alerts (§10.4) remain what watches that the
  totals add up day after day. The test checks that they add up **after the
  load**, once.
- **It is not run with real employees, nor against your production database, nor
  against a copy restored from production.** That last one is not an extra
  precaution: the test **issues and revokes cards** and **writes clock-ins** for
  its synthetic population. On a restore of your real data you would be mixing
  invented clock-ins with those of your workforce, in a database you might one
  day take as good. If you need realistic volume, use a test database and let
  the tool create its own.
- **It is not a security test.** It checks that rejections take the same time as
  each other; the rest of the hardening is in [`hardening.md`](hardening.md).

### 17.6 What is left in the database after measuring

Worth knowing before launching it, not afterwards.

**It cleans up after itself, when it finishes:**

- The **cards** it issued are left revoked.
- The **tokens of the synthetic kiosks** are left revoked.
- The management account "Responsable carga k6" is left **deactivated**.
- The working file `load-tests/k6/.fixtures/` **is deleted**. While the run
  lasts it contains **live cards and tokens**: it is written with `0600`
  permissions, it is not uploaded anywhere and it is not attached to any ticket.
  If a run is interrupted halfway and the file is still there, delete it
  yourself.

**It stays, and that is correct:**

- The **synthetic employees** ("Carga k6 NNNN") and their "Carga k6" department.
- The **clock-ins** it generated and the history it seeded so that the queries
  work with realistic volume.

They stay because deleting them would require the product to know how to delete
attendance records, and **the product deletes nothing**: corrections create new
versions. That is why the test is not run on a database you intend to keep. On a
test environment the answer is the usual one: seed it again.

### 17.7 The parameters

| Variable | Default | What it governs |
| --- | --- | --- |
| `PHP_FPM_MAX_CHILDREN` | `20` | How many requests are served at the same time. **20** is the pool of the minimum server (2 cores, 4 GB); **40**, the recommended one with 4 cores and 8 GB. Each worker takes about **60 MB**: the ceiling is set by RAM |
| `DB_LOCK_TIMEOUT` | `5s` | How long a query waits for a lock before giving up. **Only on the service that serves requests**, never on the backup |
| `DB_IDLE_IN_TRANSACTION_TIMEOUT` | `60s` | How long an open transaction with no activity is tolerated. It cuts off the session holding the lock, not the one waiting for it. **Only on the service that serves requests** |
| `KIOSK_VLAN_CIDR` | `10.0.20.0/24` | Range from which the edge allows the burst of 50 and the 600 clock-ins per minute. **Filled in on installing, always** ([`installation.md`](installation.md) §6) |

The first three are changed in the `.env` and **require restarting the
services**; their full entry is in [`configuration.md`](configuration.md) §6.24
and §6.15.
