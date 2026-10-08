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
>
> **Got a problem right now?** Go straight to **§18, "What to do if…"**:
> tablets that ask to be paired again, Redis that will not start, a `402`,
> reports that never finish, the failed nightly backup, exports shown as
> "Expired" after a restore and a purge whose report is missing.

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
| 04:15 UTC, daily | The time record of the last 7 days is reconciled with its audit trail: each shift entry against the last audit entry the application wrote about it | Act on the alert if it fires: it is critical and goes to security |
| Sunday 02:15 UTC | The same reconciliation over the **whole** record: it is the one that detects a deletion or an edit of an old shift entry | Same |
| 04:30 UTC, daily | Record review: open shifts, rest periods, anomalous working days | Resolve the incidents in the panel |
| 04:35 UTC, daily | Detection of anomalous credential usage patterns over the kiosk clockings of the last 30 days (§6) | Nothing: the incidents go to the department manager, not to IT |
| Monday 05:10 UTC | **Retention proposal**: report of what would be purged | Read it once something has expired |
| Every minute | How long the oldest WAL has gone unarchived is measured, which is the real maximum loss if the server went down now (§10.4, WAL alerts) | Nothing, unless it alerts |
| Hourly | Credential metrics and clean-up of temporary files | Nothing |
| Hourly | Expired full data exports are purged (the ZIP is deleted, the record of it stays), along with diagnostic bundles older than 7 days and the leftovers of interrupted generations (§12.2, §13 and §13.6) | Nothing |
| 04:25 UTC, daily | Expired reports generated in the background are purged: the file is deleted, the record of it stays (§6 and §13) | Nothing |
| Monday 05:40 UTC | **Telemetry**, only if you have enabled it (§13.4): the weekly report is sent to the destination you set | Nothing |
| Monday 06:00 UTC | **Weekly summary by email** to each department manager, only if it is enabled in the panel (§6) | Nothing: it goes to the manager, not to IT |
| Quarterly | — | **Restore drill** of the backup |
| Quarterly | — | **Go through the hardening checklist** ([`hardening.md`](hardening.md), last section): network, certificate, accounts, tablets, backups off the server |

---

## 2. The retention proposal (weekly, deletes nothing)

Every Monday a report is left **on the server, next to the backups**:

```text
BACKUP_PATH/reports/retention/retencion-propuesta-AAAAMMDD-HHMMSS.txt
```

`BACKUP_PATH` is the backup destination in your `.env` (`/var/backups/fichaje`
by default), and the containers mount it **at the same path** it has on the
server. So the report is read from the server itself, without entering any
container (change the path if your `BACKUP_PATH` is a different one):

```bash
sudo ls -lt /var/backups/fichaje/reports/retention/
sudo less /var/backups/fichaje/reports/retention/retencion-propuesta-AAAAMMDD-HHMMSS.txt
```

You can ask for it by hand at any time, and **it is safe**: it does not modify
a single row. The report lands in the same folder.

```bash
docker compose exec app php artisan compliance:apply-retention --dry-run
```

**The reports are not cleaned up on their own**: neither the product nor the
backup pruning ever deletes them, and each one takes a few kilobytes. If you
move the contents of `BACKUP_PATH` somewhere else, take `reports/` with it.

> **Up to 2.1.0 the report was left inside a container** and was lost on
> update. When moving to 2.2.0, `update.sh` rescues the ones it finds and puts
> them in this folder (§11, "When updating from 2.1.0: the generated files").

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
   `BACKUP_PATH/reports/retention/retencion-purga-AAAAMMDD-HHMMSS.txt`, on the
   server. Even though the order is run with `run --rm` —a container that
   disappears when it finishes—, the report stays: it is written to the backup
   folder, not inside the container.

**Afterwards:**

- **Archive the report** together with the written authorisation.
- **Check the report against its audit entry** (§3.1). It takes a minute, and
  it is what makes the report worth something two years from now.
- Run `docker compose exec app php artisan compliance:verify-audit-chain`. It has to finish green and
  say «Purga sellada reconocida: particion AAAA» (sealed purge recognised for
  partition YYYY). If it said anything else, that is a security incident.
- Run `docker compose exec app php artisan compliance:reconcile-work-record --full`.
  It also has to finish green: the purge leaves its audit entry with the
  cut-off date, and the reconciliation treats everything before that cut-off
  as purged. A discrepancy here is a deletion that the purge did **not** make
  ([`discrepancia-registro-auditoria.md`](../../runbooks/discrepancia-registro-auditoria.md),
  in Spanish).

### 3.1 The report is the readable copy; the evidence is the audit entry

**What proves a purge is the `retention.purge_executed` entry in the audit
log**, not the file. The entry is hash-chained with all the others and is
verified every night: nobody can change it without the verification giving it
away. The file, on the other hand, is text in a folder on the server: whoever
administers the machine can edit or delete it without a trace. That is why the
file is what you read and attach, and the entry is what you cite if anyone
disputes the purge.

Both carry **the same confirmation token** (`PURGAR-AAAA-MM-DD-xxxxxx`). In the
file it is on the line «Purga ejecutada con la confirmacion …» (purge executed
with confirmation …). To see the entries:

```bash
docker compose exec -T postgres psql -U fichaje_app -d fichaje -c \
  "SELECT occurred_at, payload->>'confirmation' AS confirmacion, payload->>'cutoff_date' AS corte, payload->>'retention_years' AS anos, payload->>'rows' AS filas, payload->'tables' AS tablas FROM audit_log WHERE action = 'retention.purge_executed' ORDER BY occurred_at;"
```

(The column aliases are in Spanish: `confirmacion` is the token, `corte` the
cut-off date, `anos` the retention years, `filas` the rows and `tablas` the
per-table counts.)

**How to check it:** find the row whose `confirmacion` is the report's, and
check that the cut-off date («anterior a AAAA-MM-DD», earlier than), the years
and the per-table counts in the report's «Registro de jornada» (working-time
record) section are the entry's.

- **If they match**, the file is faithful to what happened. Archive it with the
  authorisation.
- **If they do not match**, the entry prevails. A report that says something
  other than its entry has been modified by someone with access to the server:
  treat it as a security incident
  ([`../../runbooks/brecha-de-seguridad.md`](../../runbooks/brecha-de-seguridad.md),
  in Spanish).
- **If the file is not there** (it was lost in an update before 2.2.0, or
  someone deleted it), the entry is enough: cite its date (`occurred_at`), the
  action `retention.purge_executed` and the token. How to word it is in §18,
  "…you need to prove a purge and its report is missing".

If a purge only dropped audit partitions and deleted no row of the
working-time record, there is no `retention.purge_executed` entry: what remains
is one `retention.partition_sealed` and one `retention.partition_dropped` per
year, with the number of rows and the hashes at both ends, and the seal in
`audit_chain_anchors`, which is what `compliance:verify-audit-chain` recognises.

---

## 4. If something goes wrong

The messages below are the ones the commands print, which are in Spanish; the
meaning is given next to each.

| Symptom | What it means | What to do |
| --- | --- | --- |
| «La frase de confirmación no corresponde…» (the confirmation phrase does not match) | The report expired, or the compliance profile changed | Run `--dry-run` again and use the new phrase |
| «La cadena de la partición audit_log_AAAA NO verifica» (the chain of partition audit_log_YYYY does NOT verify) | Someone tampered with the audit trail | **Security incident.** `rotura-cadena-auditoria.md`. Do not repeat the purge |
| «La purga no ha podido completarse contra la base de datos» (the purge could not be completed against the database) | The `fichaje_maintenance` credential is missing, or the role does not have it set | The role is born without a password on purpose: give it one only for this operation as §9 explains ("`fichaje_maintenance`: the role that is born without a password") and try again |
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
| `COMPLIANCE_RETENTION_REPORT_PATH` | `BACKUP_PATH/reports/retention` | Where the proposal and purge reports are left. **They are not cleaned up on their own**: they are the readable copy of the evidence, which is the audit entry (§3.1). Leave it empty to use the default; if you change it, never inside `storage/app`, which is for files that expire: `product:doctor` warns about it |
| `DB_MAINTENANCE_USERNAME` | `fichaje_maintenance` | Role that runs the audit purge |
| `DB_MAINTENANCE_PASSWORD` | *(empty)* | **Not set in the `.env`.** It is supplied when the purge is run |

**The detection of anomalous credential usage patterns** is a separate pass
from the 04:30 review: it runs at **04:35 UTC**, between that review and the
compliance metrics calculation, and opens the "Anomalous credential usage
pattern" incidents that the department manager sees in their inbox
([`hr-guide.md`](hr-guide.md) §4.5). **IT gets nothing for what it finds**: a
clue about two specific people is reviewed in the inbox, not in an on-call
channel. What two alerts (§10.4) do watch is that the pass runs and finishes:
`DeteccionDePatronesAusente` (more than 26 hours without running) and
`DeteccionDePatronesConFallos` (the last pass left some finding without turning
it into an incident), both over the series
`pattern_detection_last_run_timestamp_seconds` and
`pattern_detection_last_failures` in the file
`BACKUP_PATH/metrics/kronoqr_pattern_detection.prom`. The thresholds of what is
looked for —seconds window, minimum days and transit between kiosks— are
changed in the panel, not here ([`configuration.md`](configuration.md) §2.1).
If you need to run it by hand (it is idempotent: it does not duplicate what it
already opened):

```bash
docker compose exec app php artisan attendance:detect-patterns
```

| Variable | Default | What it does |
| --- | --- | --- |
| `COMPLIANCE_PATTERN_LOOKBACK_DAYS` | `30` | Days back that this pass reviews. It is longer than the 7 of the incident review because "systematic" needs more than a week. It can be narrowed for one run with `--days=` |

The procedure when one of the two alerts fires, and the one for reviewing the
incident itself, is
[`../../runbooks/patron-anomalo-credencial.md`](../../runbooks/patron-anomalo-credencial.md)
(in Spanish).

