# Hardening a KronoQR installation

Annex to [`installation.md`](installation.md). When you finish installing, the
system **works**. This guide is what it takes for it to also be **properly set
up**: what is published and to whom, where each secret lives, what falls to the
server and what falls to the tablets.

Read it in full once, on go-live day, and go over it again with the checklist
in §11 every three months.

---

## 0. Who this is for, and what it does not cover

For the **hotel's IT staff**, after installing and before signing the
installation off as delivered. You do not need to know Laravel.

**The split, without ambiguity:**

| Hardened by the vendor | Hardened by you |
| --- | --- |
| The images: every release runs a vulnerability scan (Trivy) over the product images and **is not published** with high or critical findings | The server's operating system and its Docker: patches, users, SSH |
| The HTTP edge: TLS 1.3, security headers, per-zone request limits, `/metrics` and the portal restricted by range | **The network**: which port is reachable from where, and what does not go out to the internet |
| The application: separate database roles, append-only audit, secrets generated on your server, and a refusal to start in production with debugging switched on | The **tablets**: kiosk mode, physical custody, Android updates |
| The image tag pinned per release: an installation can always say which version it is running | The **accounts**: who has access, with which role, and removing them on time |
| The scripts (`install.sh`, `update.sh`, `backup.sh`, `doctor.sh`): they check before touching anything, print no secrets and undo what they did if they fail | The **custody** of the secrets that leave the server, and of the backups |

**Every control in this guide says what it closes and whose it is.** If it says
"Owner: you", there is no future version of the product that will solve it: it
cannot be solved from inside a container.

**What this guide does not cover**, on purpose: how to harden *your* Linux
distribution, *your* device manager or *your* particular firewall. The examples
are generic and are marked as such; your tool's manual takes precedence over
them.

---

## 1. Network exposure: what must be reachable from where

This is the section that prevents the most incidents, and the only one that has
to be done **before** giving the URL to anyone.

### 1.1 The table

Assuming the URL `https://fichaje.tuhotel.local`:

| Path | Who uses it | Must be reachable from | Who enforces it |
| --- | --- | --- | --- |
| `/kiosk/` | The tablets' PWA | **Only the kiosk VLAN** | **You** (VLAN and firewall) |
| `/api/v1/scan`, `/api/v1/scan/batch`, `/api/v1/scan/pin` | The tablets, when clocking in | **Only the kiosk VLAN** | **You**. The product **raises the limit** inside `KIOSK_VLAN_CIDR` (§1.4); it does not restrict access |
| `/api/v1/kiosk/*` (pairing, roster, heartbeat) | The tablets | **Only the kiosk VLAN** | **You** |
| `/admin/` and the management API (`/api/v1/auth/*` and the rest of `/api/v1/*`) | The HR and IT panel | **Only the internal network or the VPN. Never the internet** | **You.** The product does **not** filter these paths by range |
| `/portal/` and `/api/v1/me/*` | The employee portal | Only `PORTAL_INTERNAL_CIDR`; any other origin receives `403` at the edge | **The product** |
| `/metrics` | The metrics collector | Only `METRICS_ALLOW_CIDR`; any other origin receives `403` | **The product** |
| `/api/v1/health`, `/api/v1/ready`, `/healthz` | Probes and `doctor` | Internal network. **Unauthenticated**: see §10 | **You** |
| `/api/v1/branding`, `/api/v1/branding/logo` | The three applications, before identifying anyone | Wherever the edge is reachable. **Unauthenticated**: see §10 | — |
| Grafana | You, to look at dashboards | Listens **only on `127.0.0.1:3000`**: reached through an SSH tunnel | **The product** |
| Prometheus, Alertmanager, Loki, node-exporter | The system itself | **Publish no port** on the server | **The product** |
| PostgreSQL, Redis, Reverb | The system itself | **Publish no port** on the server | **The product** |

