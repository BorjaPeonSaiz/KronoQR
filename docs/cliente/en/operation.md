# Operating KronoQR — what needs attention, and how often

> **Status.** Sections 1 to 6 come from **task 2.10**: retention and purge,
> the only operation in the product that deletes data. Section 7 comes from
> **5.3** (licence). Sections **8, 9 and 10** come from **5.4**: the exit codes
> of the five scripts, the custody of secrets and what you lose if you switch
> observability off. Section **11** comes from **5.7**: updating. Sections
> **12 and 13** come from **5.9** and **5.10**: diagnostics, support and the
> full data export. Section **15** comes from **5.12**: the `error_events`
> history. **Task 5.11** will add the kiosks; it will not rewrite anything
> that is already here.

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
| `6` | **The work was done but the subsequent verification failed.** Nothing is undone | `install.sh`: the services are up, check the certificate and the logs. `backup.sh`: the backup exists but **does not verify: treat it as non-existent**. `restore-drill.sh`: today the record could not be recovered. `update.sh`: **does not use it**, every failed verification rolls back. `doctor.sh`: **the diagnosis has found at least one failure** — with the application running, in its own report (`product:doctor`); with the application stopped, in one of the external checks. The message says what to read |

`install.sh` and `update.sh` invoke `product:doctor` in their verification
phase (RF-PD-13): in `install.sh` a warning (`product:doctor` code `1`) is
shown and does not block, and only a failure (`2`) translates into the `6` of
the table above. In `update.sh` there is no translation into `6`: a failed
verification always rolls back, so a `product:doctor` failure on the new
version triggers the rollback just like any other failure in step 5.

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

---

## 10. Observability, and what you lose if you switch it off

The `.env` ships with `COMPOSE_PROFILES=observability`. It brings up five more
services —Prometheus, node-exporter, Alertmanager, Grafana and Loki— and takes
up about 700 MiB.

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
| `configuration` | In addition, **change** the operational settings and pair or unlink kiosks | See working days or the workforce, or touch the compliance profile (legal thresholds and retention years are yours) |

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

### 13.1 What it is

A single ZIP file, `kronoqr-export-<versión>-<fecha UTC>.zip`, with
**everything** in your installation in open formats: one CSV per table
(workforce, contracts, cards, kiosks, shift entries with all their versions,
corrections with author and reason, totals, incidents, scans, the complete
audit trail with its hash chain, management accounts, support access grants),
JSON for the configuration, the compliance profile and the licence, a
`manifest.json` with the row count and the `sha256` fingerprint of each file,
and a `README.md` that explains each file and each column in the language of
the installation. Dates and times are in **UTC**; the `README` states the
site's time zone and how to convert them.

**No secret leaves**: no passwords, no PINs, no hashes, no licence key. No
internal numbers: references between files go by `uuid`.

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

Five things a system administrator does every day on other products and that
here destroy the legal value of the record or leave the installation unable to
recover:

| Never | Why | What to do instead |
| --- | --- | --- |
| **Modify data with direct SQL** (`UPDATE`, `DELETE` or `INSERT` on the application tables) | Every correction keeps the previous version with author, moment and reason. A change by SQL leaves no trace and makes the record unreliable before the Labour Inspectorate | Corrections are made from the panel, and are traced |
| **Delete or alter rows of `audit_log`** | It is append-only and every entry is hash-chained to the previous one. The application's database user **does not have** `UPDATE` or `DELETE` on that table, on purpose; only the maintenance role can drop partitions already expired, and only in the confirmed purge (§3) | If the chain does not verify: [`../../runbooks/rotura-cadena-auditoria.md`](../../runbooks/rotura-cadena-auditoria.md) (in Spanish) |
| **Touch `daily_totals` by hand** | It is a rebuildable projection: it is recalculated in full every time a shift entry changes. A total corrected by hand goes back to its value on the next recalculation, without anyone understanding why | If a total does not add up, recalculate it: `docker compose exec app php artisan attendance:reconcile --from=2026-09-01 --to=2026-09-30` |
| **Edit a generated secret in the `.env`** (`APP_KEY`, `QR_SIGNING_KEY_*`, `BACKUP_ENCRYPTION_KEY`) | Changing `APP_KEY` makes everything encrypted unreadable; changing the QR key invalidates every card; changing the backup key leaves the previous backups impossible to restore | Rotate with its procedure: [`../../runbooks/rotacion-secretos.md`](../../runbooks/rotacion-secretos.md) and [`../../runbooks/rotacion-clave-qr.md`](../../runbooks/rotacion-clave-qr.md) (in Spanish) |
| **`migrate:rollback`, deleting volumes or reinstalling on top** | A rollback is always restoring the previous verified backup; the installer refuses to reinstall over an existing installation | `update.sh` (§11) and [`../../runbooks/restaurar-backup.md`](../../runbooks/restaurar-backup.md) (in Spanish) |

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