**Reports generated in the background** (HR asks for them from the panel:
[`hr-guide.md`](hr-guide.md) §6.3) have their own six parameters and their own
purge. The files are **per requesting person**, they live in
`REPORTING_EXPORT_PATH` —inside the generated-files volume, §13.6—, they are
downloaded from the panel with a single-use link and the scheduler deletes them
at 04:25 UTC as soon as they expire; the record that they existed is always
kept. **They are not part of the backup and are not put back on restore**:
after a restore, whoever needs the report asks for it again (§18, "…after
restoring a backup"). If you ever need to bring the purge forward:

```bash
docker compose exec app php artisan reporting:purge-expired-exports
```

| Variable | Default | What it does |
| --- | --- | --- |
| `REPORTING_EXPORT_PATH` | `storage/app/reports` (in the `app-storage` volume) | Where those files are written. Leave it empty. If you change it, it has to stay **inside `/var/www/html/storage/app`** and must not match or overlap the other generated-file paths (§13.5); `product:doctor` fails if they overlap, and if they are outside it fails in production and warns elsewhere. **Never inside `BACKUP_PATH`**: they expire on their own and must not go into the backup |
| `REPORTING_EXPORT_RETENTION_DAYS` | `7` | Days the file can be downloaded before the daily purge deletes it |
| `REPORTING_EXPORT_LINK_TTL_MINUTES` | `15` | Minutes the download link is valid for; it is also **single-use** |
| `REPORTING_EXPORT_TIMEOUT_SECONDS` | `600` | Limit on the deferred report's query. Raise it if a large export fails on time |
| `REPORTING_EXPORT_STALE_AFTER` | `3600` | Seconds after which an interrupted generation is given up as failed and lets another one be requested. Do not lower it below what your largest report takes |
| `REPORTING_EXPORT_DOWNLOAD_RATE_LIMIT` | `30` | Downloads per minute and per IP address on the download route, which is opened without a session |

**The weekly summary by email** ([`hr-guide.md`](hr-guide.md) §6.5) is the
other Monday pass: at **06:00 UTC**, and only if the panel has it set to
`enabled` (`WEEKLY_SUMMARY_EMAIL`, [`configuration.md`](configuration.md)
§2.1), every active department manager with an email address receives the
previous week —Monday to Sunday, in the site's calendar— for their scope. **It
has no parameter in the `.env`**: the switch is in the panel and the email goes
out through the same SMTP of section 6.21 of that guide. Three things worth
knowing before somebody asks why theirs has not arrived:

- **The pass never fails for being unable to send, but it does say so.** It
  leaves in the technical log (`reporting.weekly_summary`) the counts —sent,
  skipped, failed— and the reason when it sends nothing: `disabled` (switched
  off in the panel), `mailer_silent` (mail is on a transport that does not
  send, `MAIL_MAILER=log` or `array`), `not_in_plan` (the licence does not
  include `weekly_email_summary`; the rest of the product carries on as usual)
  or `no_recipients` (no active manager with an email address). An SMTP failure
  with one manager is logged as `reporting.weekly_summary_not_delivered`,
  counts as failed, **does not abort the others** and makes the command exit
  with code `1`; that week stays pending for them and **the following Monday
  does not catch it up**: the pass only looks at the previous week. The retry
  is the command below with `--week`.
- **Repeating it does not resend, and the order of the send matters.** For
  each manager: (1) a short transaction **claims** the week in the
  `weekly_summary_deliveries` table (manager and week; the unique index closes
  the race); (2) the report is composed and the email is sent **outside any
  transaction**; (3) if it went out, another short transaction writes the audit
  entry; (4) if it did not, the claim is withdrawn and the week is free for
  `--week`. Consequences: a second run skips what was already delivered; two
  simultaneous passes do not duplicate —the second one sees the claim and
  skips—; and **a failed send leaves no audit entry**, on purpose: the entry
  describes a disclosure that happened, and counting failed attempts would
  inflate the scope of a breach. You can run it as many times as you like.
- **Every email leaves an audit entry** (`personal_data.accessed`, dataset
  `weekly_summary`) with the recipient, the week and the identifiers of the
  people included, never their names: it is the trail that answers "whose data
  went to whom"
  ([`../../runbooks/brecha-de-seguridad.md`](../../runbooks/brecha-de-seguridad.md)
  §4, in Spanish). The email itself does carry names, like the daily incident
  notice, and the same cautions about the SMTP relay apply to it
  ([`hardening.md`](hardening.md) §7).

To run last week's without waiting for Monday, or to resend one specific week
(in ISO format, year and week number):

```bash
docker compose exec app php artisan reporting:weekly-summary
docker compose exec app php artisan reporting:weekly-summary --week=2026-W37
```

The pass writes two textfile series —when it last ran and how many emails it
sent— to `BACKUP_PATH/metrics/kronoqr_weekly_summary.prom`, **with no alert on
purpose**: it is an accessory, optional feature. If the pass itself blows up, it
shows in the error history (§15) like any other scheduled command.

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
| `6` | **The work was done but the subsequent verification failed.** Nothing is undone | `install.sh`: the services are up, check the certificate and the logs. `backup.sh`: the backup exists but **does not verify: treat it as non-existent**. `restore-drill.sh`: today the record could not be recovered. `update.sh`: **almost never** (every failed verification of the new version rolls back); the only exception is that the `system.updated` entry in `audit_log` could not be written after an update that did finish — the work was done and is not undone because of that. `restore.sh`: **the database is restored and in service, but the `system.restored_from_backup` entry in `audit_log` could not be written**; do not restore again, write the entry with the command in the message (`restaurar-backup.md` §6.7, in Spanish). `restore.sh`, `restore-drill.sh` and `backup.sh verify`, **since 2.2.0, also on integrity**: the `.sha256` is missing, the MAC does not match, the file name is not the one in its header, the authenticated manifest (`.manifest.mac`) is missing or does not match, or the backup is from 2.1.0 and was not explicitly requested; in that case **nothing has been touched** (§18, "…the restore refuses on integrity grounds"). `doctor.sh`: **the diagnosis has found at least one failure** — with the application running, in its own report (`product:doctor`); with the application stopped, in one of the external checks. The message says what to read |
| `7` | **Security guarantee broken. NOTHING applied.** A database role has more privileges than allowed, or a backup tried to change them (AUD-1). It is not a fault: it is a guarantee the product refuses to bypass | `backup.sh`: the role used to copy is a superuser, or can create roles or databases, or bypass RLS; no backup was written and the failed-backup alert fires. `restore.sh` and `restore-drill.sh --mode database`: the backup changed cluster roles when restored; no database was swapped and the roles were put back as they were. Follow `rotacion-secretos.md` («El rol de las copias es privilegiado») or `restaurar-backup.md` §6.6 (both in Spanish) |
| `129`, `130`, `143` | **Interrupted before writing anything**: `129` dropped SSH session, `130` Ctrl+C, `143` `kill`. NOTHING written | `install.sh` and `update.sh`: only in the phases that have not touched anything yet; once they start writing, an interruption is treated as a failure and exits with `4` or `5` after rolling back. Run them inside `tmux` or `screen` (`installation.md` §1.4) |

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

**If the whole server is lost, you also need other secrets from the `.env`**, kept off the server with the same
custody as the backup key: `APP_KEY`, `QR_SIGNING_KEY_CURRENT` and `QR_SIGNING_KEY_PREVIOUS` with their `_ID`, and
`IDENTITY_PIN_SEALING_SECRET_KEY`. A new installation generates new ones: without the old ones, no printed card is
valid and every card has to be reprinted, and without `APP_KEY` no management account passes the second factor. Do
not keep the whole `.env`: it holds database passwords that are not restored. **Whoever has the QR signing key can
make valid cards**: treat it like the building's master key. Renew this copy after every secret rotation. The full
procedure is in
[`../../runbooks/perdida-total-del-servidor.md`](../../runbooks/perdida-total-del-servidor.md) (in Spanish).

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

**From 2.2.0 on the containers do not receive the whole `.env`**, only the
variables they need ([`configuration.md`](configuration.md) §6.2), and these
commands still work as they are: `-e DB_MAINTENANCE_PASSWORD=…` hands the
password **only** to that command's ephemeral container, which disappears when
it finishes (`--rm`); the running `app` never sees it. And the `ALTER ROLE` is
done inside the `postgres` container, not the application's.

### The panel accounts: creating, deactivating and the password

**Since 2.2.0, management accounts are handled from the panel**, with an
administration account: Panel → **Accounts**. That is where the list is —name,
email, role, status, second factor, whether the password is the holder's own or
temporary, and last sign-in— together with the four actions: **create**,
**deactivate**, **reset password** and **reset 2FA**. The three that create or
remake a credential (creation and both resets) also ask, in the same dialog,
for **your** second-factor code —or your password, if your account does not
have one—: a session left open on someone else's computer is not enough.
Deactivation and both resets ask for a **reason**, which goes into the audit log
(no health data and no value judgements). The step-by-step procedure, and how
to check afterwards what was done, are in
[`../../runbooks/cuentas-de-gestion.md`](../../runbooks/cuentas-de-gestion.md)
(in Spanish).

**The password of a new or reset account is temporary.** The server generates
it, it is shown **once only**, it is handed over in person and it expires after
`IDENTITY_TEMPORARY_PASSWORD_TTL_HOURS` (72 by default,
[`configuration.md`](configuration.md) §6.8). With it, the panel only lets the
holder set their own password or sign out. Anyone can change their own password
whenever they like from “Change my password”, under their name in the menu.

What the panel does **not** let you do, on purpose, and answers with a notice:
deactivate yourself, deactivate the **last** active administration account,
reset your own password or your own second factor (that is what “Change my
password” is for), or reset the second factor of someone who does not have one
active. **Always keep two administration accounts**: if the only one loses their
phone, only the console is left.

**The console still exists as the recovery route**, for when the panel is not
available or nobody with the administration role can sign in. It applies the
same rules —it will not deactivate the last administrator either— and leaves
the same records, with “console” as their origin (the comments are in Spanish:
“list”, “create”, “deactivate”, “new temporary password”, “remove the second
factor”):

```bash
# Lista de cuentas: UUID, correo, rol, estado, 2FA y tipo de contraseña. Lleva correos: no la guardes en un fichero
docker compose exec app php artisan identity:list-users

# Alta con su rol. Pregunta nombre y correo, y muestra UNA vez la contraseña temporal
docker compose exec app php artisan identity:create-user --role=rrhh

# Baja. Deja de poder entrar, y sus sesiones abiertas dejan de valer al instante
docker compose exec app php artisan identity:deactivate-user persona@tuhotel.example --reason="Baja del hotel"

# Contraseña temporal nueva, mostrada UNA sola vez
docker compose exec app php artisan identity:reset-password persona@tuhotel.example --reason="Contraseña olvidada / Forgotten password"

# Retirar el segundo factor a quien perdió el móvil, para que lo dé de alta otra vez (UUID de la lista)
docker compose exec app php artisan identity:2fa-reset 0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90 --reason="Móvil perdido / Lost phone"
```

**A department's manager is not set through SQL either.** It is chosen in
Panel → **Departments**, with the administration account
([`hr-guide.md`](hr-guide.md) §7 bis.2), and it is recorded in the audit log.

Four things worth knowing about deactivation:

- **It deletes nothing and cannot be undone.** The account keeps its whole
  history, which is exactly what makes it possible to answer, months later, "who
  corrected this working day?". The only thing it loses is the ability to sign
  in. There is no reactivation: if the person comes back, a new account is
  created for them, with a different email, because the email of a deactivated
  account is no longer accepted.
- **It takes effect on the next request**, not when the session expires: if that
  person had the panel open on a tablet, it stops working immediately.
- **It withdraws the support access grants that account issued** and that were
  still in force (§12.4). A temporary access for the manufacturer does not
  outlive the person who answered for it.
- **It does not reopen the creation of the first administrator.** That door
  stays closed even when there are deactivated accounts — if it reopened,
  removing someone would be a way of creating an administrator without
  credentials.

The temporary password, whether shown by the panel or by
`identity:reset-password`, **cannot be looked up again**: the product stores its
digest, not the password. Write it down at that moment and hand it over in
person, never by email or messaging. If it is lost before being handed over,
reset it again.

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
| Week | `docker compose exec scheduler php artisan backup:verify` — that the latest backup exists and verifies |
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
| `KioskQueueStorageDegraded` | The tablet has lost the storage for its queue, `for: 10m` | High | IT | [`cola-offline-atascada.md`](../../runbooks/cola-offline-atascada.md#7-almacenamiento-de-la-cola-degradado-kioskqueuestoragedegraded) (in Spanish) | Whatever is clocked now **is lost if the tablet restarts**: reload the app or free up space on the tablet. **Do not restart it** while it may hold clock-ins only in memory. Meanwhile the two queue alerts above stay silent: there is no size to measure (§16.3 bis) |
| `KioskUnreportedDiscards` | Discarded clock-ins not yet reported to the server, `for: 30m` | Medium | IT | [`cola-offline-atascada.md`](../../runbooks/cola-offline-atascada.md#8-descartes-sin-avisar-kioskunreporteddiscards) (in Spanish) | Check that tablet's network and its app version. Until it reports them, HR does not see the "Clocking discarded by the kiosk" incident ([`hr-guide.md`](hr-guide.md) §4.7) |
| `ScanBatchItemNotProcessed` | 3 or more batch items not processed in 30 min on the same kiosk, `for: 5m` | Medium | IT | [`cola-offline-atascada.md`](../../runbooks/cola-offline-atascada.md#9-un-elemento-del-lote-que-no-se-procesa-scanbatchitemnotprocessed) (in Spanish) | One clock-in from that tablet has gone several syncs without the server managing to process it, and **the others from that kiosk wait behind it**: they are not lost, but they do not reach the record. Fix the cause on the server (look for `attendance.batch_scan_failed` in the `app` log). **Never clear the tablet's queue or unpair it** (§16.3 bis) |
| `KioskDiscardedScansAttributed` | Discarded clock-in reports attributed to people from the same kiosk in the last hour, `for: 0m` | Medium | IT | [`cola-offline-atascada.md`](../../runbooks/cola-offline-atascada.md#10-descartes-atribuidos-en-un-quiosco-kioskdiscardedscansattributed) (in Spanish) | That tablet is sending clock-ins the server does not accept, and they belong to real people: usually an outdated app after an update (reload it with the queue empty); if the kiosk should not be sending anything, unpair it. HR will see that day's "Clocking discarded by the kiosk" incidents |
| `ErroresDeServidorEnElFichaje` | > 1 % of `5xx` on `/api/v1/scan*`, 5 min | Critical | IT | [`errores-en-el-panel.md`](../../runbooks/errores-en-el-panel.md) (in Spanish) | `product:doctor` first: the database and disk are the most frequent cause |
| `LatenciaDelFichajeAlta` | p95 of clock-ins > 500 ms, 10 min | High | IT | [`errores-en-el-panel.md`](../../runbooks/errores-en-el-panel.md) (in Spanish) | Check whether it coincides with the shift change or with a recent update |
| `SondaDelBordeFallida` | The server is not responding, `for: 5m` | Critical | IT | [`errores-en-el-panel.md`](../../runbooks/errores-en-el-panel.md) (in Spanish) | `docker compose ps` and the `postgres`/`redis` logs, before the panel |
| `AlmacenDeMetricasCaido` / `AlmacenDeMetricasAusente` | Redis (metrics store) is not responding, `for: 15s`; or the application publishes no metrics, `for: 2m` | Critical | IT | [`almacen-de-metricas-caido.md`](../../runbooks/almacen-de-metricas-caido.md) (in Spanish) | `docker compose ps redis` and `./doctor.sh`. **While it lasts, kiosk and queue alerts are not reliable** (they are inhibited): clocking keeps working, what is missing is visibility |
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
| `DeteccionDePatronesAusente` | More than 26 h without the detection of anomalous credential usage patterns running (04:35 UTC) | Medium | IT | [`patron-anomalo-credencial.md`](../../runbooks/patron-anomalo-credencial.md) §5 (in Spanish) | Check that the `scheduler` is still alive and run `attendance:detect-patterns` by hand. **It is not about any person**: it means nobody is looking |
| `DeteccionDePatronesConFallos` | Last night's pattern pass left some finding without turning it into an incident | High | IT | [`patron-anomalo-credencial.md`](../../runbooks/patron-anomalo-credencial.md) §5 (in Spanish) | `product:doctor`, fix the cause and repeat the command: it is idempotent |
| `ErroresCriticosNuevos` | New or reopened `critical` group in 5 min | High | IT | [`errores-en-el-panel.md`](../../runbooks/errores-en-el-panel.md) (in Spanish) | Open "Errors" in the panel and follow the "What to do" column for that row |
| `DivergenciaEnReconciliacionNocturna` | Any | Critical | IT | [`divergencia-proyeccion.md`](../../runbooks/divergencia-proyeccion.md) (in Spanish) | The fix is already applied; find out who wrote outside the recalculation |
| `ReconciliacionConFallos` | Last night's run failed | High | IT | [`divergencia-proyeccion.md`](../../runbooks/divergencia-proyeccion.md) (in Spanish) | Run `attendance:reconcile` by hand and check the reason for the failure |
| `ReconciliacionDeProyeccionAusente` | > 26 h without reconciling | Medium | IT | [`divergencia-proyeccion.md`](../../runbooks/divergencia-proyeccion.md) (in Spanish) | Check that the `scheduler` is still alive and run `attendance:reconcile` by hand |
| `RoturaDeCadenaDeAuditoria` | Any | Critical | Security | [`rotura-cadena-auditoria.md`](../../runbooks/rotura-cadena-auditoria.md) (in Spanish) | Preserve the evidence (§2 of that runbook) before touching anything |
| `VerificacionDeAuditoriaAusente` | > 26 h without verifying | Critical | Security | [`rotura-cadena-auditoria.md`](../../runbooks/rotura-cadena-auditoria.md) (in Spanish) | Check that the `scheduler` is still alive |
| `DiscrepanciaEntreRegistroYAuditoria` | Any | Critical | Security | [`discrepancia-registro-auditoria.md`](../../runbooks/discrepancia-registro-auditoria.md) (in Spanish) | Someone has written to the time record outside the application. Preserve the evidence (§2 of that runbook) before touching anything, and do not generate legal exports of the period until it is cleared up |
| `ConciliacionDelRegistroAusente` | > 26 h without reconciling | Critical | Security | [`discrepancia-registro-auditoria.md`](../../runbooks/discrepancia-registro-auditoria.md) (in Spanish) | Check that the `scheduler` is still alive and run `compliance:reconcile-work-record` by hand |
| `ConciliacionCompletaDelRegistroAusente` | > 8 days without the full reconciliation | High | Security | [`discrepancia-registro-auditoria.md`](../../runbooks/discrepancia-registro-auditoria.md) (in Spanish) | Run `compliance:reconcile-work-record --full` by hand. After installing or updating it does not fire until a Sunday has gone by |
| `ParticionDeAuditoriaAusente` | The current year's partition is missing | Critical | IT | [`rotura-cadena-auditoria.md`](../../runbooks/rotura-cadena-auditoria.md) (in Spanish) | **Clock-ins are down**: run `compliance:ensure-audit-partitions` now |
| `ParticionDeAuditoriaDelProximoAnoSinPreparar` | Next year's is missing, from November on | Medium | IT | [`rotura-cadena-auditoria.md`](../../runbooks/rotura-cadena-auditoria.md) (in Spanish) | Check that the `scheduler` is running and that migration `2026_09_29_100000` is applied (`migrate:status` through the `migrate` service, see the runbook §5). It no longer depends on `DB_MIGRATION_USERNAME`: the application asks a database function for the partition |
| `CopiaDeSeguridadFallida` / `CopiaDeSeguridadSinVerificar` | Any | Critical | IT | [`restaurar-backup.md`](../../runbooks/restaurar-backup.md) (in Spanish) | `backup:verify`, then retry with `backup:run`, both in the `scheduler` container (not `app`) |
| `CopiaDeSeguridadAusente` | No metric in 30 min | Critical | IT | [`restaurar-backup.md`](../../runbooks/restaurar-backup.md) (in Spanish) | Check the `scheduler` and that `BACKUP_PATH` is mounted |
| `ArchivadoDeWalDetenido` | The oldest unarchived data is more than 25 min old (`archive_timeout` + 10 min), or there are 3 complete segments unarchived; `for: 3m`. **It does not wait for the nightly backup** | Critical | IT | [`restaurar-backup.md`](../../runbooks/restaurar-backup.md) §4 (in Spanish) | Urgent: while it lasts, the maximum loss is no longer 15 min and, without space, PostgreSQL ends up stopping entirely. `docker compose logs --tail=100 postgres` gives the cause |
| `ArchivadoDeWalFallando` | The last archiving attempt failed and has not recovered; `for: 10m` | Critical | IT | [`restaurar-backup.md`](../../runbooks/restaurar-backup.md) §4 (in Spanish) | Destination not mounted or without permissions, disk full, or `BACKUP_WAL_KEY` missing: archiving never writes unencrypted. The `postgres` log says which |
| `MedicionDeWalAusente` | The WAL measurement has not been published for more than 5 min; `for: 5m` | Critical | IT | [`restaurar-backup.md`](../../runbooks/restaurar-backup.md) §4.3 (in Spanish) | Without a measurement you do not know whether the RPO holds: check the `scheduler`, which also makes the backups |
| `ArchiveTimeoutFueraDeRango` | `archive_timeout` is 0 or more than 900 s; `for: 10m` | Critical | IT | [`restaurar-backup.md`](../../runbooks/restaurar-backup.md) §4.4 (in Spanish) | Someone has changed the PostgreSQL configuration: put back the package's and recreate `postgres` |
| `SlotDeReplicacionParado` | ≥ 1 replication slot without a consumer for 15 min | Critical | IT | [`slot-replicacion-parado.md`](../../runbooks/slot-replicacion-parado.md) (in Spanish) | KronoQR uses none: a single one is already anomalous. It retains transaction log and can fill the data disk |
| `DiscoDeCopiasCasiLleno` | < 20 % free on the backup volume | Medium | IT | [`restaurar-backup.md`](../../runbooks/restaurar-backup.md) (in Spanish) | Expand the disk, or lower `BACKUP_RETENTION_DAYS` |
| `SimulacroDeRestauracionNuncaEjecutado` | None recorded yet | Medium | IT | [`restaurar-backup.md`](../../runbooks/restaurar-backup.md) (in Spanish) | Run it: without it, nobody has checked that the backups actually restore |
| `SimulacroDeRestauracionCaducado` | Failed, or > 100 days | Medium | IT | [`restaurar-backup.md`](../../runbooks/restaurar-backup.md) (in Spanish) | Repeat the drill with the previous backup if the latest one fails |
| `KronoqrAuthFailureBurst` | > 20 failures in 5 min, one channel | Medium | Security | [`ataque-a-credenciales.md`](../../runbooks/ataque-a-credenciales.md) (in Spanish) | Work out whether it is a person mistyping or an automated attempt |
| `KronoqrAuthLockouts` | ≥ 3 distinct lockouts in 15 min, one channel | Medium | Security | [`ataque-a-credenciales.md`](../../runbooks/ataque-a-credenciales.md) (in Spanish) | Scope how many accounts, and whether any got in before locking out |
| `KronoqrAuthFailureSpike` | > 100 failures in 5 min, one channel | Critical | Security | [`ataque-a-credenciales.md`](../../runbooks/ataque-a-credenciales.md) (in Spanish) | Preserve evidence before blocking the origin at the edge |
| `KronoqrPortalOriginLockouts` | > 5 portal origin lockouts in 1 h; `for: 1m` | Medium | Security | [`bloqueo-por-origen.md`](../../runbooks/bloqueo-por-origen.md) (in Spanish) | Tell a spread-out attack from the whole staff behind one IP (hairpin NAT, `TRUSTED_PROXY_CIDR`); clocking in is not affected |
| `KronoqrManagementTwoFactorReset` | Every 2FA reset of a management account (no threshold) | Critical | Security | [`ataque-a-credenciales.md`](../../runbooks/ataque-a-credenciales.md) §9 (in Spanish) | Confirm with the administrator and the account owner that it was intended; if nobody recognises it, it is an account incident |
| `KronoqrManagementAdminAccountCreated` | Every management account created with the admin role (no threshold) | Critical | Security | [`ataque-a-credenciales.md`](../../runbooks/ataque-a-credenciales.md) §9 (in Spanish) | Confirm with whoever requested it; if nobody recognises it, deactivate it |
| `KronoqrPortalOriginLockouts` | > 5 portal origin lockouts in 1 h; `for: 1m` | Medium | Security | [`bloqueo-por-origen.md`](../../runbooks/bloqueo-por-origen.md) (in Spanish) | Tell a spread-out attack from the whole staff behind one IP (hairpin NAT, `TRUSTED_PROXY_CIDR`); clocking in is not affected |
| `FicheroGeneradoDesaparecidoAntesDeCaducar` | `generated_files_missing_total` goes up (within 30 min, or a new series), for 1 min | High | Security | [`ficheros-generados.md`](../../runbooks/ficheros-generados.md) (in Spanish) §2 | An export or a report lost its file before expiring. After restoring a backup or updating from 2.1.0 it is expected (§18); otherwise, read the `*.file_missing` entry and treat it as a possible breach |
| `FicheroGeneradoSinRetirarPasadoSuPlazo` | `generated_files_overdue > 0` for 1 h: an export for the Labour Inspectorate has been on the server > 30 days | Medium | IT | [`ficheros-generados.md`](../../runbooks/ficheros-generados.md) (in Spanish) §4 | It is not deleted on its own: confirm it was handed over and delete it ([`requerimiento-inspeccion.md`](../../runbooks/requerimiento-inspeccion.md) §7, in Spanish) |
| `PurgaDeFicherosGeneradosSeHaNegadoATocarAlgo` | `generated_files_refused_total` goes up (within 1 h, or a new series), for 1 min | Medium | IT | [`ficheros-generados.md`](../../runbooks/ficheros-generados.md) (in Spanish) §5 | There is a link, a subdirectory or a foreign name in a folder of the volume, or overlapping `*_PATH` paths: `product:doctor` (§13.5) |
| `PurgaDeFicherosGeneradosNoPuedeBorrar` | `generated_files_remove_failed_total` goes up (within 1 h, or a new series), for 1 min | Medium | IT | [`ficheros-generados.md`](../../runbooks/ficheros-generados.md) (in Spanish) §6 | The file system refused a deletion and a file with personal data is still there past its term; the usual cause is permissions (an export launched with `exec -u root`). Fix the folder owner and mode; the next hourly pass removes it |
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
| 6 · Rollback | Exits `5` | **Requires a person.** The message distinguishes two cases: only maintenance mode was left on (lift it with `docker compose exec app php artisan up`, **without restoring anything**) or the restore was left half-done (three orders and the path of the backup) | Runbook §5. The kiosks keep queueing meanwhile |
| 7 · Report | — | The **report** at `BACKUP_PATH/reports/update-<fecha>.log` (uid 1000, `0640`), always. The **detail** (`update-<fecha>.detalle.log`: raw output, **may contain personal data**) is **not there**: it lives only in `/var/log/kronoqr/` (`root:root 0600`, directory `0700`), next to a local copy of the report. The report appears in `reports/` **when the script finishes, not while it runs**; if it cannot be published, the script warns and says it is safe in `/var/log/kronoqr/`. `setpriv` (`util-linux` package) is required. **How long they are kept** (since 2.2.0): the detail, **30 days** (`KRONOQR_LOG_RETENTION_DAYS` in the environment of whoever runs the script, minimum 7); the local summary `update-<fecha>.log`, without personal data, **90 days**. `update.sh` deletes them when it starts and `./doctor.sh` does too if you run it with `sudo`; if you neither update nor run `doctor.sh` for months, the deletion waits for the next run | Attach the report to the diagnostic bundle if you open a case; the detail file, only after reviewing it and if asked for |

Steps 5 and 6 also leave their own entry in `audit_log` (`system.updated` or
`system.restored_from_backup`): if for whatever reason it cannot be written,
the update is not rolled back because of that —the fact already happened—,
but an update that otherwise finished fine exits `6` instead of `0`, and the
report says so on its own line. The rollback entry is only written if the version
you roll back to already knows that action (from 2.2.0): an earlier one could not
verify the chain with it, so the report keeps the data and you write it with
`compliance:record-system-event` after the next update.

**What does not change:** your secrets (the `.env` is copied as is and only
`IMAGE_TAG` changes; the only exception is the one below, when coming from
2.1.0), the data, the licence (an expired licence **does not prevent
updating**), and clocking. **What is needed:** `BACKUP_ENCRYPTION_KEY` in the
`.env` and space for the backup and for the migration; step 1 says so with
figures. And, from 2.2.0 on, **`setpriv`** on the server (`util-linux`
package, standard on Debian, Ubuntu and RHEL 7 or later; check it with
`command -v setpriv`): `update.sh` uses it to write its report, the rescued
retention reports and its metrics into `BACKUP_PATH` as the application user
and without following links. Without it, it does not stop: it warns, does not
rescue the retention reports and leaves its report in a temporary directory,
saying which.

**When updating from 2.1.0: each container receives only its own.** Up to
2.1.0 every application container received the whole `.env`, including the
password of the migration role, which is a database superuser. From 2.2.0 on,
each service receives **only the variables it needs** (the table is in
[`configuration.md`](configuration.md) §6.2). Three consequences you need to
know:

- **If you added a variable of your own to the `.env`**, it no longer reaches
  any container. Step 1 (and `--check-only`) warns you with the list of the
  keys in your `.env` that no service will receive. It is a warning, not a
  failure: they are almost always leftovers that did nothing. If one of them
  does matter to you, tell the vendor; do not edit `docker-compose.yml`, which
  the next update replaces.
- **Backups move to a read-only role.** The updater creates the
  `fichaje_backup` role and writes `BACKUP_DB_USERNAME` and a new
  `BACKUP_DB_PASSWORD` **into the new version's `.env`**. The previous
  version's `.env` keeps the old credentials on purpose: it is what the
  rollback starts with.
- **A rollback to 2.1.0 goes back to the whole of 2.1.0**, including its way of
  handing out credentials: the containers receive the full `.env` again until
  you update once more. If the update was rolled back, do not leave it for
  months: the reason is in the report.

**When updating from 2.1.0: the generated files.** Up to 2.1.0, what the
product wrote to disk outside the database —the full data export, the
background reports, the retention reports, the diagnostic bundle, the
telemetry state— stayed **inside each container**, and was lost every time an
update recreated it. From 2.2.0 on it lives in the `app-storage` volume, shared
by the application containers (§13.6), and the retention reports live in
`BACKUP_PATH/reports/retention` (§2). You do not have to do anything: the
volume is created on its own at the first start. What is worth knowing:

- **The retention reports are rescued, and only they.** Before recreating the
  containers, `update.sh` copies the `retencion-propuesta-*.txt` and
  `retencion-purga-*.txt` files it finds in the 2.1.0 ones to
  `BACKUP_PATH/reports/retention/`, without overwriting any and with `0640`
  permissions. The update report says how many it rescued and from where. If
  the rescue fails, it warns you with the order to do it by hand and **the
  update goes on**: an update is not rolled back because of a report.
- **If your `.env` sets `COMPLIANCE_RETENTION_REPORT_PATH` inside
  `storage/app`**, the rescue warns about it (and `product:doctor` afterwards):
  remove that line from the `.env` to use the default.
- **Not rescued, and lost**: the full export ZIPs, the background reports and
  the diagnostic bundles that were in the containers. They are personal data
  that expire after 7 days or disposable material, and there is no point in
  giving them a new life in the new volume. Their records turn to
  **"Expired"** in the first purge pass. If one had not expired yet, the
  `data_export.file_missing` or `report_export.file_missing` entry is left as
  well and the `FicheroGeneradoDesaparecidoAntesDeCaducar` alert may fire:
  **after this update that is expected**. If you need an export, ask for it again.
- **The telemetry identifier changes once**, if you had telemetry enabled
  (§13.4). From here on it is kept across updates.
- **What was already lost cannot be recovered**: the reports of purges run with
  `run --rm`, which disappeared when the order finished, and everything earlier
  updates deleted. **The evidence of each purge is still in the audit log**,
  and that is the one that counts: how to find and cite it is in §3.1 and in
  §18, "…you need to prove a purge and its report is missing".
- **If you roll back to 2.1.0**, the volume stays intact and unused until the
  next update, and the rescued reports stay in `BACKUP_PATH/reports/retention`.

**When updating from 2.1.0: offboardings with a future termination date.**
2.1.0 accepted recording an offboarding with a termination date later than the
day it was recorded, and applied it on the spot: from that moment the person
could not clock and their card was revoked. From 2.2.0 a termination date later
than today is rejected (offboarding is recorded once the person has finished
their last shift: [`hr-guide.md`](hr-guide.md) §8, "…a person leaves"). Those
already recorded that way **are neither migrated nor reactivated**: they stay
offboarded, with their date. What HR needs to know:

- **Period reports count them as employed up to their termination date**, so
  the days between the offboarding being recorded and the termination date come
  out as employed days **with no activity**. It is not a report fault: that
  person could not clock on those days.
- **If they worked on those days, they can now be filled in by hand**: from
  2.2.0 an entry can be added to an offboarded person on any working day
  between their start date and their termination date, both included, with its
  reason and its audit entry, like any manual entry. Never a day that has not
  arrived yet. How it is done: HR guide, "After offboarding: filling in the
  missing days".

How to find them, without touching the database: in the panel, **Workforce**,
filter **"Employment status"** set to **"Former staff"**, and open each
person's record: **"Termination date"** is in their details. They are the ones
with a termination date later than the day you updated to 2.2.0. Hand the list
to HR: they should fill in the days those people did work and know how to read
the days with no activity in those reports. Nothing else needs doing in the
system.

**When updating to 2.2.0: the kiosk privacy notice is configured from the
panel.** The data protection notice the tablet shows when clocking (GDPR art.
13) names the data controller and links to the full policy. Up to 2.1.0 those
two items could only be set when building the tablet app, so in practice the
generic text was shown. From 2.2.0 they are set in Panel → **Branding** →
"Kiosk privacy notice", without touching the server
([`configuration.md`](configuration.md) §2.2). Worth knowing:

- **Nothing visible changes after updating**: both fields start empty and the
  notice keeps its generic wording ("the company operating this workplace",
  "the full policy is available at the front desk"). The notice **never
  disappears**.
- **Hand the task to whoever handles data protection**: ask them for the exact
  legal name and the `https://` address of the policy approved for the staff,
  and enter them yourself. With an address, the tablet also shows a QR code to
  open it on a phone.
- **The tablets pick it up** when the clocking screen loads or when the network
  comes back; a tablet with an app older than 2.2.0 keeps the generic one until
  it is updated (below, "the tablets").

**When updating to 2.2.0: three incidents HR has never seen before.** They show
up in the inbox without anything to switch on; HR has the explanation of each in
[`hr-guide.md`](hr-guide.md) §4.1:

- **"Clocking before the credential was withdrawn"** (§4.6 of that guide): a
  card that was valid when it was scanned and reached the server after it had
  been withdrawn —typically, the last day of someone who left while the tablet
  was offline—. **The clocking is not recorded**; HR completes that day by
  hand.
- **"Clocking discarded by the kiosk"** (§4.7): the tablet sent a clocking the
  server did not accept as valid, set it aside and reported it. **It is not
  recorded**; HR reviews it and corrects it by hand. If it repeats on one
  tablet, that tablet's app is out of date: update it.
- **"PIN clocking not recorded"**: a PIN clocking attempt that was not accepted
  and that the person did not repeat.

**The incidents open from what arrives after the update**, not over history:
what happened before is not reprocessed. **What does not change**: a late PIN
clocking from a person who has already left **still opens no incident**, so HR
still has to check by hand the last day of offboardings recorded while any
tablet was offline.

**When updating to 2.2.0: the tablets, after the server and with the queue
empty.** 2.2.0 changes **how the tablet app empties its queue** (§16.3 bis): it
records clock-ins strictly in order and, if the server rejects one as invalid,
it keeps it aside and reports it instead of simply dropping it. **That is done
by the new app, not by the server**: a tablet still running the 2.1.0 app
against a 2.2.0 server does not have that safety net. So, in this order:

1. **Before you start**, check in Panel → **Kiosks** that the "Pending" column
   is at zero on every tablet you can, or that they at least have network. It is
   not mandatory —2.2.0 is built to accept what 2.1.0 tablets send—, but the
   less there is in flight during the change, the less there is to review
   afterwards.
2. **Update the server** as usual (above).
3. **Move the tablets to 2.2.0 the same day, not weeks later.** The simplest
   way is to let them do it themselves: set `KIOSK_UPDATE_WINDOW` to a slot
   that starts now (§11.1); each tablet reloads itself as soon as its queue is
   empty and nobody has clocked in the last few minutes, which is exactly the
   safe condition. **Put the window back** once they are all up to date. If you
   prefer to do it by hand on a particular tablet, leave kiosk mode with the IT
   PIN and reload the app **only when its "Pending" is at zero**.
4. **Check it** in the "Application version" column of **Kiosks**, or with
   `docker compose exec app php artisan kiosk:health`.

**Never clear the app's data or unpair a tablet to "force" the update**: its
queue lives there, and the clock-ins it has not sent yet would go with it.

**When updating to 2.2.0: the error history is filtered again.** Up to
2.1.0, the text of an error was only cleaned of emails, ID documents, phone
numbers and whatever was between quotes, so a name without quotes could stay
in the history and, with it, in the diagnostic bundle. 2.2.0 filters that text
with a closed technical vocabulary (§12.2 and §15.1), and the update also runs
that filter over the rows you already had. What is worth knowing:

- **Old messages are rewritten and their fingerprints change.** It is
  irreversible: what is removed cannot be recovered. Errors that only differed
  by a person's name become a single group, which adds up the occurrences and
  stays open if any of them was open. If an open support ticket, or your own
  notes, quoted an error's fingerprint, you will not find it any more: look
  it up by its code and its origin.
- **Diagnostic bundles generated before the update may contain names.** Do
  not send them. If any is left on the server, delete it
  (`docker compose exec app rm -f storage/app/diagnostics/<fichero>`); if you
  forget, the hourly purge removes it after 7 days (§12.2). If you copied any
  off the server, delete it from wherever you kept it as well.
- **A backup taken before the update keeps the old text** and, once
  restored, the panel shows it again until the next update (or a `migrate`)
  filters it once more. The diagnostics bundle, by contrast, always filters it
  when generated, also on a restored backup.
- **What you already sent to the manufacturer**: it treats bundles from
  earlier versions that it has received as bundles with personal data, and
  deletes them.

**When updating to 2.2.0: backups and the WAL, encrypted and authenticated.**

- **What changes.** Backups and the archived WAL are now **encrypted and
  authenticated** (ADR-049): a file that has been altered, renamed or replaced
  is detected on restore, and the restore refuses. **Up to 2.1.0 the archived
  WAL was not encrypted.**
- **What `update.sh` does by itself.** It computes `BACKUP_WAL_KEY` from
  `BACKUP_ENCRYPTION_KEY` and writes it into the `.env`: you still keep **a
  single** key safe. If it cannot compute it, it **stops before touching
  anything**, with the cause and the command. In the following minutes it
  encrypts the old WAL segments in place, with no extra downtime (`./doctor.sh`
  says how many are left).
- **What you have to do: destroy the unencrypted WAL copies.** Any copy of
  `BACKUP_PATH/wal` you made before updating (another disk, another network
  folder, another backup of the server) contains personal data **unencrypted**.
  Destroy it. If it was within reach of people who should not see it, assess
  with your DPO whether it is a breach
  ([`legal-obligations.md`](legal-obligations.md) §4 and §6).
- **2.1.0 backups** still in `daily/` and `base/` (up to
  `BACKUP_RETENTION_DAYS`, 30 days by default) can be restored, but only by
  asking explicitly (§18, "…the restore refuses on integrity grounds"). They
  expire by themselves.
- **Mounts.** The application no longer writes to the root of `BACKUP_PATH`. If
  your destination is a network share with special permissions, check that
  `daily/`, `base/`, `metrics/`, `reports/` and `reports/retention/` exist and
  belong to user 1000 ([`installation.md`](installation.md) §6,
  "`BACKUP_PATH`"). `update.sh` creates them if they are missing.
- **The update lock now lives in `/var/log/kronoqr/update.lock`**, out of the
  application's reach (before, in `BACKUP_PATH`; an old one there is ignored).
  If an interrupted update leaves it in place, step 1 says so, with the process
  that created it. Check that no `update.sh` is running (`pgrep -af update.sh`)
  and remove it with `sudo rm -r /var/log/kronoqr/update.lock`.
- **Rolling back to 2.1.0** uses the pre-update backup `update.sh` has just
  taken, which still has the 2.1.0 format. It accepts it **only** if it matches
  the fingerprint the script itself computed and stored in `/var/log/kronoqr/`,
  not the `.sha256` next to the backup. After rolling back, what 2.2.0 wrote
  (`.gz.enc` WAL, new backups) **cannot be read by the 2.1.0 `restore.sh`**: if
  you need to restore one of those, do it with the 2.2.0 package.
- **Alerts.** A stopped archiving is now detected in about 25 minutes, without
  waiting for the nightly backup, and there are three more alerts (§10.4). If
  you have your own silences or rules on `kronoqr_backup_wal_*` or
  `kronoqr_backup_replication_slot*`, **they are renamed** to `kronoqr_wal_*`.
- **Downtime:** none extra.

**On updating to 2.2.0: the portal and the panel, better closed (above all if
they are open to the internet).** Four changes; the first two require nothing,
the last two call for a review on the same day:

- **The PIN can have 6 or 8 digits.** It is still 6 by default. If the portal
  is reachable from outside the hotel network, move it to 8 in the panel, with
  the administration account: **Operational settings → Access → PIN length**
  (it is recorded in the audit log). **No PIN is voided**: the 6-digit ones
  already handed out keep working and the portal and the tablet accept 6 to 8
  digits; only those issued from then on —new hires and resets— come out with
  8. HR resets them and hands them over in person as each person comes by the
  office ([`hr-guide.md`](hr-guide.md) §2.2). How many are left —only the
  number, never who— is what the `access.short_pins` check of `product:doctor`
  says (§12.1).
- **Per-connection lockout on the portal.** 20 failed portal sign-ins from one
  address within 15 minutes, with any code, close the portal **to that
  address** for one hour, even with the right PIN. The person sees "Too many
  attempts from this connection" and the minutes left. It does not affect
  clocking in. If it closes the whole hotel wifi and waiting is not an option,
  lift it from the installation directory with the address shown in the
  `auth.origin_locked` entry of the audit log (the example is a documentation
  address; put yours):

  ```bash
  docker compose exec app php artisan identity:origin-unlock 198.51.100.23
  ```

  If there is a proxy in front of the server and you have not set
  `TRUSTED_PROXY_CIDR`, the whole staff arrives with the proxy's IP and a single
  lockout keeps all of them out: set it ([`installation.md`](installation.md)
  §6). The full procedure, including what to do if it keeps happening, is in
  [`../../runbooks/bloqueo-por-origen.md`](../../runbooks/bloqueo-por-origen.md)
  (in Spanish).
- **Department managers need a second factor.** Since 2.2.0 it is mandatory
  for the four management roles, because the manager corrects working days.
  Nobody is locked out: whoever does not have one enrols it on their **first
  sign-in** to the panel after updating, with the app on their phone. **Until
  they sign in, someone holding only their password could enrol it in their
  place**, so **ask every manager to sign in on the same day** and then review
  the enrolments: each one leaves an `auth.two_factor_enabled` entry with the
  time and the IP it was made from.

  ```bash
  docker compose exec -T postgres psql -U fichaje_app -d fichaje -c \
    "SELECT a.occurred_at, a.ip, u.uuid, u.email FROM audit_log a JOIN users u ON u.id = a.subject_id WHERE a.action = 'auth.two_factor_enabled' AND a.occurred_at > now() - interval '30 days' ORDER BY a.occurred_at;"
  ```

  If the account holder does not recognise an enrolment (another time, another
  IP), remove that second factor and give them a new password; on their next
  sign-in they enrol it again themselves. It is done in Panel → **Accounts**,
  with “Reset 2FA” and then “Reset password” (§9); if the panel is not
  available, from the console, with the row's `uuid`:

  ```bash
  docker compose exec app php artisan identity:2fa-reset 0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90 --reason="2FA no reconocido / 2FA not recognised"
  docker compose exec app php artisan identity:reset-password responsable@tuhotel.example
  ```

  The `access.two_factor_pending` check of `product:doctor` says how many
  accounts of those roles still have no second factor; when it reaches zero,
  the window is closed. If your `.env` sets `IDENTITY_2FA_REQUIRED_ROLES` to the
  old list, 2.2.0 respects it and `access.two_factor_roles` warns about the
  missing role.
- **`ADMIN_INTERNAL_CIDR`, new and empty.** It closes the management panel to
  the network you set; empty, it filters nothing, as until now. If the portal is
  open to the internet, so is the panel while you leave it empty:
  [`configuration.md`](configuration.md) §6. `product:doctor` reminds you with
  the `network.admin` warning.

**When updating to 2.2.0: management accounts, from the panel.** Creating,
deactivating, resetting the password and resetting 2FA move to Panel →
**Accounts** (§9, “The panel accounts”), and each department's manager is
chosen in Panel → **Departments**. Three things to do or know on the same day:

- **Whoever holds the administration account has to sign out and sign in
  again.** A session's permissions are fixed when it opens, and the sessions
  opened before the update do not carry the one for managing accounts: until
  they sign in again they will not see “Accounts” in the menu, and Departments
  will not let them choose a manager. It is not a fault and nothing else needs
  touching.
- **Passwords handed out from now on are temporary.** They expire after 72
  hours (`IDENTITY_TEMPORARY_PASSWORD_TTL_HOURS`) and force the holder to set
  their own when signing in. `identity:create-user` no longer asks for the
  password: it generates it and shows it once. Existing passwords do not
  change.
- **Review the department managers.** If they were ever assigned by editing the
  database, check them on the Departments screen and, from now on, change them
  only there: each change leaves its `role_assignment.changed` record. A manager
  with no department assigned can sign in to the panel but sees nobody.

Every creation of an account with the administration role and every reset of a
second factor alerts the security recipient (§10.4,
[`ataque-a-credenciales.md`](../../runbooks/ataque-a-credenciales.md) §9, in
Spanish). If you are going to do several on the same day, tell them beforehand.

**Which versions you can jump from** to the package's, without touching
anything: `./update.sh --supported-sources`. The rule is the current minor
version and the two before it; from an older one, the script tells you which
one to go to first.

**A second run** on an already updated installation: exits `3`, "already on the
version", and touches nothing.

### 11.1 The tablet app: when it changes version

The above is the server. The kiosk app is a separate piece: the tablet
downloads it from the server, and **the new version does not enter the tablet
at the moment the server is updated**. Every tablet checks **every hour**
whether there is a new version, downloads it in the background and leaves it
waiting; while it waits, it keeps clocking with the usual one. It only reloads
with the new one when **three conditions hold at the same time**, and it checks
them every minute:

1. **The site's local time is inside the window** `KIOSK_UPDATE_WINDOW`
   (`03:00-05:00` by default; it may cross midnight).
2. **The queue of unsent clockings is empty.** An update with clockings still
   waiting to be uploaded would be the only way to lose one, so it is not done:
   when the tablet updates, it has nothing to lose, by construction. The queue
   also lives in the tablet's durable storage, and a clocking is only removed
   from it once the server has confirmed it.
3. **There has been no clocking in the last `KIOSK_UPDATE_QUIET_MINUTES`
   minutes** (10 by default). It is the guard against the shift that starts
   earlier than planned: the tablet does not know your schedule, but it does
   know whether somebody has just presented a card.

If the window closes without all three holding, it waits for tomorrow's. That is
what makes an update invisible to the workforce: **the tablet never updates
with people in front of it**, and when it does, the queue is empty.

**Where it is set.** Both keys are in Panel → **Operational settings**
(`/settings`), administrator role ([`configuration.md`](configuration.md) §2.1),
and they reach the tablets on the next heartbeat —in under a minute—; every
tablet stores them, so they hold without network, and one that has not received
any yet uses the defaults. **It is a single window for the whole installation**:
there is no per-kiosk one, and no way to force a tablet's update from the panel.
If you need it to change now, move the window temporarily to the current time:
as soon as the quiet minutes pass with an empty queue, it updates on its own.

**What the tablet shows.** The diagnostic screen (§16.5), next to the installed
version, says "up to date" or "update pending: it will be applied in the
03:00–05:00 window", with the window in force. It is what to look at when a
tablet has spent days on an older version than the rest: if it says pending, the
three conditions have never held in its window —usually because there is always
some clocking in that slot, or because the queue never empties for lack of
network—, and the fix is to move the window or repair the network, not to
restart the tablet.

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
that there is a live worker: the `horizon` service), mail (transport configured and server
reachable), TLS certificate (expiry and self-signed), permissions (working
directories, backups, logo), generated files (that the `app-storage` volume is
mounted and writable, that its paths do not coincide with one another, that the
retention reports folder is writable, and exports for the Labour Inspectorate
forgotten on the server for more than 30 days; §13.6), disk space (application
and backups) and settings
(time zone in UTC, debug mode, invalid keys, differences between the `.env` and
what is stored, licence and branding),
edge networks (where the portal, the panel, the kiosks and `/metrics` open
from) and access (since 2.2.0: 6-digit PIN with the portal open to the
internet, how many people still have a 6-digit PIN with the setting on 8,
management roles without a mandatory second factor and how many accounts have
not enrolled it yet; **only numbers, never who**). **Every red line says what to do**,
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
service, `.env`, disk, certificates, ports, the generated-files volume and its
size— and tells you how to start it.

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

  It leaves the file in `storage/app/diagnostics/`, inside the generated-files
  volume (§13.6), and tells you the path, the size and the fingerprint. Copy
  it out with
  `docker compose cp app:/var/www/html/storage/app/diagnostics/<fichero> .`
  and **delete it from the server once you have sent it**
  (`docker compose exec app rm -f storage/app/diagnostics/<fichero>`): it is
  disposable material. In case that is forgotten, **the scheduler deletes
  every hour any bundle older than `PRODUCT_DIAGNOSTICS_RETENTION_DAYS` days**
  (7 by default), and the command itself does the same on start-up and says
  so. The volume persists across updates, so that sweep is what stops a
  forgotten bundle —perhaps with personal data in it (§12.3)— from staying on
  the server forever.

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

**What it carries from the error history.** Each error appears with its code,
its origin, its class, how many times it has happened and when it happened
first and last. Of its text, only the words of a closed technical vocabulary
that is part of the product are kept; any other word —a first name, a
surname, a street— appears as "…". Long numbers, and those shaped like an ID
document, phone number, card, bank account, social security number, employee
code, date, time or IP address, appear as a marker (`[n]`, `[id]`, `[time]`…).
**The anonymised bundle contains no employee identifier at all**, not even
the internal one. It does carry two technical identifiers that do not
correspond to a person: the tablet's (`device_id`) and the request's
(`trace_id`), which only your installation can relate to its log.

**What cannot be ruled out entirely.** A name that matches a word of the
technical vocabulary exactly and appears on its own, without a surname, would
be kept. With every release the product checks that the vocabulary contains
none of the most frequent first names and surnames in Spain or in the
nationalities most common in hospitality, but it cannot check it against
every possible name. **Open it before sending it**: it is readable JSON.

**How it is checked.** An automated test of the product generates a real
bundle after injecting —through both routes by which the applications' errors
arrive and through a server error; in the message, in the values, in the keys
and in nested data— first names, surnames, emails, DNI, NIE, passports,
social security numbers, bank accounts, cards, phone numbers and employee
codes in all their forms, and checks that none of them appears, nor the
internal identifier of any employee.

#### What it carries about each tablet, your settings and volume (since 2.2.0)

So that support can answer «a tablet does not sync» or «the payroll file comes
out empty» without asking you for a second round of screenshots, the bundle
adds:

| Where (in the JSON) | Field | What it is | If it is `null` |
| --- | --- | --- | --- |
| `kiosks[]` | `token_expires_on` | **Only the day** (UTC) on which that tablet's credential expires. Never the credential itself | The tablet has no credential: not paired, or unpaired |
| `kiosks[]` | `paired_at` | When it was paired | It was paired before the product recorded this |
| `kiosks[]` | `oldest_pending_at` | Time of the oldest clock-in the tablet has not sent yet. **Only the time**: neither whose it is nor its identifier | Nothing pending, or no heartbeat received since the tablet was updated |
| `kiosks[]` | `battery_level`, `battery_charging` | Battery in % and whether it is charging, from its last heartbeat | The tablet's browser does not report the battery (normal on some models) |
| `configuration.installation_settings` | one entry per setting | The settings saved from the admin panel (clocking tolerances, languages, payroll file format, tablet update window, weekly summary on/off), with `source: stored` if you changed it and `default` if the factory value applies | — (always has a value) |
| `installation.volume` | `active_employees`, `scan_events_last_30_days`, `shift_entries_last_30_days`, `open_incidents` | **Numbers only**: active staff, scans and shift entries in the last 30 days, open incidents | — (always has a value) |

Of your settings, the trade name, logo and brand colour, the tablets' service
code and the manual consolidation hours you declared are **not** sent. Of the
payroll format, **which columns** are written and in what order is sent, but
**not the labels** you gave them.

### 12.3 Including personal data is a separate action

If the ticket requires seeing specific clock-ins —one person's payroll
discrepancy, for example— you can include them. **It is your decision, explicit
and audited**, never the default:

- Panel: tick "Include personal data"; the screen tells you what will be
  included and that it is recorded, and only then lets you generate.
- Console: `docker compose exec app php artisan product:diagnostics --with-personal-data --period-days=7`
  (31 days at most).

It adds the workforce —**only the people with activity in the period or with an
open incident**, never the whole workforce— with code, name, status and
department, the clock-ins and shift entries of the period, and the open
incidents. The bundle is marked as **not anonymised** and
`diagnostics.personal_data_included` appears in your audit trail. By sending it
you are communicating personal data to a third party: read
[`legal-obligations.md`](legal-obligations.md) §8 first, and have the
processing agreement signed.

**The product does not encrypt this file**, so that you can open it and check
what leaves before sending it. Send it only through the encrypted channel set by
your support and processing agreement, never by unencrypted email, and delete it
from the server and from your computer as soon as you have sent it.

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
| `read_only` | In addition, **read the workforce and one person's time record** (their record and their working days), **every read audited** as a disclosure of personal data, and the audit trail | Change anything. **Neither the absence register** —health data: the "Sick leave" type and its note—, **nor live presence, nor the per-person compliance summary, nor the department catalogue**: they are not needed to diagnose an hours calculation, and no support access reaches any of them (403 with any scope; absences closed at the Phase 3 close, presence and compliance on 24-09-2026 by a product decision of the manufacturer, the same for every installation) |
| `configuration` | In addition, **change** the operational settings and pair or unlink kiosks | See working days or the workforce, or touch the compliance profile (legal thresholds and retention years are yours), **or turn break clocking on or off** (`ATTENDANCE_BREAK_CLOCKING`: it decides what counts as an incident, just like the profile; any attempt gets a 403), **or touch the two pattern-detection keys** (`ATTENDANCE_PATTERN_WINDOW_SECONDS` and `ATTENDANCE_PATTERN_MIN_REPEATS`: with `0` in the first one the coincidence detection of RF-PR-06 is switched off, the mitigation that makes up for having no biometrics, and that decision is the hotel's; also a 403), **or turn the weekly email summary on or off** (`WEEKLY_SUMMARY_EMAIL`: it decides whether your staff's names and hours go out by email every Monday; also a 403), **or declare or change the pre-system timesheet hours** (`BASELINE_MANUAL_HOURS_PER_MONTH`: it is the declared denominator of the impact dashboard's commercial target and describes your process before the installation, which only you know; also a 403), **or change the PIN length** (`IDENTITY_PIN_LENGTH`: it decides how strong the key to your staff's record is; also a 403), **or see or change the kiosk service code**: it arrives empty and marked as redacted, and any attempt to change it gets a 403 (§16.5) |

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
| `PRODUCT_DIAGNOSTICS_RETENTION_DAYS` | `7` | Days a bundle generated from the console stays in `storage/app/diagnostics` before the scheduler's hourly pass deletes it (the next `product:diagnostics` also deletes it) |
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
incidents, scans, reports generated in the background, weekly summaries sent
by email, the complete audit trail with its hash chain, management accounts,
support access grants),
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
another. **Downloading from the panel is the recommended route**: it is the
only one that records who took the file (§13.3).

**From the console**, on the spot and in the foreground:

```bash
docker compose exec app php artisan product:export-all
```

It leaves the file in `storage/app/exports/`, inside the generated-files volume
(§13.6), prints the path, the size, the fingerprint and the row count of each
file, and records the export just as if you had requested it from the panel: it
appears in the same list and **is downloaded from there**. If you would rather
get it out from the console:

```bash
docker compose cp app:/var/www/html/storage/app/exports/<fichero> .
```

> **`docker compose cp` leaves no download entry.** What leaves the server that
> way does not appear as `data_export.downloaded` in your audit trail, and in
> the event of a breach you will not be able to answer from the product who
> took that copy. If you use the console, write down yourself who took it, when
> and where to, and keep that note with the rest of your record of processing
> activities.

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

**It is not part of the backup, and a restore does not put it back.** It is a
view of data that is already in the encrypted backup; putting it in there would
stretch from 7 to 30 days the life of a file holding all your personal data.
After restoring a backup, ask for a new export: it is generated from the
restored data (§18, "…after restoring a backup").

Your audit trail keeps `data_export.requested` (who requested it and through
which channel), `data_export.generated` (counts, fingerprint and size) and
**`data_export.downloaded` for every download from the panel**: in the event of
a breach you can answer who took what and when. What is taken out with
`docker compose cp` leaves no such entry (§13.2).

**If the file disappears before it expires**, the export still turns to
"Expired", but the `data_export.file_missing` entry is also left (with the
export's identifier, no path) and the `FicheroGeneradoDesaparecidoAntesDeCaducar`
alert fires, addressed to the security officer (§10.4). Outside the two cases where
it is expected —right after restoring a backup, or after updating from 2.1.0—,
**treat it as a security event**: someone with access to the server has
deleted or moved a file holding all the workforce's data
([`../../runbooks/brecha-de-seguridad.md`](../../runbooks/brecha-de-seguridad.md),
in Spanish). Background reports do the same with `report_export.file_missing`.

**If it gets stuck on "Generating".** An export that is interrupted halfway
—because you stopped the containers to update, or because the queue worker
restarted— blocks nothing: once the maximum generation time (one hour) has
passed, the system marks it as **failed** with reason `stale` as soon as
someone requests another or in the next hourly purge, and you can generate
again. What it left half-written is deleted on its own in a later hourly
purge, once twice that maximum time has passed. There is no need to touch the
database or the disk; if you really see one in progress for more than an hour
without it moving to failed, run
`docker compose exec app php artisan product:export-all --purge` and request it
again.

**If it shows as "Could not be generated".** The reason the panel shows is a
code, not free text, so that no data from a row is ever put on the screen or in
the log: `write_failed` (could not write to `PRODUCT_DATA_EXPORT_PATH`: it is
almost always lack of space on Docker's disk; `./doctor.sh` says how much the
generated-files volume takes up and `product:doctor` whether it can be written
to), `database_error` (the database
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

The installation's random identifier lives in the generated-files volume
(§13.6) and is kept across updates. **It changes once when updating from
2.1.0**, and it changes when a backup is restored on a new server, because the
volume does not travel in the backup. Only telemetry uses it, not the licence:
its changing affects nothing else.

### 13.5 The parameters

| Variable | Default | What it governs |
| --- | --- | --- |
| `PRODUCT_DATA_EXPORT_PATH` | `storage/app/exports` (in the `app-storage` volume) | Where the ZIPs are written. Outside `BACKUP_PATH` on purpose: it is material that expires. See the note below |
| `PRODUCT_DATA_EXPORT_RETENTION_DAYS` | `7` | Days the ZIP can be downloaded before it is purged. The record is kept. It can be lowered to `1` if you would rather a full copy of your data did not spend more than a day on the server |
| `PRODUCT_DATA_EXPORT_RATE_LIMIT` | `30` | Requests per minute **per account** to the list and the download; the per-IP-address bucket is four times larger (120), so that several administrators behind the same internet gateway do not block each other. The panel polls every 5 s while one is in progress |
| `PRODUCT_DATA_EXPORT_STALE_AFTER` | `3600` | Seconds after which an export left half-done (container stopped, queue restarted) is marked as failed with reason `stale`, freeing up the next one. Do not lower it below what your largest export takes |
| `TELEMETRY_ENABLED` | `false` | Whether telemetry is sent. `TELEMETRY_ENDPOINT` is also needed, and the licence has to include it |
| `TELEMETRY_ENDPOINT` | empty | Where it is sent. Empty by default: you set it |

**The four generated-file paths** —`PRODUCT_DATA_EXPORT_PATH`,
`REPORTING_EXPORT_PATH`, `PRODUCT_DIAGNOSTICS_PATH` and `TELEMETRY_STATE_PATH`—
are left empty, and hardly anyone has a reason to change them. If you do, two
conditions: **they have to stay inside `/var/www/html/storage/app`**, which is
where the volume is mounted (a path outside it is not seen by the other
containers and is lost on the next update), and **they must not coincide or
contain one another**, because each purge deletes in its own folder and only in
its own. `product:doctor` fails if two coincide, if one contains another or if
any of them is `storage/app` (or contains it) or overlaps `BACKUP_PATH`; and if any of them is outside `storage/app` it fails in production (that is the fault the volume fixes) and warns elsewhere. It also warns if it finds generated files in `storage/app` outside the configured paths, which is what is left after changing one of them: empty the old folder.

### 13.6 Where the files the product generates live, and who can read them

Almost everything the product keeps is in PostgreSQL. What it writes to disk
apart from that are these files:

| File | Where | How long it lives | Is it in the backup? |
| --- | --- | --- | --- |
| Full data export (ZIP, §13) | `app-storage` volume, `exports/` folder | 7 days; the hourly purge deletes it | No |
| Reports generated in the background (§6) | `app-storage` volume, `reports/` folder | 7 days; the daily 04:25 UTC purge deletes them | No |
| Diagnostic bundle generated from the console (§12.2) | `app-storage` volume, `diagnostics/` folder | 7 days; the hourly purge deletes it | No |
| Export for the Labour Inspectorate generated from the console | `app-storage` volume, `legal-exports/` folder | **Until you delete it.** After 30 days, `product:doctor` and the `FicheroGeneradoSinRetirarPasadoSuPlazo` alert (§10.4) warn about it | No |
| Temporary file of the Labour Inspectorate export requested from the panel | `app-storage` volume, `tmp/legal-exports/` folder | Deleted when the download finishes; if the download was cut off, the hourly purge deletes it after 6 hours | No |
| Telemetry state (§13.4) | `app-storage` volume, `telemetry/` folder | Kept; it holds no personal data | No |
| Retention reports (§2 and §3) | `BACKUP_PATH/reports/retention` | **Forever**: they are not cleaned up on their own | They live in the backup folder |

**The `app-storage` volume** (Docker shows it as `kronoqr_app-storage`) is
created by Docker at the first start and mounted by the three application
containers —`app`, `horizon` and `scheduler`— at `/var/www/html/storage/app`.
That is why what one generates the other sees: `horizon` generates the export,
`app` serves it to the panel and `scheduler` purges it. There is nothing to
configure, and `./doctor.sh` checks that all three mount it, that what one
writes the other reads, that its root belongs to the `app` user with mode
`0700`, and how much it takes up. To look yourself:

```bash
docker compose exec app sh -c 'du -sh storage/app storage/app/*'
docker compose exec app sh -c 'ls -l storage/app/legal-exports/ 2>/dev/null'
```

The first says how much the volume and each folder take up; the second, which
exports for the Labour Inspectorate are still on the server (if nothing comes
out, there are none).

**It is not part of the backup, and `restore.sh` does not put it back.**
Everything in it expires within days or can be generated again from the
database, which is in the backup. After a restore, what the database remembers
and the volume no longer has shows as "Expired"; the export or the report is
requested again (§18, "…after restoring a backup").

**It takes up space on Docker's disk**, the same one as the database. A full
export of four years of a large workforce can weigh hundreds of megabytes;
until it expires, it counts.

**The files in the volume are in clear text on the server, just like the
PostgreSQL data.** The full export holds all the personal data of the
workforce, unencrypted on purpose: it is the one you must be able to open
without depending on anything
([`legal-obligations.md`](legal-obligations.md) §7 quater), and encrypting it
with a key kept on the same server would protect nothing against whoever can
already read the database. What does protect it is **encrypting the server's
disk**, and we recommend it: the disk where Docker keeps its data (usually
`/var/lib/docker`, which holds the database and this volume) and the one for
`BACKUP_PATH`. It is a measure of the operating system or of the
virtualisation platform, and the decision is yours.

**Belonging to the `docker` group amounts to having access to all the data.**
Whoever is in that group can enter any container, copy any file from the volume
and read the database, without going through the panel or leaving an entry.
Treat it like administrator access ([`hardening.md`](hardening.md) §3).

**The only audited way to take a file away is the panel.** A download from the
panel leaves an entry (`data_export.downloaded`, `report_export.downloaded`);
taking the same file out with `docker compose cp` leaves none. If you do,
write it down yourself (§13.2).

**Three alerts watch these files** (§10.4): one that disappears before
expiring, an export for the Labour Inspectorate forgotten for more than 30 days
and a purge that has refused to touch something. What to do with each:
[`../../runbooks/ficheros-generados.md`](../../runbooks/ficheros-generados.md)
(in Spanish).

**`docker compose down -v` deletes the volume**, just as it deletes the
database. Never use it on a production installation.

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
from the inside. **It stores nobody's clock-ins, and it is built not to store
names or emails.** Since 2.2.0 the server filters the text of each error as
it saves it, with the same closed technical vocabulary described in §12.2: a
word that is not in it —a first name, a surname— is stored as "…", and
numbers shaped like an ID document, phone number, account, employee code,
date, time or IP address, as a marker (`[n]`, `[id]`, `[time]`…). In
addition, a database failure never prints what it was trying to save, and the
context only accepts a closed list of technical keys. A person can only appear
through their internal identifier (`employee_uuid`), which your installation
keeps so the error can be related to what happened, and which **does not
travel in the anonymised bundle**. What cannot be ruled out entirely is the
same as in §12.2: a name that matches a word of the vocabulary and appears on
its own. The full mechanism, one by one, is in
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
| **Pending** | How many clock-ins the tablet holds in its local queue without sending, and **how old the oldest one is**. From 2.2.0, also **"Unknown"** when the tablet has lost the storage for its queue, and **how many discards it has not yet reported** to the server | "37 pending, the oldest 3 h ago" is a tablet that has been without network for three hours, not an error. The clock-ins are safe as long as the tablet is not unpaired and its site data is not cleared. **"Unknown" is not zero**: read §16.3 bis before touching that tablet |
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
| **Failure** | *Queue in memory only* | The tablet **has lost the storage for its queue** and keeps clock-ins in memory, or has nowhere to keep them. It keeps clocking, but whatever it queues **is lost if it restarts**, and the number pending is "Unknown". From 2.2.0; see §16.3 bis |
| **Warning** | *Unreported discards* | The tablet set aside clock-ins the server did not accept as valid and **has not yet managed to report them**: until it does, HR does not see them. From 2.2.0; see §16.3 bis |
| **Unpaired** | *Unpaired* | No longer a source of clock-ins. It counts for no alert |

When there is more than one reason, the row shows **the most serious one**, in
this order: unpaired, never seen, awaiting the first heartbeat, no signal,
queue in memory only, late heartbeat, unreported discards, low battery, pending
clock-ins, beating.

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

### 16.3 bis The tablet's queue: in order, and what you see when something does not add up

When the tablet gets its network back, it sends its queue **in the order the
clock-ins happened**, and from 2.2.0 it keeps to that strictly: **a clock-in is
not recorded while an earlier one from the same tablet is still waiting for the
server's answer**. It is not a whim: the 07:00 clock-in and the 15:00 clock-out
of the same person have to arrive in that order, or the clock-out would be
taken for a clock-in.

**If one gets stuck, the others wait behind it. They are not lost.** If the
server cannot process a clock-in at that moment —it is starting up, the
database is slow—, the tablet retries it later, and everything that came after
it stays in the queue until it goes through. This applies to the whole tablet,
not per person: without network the tablet does not know whose each card is,
so it cannot let some through and hold others. In the panel it shows as
**"Pending clock-ins"** with the oldest stuck at the same time, and if the server
fails again and again on the same clock-in, `ScanBatchItemNotProcessed` fires
(§10.4). It is a server fault, not a tablet fault: **do not clear the
tablet's queue**, fix the cause on the server and follow
[`../../runbooks/cola-offline-atascada.md`](../../runbooks/cola-offline-atascada.md)
(in Spanish). A clock-in the server **rejects** —a revoked card, one that does
not fit the working day ("Out-of-order clocking", [`hr-guide.md`](hr-guide.md)
§4.4)— holds nothing up: it has an answer, and the queue moves on.

**Two new situations on the "Kiosks" screen** (from 2.2.0):

- **"Pending: Unknown"**, with the verdict at **failure**. The tablet **has lost
  its storage** —the browser's, where it keeps the queue— and has switched to
  keeping it in memory. It keeps accepting cards, but it does not know how many
  clock-ins were left on disk, and so it does not make up a zero. Whatever is
  clocked now **is lost if the tablet restarts or switches off**. What to do:
  1. **Do not restart the tablet, reload the app or clear its data while it may
     hold clock-ins only in memory**: any of the three wipes them. If there is
     network, wait for it to sync; what it holds in memory is sent as usual.
     The tablet also tries to recover its storage on its own every so often,
     and if it succeeds it moves what it had in memory to disk and the row
     shows a number again.
  2. **Once it has nothing left to send** —check it on its diagnostic screen
     (§16.5, "Queue" block)— and it still says "Unknown", **reload the app**
     (leaving kiosk mode with the IT PIN).
  3. If it does not come back, **free up space on the tablet** (downloads and
     unrelated apps) and check that the browser is not in guest or private
     mode. Reload again.
  4. If it stays the same, replace the tablet
     ([`../../runbooks/alta-nuevo-quiosco.md`](../../runbooks/alta-nuevo-quiosco.md),
     in Spanish) **after** it has synced.

  While it lasts the `KioskQueueStorageDegraded` alert fires (§10.4), and the
  two stuck-queue alerts stay silent because there is no size to measure.
- **"N discards not yet reported to the server"**, with the verdict at
  **warning**. The tablet sent clock-ins the server **did not accept as
  valid** —almost always, a tablet app older than the server after an
  update—. So as not to hold up the queue it has set them aside, but it keeps
  them and has to **report** them to the server, which is what opens the
  "Clocking discarded by the kiosk" incident for HR
  ([`hr-guide.md`](hr-guide.md) §4.7). Until the number drops to zero, HR does
  not know about those clock-ins. What to do: check that the tablet has network
  (the report goes out by itself as soon as it does), and **update its app** by
  reloading it with the queue empty (§11, "the tablets"). **Do not unpair it or
  clear its data**: those reports exist only on it. If it goes past 30 minutes
  the `KioskUnreportedDiscards` alert fires (§10.4).

### 16.4 What to do when a row is not up to date

**When —and only when— the verdict is not "up to date"**, the row carries a
**"What to do"** block written for someone who does not know the system, with
the next step for its reason. A kiosk that is fine asks for nothing. The
summary:

| What you see | Where to start |
| --- | --- |
| **Failure, no signal** | [`../../runbooks/quiosco-no-responde.md`](../../runbooks/quiosco-no-responde.md) (in Spanish) §2, which starts on this very screen |
| **Warning, pending clock-ins** — the tablet **has network and still has clock-ins left to send** | [`../../runbooks/cola-offline-atascada.md`](../../runbooks/cola-offline-atascada.md) (in Spanish). **Do not unpair that tablet**: you would lose the queue |
| **Failure, queue in memory only** — "Pending: Unknown" | §16.3 bis and [`../../runbooks/cola-offline-atascada.md`](../../runbooks/cola-offline-atascada.md) (in Spanish) §7. **Do not restart the tablet** until it has synced |
| **Warning, unreported discards** | §16.3 bis and [`../../runbooks/cola-offline-atascada.md`](../../runbooks/cola-offline-atascada.md) (in Spanish) §8. Network first; then, update that tablet's app |
| **Warning, low battery** | Go to the mounting point: unplugged charger, switched-off power strip or a broken cable |
| **Warning, late heartbeat** | Nothing yet. If it does not return to "up to date" within ten minutes it becomes a failure and the alert fires |
| **The tablet went back to the pairing screen on its own** | Someone unpaired it, or it went more than about 18 days without a heartbeat and its token expired before it could be renewed (§18): [`../../runbooks/alta-nuevo-quiosco.md`](../../runbooks/alta-nuevo-quiosco.md) (in Spanish) §6 |

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
| **Queue** | Pending clock-ins, how old the oldest is, whether the tablet's storage is durable and whether it is syncing right now. From 2.2.0, also **how many discarded clock-ins it has not yet reported** to the server (§16.3 bis) |
| **Roster** | How old the local copy of the workforce is and how many entries it has |
| **Token** | Whether the tablet is paired, when its credential expires, its device identifier and the kiosk name. **The token is never shown**: only eight characters of its fingerprint, so you can compare it with the panel |
| **Version** | The version of the PWA and the state of its update: "up to date" or "update pending: it will be applied in the HH:MM–HH:MM window", with the window in force (§11.1) |
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
| `KIOSK_UPDATE_WINDOW` | `03:00-05:00` | Slot, in site local time, in which the tablet **may** install a new version of the app (§11.1). **It is not a `.env` variable**: it is changed in the panel |
| `KIOSK_UPDATE_QUIET_MINUTES` | `10` | Minutes without a single clocking that the tablet demands, on top of the window and the empty queue, before updating (§11.1). Same: in the panel |

---

## 17. Server sizing and load test

> **What this section is for.** To answer, with a measurement of your own rather
> than a promise of ours, two questions that are only asked once: "does this
> server cope with my shift change?" and "what do I change if it does not?".

### 17.1 What the product promises, and what it means in a hotel

The product is designed against a written threshold: **50 clock-ins per second
sustained on the server, with 95 % of the responses under 150 ms** (`RNF-P-06`
and `RNF-P-02`). **Today it is a design target, not a measured figure we can
hand you**: it has not yet been measured on the reference hardware (4 cores and
8 GB) with a dedicated server, so no version ships with that measurement. When
it exists, the release notes will say so, with the figure and the machine.

**Measure it yourself with `make load-test`** (§17.2): it is the same test, it
gives a verdict requirement by requirement and it is the only figure that holds
for your server, because it comes from your hardware, your disk and your
network.

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
| **On the server, in total** | **50 clock-ins per second sustained** across all origins, with p95 < 150 ms | It is the design target and what the load test judges when you run it on your server |

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
([`installation.md`](installation.md) §0). The minimum is sized for a workforce
of up to 100 people with the default pool — that is the design target; confirm
it on your server with `make load-test` —; beyond that, the conversation is
about cores and RAM before it is about parameters.

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

---

## 18. What to do if…

The foreseeable failures of a running installation, with the symptom someone at
the hotel sees and the commands to get out of it. **First of all, in any of
them:**

```bash
./doctor.sh
```

It says what is red and what to do about each thing (§12.1). What follows is
for when you already know which of these cases is yours.

### …a tablet goes back to the pairing screen after many days switched off or without network

**What is going on.** When it is linked, each tablet receives a token that
lives **90 days** (`IDENTITY_DEVICE_TOKEN_DAYS`). **You do not have to renew it
yourself**: as long as the tablet is on and has network, the server hands it a
new one in its heartbeat once that token has used up 80 % of its life
(`IDENTITY_DEVICE_TOKEN_ROTATION_THRESHOLD`) —with the default values, around
**day 72**—, and the tablet adopts it without anyone doing anything and
without interrupting clock-ins. The previous token stays valid for **24 more
hours** (`IDENTITY_DEVICE_TOKEN_OVERLAP_HOURS`) or until the tablet uses the new
one, whichever comes first: if a wifi drop swallows exactly that response, the
next heartbeat hands it another. Every renewal is recorded in the audit log as
`device.paired`.

**The limit, which is the only thing you have to watch.** The renewal can only
happen in a heartbeat. Between day 72 and day 90 the tablet has **18 days** to
send at least one. A tablet that spends **more than about 18 days in a row
without a heartbeat** —switched off in a storeroom, put away out of season, or
on but without network all that time— can reach day 90 without having picked
up its replacement. Its token then expires: when it comes back, the tablet is
no longer accepted, goes back to the pairing screen by itself and **nobody can
clock in on it until it is linked again**. It is not a fault and it does not
fix itself.

The renewal is done by **the tablet app from 2.2.0 onwards**. After updating
the server, each tablet loads the new app in its update window (§11.1),
normally that same night. Check in **Kiosks** that the **"Application version"** column
of all of them has caught up: a tablet still on an earlier app would not pick
up its replacement and would go back to the pairing screen the day after its
day 72.

**What is not lost:** the clock-ins the tablet had in its local queue. They are
kept and sent as soon as it is linked again.

**Prevention.** In the panel, **Kiosks**, the **"Last contact"** column says
when each tablet last sent a heartbeat (§16.1). A tablet in **failure, no
signal** is not urgent because of the token during the first few days, but do
not leave it like that for more than **two weeks**: switch it on and connect it
so it sends a heartbeat. If you are going to put a tablet away for longer
(end of season, a station that is not used), the clean way is to **unlink it**
when you put it away and link it again when you bring it back, with the same
name.

**If it has already happened.** The panel still shows that kiosk as **active**
(its verdict will be *No signal*): the server does not know the tablet has been
locked out until someone resolves it. That is why **it will not let you link
it with the same name** —it will say that name is already in use— **until you
unlink it first**. With the tablet in front of you and away from the shift
change:

1. **Unlink it** (Kiosks › the kiosk › **Unlink**). Within a couple of minutes
   the tablet shows a new code.
2. **Link it with exactly the same name.** The same kiosk is reactivated, with
   its history, and receives a new 90-day token that from then on renews
   itself again.
3. Check that its queue goes down to `0`:

```bash
docker compose exec app php artisan kiosk:health
```

It takes two minutes and it is recorded in the audit log. The detail, with
screenshots, is in
[`../../runbooks/alta-nuevo-quiosco.md`](../../runbooks/alta-nuevo-quiosco.md)
§5.2 and §5.3 (in Spanish).

**If a tablet that did send heartbeats every day goes back to the pairing
screen**, it is not an expired token: someone unlinked it. Handle it as
described in
[`../../runbooks/alta-nuevo-quiosco.md`](../../runbooks/alta-nuevo-quiosco.md)
§6, "…la tablet vuelve sola a la pantalla de emparejamiento" (in Spanish).

### …Redis restarts over and over, almost always after a power cut

**Symptom.** `docker compose ps` shows `redis` as `Restarting`, and its log
talks about the persistence file (`Bad file format reading the append only
file`, `AOF ... is not valid`). An abrupt shutdown left that file half written
and Redis refuses to start with it.

**Impact.** **Clocking in keeps working**: the tablets record against the
database, which does not depend on Redis. What does fail in the meantime:

| What | How you notice |
| --- | --- |
| Access to the panel and the portal | Errors when logging in, or screens that do not load |
| Background jobs (reports, exports, notices) | The ones requested now come out **"Failed"**: they must be repeated once Redis is back |
| The real-time presence screen | Stops updating instantly |

**What to do.** `./doctor.sh` detects it and gives you these same commands.
Stop Redis, repair its file and bring it back up. The repair asks before
truncating; the `echo y` in the second command answers for you. It ends with
`All AOF files and manifest are valid`.

```bash
docker compose logs --tail 30 redis
docker compose stop redis
echo y | docker compose run --rm -T --no-deps --entrypoint redis-check-aof redis --fix /data/appendonlydir/appendonly.aof.manifest
docker compose up -d redis
./doctor.sh
```

If `./doctor.sh` still sees a service stopped, `docker compose up -d` brings
everything up. The same procedure, seen from the alert that triggers it, is in
[`../../runbooks/errores-en-el-panel.md`](../../runbooks/errores-en-el-panel.md)
§1.1 (in Spanish).

The repair truncates the last thing written before the cut. **Redis holds
nothing of the working-time record**: what is lost is, at most, jobs that were
queued at that moment. If a report comes out "Failed", request it again.

**If `redis-check-aof` cannot repair it**, Redis can be started empty: for the
same reason, no clock-in and no correction is lost. Queued jobs and the
failed-attempt counters are lost, and the counters go back to zero. Replace
`NOMBRE` with the name the second command returns:

```bash
docker compose rm -sf redis
docker volume ls --filter name=redis-data
docker volume rm NOMBRE
docker compose up -d
```

### …a panel screen says that feature is not included in the licence (`402`)

**It is neither a fault nor a permission.** It is the product's `402` response:
the **accessory** feature requested — period reports, payroll export, adoption
dashboard, background exports — is not in the active licence, or the licence
has expired. The message itself says what is still available.

**What never answers `402`:** clocking in, tablet synchronisation, looking up
working days, the employee portal, the export for the Labour Inspectorate,
corrections, the audit log and backups. No licence touches those (§7).

**What to do:**

```bash
docker compose exec app php artisan license:show
```

If it says there is no licence, that it expired or that the feature is not in
the plan, it is a conversation with the vendor, not an IT task. With the new
key, `license:activate` (§7). Do not restart anything: it achieves nothing.

### …reports stay "Queued" and never finish

**What is going on.** Reports and exports that do not fit in an immediate
response are generated by the **`horizon`** service, the queue worker. If it is
stopped, they stay **"Queued"**. **They are not lost**: they are generated as
soon as it comes back. Today no alert warns that `horizon` is stopped; this is
the symptom.

```bash
docker compose ps horizon
docker compose logs --tail 50 horizon
docker compose up -d horizon
```

If `horizon` does not start, read its log: it is almost always that Redis is
not there either (previous case in this same section) or that the database does
not respond (`./doctor.sh`). Clocking in does not depend on `horizon`.

### …the nightly backup has failed

**Symptom.** `CopiaDeSeguridadFallida`, `CopiaDeSeguridadSinVerificar` or
`CopiaDeSeguridadAusente` fires (§10.4), or `./doctor.sh` warns that it cannot
write to the backup directory.

**Impact.** Clocking in does not notice. But **without a verified backup there
is no update** (`update.sh` refuses at its step 3) and, if a restore were
needed, you would go back to the last good backup. Solve it the same day.

The backup commands go through the **`scheduler`** container, not `app`: it is
the one holding the encryption key and the backup role.

```bash
docker compose ps scheduler
docker compose logs --since 24h scheduler | grep -i backup
docker compose exec scheduler php artisan backup:verify
docker compose exec scheduler php artisan backup:run
```

The last one ends with a code from the common table (§8), and the message says
the cause:

| Code | Most frequent cause | What to do |
| --- | --- | --- |
| `2` | `BACKUP_PATH` not mounted or out of space, or the encryption key is missing | Mount the destination or free space and repeat. The previous backup is intact |
| `6` | The backup was written but **does not verify** | Treat it as non-existent and repeat. If it happens again, [`../../runbooks/restaurar-backup.md`](../../runbooks/restaurar-backup.md) §2 (in Spanish) |
| `7` | The backup role has more privileges than allowed | It is not a fault: [`../../runbooks/rotacion-secretos.md`](../../runbooks/rotacion-secretos.md), "El rol de las copias es privilegiado" (in Spanish) |

The full diagnosis, code by code, is in
[`../../runbooks/restaurar-backup.md`](../../runbooks/restaurar-backup.md) §2
(in Spanish).

### …the restore refuses on integrity grounds (exit `6`, "unauthenticated backup")

**What is happening.** Since 2.2.0 every backup carries a keyed signature (a
MAC) over its content, its name and its creation date. `restore.sh`,
`restore-drill.sh` and `backup.sh verify` check it **before** decrypting, and
refuse, **without touching the database**, if:

- the backup's `.sha256` is missing (it is mandatory);
- the MAC does not match: the file has been altered or is damaged;
- the file name is not the one its header states: it has been renamed or put in
  place of another;
- the dump's authenticated manifest (`.manifest.mac`) is missing or does not
  match;
- the backup is **from 2.1.0**, which has no MAC: it is an "unauthenticated
  backup", and it is not used unless you ask for it explicitly.

**Before confirming a restore, read the date `restore.sh` shows** ("copia
creada el …", backup created on …). It comes from the authenticated header, not
from the file name: if it is not the one you expect, someone has put an older
backup in its place. Do not go on.

**What to do**, depending on the message:

| Message | What to do |
| --- | --- |
| `el MAC no cuadra` (the MAC does not match) | Do not use that backup: try the previous one (`restore.sh --list`). If no storage fault explains it, treat it as a security incident ([`brecha-de-seguridad.md`](../../runbooks/brecha-de-seguridad.md), in Spanish) |
| `clave distinta o cabecera alterada` (different key or altered header) | Almost always, a rotation of `BACKUP_ENCRYPTION_KEY`: pass the old one with `-e BACKUP_ENCRYPTION_KEY_PREVIOUS` to `docker compose run --rm restore` (restore only) and repeat |
| `sin .sha256` (no `.sha256`), or the manifest is missing | The backup is incomplete or has been tampered with. Do not use it |
| `copia heredada de la 2.1.0` (backup inherited from 2.1.0) | See below |

**Restoring a 2.1.0 backup.** It is encrypted but **not authenticated**: its
`.sha256` proves nothing against someone who can write to the destination. If
it is the one you need, ask for it **in the command itself** with
`--accept-unauthenticated` (first with `--dry-run`):

```bash
docker compose run --rm --no-deps restore bash /opt/kronoqr/scripts/restore.sh --accept-unauthenticated --file <copia>.dump.enc --dry-run
```

- It only works for 2.1.0 backups, and the `.sha256` **is still mandatory** and
  has to match.
- It is passed **in every command**. Never put `KRONOQR_ACCEPT_UNAUTHENTICATED`
  in the `.env` or in a cron table: it is ignored (only the `--accept-unauthenticated` option counts), and `./doctor.sh` reports it as a failure.
- **It is recorded**: the restore report notes it on its first line and the
  `system.restored_from_backup` entry in the audit log carries
  `integrity=legacy_accepted`.
- It will disappear once no version earlier than 2.2.0 can be updated from.
  2.1.0 backups expire by themselves after `BACKUP_RETENTION_DAYS`.

The detail is in
[`../../runbooks/restaurar-backup.md`](../../runbooks/restaurar-backup.md) §6.8
(in Spanish).

### …point-in-time recovery stops ("restore_command failed", `exit 200`)

**What is happening.** While replaying the archived WAL over a physical copy,
`kronoqr-restore-wal` has found a segment that **cannot be trusted**: the MAC
does not match, it is encrypted with another key, an intermediate segment is
missing or it could not be read. It returns `200` and PostgreSQL **aborts the
recovery on purpose**: if it went on, the database would "recover successfully"
up to the previous segment and data would be lost without anyone seeing it. The
error names the segment and the reason.

**What to do:**

1. **Do not use or promote that database.** It stays in recovery, and that is
   correct.
2. Resolve the reason with the runbook table (§4.1): the old key if
   `BACKUP_ENCRYPTION_KEY` was rotated, or the segment from another medium that
   keeps it.
3. If that is not possible, repeat with a `recovery_target_time` **earlier**
   than that segment, and write down in the incident report how far you got.

The full procedure, with the commands, is in
[`../../runbooks/restaurar-backup.md`](../../runbooks/restaurar-backup.md) §6.4
(in Spanish). Rehearse it first with `restore-drill.sh --mode pitr`, which does
it in a clean container without touching anything (runbook §7).

### …after restoring a backup, an export or a report shows as "Expired"

**What is happening.** You restored everything correctly. The backup holds the
database, but **not the generated files** (§13.6): the full export and the
background reports expire within 7 days and can be generated again from the
database, so they are not backed up. After a restore they can be out of step
in both directions:

- **The database remembers an export or a report whose file is no longer
  there** —it was purged after the backup, or you restored onto a new server,
  with an empty volume—. In the first purge pass (the hourly one for full
  exports, the 04:25 UTC one for reports) it turns to **"Expired"** and leaves
  the `data_export.file_missing` or `report_export.file_missing` entry; if it
  had not expired yet, the `FicheroGeneradoDesaparecidoAntesDeCaducar` alert
  may fire as well. **After a
  restore that is expected**, and the `restore.sh` report (in
  `BACKUP_PATH/reports/`) announces it.
- **The volume has a file the restored database does not know about** —it was
  generated after the backup—. Nobody can download it from the panel, and the
  purge deletes it on its own when its period is up. There is nothing to do.

**What to do.** Ask again for whatever you need: the full export from Licence →
"Your data is yours" (§13.2), and each report from the panel, by whoever needs
it. They are generated from the restored data, which is what you want. If
`FicheroGeneradoDesaparecidoAntesDeCaducar` fires **without** there having been a restore or an update
from 2.1.0, it is not this: read §13.3, "If the file disappears before it
expires".

**What has not been lost.** The retention reports are in
`BACKUP_PATH/reports/retention` and the restore does not touch them: a purge
report describes something that happened, even if the database goes back to an
earlier moment.

### …you need to prove a purge and its report is missing

**When it happens.** You are asked to show that a retention purge was regular
—a complaint, a data protection audit— and the `retencion-purga-*.txt` file is
not in `BACKUP_PATH/reports/retention`. Up to 2.1.0 the reports of purges run
with `run --rm` disappeared when the order finished, and the rest with every
update; `update.sh` rescues, when moving to 2.2.0, the ones still left (§11),
but not the ones already lost.

**What counts is the audit entry, and it is still there.** Every purge that
deleted working-time records left in the audit log a `retention.purge_executed`
entry with the date, the cut-off date, the retention years, the per-table counts
and the confirmation token. The audit log is kept for four years and is
hash-chained: it is stronger evidence than the file, which was never more than
its readable copy (§3.1).

**What to do:**

1. Check that the audit chain is intact:

   ```bash
   docker compose exec app php artisan compliance:verify-audit-chain
   ```

2. Get the entry with the query in §3.1 and find the purge by its date.
3. Cite it like this in your reply or in your file: *"Retention purge executed
   on YYYY-MM-DD at HH:MM UTC, recorded in the system's audit log as
   `retention.purge_executed` with confirmation PURGAR-AAAA-MM-DD-xxxxxx:
   working-time records earlier than YYYY-MM-DD, kept for N years; N rows,
   broken down by table in the entry itself. The integrity of the audit log was
   verified on YYYY-MM-DD."*
4. Attach the output of both orders and the written authorisation that was
   signed at the time.

If the purge only dropped audit partitions, what you cite are its
`retention.partition_sealed` and `retention.partition_dropped` entries (§3.1).