Only two services publish a port on the server: the HTTP edge (`HTTP_PORT` and
`HTTPS_PORT`, 80 and 443 by default) and Grafana, bound to `127.0.0.1`. Check
it:

```bash
cd /opt/kronoqr-2.1.0
docker compose ps --format 'table {{.Service}}\t{{.Ports}}'
```

> **Closes:** unnecessary exposure of the database, the queue and the
> observability stack. · **Owner:** the vendor (that they are not published),
> you (checking it).

### 1.2 What the product filters, and what you have to filter

Say it out loud before going on: **the product restricts the employee portal
and the metrics by range, and nothing else.** The panel, the management API and
the kiosk path are served to whoever reaches port 443.

This is not an oversight. The edge does not know what your network is and
cannot guess it without an over-permissive default ending up as everyone's. You
provide the segmentation, with the VLAN and the firewall, and that is why it is
here.

> **Closes:** a hotel's management panel ending up reachable from the internet
> because nobody decided otherwise. · **Owner:** you.

### 1.3 The panel, before step 1 of the wizard

**Do not publish the panel — do not give it a DNS name, do not open its port to
the outside — until you have finished step 1 of the onboarding wizard.** The
"first administrator" screen is the only one in the product that writes without
asking for credentials, and **whoever arrives first uses it**. It closes itself,
for good, as soon as a management account exists.

The full procedure, including what to do if you run into a `409` saying there
is already a management account when you have not created any, is in
[`installation.md`](installation.md) §1.7.

> **Closes:** takeover of the installation by whoever gets there before you.
> · **Owner:** you.

### 1.4 The kiosk VLAN: the failure is silent

The tablets go on **their own VLAN**, separate from the guest network and from
the office network, and that range is declared in `KIOSK_VLAN_CIDR`. From there
the edge accepts **600 clock-ins per minute**; from any other origin, **30**,
which is a limit designed for the internet. The internal limit **is raised, not
removed**: a compromised machine plugged into that VLAN still has a ceiling.

If the tablets fall outside the range, **you will see no error**: you will see
*"the kiosk is slow at 06:00"*. The two limits and why there are two are in
[`installation.md`](installation.md) §6; what this guide adds is **how to check
that the configuration matches reality**, from the server and with the tablets
already mounted:

```bash
cd /opt/kronoqr-2.1.0
docker compose logs --tail 200 nginx | grep '"from_kiosk_vlan":0'
```

Every line that shows up there with a `/api/v1/scan` path is a clock-in that
did **not** come in through the fast zone. If they are your tablets', fix
`KIOSK_VLAN_CIDR` in the `.env` and reload the edge:

```bash
cd /opt/kronoqr-2.1.0
docker compose up -d nginx
```

> **Closes:** silent degradation of clocking in at the shift-change peak.
> · **Owner:** you (the VLAN and the value), the vendor (the two zones).

### 1.5 Firewall example

**This is an example, not a supported configuration.** The ranges are made up:
replace them with yours. Your firewall's manual takes precedence.

With `ufw` (Debian/Ubuntu), assuming kiosk VLAN `10.0.20.0/24`, office network
`10.0.10.0/24` and VPN `10.8.0.0/24`:

```bash
sudo ufw default deny incoming
sudo ufw default allow outgoing
sudo ufw allow from 10.0.10.0/24 to any port 22 proto tcp comment 'SSH solo desde ofimatica'
sudo ufw allow from 10.0.20.0/24 to any port 443 proto tcp comment 'KronoQR quioscos'
sudo ufw allow from 10.0.10.0/24 to any port 443 proto tcp comment 'KronoQR panel'
sudo ufw allow from 10.8.0.0/24 to any port 443 proto tcp comment 'KronoQR VPN'
sudo ufw enable
sudo ufw status numbered
```

With `nftables`, the same criterion:

```bash
sudo mkdir -p /etc/nftables.d
sudo tee /etc/nftables.d/kronoqr.nft >/dev/null <<'REGLAS'
table inet kronoqr {
  chain input {
    type filter hook input priority 0; policy drop;
    ct state established,related accept
    iif "lo" accept
    ip saddr 10.0.10.0/24 tcp dport 22 accept
    ip saddr { 10.0.20.0/24, 10.0.10.0/24, 10.8.0.0/24 } tcp dport 443 accept
  }
}
REGLAS
sudo nft -f /etc/nftables.d/kronoqr.nft
sudo nft list table inet kronoqr
```

> **Before applying a `drop` policy, make sure you can get back in.** Leave a
> second SSH session open, or have the virtual machine's console to hand. And
> check that those rules **are loaded at boot**: `nft -f` applies them now, it
> does not make them permanent. In most distributions that means including the
> file from the `nftables` configuration and enabling its service; your
> distribution's manual says how.

Two warnings that cost dearly if forgotten:

1. **Docker publishes ports bypassing `ufw`** on many distributions, because it
   writes its own rules. Check from **outside** the server that what you believe
   closed is closed, instead of trusting the rule table:

   ```bash
   nmap -Pn -p 22,80,443,3000,5432,6379 fichaje.tuhotel.local
   ```

   Only the ports you decided on should show as open.
2. **Leave port 80 open only if you need it.** The edge uses it to redirect to
   HTTPS and for automatic certificate renewal. With your own certificate and
   without that renewal, close it.

> **Closes:** the panel and the clock-in path being reachable from networks
> that should not reach them. · **Owner:** you.

### 1.6 If you put a reverse proxy in front

It is a legitimate set-up — for instance, to keep a service that was already
listening on 443 — but it has **two consequences you need to know beforehand**:

1. **All traffic will reach the edge with the proxy's IP.** The two clock-in
   zones stop telling origins apart and `KIOSK_VLAN_CIDR` stops having any
   effect: everything falls into whichever zone that single IP belongs to. The
   same happens to `PORTAL_INTERNAL_CIDR` and `METRICS_ALLOW_CIDR`. **It cannot
   be fixed from the `.env`.**
2. **The tablets have to open the PWA from the same origin that serves the API,
   and the proxy must not touch the security headers.** If it removes or
   rewrites `Permissions-Policy`, the kiosk camera is no longer granted and the
   symptom does not point to its cause — it is in
   [`../../runbooks/alta-nuevo-quiosco.md`](../../runbooks/alta-nuevo-quiosco.md)
   (in Spanish) §6.

The recommended layout is to publish the KronoQR edge directly and segment with
the firewall.

> **Closes:** a performance degradation and a camera failure that cost hours of
> diagnosis. · **Owner:** you.

---

## 2. TLS

**Your own certificate or Let's Encrypt: both are fine.** What is not fine is a
self-signed one in production: it forces someone to accept a warning on every
tablet every morning, and the day somebody does not accept it, that kiosk does
not clock anyone in. That is why `TLS_ALLOW_SELF_SIGNED` stays at `true` **only
in testing**, and goes to `false` in production.

Where the certificate goes, which owner it has to have and what to repeat on
every renewal is in [`installation.md`](installation.md) §6. What this guide
adds is three things:

- **The certificate name has to be exactly the one in `APP_URL`**, and `tls.crt`
  has to carry the **full chain** of intermediates. With an incomplete chain
  some browsers accept it and others do not, and tablets tend to be in the
  second group.
- **If your CA is internal, install it in the trust store of every tablet**
  ([`../../runbooks/alta-nuevo-quiosco.md`](../../runbooks/alta-nuevo-quiosco.md) §2.5).
  Do not teach staff to accept the warning.
- **The edge requires TLS 1.3.** A device that does not support it will not
  connect: test it with one tablet before buying twenty.

**Expiry is watched by `doctor`**, with a 30-day margin, and it reads the
certificate **the edge is serving**, not the file on disk: that way it also
catches the case of having renewed without reloading, which is exactly the
mistake that leaves someone believing they have already fixed it.

```bash
cd /opt/kronoqr-2.1.0
./doctor.sh
```

> **Closes:** traffic interception, and kiosks that stop synchronising without
> warning. · **Owner:** you (the certificate), the vendor (TLS 1.3 and the
> expiry warning).

---

## 3. The host

Outside the product, and entirely yours. The minimum:

- **A server dedicated to this.** Do not share the machine with the POS or the
  file server.
- **No interactive `root` session.** A dedicated user with `sudo`.
- **The `docker` group is equivalent to `root`.** Whoever is in that group can
  mount the whole disk inside a container. Treat membership as you would treat
  passwordless `sudo`: named, reviewed and short.

  ```bash
  getent group docker
  ```

- **SSH with a key, never with a password**, and no direct `root` access:

  ```bash
  sudo sed -i 's/^#\?PasswordAuthentication.*/PasswordAuthentication no/' /etc/ssh/sshd_config
  sudo sed -i 's/^#\?PermitRootLogin.*/PermitRootLogin no/' /etc/ssh/sshd_config
  sudo sshd -t && sudo systemctl reload ssh
  ```

  If your distribution calls that service `sshd`, use `sudo systemctl reload
  sshd`. And check that **you can still get in** from another terminal
  **before** closing the current session.
- **Operating system and Docker patches.** The vendor patches the product
  images in every release; the host is yours. Automatic security updates, and a
  planned reboot away from the shift change.
- **Time synchronised by NTP.** The system stores everything in UTC and the
  conversion is done by the screen, so a drifted clock corrupts nothing, but it
  **does fill the incidents tray**: a clock-in arriving with more than
  `ATTENDANCE_MAX_CLOCK_SKEW_MINUTES` of skew (15 minutes by default) raises an
  incident for human review and **is never rejected** — the employee clocks in
  all the same.

  ```bash
  timedatectl status
  ```

- **The `.env` is `0600`.** The installer leaves it that way, and only the user
  who installed can read it — `root`, if you ran it with `sudo`, which is the
  recommended way. Check it after any restore or after copying directories:

  ```bash
  cd /opt/kronoqr
  sudo ls -l .env
  sudo chmod 0600 .env
  ```

  The permissions have to be `-rw-------`. `./doctor.sh` checks it too, but
  **only with the application stopped** (with it running it delegates to
  `product:doctor`, which runs inside the container and cannot see the
  server's `.env`): the command above is the real check. It also
  warns if someone has opened them up.
- **Nothing from the `.env` travels in a ticket, an email or a screenshot.** The
  vendor needs no value from that file to help you: that is what the diagnostic
  bundle is for ([`operation.md`](operation.md) §12.2), and it is built not to
  carry them.

> **Closes:** access to the host, escalation through the `docker` group, reading
> of the installation's secrets, and false incidents from a drifted clock.
> · **Owner:** you.

---

## 4. The secrets: where each one lives

The installer generates them **on your server**, writes them to the `.env` at
`0600` and **prints none of them**. The vendor does not know them and cannot
recover them.

| Secret | Where it lives | Does it leave the server? |
| --- | --- | --- |
| Application key, QR signing keys, database passwords, real-time server credentials, PIN sealing key | `.env`, `0600` owned by `root` | **No** |
| Grafana admin password | `.env`, generated by the installer | **No** |
| `BACKUP_ENCRYPTION_KEY` | `.env` **and a copy kept in custody off the server** | **Yes, and it is mandatory** |
| Database maintenance role password | **Does not exist** until the annual purge: it is created for that operation and withdrawn when it is done | No |
| A tablet's token | On the tablet itself (§6) | No |
| A support access token | Shown **only once**, when granted | Handed to support through the contract's channel |

**`BACKUP_ENCRYPTION_KEY` is the only one that has to be taken off the
server**, and it has to be done on installation day: if the machine is lost and
the key was only there, the encrypted backups are worthless bytes and the
working-time record — which has to be kept for four years — is gone. The
procedure, with where to keep it and what not to do with it, is in
[`operation.md`](operation.md) §9.

**Never write a secret into a new file on the server.** When you need to
generate one:

```bash
openssl rand -base64 32
```

**Rotation.** Each secret has its own procedure and not all of them rotate the
same way: the backup key, for instance, is not rotated without keeping the
previous one, because **a backup can only be decrypted with the key it was made
with**. It is all in
[`../../runbooks/rotacion-secretos.md`](../../runbooks/rotacion-secretos.md)
(in Spanish). Rotate when who has access changes, not by the calendar.

> **Closes:** leakage of the installation's credentials, and irrecoverable loss
> of the backups. · **Owner:** the vendor (generating them properly and not
> printing them), you (keeping them in custody).

---

## 5. The backups

Three things, and none of them is optional:

1. **Off the server.** A backup on the same disk as the database is not a
   backup. If `BACKUP_PATH` points to a network share, it has to be mounted
   **before** bringing the services up.
2. **Encrypted, with the key kept in custody separately** (§4).
3. **Tested.** A backup nobody has ever restored is a hypothesis. A
   **quarterly** drill, following
   [`../../runbooks/restaurar-backup.md`](../../runbooks/restaurar-backup.md)
   (in Spanish).

The schedule, what each job backs up, how long it is kept and how it is
verified are in [`operation.md`](operation.md). If you switch observability
off, **checking that last night's backup was made becomes a manual task of
yours** (§9).

> **Closes:** loss of the working-time record, which is a four-year legal
> obligation. · **Owner:** you.

---

## 6. The tablets

**The real risk of a kiosk is physical.** Whoever takes the tablet takes its
device token: the PWA keeps it in the browser's storage, in the clear, and it
has to be that way. A tablet hanging on a wall reboots on its own after a power
cut and must go back to clocking people in **without anyone stepping in**;
keeping the token only in memory would force re-pairing it, with a person
present and a new code, every time — and the kiosk cannot leave anyone unable
to clock in at 06:00.

It is a risk that is **known and accepted by design**, with these controls
already in the product:

- The token carries **only three permissions**: recording clock-ins, reading
  the roster and sending the heartbeat. Whoever extracts it **cannot reach**
  the workforce or the management side.
- **Unpairing invalidates it on the very next request**, not in 90 days. The
  tablet then purges the roster it had stored and returns to the pairing
  screen **without touching the queue of pending clock-ins**, because there
  are people's workdays in there.
- The pairing secret **is not kept** once the token has been collected, so no
  forgotten second credential is left behind.

And these are yours:

- **Kiosk mode or mobile device management (MDM)**, with the PWA as the only
  application and the exit protected by PIN. It is what prevents opening a
  browser or a console on the device. **It is not a product feature and never
  will be**: no web application can stop someone swiping out to the home
  screen. Full procedure in
  [`../../runbooks/alta-nuevo-quiosco.md`](../../runbooks/alta-nuevo-quiosco.md) §2.
- **Physical custody**: anchored mount, in sight of staff, with no public
  access.
- **The response to a theft or a loss is to unpair that tablet**, immediately,
  from "Kiosks" in the panel ("Unlink kiosk"): it is the action that
  invalidates the token. Afterwards, if warranted, treat the event as a
  security incident
  ([`../../runbooks/brecha-de-seguridad.md`](../../runbooks/brecha-de-seguridad.md)
  (in Spanish)).

> **Closes:** use of a stolen tablet's token to send forged clock-ins.
> · **Owner:** the vendor (least-privilege permissions and immediate
> revocation), you (kiosk mode and physical custody).

---

## 7. Email

**Workforce names leave through this channel, daily.** The nightly incidents
digest is sent to each department's manager with **the date, the name , the type and the severity** of each finding, and nothing more: no working-time record, no contract
data, no totals. It is the only path by which personal data leaves the server
without anyone pressing anything, and that is why it **leaves an entry in the
audit log**: you will always know whose data was sent and when.

Two things fall to you:

1. **`MAIL_SCHEME=smtps` in production.**

   ```dotenv
   MAIL_SCHEME=smtps
   ```

   Empty or `smtp` means **opportunistic** STARTTLS: if the relay does not
   advertise it, the session goes on **in the clear** and that email — with
   names inside — travels readable. With `smtps` encryption is mandatory from
   the first byte and, if the relay does not support it, **the delivery fails
   instead of degrading**. A failed delivery breaks nothing: the incident stays
   open in the tray and goes into the next night's digest.

2. **If the SMTP relay belongs to a third party** (hosted corporate email, your
   domain's provider), that third party is a **data processor** and you have to
   have it contracted as such. It is in
   [`legal-obligations.md`](legal-obligations.md) §2.

After configuring it, check that the system reaches the relay and that it is
not sending the emails to a file:

```bash
cd /opt/kronoqr-2.1.0
docker compose exec app php artisan product:doctor
```

The two checks that look at this are `mail.transport` — which warns if in
production email goes to the log instead of out — and `mail.reachable`, which
opens the connection to the configured mail server and closes it. **It sends no
test email** and **does not check encryption**: you verify that by seeing that
the next night's digest arrives.

> **Closes:** workforce names travelling in the clear over the internet, and
> processing by a third party without a contract. · **Owner:** the vendor (the
> channel and the minimum that travels), you (the encryption and the contract).

---

## 8. Accounts and access

- **Second factor mandatory.** By default it is required of the three roles
  that reach data on the whole workforce: administrator, HR and auditor. The
  department manager is not required to have it because their scope is limited
  to their own department. If your security policy is stricter, add it without
  touching anything else:

  ```dotenv
  IDENTITY_2FA_REQUIRED_ROLES=admin,rrhh,auditor,responsable_departamento
  ```

  Whoever already has a second factor always uses it, even if their role does
  not require it.
- **One role per function, and the smallest that does the job.** There are
  four:

  | Role | For whom | Scope |
  | --- | --- | --- |
  | `responsable_departamento` | Heads of front of house, housekeeping, kitchen | Their department: viewing, corrections and incidents |
  | `rrhh` | Whoever operates the product day to day | The whole workforce: workdays, corrections, employees, cards and reports |
  | `auditor` | Internal audit, employment advisers | Read-only: workdays, audit log and legal export |
  | `admin` | IT | All of the above, plus settings, licence, support and diagnostics |

  **Do not give `admin` to HR.** The `rrhh` role does all the daily work;
  `admin` adds exactly what HR should not be able to touch: the legal
  thresholds, the licence and support access.
- **Never a shared account.** A clock-in correction is stored with its author,
  and a "reception" account turns the audit log into a document that cannot
  answer the only question it will ever be asked: who.
- **Review the accounts when people leave.** When someone leaves the hotel or
  changes position, their management account is withdrawn that same day. Put
  it on the staff leaver checklist, alongside the keys and the email.
- **Support access: with a reason, a scope and an expiry.** The vendor **has no
  access to your installation**. When the diagnostic bundle is not enough, you
  grant temporary access: you grant it, it expires on its own (72 hours at
  most) and you revoke it whenever you want. It is recorded who granted it,
  why, with what scope, **when it was used** and when it was revoked. Procedure
  and scope table in [`operation.md`](operation.md) §12.4.

  Review them from time to time, and revoke whatever is left over:

  ```bash
  cd /opt/kronoqr
  docker compose exec app php artisan support:revoke --all
  ```

- **The employee portal opens with employee code and PIN**, not with email. The
  PIN is handed over in person and is not recovered by email: it is reset by
  HR. There is no credential on the phone and no biometrics, by product
  decision: the credential is the physical card.

> **Closes:** accounts with more permissions than needed, accounts of people
> who are no longer there, and vendor access with no expiry and no trace.
> · **Owner:** you (the accounts), the vendor (the scopes and the audit log).

---

## 9. Observability

It comes **switched on** (`COMPOSE_PROFILES=observability`) and it is worth
leaving it that way: it is what warns you of the two things that turn a healthy
installation into data loss without anyone noticing on screen — that last
night's backup failed, and that write-ahead log archiving has stopped and the
disk is filling up.

- **Grafana listens only on `127.0.0.1:3000`.** It is reached through an SSH
  tunnel:

  ```bash
  ssh -L 3000:127.0.0.1:3000 tu-usuario@fichaje.tuhotel.local
  ```

  And then `http://127.0.0.1:3000` in your browser.
- **Its password is generated by the installer** and lives in the `.env`. If
  you need to read it:

  ```bash
  cd /opt/kronoqr
  sudo sed -n 's/^GRAFANA_ADMIN_PASSWORD=//p' .env
  ```

  If you change it from Grafana's own interface, make a note of it: the `.env`
  will no longer reflect the one that counts.
- **`METRICS_ALLOW_CIDR` authorises a single address**, the metrics
  collector's, and the default value is already the right one. Any other origin
  receives `403`, **including the server itself**. That it is one specific
  address and not a whole network is not fussiness: with the whole network
  authorised, requests made from the host fall inside the range and `/metrics`
  becomes reachable without anything warning you.
- **If you switch it off** — it is a supported configuration, and it frees up
  about 700 MiB on a server tight on memory — you lose the backup and disk
  alerts: checking the backup becomes a **weekly** manual task of yours. What
  exactly is lost is in [`operation.md`](operation.md) §10.

> **Closes:** exposure of the system's internal state, and a loss of backups
> that nobody detects. · **Owner:** the vendor (the isolation), you (the
> password and the decision to switch it off).

---

## 10. What the system reveals to whoever reaches the edge

Three things are served **unauthenticated**, and all three on purpose. Better
to know them before an audit report shows them to you:

| What it reveals | Where | Why it is acceptable |
| --- | --- | --- |
| **The installed version and the licence status**, in one word (`valid`, `expired`, `absent`…) | `GET /api/v1/health` | It is the only probe that requires neither a session nor the database, and it is what lets `doctor` and the diagnostic bundle report the status. It does **not** give away the customer's name, the plan, the limits or the dates: those require an administrator account. An expired licence answers `200`, because otherwise an orchestrator would take out of service a system that clocks people in perfectly well |
| **The installation's brand**: display name, one colour and the logo | `GET /api/v1/branding` and `/api/v1/branding/logo` | The kiosk and the portal need it **before** identifying anyone. What it reveals is the same thing printed on every card and on the reception sign. The response is closed to five presentation keys and has its own per-IP limit |
| **That the installation is being updated right now**, during the maintenance window | `503` with `Retry-After` on the panel and the portal | It is what lets the tablet tell "not decided" from "rejected" and **keep the clock-in in its queue**. The body does not say from which version to which, how long is left, or who the customer is |

**During that window clocking in is not interrupted:** the tablets confirm
locally and queue, and every clock-in keeps its real time.

None of this is personal data or a credential. Even so, **whoever should not
reach the edge should not reach it**: with §1 done properly, this information
is only seen by whoever is already inside your network. That is why this
section comes after the network one and not before.

> **Closes:** nothing by itself. It documents what is **your network's
> decision** and not the product's. · **Owner:** the vendor (that nothing more
> gets out), you (the exposure).

---

## 11. Checklist

Go over it on delivery day and every three months. What is not checked is not
there.

| # | Control | How it is checked | How often |
| --- | --- | --- | --- |
| 1 | Only the edge and Grafana publish a port | `docker compose ps --format 'table {{.Service}}\t{{.Ports}}'` | Delivery and after every update |
| 2 | What is closed is closed, seen from outside | `nmap -Pn -p 22,80,443,3000,5432,6379 fichaje.tuhotel.local` from another machine | Delivery and quarterly |
| 3 | The panel is not reachable from the internet | Try to open `/admin/` from outside the network | Delivery and quarterly |
| 4 | The tablets fall inside `KIOSK_VLAN_CIDR` | `docker compose logs --tail 200 nginx \| grep '"from_kiosk_vlan":0'` | Delivery and whenever a kiosk is added |
| 5 | The portal answers only from its range | Open `/portal/` from outside: it must give `403` | Delivery and quarterly |
| 6 | Certificate valid and with margin | `./doctor.sh` (warns 30 days ahead) | Monthly |
| 7 | `TLS_ALLOW_SELF_SIGNED` at `false` | `sudo grep '^TLS_ALLOW_SELF_SIGNED=' .env` | Delivery |
| 8 | `.env` at `0600` | `sudo ls -l .env` (`./doctor.sh` only checks it with the application stopped) | Delivery and after every restore |
| 9 | SSH with a key, no password and no `root` | `sudo sshd -T \| grep -E 'passwordauthentication\|permitrootlogin'` | Quarterly |
| 10 | The `docker` group has only who it should | `getent group docker` | Quarterly and on every leaver |
| 11 | Time synchronised | `timedatectl status` | Quarterly |
| 12 | Backup key kept in custody off the server | Check that it exists in the password manager or the safe, with its date | Delivery and annually |
| 13 | Last night's backup exists and was verified | `docker compose exec app php artisan backup:verify` ([`operation.md`](operation.md) §2), or the observability alert | Daily (automatic) or weekly (manual, if you switched it off) |
| 14 | Restore genuinely tested | Drill from [`../../runbooks/restaurar-backup.md`](../../runbooks/restaurar-backup.md) | **Quarterly** |
| 15 | Email encryption mandatory | `sudo grep '^MAIL_SCHEME=' .env` | Delivery |
| 16 | Second factor mandatory on the management accounts | `grep '^IDENTITY_2FA_REQUIRED_ROLES=' .env` still says `admin,rrhh,auditor`: an account with one of those roles and no second factor cannot log in | Quarterly |
| 17 | No account belonging to someone who has left | There is no management-accounts screen: run the read-only query `docker compose exec -T postgres psql -U fichaje_app -d fichaje -c "select email, is_active, last_login_at from users order by last_login_at nulls first"` (with the application role, which cannot alter the record; never with `fichaje_migrator`) and compare it with the staff list. **This version has no way to deactivate a management account**, neither from the panel nor from the console: if one is left over, open a case with the vendor; it arrives in a later 2.x release | Quarterly and on every leaver |
| 18 | No live support access without a reason | Panel → "Support" → "Support access grants" | Monthly |
| 19 | Every tablet in kiosk mode and anchored | Physical walk-round: reboot one and check it starts on its own into the PWA | Quarterly |
| 20 | Paired kiosks = stations that exist | `docker compose exec app php artisan kiosk:health` | Quarterly |
| 21 | General diagnostics green | `./doctor.sh` | Monthly and before every update |
| 22 | Operating system and Docker up to date | Your distribution's package manager | Monthly |

---

## And if something here clashes with your internal policy

Yours takes precedence, with two exceptions that are not negotiable because
they are not configuration options:

1. **Licence expiry never blocks clocking in or access to the record.** No
   hardening measure should try to "tighten" that: it would leave the hotel in
   breach of the law and without access to data it is obliged to keep for four
   years.
2. **The working-time record is not touched through SQL.** Not to correct, not
   to clean up. Everything that needs doing has its path in the panel or in a
   command, and all of them leave a trace. What must never be done is in
   [`operation.md`](operation.md).

If in doubt about a specific control, the safe answer is the more restrictive
one: everything in this guide is designed so that clocking in keeps working
even if you apply the strict version.
