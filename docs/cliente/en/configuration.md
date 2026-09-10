# Configuring KronoQR — what can be changed and what the consequences are

> **Status.** Written in **task 5.1** with the **installation configuration
> keys** (`GET`/`PATCH /api/v1/settings`) and extended in **task 5.2** with the
> **compliance profile** (`GET`/`PATCH /api/v1/compliance-profile`, section 2.4)
> and in **5.3** with the **licence** (section 3 bis). **5.8** adds the brand
> screen to the management panel and **5.11** integrates this guide with the
> rest; neither of the two rewrites what is here.

---

## 1. The three places where configuration lives, and why there are three

Before changing anything, it helps to know where to look. **If you pick the
wrong place, the change is not applied** and nothing warns you about it.

| What | Where | How it is changed | Restart needed? |
| --- | --- | --- | --- |
| **Branding, languages and operational thresholds** | Table `installation_settings` | `PATCH /api/v1/settings` (management panel, *administrator* role) | No |
| **Legal thresholds**: minimum rest, maximum working day, breaks, retention years | Table `compliance_profiles` | `PATCH /api/v1/compliance-profile` (management panel → “Compliance”, *administrator* role) | No |
| **Everything about the deployment**: paths, credentials, ports, keys | The server's `.env` file | Edit it and restart the containers | **Yes** |

The rule for not getting it wrong: **if you would change it without telling
anyone in IT, it belongs to the panel; if touching it means restarting the
service, it belongs to the `.env`.**

### The environment variable does not beat the database

Some properties have two faces: there are branding variables in the `.env` and
there are branding keys in the installation configuration. **The database always
wins.** The environment variable is only the value the installer uses to seed
the first row the first time; from then on, what you save in the panel is what
applies.

There is no longer any exception: **the variables `BRANDING_NAME` and
`BRANDING_ACCENT_COLOR` were removed from the `.env`**, because having two places
for the same piece of data only meant that someone changed the colour in the
panel, saw no effect and had no way of knowing why. The only branding item that
remains in the `.env` is **where the logo file may live** (`BRANDING_LOGO_ROOT`
and `BRANDING_PATH`), which belongs to the server, not to the hotel.

---

## 2. What can be configured, one by one

Every key has **a default value**, and that default value **is the product**: a
freshly started installation works without touching any of them. You change
only the ones you need.

### 2.1 Clocking

| Key | Default | Range | What happens if you change it |
| --- | --- | --- | --- |
| `ATTENDANCE_MAX_SHIFT_HOURS` | `12` | 1 – 24 | From that duration on, a closed shift entry is marked as **anomalous** and an incident is opened for review. **It never closes a shift on its own.** |
| `ATTENDANCE_DEBOUNCE_SECONDS` | `60` | 0 – 3600 | Grace window: two scans by the same person within that window count as one. **This key changes the recorded hours** — see the warning below. `0` disables it. |
| `ATTENDANCE_MAX_CLOCK_SKEW_MINUTES` | `15` | 1 – 1440 | Drift tolerated between the tablet's clock and the server's before flagging the clock-in for review. **It never rejects a clock-in**, it only flags it. |
| `ATTENDANCE_MIN_TRANSIT_SECONDS` | `120` | 0 – 3600 | Minimum credible time to get from one kiosk to another. Below it, an incident is opened. Set it to `0` if you have two tablets at the same door; raise it if there are two buildings. |

> **⚠️ `ATTENDANCE_DEBOUNCE_SECONDS` affects the hours calculation.** Raising it
> makes real clock-ins that are very close together get discarded, and the total
> for the working day comes out different. It is the only key in this list that
> moves minutes of the legal record. Change it with care and leave it in
> writing: the change is audited with your name, the date and the previous
> value.

### 2.2 Branding

| Key | Default | What it is |
| --- | --- | --- |
| `BRANDING_APP_NAME` | `KronoQR` | Application name. Up to 60 characters: that is what fits in the header of the printed card. |
| `BRANDING_LOGO_PATH` | *(empty)* | **Absolute path on the server** to a PNG or an SVG. Empty means “the product's logo”, not “no logo”. |
| `BRANDING_ACCENT_COLOR` | `#b8542a` | Accent colour, in `#rrggbb` notation. Any other form is rejected. |

**Where it shows.** In the header and in the browser tab title of the panel, the
portal and the kiosk; on the sign-in screen of all three; on the printed
credential card; in the header of the PDF hours report; and in the first line
of the export for the Labour Inspectorate. A change applies **on the next
request**, without restarting anything. Cards already printed, naturally, do not
change.

**What never changes**: the technical identifiers. The `FH1` prefix of the QR
codes, the table names, the API paths and the commands stay the same — renaming
them would leave anyone carrying an already printed card in their pocket unable
to clock in.

#### The logo: where to put it and what is accepted

The logo is a **file on your server**, not a web upload. It lives in the brand
directory, which the `docker-compose` mounts **read-only** inside the container:

| | |
| --- | --- |
| Folder on your server | The one in `BRANDING_PATH` in the `.env`. If empty, `./branding` next to the `docker-compose.yml` |
| Path you write in the panel | `/var/kronoqr/branding/<file>` |
| Formats | **PNG or SVG**, checked by their content and not by the extension |
| Maximum size | **512 KiB** |
| Maximum dimensions (PNG only) | **2048 pixels** per side |

**A single file for two backgrounds.** The same logo is shown on a light
background (panel, portal, PDF) and on the **dark background of the tablet**. A
dark-stroke logo on transparent reads fine in the panel and disappears on the
kiosk; pick a version that works on both —or one with its own background— and
check it on the tablet screen before signing it off. There is no second logo for
the kiosk today.

In the commands of this guide, `TU-SERVIDOR` stands for your server's host name
and `TU-TOKEN` or `$TOKEN` for a management token; they are kept as in the
Spanish guide so that the commands are identical in both languages.

```bash
# 1. Copy the logo to the brand folder on the server.
sudo mkdir -p /opt/kronoqr/branding
sudo cp logo.png /opt/kronoqr/branding/logo.png
sudo chmod 0644 /opt/kronoqr/branding/logo.png

# 2. If it was not already, point BRANDING_PATH there in the .env and recreate
#    the application container (first time only: changing the FILE afterwards
#    does not require restarting anything).
#    BRANDING_PATH=/opt/kronoqr/branding
sudo docker compose up -d app

# 3. Save the path AS SEEN FROM INSIDE the container, from the panel or via the API.
curl -sS -X PATCH https://TU-SERVIDOR/api/v1/settings \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"settings":{"BRANDING_LOGO_PATH":"/var/kronoqr/branding/logo.png"}}'
```

**It is checked on save, which is why you find out straight away.** If the file
does not exist, is outside the brand directory, is not a real PNG or SVG or
exceeds the limits, the request answers `422` and **nothing is saved**: the
message says what is wrong and what to do. The full list is in section 4.

Checking the folder is not bureaucratic fuss: the logo is served from a
**public** address —the tablets ask for it before anyone signs in—, and without
that confinement anyone allowed to save the configuration could publish any file
on the server.

**Afterwards, it is tolerant.** If the file is deleted or the volume is no
longer mounted, documents come out without a logo and the applications show the
name as text. Nobody is left unable to clock in because of a missing image.

**To go back to the product's logo**, save the key with the empty string:

```bash
curl -sS -X PATCH https://TU-SERVIDOR/api/v1/settings \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"settings":{"BRANDING_LOGO_PATH":""}}'
```

#### Custom appearance is a plan feature; the name is not

If your licence does not include white-label branding —or it has expired— you
lose **the accent colour and the logo**: the applications and the documents come
out with KronoQR's colours and without an image.

**Your installation's name is always used.** It does not depend on the licence
and cannot depend on it: that name heads the export for the Labour Inspectorate
and the sealed report, that is, it is the line that says **whose** working-time
record someone is looking at. If it changed according to the state of a licence
—or according to a passing failure verifying it— two exports of the same month
could come out with different headers without a single piece of data having
changed. That does not happen.

What you have configured **is neither deleted nor lost**: it is still shown and
can still be edited on this screen, and the appearance **is applied again by
itself** as soon as the licence covers it, without you having to reconfigure
anything.

And what **never** degrades is the record: clocking in, lookups, corrections,
the export for the Labour Inspectorate and backups work exactly the same. See
section 3 bis.3.

### 2.3 Languages

| Key | Default | What it is |
| --- | --- | --- |
| `LOCALE_AVAILABLE` | `["es","en"]` | Languages the installation offers. Only those the product ships translated are accepted. |
| `LOCALE_DEFAULT` | `es` | Language the applications and documents are served in when the browser does not ask for another. |

**These two keys are now the ones that rule.** `APP_LOCALE` and
`APP_SUPPORTED_LOCALES` in the `.env` remain only as a fallback: they apply if
the database does not answer, so that an installation with PostgreSQL down can
still say what is wrong instead of erroring everywhere.

A document (the Inspectorate CSV, the PDF report) always comes out in the
**installation's** language, even if the browser downloading it asks for
another: the language that matters there is that of the program that will open
the file. What does follow the browser are the on-screen texts.

**Languages do not depend on the licence**: an installation working in English
does not lose its language because a plan expires.

**The default language has to be among the available ones.** If you try to leave
it out —for example, removing `es` from the list without changing the default
language— the whole request is rejected and nothing is saved.

---

### 2.4 Legal thresholds: the compliance profile

This is **not** on the configuration screen: it has its own, “Compliance”, and it
is also administrator-only. They are kept apart because they are a different
thing. An **operational** threshold is decided by you according to how your
hotel works; a **legal** threshold is set by the law or the collective
agreement, and getting it wrong has different consequences.

The **`ES-hosteleria`** profile is shipped, with these values:

| Field | Default | What it does | Where it comes from |
| --- | --- | --- | --- |
| `min_rest_hours` | `12` | An incident is opened if **fewer** than that many hours elapse between the end of one shift and the start of the next | Art. 34.3 of the Workers' Statute |
| `max_daily_hours` | `9` | An incident is opened if the sum of the shift entries of a working day **exceeds** that many hours | Art. 34.3 of the Workers' Statute |
| `break_required_after_hours` | `6` | Threshold for a continuous shift entry without a recorded break. **Today the rule is evaluated but does not open incidents** (see below) | Art. 34.4 of the Workers' Statute |
| `updated_at` | empty | Read-only: when it was last adjusted. **Empty means “as installed”** | — |
| `max_weekly_hours` | `40` | Ordinary weekly working hours. **No rule applies it yet** | Art. 34.1 of the Workers' Statute |
| `week_starts_on` | `1` (Monday) | Day the week starts on. **No rule applies it yet** | ISO 8601 |
| `holiday_calendar` | empty | The site's public holidays, one date per line. **No rule applies it yet** | You load it |
| `retention_years` | `4` | Years the record has to be kept before it can be purged | Art. 34.9 of the Workers' Statute |
| `name` | `ES-hosteleria` | What the collective agreement the profile describes is called | You set it |

**The holiday calendar is shipped empty on purpose.** Public holidays depend on
the municipality and the year: a calendar built into the product would expire
every 31 December and be wrong for half the customers. You load it, once a year,
by pasting the dates.

**Three fields are stored and not applied yet** —weekly working hours, first day
of the week and holidays—. The screen says so next to the fields. You can leave
them already adjusted to your collective agreement: the compliance view of a
later version will be the first to use them, and the changes are audited from
today.

**`break_required_after_hours` is stated but does not open incidents yet.** The
system cannot tell “did not rest” from “rested and did not clock it” until the
kiosk records the break as such; opening incidents in the meantime would fill
the inbox with false positives and bury the ones that do matter. The threshold
is stored and will apply when detection is reactivated.

Practical consequence, worth knowing before touching it: **changing that
threshold today does not alter a single incident**. The screen says so next to
the field and the audit log writes it down (`detection_suspended`), so that in
two years' time “this moved no alerts” can be told apart from “it moved them,
but the rule was suspended at the time”.

#### Changing a threshold applies from the change onwards, not backwards

It is the most important decision on this screen and you should know it before
touching anything:

- The new value applies **on the next daily review**, which looks at the last
  seven days. Watch out for this: **tightening a threshold can open incidents
  for working days already past** that fall within that window. It is not a
  bug, it is the window doing its job.
- **History is not recalculated.** A working day from three months ago is not
  re-evaluated.
- **No already open incident is closed** and no resolved one is reopened.
  Closing them automatically would erase the trace of a decision a person made.
- **The change is audited** with the previous value, the new one, who made it
  and when. It is what makes it possible, two years from now, to explain why a
  working day in March raised no alert and one in April did.

Practical consequence: **if you lower a threshold, the incidents that were
already open stay there and have to be closed by hand**, stating the reason. It
is not a fault: it is the only way for the record to keep what happened.

#### `retention_years` is the only dangerous field

Lowering it widens what the purge considers expired, over data you **are obliged
to keep for four years**. Nothing is deleted by changing it: the purge is run by
hand, proposes first in dry-run mode and demands a confirmation derived from that
report. Even so, it is the only field of the profile whose mistake is paid for
with data that does not come back. If your advisers tell you your period is
different, change it; if not, leave it alone.

---

## 3. How it is changed

From the management panel, with an **installation administrator** account. HR,
managers and auditors do not reach this screen, and that is deliberate:
correcting a clock-in leaves a trace on one working day; moving the debounce
changes the calculation of every working day that follows.

From the console, when there is no panel at hand —during setup, for example—:

```bash
curl -sS -X PATCH https://TU-SERVIDOR/api/v1/settings \
  -H 'Authorization: Bearer TU-TOKEN' \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json' \
  -d '{"settings":{"ATTENDANCE_MIN_TRANSIT_SECONDS":300,"BRANDING_APP_NAME":"Hotel Marina"}}'
```

And to see what is there right now, with the origin of each value:

```bash
curl -sS https://TU-SERVIDOR/api/v1/settings \
  -H 'Authorization: Bearer TU-TOKEN' \
  -H 'Accept: application/json'
```

The response carries **all** the keys, not only those you have changed. Each one
has a `source` field:

- `installation` — you configured it.
- `product_default` — nobody has touched it and the default value rules.

The compliance profile has its own address, with the same account:

```bash
curl -sS https://TU-SERVIDOR/api/v1/compliance-profile \
  -H 'Authorization: Bearer TU-TOKEN' \
  -H 'Accept: application/json'
```

```bash
curl -sS -X PATCH https://TU-SERVIDOR/api/v1/compliance-profile \
  -H 'Authorization: Bearer TU-TOKEN' \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json' \
  -d '{"max_daily_hours":8,"break_required_after_hours":5,"name":"Convenio de hostelería de Cantabria"}'
```

Send only the fields you want to change: those that do not travel stay as they
are. Numbers go **without quotes** (`8`, not `"8"`).

---

## 3 bis. The licence

> **First things first, because it is what gets asked most: the licence CANNOT
> prevent clocking in.** With the licence expired, absent or unreadable, your
> installation keeps recording clock-ins, keeps letting you look up anyone's
> record, keeps exporting for the Labour Inspectorate, keeps serving the
> employee portal, keeps allowing working-day corrections and keeps making
> backups. **That is not an accident of this version: it is a promise of the
> product**, and it is written in its design documentation.
>
> The only thing a licence governs are the **accessory features**, and they are
> listed further down.

### 3 bis.1 What the licence key is

A text string your provider gives you. It looks like this:

```text
KQL1.eyJsaWNlbnNlX2lkIjoiOWYyYzRhMWI3ZTBk....Zm9vYmFyYmF6cXV1eA
```

Inside it carries, **signed**, your company's name, your plan, the contracted
limits, the included features and the validity dates. The signature is what
prevents it from being modified: if someone changes a single character, the key
stops being valid.

**It is verified on your own server and with no internet connection.** The
system does not call any vendor server, neither when activating it nor
afterwards. It is deliberate: your installation has to be able to work on an
isolated network, and an online check would turn another company's connectivity
into a point of failure for your working-time record.

### 3 bis.2 How it is activated

**From the management panel** (the usual way): sign in as *administrator*, go to
**Licence**, paste the key into the box and press “Activate a key”. You can
paste it with spaces or line breaks: they are cleaned up automatically.

**From the server console**, if you prefer:

```bash
docker compose exec app php artisan license:activate "KQL1...."
```

And to see how it stands at any time:

```bash
docker compose exec app php artisan license:show
```

That command prints, in this order: the state, your plan against what you are
actually using, what is degraded, **what keeps working whatever happens** and
what to do. It is the one support will ask you for if you call.

> **The full key never appears** in the command output or in the panel: its
> *fingerprint* is shown, twelve characters, which is what serves to confirm
> over the phone that the activated key is the one you were sent.

### 3 bis.3 What happens when it expires

**Thirty days before**, a permanent notice appears in the panel, for the
administration roles, saying when it expires and what will be degraded.
**During those thirty days nothing is lost**: the licence is still valid.

**On the day it expires**, the notice changes its tone and its text, and these
features stop being available:

| Stops working | Keeps working instead |
| --- | --- |
| **Reports by period** and their comparison with the contracted hours | Looking up each person's record, the export for the Labour Inspectorate and the employee portal |
| **Real-time presence**: it switches to **polling**, it does not switch off. The screen still shows who is in, a few seconds behind, and says so | — |

And these are **never** affected, whatever the state of the licence:

- Clocking by QR and by fallback PIN.
- Synchronisation of the kiosk queue when it gets the network back.
- Looking up anyone's working days and shift entries.
- Employee portal.
- Standardised export for the Labour Inspectorate.
- Working-day corrections with their reason.
- Audit log.
- Backups and their restoration.
- Health probes.

The notice **cannot be dismissed** while the situation remains true. It is on
purpose: a notice dismissed on the first day stops warning precisely on the day
that matters.

### 3 bis.4 The plan limits

Your key carries two figures: **how many people** and **how many kiosks** you
have contracted. `license:show` and the licence screen show both against what
you are actually using.

> **Exceeding them blocks nothing, and it never will.** You can add person
> number 81 on a plan of 80, and they can clock in from day one. You can pair a
> replacement kiosk even though the broken one still counts.
>
> The reason is simple: if the product prevented you from adding a waiter in
> high season, that person would work **without a working-time record**, and
> the breach of art. 34.9 of the Workers' Statute would be yours because of a
> commercial decision you do not control. And if it prevented you from pairing
> a kiosk, you would be left without a clocking point precisely on the day one
> breaks.

What does happen when you exceed a figure:

1. A permanent notice appears in the panel with what was contracted, the actual
   figure and since when.
2. An entry is left in the **audit log** with the exact date. It is the record
   your provider will use to propose extending the plan, and also the one that
   lets you check yourself since when you have been over.
3. The figures appear in `license:show`.

**People who have left do not count**, even though their record is kept for the
mandatory four years. A revoked kiosk frees its slot immediately.

### 3 bis.5 Querying it via the API

```bash
curl -sS https://TU-SERVIDOR/api/v1/license \
  -H "Authorization: Bearer $TOKEN" | jq
```

```bash
curl -sS -X POST https://TU-SERVIDOR/api/v1/license/activate \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"signed_key":"KQL1...."}' | jq
```

Both require the *administrator* role. **Neither of the two is closed by an
expired licence**: they are precisely the screen from which the problem is
fixed.


## 3 ter. Importing staff from a file

It is the step of the setup wizard that saves you typing the whole staff list by
hand, and also the way to bring in a large group later on. It works with **CSV
and with XLSX**.

### 3 ter.1 How it is done: two steps, and the second does not happen unless you confirm it

1. **You upload the file in check mode.** Nothing is written. You receive a
   **line-by-line** report: what would be added, what would be updated, what is
   rejected and **why**.
2. **You review the report and confirm.** Only then is it written, and
   **exactly what you reviewed** is written: the system checks that the file is
   the same before touching anything.

> **Why the file has to be uploaded again to confirm.** Because the system
> **does not keep it** between the two steps. That file carries names and
> identity documents of your entire staff, and leaving it on the server's disk
> waiting for someone to confirm would be a pile of personal data with no owner.
> It is a few kilobytes: it is uploaded again and that is that.

From the console, if you prefer:

```bash
# 1. Check (writes nothing). Keep the sha256 it returns.
curl -sS -X POST https://TU-SERVIDOR/api/v1/employees/import \
  -H "Authorization: Bearer TU-TOKEN" \
  -F "mode=validate" \
  -F "file=@plantilla.csv"

# 2. Apply, confirming with that fingerprint.
curl -sS -X POST https://TU-SERVIDOR/api/v1/employees/import \
  -H "Authorization: Bearer TU-TOKEN" \
  -F "mode=apply" \
  -F "confirm_checksum=EL-SHA256-DEL-PASO-1" \
  -F "file=@plantilla.csv"
```

(`plantilla.csv` is the staff file and `EL-SHA256-DEL-PASO-1` stands for the
sha256 returned by step 1.)

### 3 ter.2 What columns the file has to carry

The first row is the **column names**. The order does not matter and extra
columns do no harm: the unused ones are reported and ignored.

| Field | Mandatory | Names recognised by default |
| --- | --- | --- |
| First name | **Yes** | `nombre`, `first_name`, `firstname`, `given_name` |
| Surname | **Yes** | `apellidos`, `apellido`, `last_name`, `lastname`, `surname`, `family_name` |
| Start date | **Yes** | `fecha_alta`, `fecha_de_alta`, `alta`, `hired_at`, `start_date`, `hire_date` |
| Identity document | **One of the two** | `dni`, `nif`, `nie`, `documento`, `national_id`, `id_number` |
| Email | **One of the two** | `email`, `correo`, `correo_electronico`, `e_mail` |
| Department | No | `departamento`, `department`, `seccion`, `section` |
| Language | No | `idioma`, `locale`, `language` |

**Names are compared ignoring case, accents and separators**: `Fecha de alta`,
`FECHA_ALTA` and `fecha alta` are the same column.

> **Document or email, at least one.** It is not a whim: it is what makes it
> possible to **re-import the same file without duplicating anyone**. If a line
> carries neither, the system would have no way of recognising that person the
> second time and you would end up with two records for them. **Email remains
> optional** —the file may lack that column and works all the same—; what
> cannot be missing is **both at once**.

> **The employee code is NOT read from the file**, even if you include it. The
> system generates it and it is an opaque code on purpose: it is printed on the
> card and in the QR, so it cannot be the payroll number or anything with a
> meaning.

### 3 ter.3 The file format: there is nothing to configure

- **The separator is detected.** A Spanish Excel exports with `;` and an English
  one with `,`. Both work, and so does the tab.
- **The encoding is detected.** “Save as CSV” on a Spanish Windows produces
  `Windows-1252`, not UTF-8. Both work, and **ñ** and accents arrive intact.
- **Dates** are accepted as `2026-03-15` or as `15/03/2026`. **Month/day/year is
  not accepted**: `03/04/2026` is always read as **3 April**, which is what
  whoever wrote it meant. A start date read a month off is a month of working
  days that should not exist.

### 3 ter.4 Re-importing the same file is safe

It is what always happens: a line is corrected and the whole file is uploaded
again. The system recognises each person by their document (and, failing that,
by their email) and:

- **does not duplicate them**;
- **updates** first name, surname, email, department and language if they
  changed;
- **does not touch their start date**, even if the file carries another. It
  says so as a warning on the line. Changing someone's start date moves the
  point from which the legal retention of their record counts and from which
  working days can be attributed to them: that is changed on their record,
  deliberately, not in passing in a forty-line import.
- **does not add or offboard anyone by changing a status**. Offboarding has its
  own procedure, with an end date and revocation of the card.

### 3 ter.5 After importing, the cards remain

**Importing forty people issues not a single card.** The credential is a
physical printed card: it has to be issued, printed and handed over, and that
takes days. **Nobody receives anything by email**, nor do they need to have an
email.

What has to be done afterwards is on the **credential status board**
(**Credentials** in the panel), and from the console:

```bash
docker compose exec app php artisan credentials:status --pending
```

> **Do it days ahead of the first working day.** The credential status board
> exists precisely so that nobody discovers the problem in front of the tablet
> at 06:00.

### 3 ter.6 If your file uses other column names

There is no need to touch the program: aliases are added in the server's `.env`,
in `field=header` format, separated by `;`.

```bash
# .env — the export from your previous system calls the national ID "documento_id"
# and the department "seccion_hotel".
WORKFORCE_IMPORT_COLUMN_ALIASES="national_id=documento_id;department=seccion_hotel"
```

**They are added to the default ones, they do not replace them**: next week's
file may come from the other system and will keep working. The fields that
accept aliases are `first_name`, `last_name`, `email`, `national_id`,
`department`, `hired_at` and `locale`. A badly written entry is ignored and its
column shows up as “not recognised” in the report; it does not break the import.

It requires **restarting the containers**, like everything in the `.env`.

### 3 ter.7 The other two parameters in the `.env`

| Variable | Default | What for |
| --- | --- | --- |
| `WORKFORCE_IMPORT_MAX_ROWS` | `500` | Maximum data lines per file. It is the largest staff an installation supports. If you exceed it, the report says so and **nothing is imported**: split the file in two and import them one after the other. |
| `WORKFORCE_IMPORT_MAX_FILE_KILOBYTES` | `4096` | Maximum file size. An XLSX of 500 people is around 60 KB, so there is plenty of room. |

**Why 500 and not more.** Each addition computes the cryptographic hash of that
person's PIN, which costs on the order of 0.16 s. With 500 people that is about
80 s of computation, which the system does **before** touching the database
precisely so that it does not block the kiosk's clock-ins in the meantime.
Above that figure the request would approach the web server's one-minute limit
and would be cut off halfway. You can raise it if your staff is larger, but
**split the file before doing so**: it is faster and does not depend on any
limit.

---

## 3 quater. Diagnostics and support

What you need to know to open a support ticket —the `product:doctor` health
review, the anonymised diagnostics package and the temporary access grants to
the vendor— is in [`operation.md`](operation.md) §12, because it is operation,
not configuration. Here go only their **parameters**, which live in the `.env`
and are deliberately **not** edited from the panel: if the maximum hours of an
access grant were a panel key, whoever grants the access could raise it before
granting it and the limit would stop being one.

| Variable | Default | What it governs |
| --- | --- | --- |
| `PRODUCT_DIAGNOSTICS_MAX_BYTES` | `8388608` | Maximum size of the diagnostics package (8 MiB). It is a channel limit —email, ticket portal—, not a memory limit |
| `PRODUCT_DIAGNOSTICS_RATE_LIMIT` | `3` | Packages per minute and per account from the panel |
| `PRODUCT_DIAGNOSTICS_PERSONAL_DATA_MAX_PERIOD_DAYS` | `31` | Maximum days of clock-ins that fit in a package **with personal data**. Raising it is a legal decision, not a performance one |
| `PRODUCT_DIAGNOSTICS_RETENTION_DAYS` | `7` | Days a package generated from the console is kept on the server before the next `product:diagnostics` deletes it |
| `PRODUCT_SUPPORT_GRANT_DEFAULT_HOURS` | `24` | Duration of a support access grant if none is stated |
| `PRODUCT_SUPPORT_GRANT_MAX_HOURS` | `72` | Maximum duration accepted |
| `PRODUCT_SUPPORT_USE_AUDIT_WINDOW_SECONDS` | `900` | How often, at most, a new use of a support access grant is written to the audit log |
| `PRODUCT_DATA_EXPORT_PATH` | `storage/app/exports` (in the container) | Where the full data export leaves the ZIP ([`operation.md`](operation.md) §13). Outside `BACKUP_PATH` on purpose: it is material that expires |
| `PRODUCT_DATA_EXPORT_RETENTION_DAYS` | `7` | Days the ZIP of a full export can be downloaded before it is purged. The note that it existed is always kept |
| `PRODUCT_DATA_EXPORT_RATE_LIMIT` | `30` | Requests per minute **per account** to the export list and download (per IP address, four times as many); the panel polls every 5 s while one is in progress |
| `PRODUCT_DATA_EXPORT_STALE_AFTER` | `3600` | Seconds after which an export interrupted halfway is considered failed (`stale`) and lets you request another. Never below what your largest export takes |

What you **do** decide each time, and not in the `.env`: whether the package
carries personal data (never by default), and the reason, the scope and the
hours of each access grant.

---

## 3 quinquies. Telemetry

### What it is, and why you probably do not need it

Telemetry is a JSON document with **technical and aggregated** data about your
installation —which version is running, how the licence stands, what size band
the staff falls in, a few usage counters and the result of the health review—
that your server sends to the vendor once a week.

**It ships switched off, and stays that way if you do nothing.** The product
works **exactly the same** with it off: no feature is degraded, no notice
appears, there is no banner and nobody is going to remind you. If this section
seems unnecessary to you, do nothing: you are not missing anything.

It serves one purpose only: that whoever maintains the product knows which
versions are actually in use and with what installation sizes, so as not to
break them in an update. It is not used to bill you, nor to watch anyone, nor to
know who clocks in.

### Three things are needed at once

If one is missing, nothing is built or sent —not even a counter is read—, and
`product:telemetry` tells you which one is missing:

1. `TELEMETRY_ENABLED=true` in your `.env`. By default it is `false`.
2. `TELEMETRY_ENDPOINT` with an address **starting with `https://`**. By default
   it is **empty**: the product ships no destination written in, not even one
   of the vendor's. With this empty, enabling the previous one does absolutely
   nothing. A destination on `http://` —or without `https://` in front— **is
   rejected as if there were none**: nothing is built or sent, and
   `product:telemetry` tells you the address has to start with `https://`.
3. That your licence includes `telemetry`. It is an accessory feature: if your
   licence expires, it switches itself off and nothing else happens.

### How often, and where to

On **Mondays at 05:40 UTC**, a `POST` with `Content-Type: application/json` over
HTTPS with the certificate verified, without following redirects, with 3 seconds
to connect and 10 in total. If it fails, it is retried **once** after 5 seconds
and left for the following week: there are no retries in a loop, no error
appears on screen and it is logged in the technical log as a warning, not as a
failure. **It never happens during a clock-in**: only the scheduled task
triggers it.

You choose the destination. If you do not want to send anything to the vendor
but do want to bring the data into your own monitoring system, point
`TELEMETRY_ENDPOINT` wherever you like: the product does not care who is on the
other end.

**And that is the division of responsibilities**, said plainly: the product
guarantees the **channel** —verified TLS, no redirects followed, bounded
timeouts— and the **content**, which is exactly what the table below lists.
**Who is on the other end of the destination is your responsibility**, because
you write the address: if you point at a server you do not control, the document
reaches whoever owns that server. It is noted as an accepted risk in the
product's security document (doc 07 §6).

### Look at it before deciding

This command **sends nothing**. It prints the exact document that would be sent,
with your installation's real values:

```bash
docker compose exec app php artisan product:telemetry
```

With `--send` it sends it, if the three conditions are met. With `--json` it
comes out in a format another program can read.

**Looking leaves no trace.** Without `--send`, the command sends nothing and
writes nothing to disk either: the identifier it shows is provisional and says
so. The definitive one is minted on the first send.

### Variables

| Variable | Default | What it governs |
| --- | --- | --- |
| `TELEMETRY_ENABLED` | `false` | Whether telemetry is enabled |
| `TELEMETRY_ENDPOINT` | *(empty)* | Where it is sent. It has to start with `https://`; empty, or without `https://`, means it is not sent |
| `TELEMETRY_STATE_PATH` | `storage/app/telemetry/state.json` | Where the installation identifier and the send history live |
| `TELEMETRY_RETRY_DELAY_SECONDS` | `5` | Seconds between the attempt and its single retry |

### Everything that is sent, field by field

This is the **complete and closed** list. What is not in this table does not
leave your installation, and an automated test compares this table with the
code: if someone added a field without writing it here, the check fails and the
change does not get in.

| Field | Example | What it is |
| --- | --- | --- |
| `schema_version` | `1` | Version of the document format. Goes up if the list of fields ever changes |
| `installation_id` | `9f2c7b41-0f6a-4a1e-9d54-6b0f3a2c81de` | **Random** identifier your own installation generates the first time. It is not derived from your licence, your name or your address. If you delete the state file, a new one is minted |
| `sent_at` | `2026-09-08T05:40:12.004311Z` | Moment of the send, in UTC |
| `product.version` | `2.1.0` | Version of KronoQR running on your server |
| `product.php_version` | `8.4.24` | PHP version |
| `product.database_version` | `17.11` | PostgreSQL version, the number only. No distribution, no compiler and no path on your server |
| `license.state` | `active` | Licence state: `active`, `expiring`, `expired`, `absent`, `not_yet_valid` or `unverifiable` |
| `license.plan` | `estandar` | Name of the contracted plan |
| `license.features` | `["advanced_reports","telemetry"]` | Accessory features included in the plan |
| `license.days_until_expiry` | `114` | Days left until it expires |
| `scale.employees_active` | `101-250` | Size of the active staff **by band**: `0`, `1-25`, `26-100`, `101-250`, `251-500` or `501+`. **Never the exact figure** |
| `scale.devices_active` | `3` | How many tablets are registered |
| `scale.departments` | `6` | How many departments have been created |
| `usage_7d.scans_accepted` | `4812` | Clock-ins accepted since the previous send (normally, one week). Only the number: no who, no when and no which tablet |
| `usage_7d.scans_rejected` | `37` | Scans rejected in that same period. Only the number, and **without the reason for the rejection** |
| `usage_7d.batches_synced` | `1204` | Batches the tablets synchronised on getting the network back, in that period |
| `usage_7d.incidents_open` | `2` | Incidents open **right now**. It is the only figure in this block that is not for the period, because it is a level and not a count |
| `usage_7d.reports_generated` | `9` | Reports exported in the period |
| `doctor` | `{"database.connection":"ok","license.state":"warning"}` | The verdict of each `product:doctor` check: `ok`, `warning` or `failure`. **Only the verdict**: no text, no explanation and no details, which may carry paths on your server and go only in the diagnostics package that you decide to send |

On the first send, and on any that follows a failed week with no possible
comparison, the four counters of the `usage_7d` block go empty (`null`). It is
on purpose: a `0` would claim there was not a single clock-in all week, and that
would be false.

### What is NEVER sent

Neither by enabling telemetry nor with any combination of options:

- First names, surnames or any data about a person on the staff.
- Email addresses, employee codes, PINs, national IDs or their hashes.
- Clock-in times, working days, shift entries, daily totals or corrections.
- The identifier (`uuid`) of any person, tablet, shift entry or user.
- Your licence's company name, its `license_id` or your key's fingerprint.
- Your installation's URL (`APP_URL`), the name of your hotel, of your sites, of
  your departments or of your tablets.
- Paths on your server, file names, passwords or any key.
- Anything not in the table above.

### How to switch it off, and how to start a fresh identity

To switch it off, `TELEMETRY_ENABLED=false` and restart the application. Nothing
else is needed and there is nobody to notify.

If you want the vendor to no longer be able to relate the previous sends to the
following ones, delete the state file:

```bash
docker compose exec app rm -f storage/app/telemetry/state.json
```

The next send mints a new identifier, unrelated to the previous one, and the
counters start over.

---

## 4. What to do if…

### …I save a change and it answers “not valid” (code 422)

The response says **which key** fails and why, in the `errors` field. The cases
that actually happen:

| Message | What has happened | What to do |
| --- | --- | --- |
| “The key … does not exist in this installation” | A misspelt name, or a key from a different version | Compare with the table in section 2. The catalogue is closed on purpose: a key that got saved and nobody read would be worse than an error |
| “… accepts from X to Y” | The value is out of range | Use a value in the range. The limits are in the table |
| “… must be an integer, without quotes” | You sent `"12"` instead of `12` | Remove the quotes. With them, the threshold applied would not be the one you think |
| “The default language … is not among the available languages” | You removed the default language from the list | Send both keys in the same request |

### …I save `BRANDING_LOGO_PATH` and it answers 422

The file is checked **against the disk on save**, so you run into the error at
that moment and not when someone prints a card. Each message says what to do:

| Message | What has happened | What to do |
| --- | --- | --- |
| “The logo path has to be absolute and start with `/`” | You wrote a relative path | Write the full path: `/var/kronoqr/branding/logo.png` |
| “The logo path cannot contain `..`” | The path climbs a directory | Write it without jumps, exactly as it stands inside the brand directory |
| “The logo has to be inside the brand directory” | The file is somewhere else on the server | Copy it to the `BRANDING_PATH` folder and save the path **as seen from inside the container** |
| “There is no file at that path” | Almost always, the volume is not mounted | `docker compose exec app ls -l /var/kronoqr/branding`. If it comes out empty, check `BRANDING_PATH` and `docker compose up -d app` |
| “The file exists but the application cannot read it” | Permissions | `sudo chmod 0644 <file>` on the server |
| “The logo is larger than 512 KiB” | Image too heavy | Export it at a lower resolution, or as SVG |
| “The file is neither a PNG nor an SVG” | The **content** is checked, not the extension | Renaming does not help. Export it again from your design tool |
| “The SVG contains a `<script`” | The SVG has code inside | Export it without scripts or interactivity. A logo does not need code |
| “The PNG exceeds 2048 pixels per side” | Huge image | Shrink it before saving it again |

While the `422` is there **nothing has been saved**: the key keeps the value it
had.

### …I have changed the branding and the kiosk still shows the old one

1. Check what the server is serving, which is what the three applications see.
   No token needed:

   ```bash
   curl -sS https://TU-SERVIDOR/api/v1/branding
   ```

   If your name already shows there, the server is fine and the problem is the
   tablet browser's cache.

2. If the name is yours but your colour and your logo have disappeared, look at
   the licence: custom appearance is a plan feature (section 2.2). The name
   never degrades. What was saved is still there and the appearance comes back
   by itself on renewal.

3. The **kiosk stores the branding so it can paint it without a network** (that
   way the tablet starts with your logo even if the wifi takes a while). It
   updates itself on getting the connection back; to force it, reload the kiosk
   screen.

4. The logo is cached **forever on purpose**, but its address changes when the
   file changes, so replacing it shows up straight away. If you have replaced
   the file and `curl` returns the same `logo_url` as before, the content is
   identical.

### …I change a value and it is not applied

It should not happen: the change takes effect on the next request, without
restarting anything. If you still see the old value:

1. Request `GET /api/v1/settings` again and look at the `source` field of that
   key. If it says `product_default`, the change **was not saved**.
2. Check that you are looking at the right installation.
3. If `source` says `installation` with the new value and the old one is still
   applied, keep the output of `GET /api/v1/settings` and tell support: it is a
   product fault, not your configuration.

### …the response carries `meta.unknown_keys` with something in it

They are stored rows whose name this version does not recognise. **They do
nothing** —nobody reads them— but they should not be there: they usually mean an
update that was left halfway or a manual edit of the database. Note it down and
mention it at the next contact with support. There is no rush and it does not
affect clocking.

### …the response carries `meta.invalid_keys` with something in it

**This one is urgent.** They are rows whose key exists and whose stored value
the system cannot apply. They have been discarded and the default value rules,
so **what is being applied is not what is written in the database**.

Each entry carries three fields: the key, the reason in your language and
`affects_worked_hours`.

1. If any entry has `affects_worked_hours: true`, the hours calculation has
   been applying the default value since the row got corrupted. Note the
   approximate date and review the reports for that period.
2. Save that key again from the panel with the correct value. That replaces the
   row and the entry disappears.
3. If you do not know what the correct value was, the audit log has the change
   history of that key.

They can only appear through a manual edit of the database or through an update
between versions with different catalogues: the API **does not let you save** a
value its key does not accept. The server also leaves a warning
(`product.settings_anomaly`) in its technical log.

**Neither of the two lists prevents clocking in.** Reading the configuration is
deliberately tolerant: a misspelt colour cannot leave the staff unable to
present their card.

### …I want to go back to the default value

Write it explicitly again. The default values are in section 2. Saving an empty
string is **not** going back to the default: for most keys it is rejected, and
for `BRANDING_LOGO_PATH` it means exactly “use the product's logo”, which is
what you want.

### …I change a legal threshold and the incident inbox does not change

It is expected during the first hours. The review that opens incidents runs
**once a day, in the early morning**: until the next pass, the inbox keeps
showing what was detected with the previous threshold. And even after the review
runs, **incidents already open do not close by themselves**: if you have raised
the threshold and one of them stopped being a breach, it has to be closed by
hand, stating the reason.

If you want to check the effect without waiting for the early morning, someone
with access to the server can run the review by hand:

```bash
docker compose exec -T app php artisan attendance:detect-incidents
```

It prints how many working days it has reviewed and how many findings of each
type it has opened. Repeating it is safe: it duplicates nothing and closes no
shift.

### …I have lowered the retention years by mistake

**Nothing has been deleted.** Changing `retention_years` does not purge: the
purge is a separate command that is run by hand, proposes first in dry-run mode
and demands a confirmation derived from that report. Put the correct value back
on the screen and check the dry run before running any purge:

```bash
docker compose exec -T app php artisan compliance:apply-retention --dry-run
```

The first line of the report states the cut-off that would apply and which
profile it comes from.

### …I need to know who changed a threshold and when

Each modified key leaves its own entry in the audit log, with the account that
did it, the moment, the previous value, the new one and whether that key affects
the hours calculation. It is information that is kept for four years and can be
shown to the Labour Inspectorate. It is requested by an account with audit
permission.

The same goes for the compliance profile: **one entry per threshold changed**,
with the previous value, the new one, whether that change moves incident
detection and whether it moves the retention period. It is what makes it
possible to answer “why did this working day in March raise no alert?”.

### …the licence key does not activate

**Nothing has broken and the previous licence is intact.** The message tells you
which of the four reasons it is, because what to do is different in each case:

| What it says | What has happened | What to do |
| --- | --- | --- |
| “The key is incomplete or truncated” | It is, by far, the most frequent case: the key was half copied from an email, or split across two lines | Copy it whole. It starts with `KQL1.` and has no spaces. You can paste it with line breaks: they are cleaned up automatically |
| “This key was not issued by the vendor of this version” | The key was modified along the way, or it is from another issuer | Ask your provider for a new key |
| “The key is signed but is missing information” | It is an **issuing fault**, not yours | Tell your provider, quoting the fingerprint shown on the screen, and ask for another key. Do not waste time checking your copy-and-paste |
| “This installation does not carry the vendor public key” | It is a **deployment** problem, not your key's: a piece of data is missing from the installed image | Tell your provider, quoting the version returned by `GET /api/v1/health`. In the meantime clocking carries on as normal |

### …I see a licence notice and want to know exactly what I have lost

```bash
docker compose exec app php artisan license:show
```

The “Accessory features” section lists what is degraded **with the date since
which it is**, and the next section, “What NEVER depends on the licence”, lists
what keeps working. The same information is in the panel, under **Licence**.

If what you need today are your employees' hours and the report by period is
degraded, you have two routes that do **not** depend on the licence: each
person's record (employee record → “Time record”) and the **export for the
Labour Inspectorate**, which carries the daily record of the whole staff in one
file.

### …live presence says it is not in real time

Look at the reason shown on the screen itself:

- If it says it is **because of the licence**, the view is polling every few
  seconds and has lost no information: it still shows who is in. It recovers on
  renewal.
- If it says **nothing** about the licence, what is missing is the real-time
  service configuration (`REVERB_*` in the `.env`) or the proxy does not allow
  WebSocket. That is fixed by whoever administers the server, not by a renewal.

### …I want to check that the licence is not blocking anything

Clock in with a card and download the legal export, whatever the state of the
licence. Both have to work. If either does not, **it is not the licence**: it is
a fault, and you should tell support with the output of:

```bash
docker compose exec app php artisan license:show
curl -sS https://TU-SERVIDOR/api/v1/health
```

### …the import rejects lines for “carries neither document nor email”

The line has nothing to identify itself with. Add to the file the document
column (`dni`, `nif`, `nie` or `documento`) **or** the email one, and check it
again. Email remains optional: what cannot be missing is both at once.

**Why it is rejected instead of being imported anyway:** without one of the two,
the system could not recognise that person if you upload the file again, and
you would end up with two records for them.

### …the import cannot find a column that is in the file

Look at it in the report: the columns it does not recognise come out as a
warning with their exact name. The usual causes, by frequency:

1. **The name is not in the list.** Check the table in section 3 ter.2. If your
   system calls it something else, add the alias in the `.env` (section
   3 ter.6) — there is no need to touch the program.
2. **An invisible character.** An extra space or a hyphen instead of an
   underscore are **not** a problem: the comparison ignores them. A strange
   character stuck to the start of the first column is: it is the byte order
   mark some editors add. Export the file again from the original program.

### …I imported and the accents or the ñ come out wrong

It should not happen: the encoding is detected automatically. If it happens, the
file is probably neither UTF-8 nor Windows-1252 —the two that are recognised—
but something rarer. Open it in your spreadsheet and save it again as **CSV
UTF-8**. Then correct the names on the records: the import deletes nothing, so
you can re-import the corrected file and they will update themselves.

### …I imported the wrong file

**Nothing is deleted.** People imported by mistake are offboarded one by one
from their record, with their end date; the record they may have generated is
kept, because the law requires keeping it for four years.

If you had **not confirmed** yet —you only did the check— nothing was written:
upload the right file and start over.

### …it says the file is not the one that was validated

You changed the file between the check and the confirmation, even if only by a
space. It is deliberate: that way what is written is exactly what you reviewed.
Do the check again with the new file and confirm with the fingerprint it gives
you back.

### …it says the file has too many lines

Nothing has been imported. Split it into several smaller files and import them
one by one; the system recognises each person, so the order does not matter and
repetitions between files duplicate nobody.

If your staff really is larger than the limit, raise it with
`WORKFORCE_IMPORT_MAX_ROWS` in the `.env` and restart.

### …I have imported the whole staff and nobody can clock in

It is expected, and it is the most expensive mistake in this guide if discovered
late: **importing issues no card**. Check how many are missing and start now,
because printing and handing over takes days:

```bash
docker compose exec app php artisan credentials:status --pending
```

### …the setup wizard no longer appears

It is expected: **it is single-use**. Everything it configured is changed
afterwards from the panel —the site, the departments, the collective agreement
profile, the licence—, and every change is recorded with its author and its
date, which is precisely what a reopenable wizard could not guarantee.

### …I cannot sign in and the wizard says there is already an account

What happened to you is the most common thing: you created the first
administrator and the screen closed before you scanned the authenticator's QR
code. **The account exists.** Sign in with your email and your password through
the normal sign-in screen: since you do not have a second factor yet, the
response itself will offer to set it up and will show you the QR again.

If you have also lost the password, it is reset from the server (the trailing
comments say “creates another management account” and “removes a second
factor”):

```bash
docker compose exec app php artisan identity:create-user   # crea otra cuenta de gestión
docker compose exec app php artisan identity:2fa-reset     # retira un segundo factor
```

---

## 5. What is NOT configured here, and where it is

- **The legal thresholds** —minimum rest between working days, maximum ordinary
  working day, mandatory break, retention years— belong to the **compliance
  profile** (section 2.4), which has its own screen and its own address. A
  legal threshold is set by the law or the collective agreement; an operational
  one is set by you.
- **The active features** are decided by **the licence**, not by a panel
  checkbox: if you could switch them on from here, the licence would limit
  nothing. An expired licence trims accessory features and shows notices, but
  **never prevents clocking in or looking up the record**. Everything you need
  to know about it is in **section 3 bis**.
- **Paths, credentials, ports, networks and signing keys** belong to the
  server's `.env` and require restarting the containers. **They are all in
  section 6**, one by one, with what each one does and when it is worth
  touching; the network parameters and the certificate are also explained in
  detail in [`installation.md`](installation.md) §6.

---

## 6. Complete `.env` reference, variable by variable

This is the **complete** list of what can be written in the server's `.env`:
**161 variables**, all those that `.env.example` ships. It is here so that you
do not have to read the whole file when you are looking for one thing, and so
that you can tell at a glance whether touching it moves working hours or not.

The marker on each row is the one `.env.example` carries, in Spanish, because
that is the file you open: `[CLIENTE]` = you fill it in; `[INSTALADOR]` =
generated by `install.sh`; `[FIJO]` = do not change.

**Before changing anything, three things:**

1. **Everything in the `.env` requires a restart.** The file is edited and the
   containers are recreated. Panel settings do not (section 1).
2. **Look at the marker.** It is the same one `.env.example` carries on the line
   above each variable:

   | Marker | What it means |
   | --- | --- |
   | `[CLIENTE]` | You fill it in before installing. `install.sh` checks in its phase 1 that they are there and **refuses to install** if any is missing |
   | `[INSTALADOR]` | `install.sh` generates it **on your server** and transmits it to nobody. Leave it empty: if you write something, the installer replaces it. The vendor does not know these values and **cannot recover them** |
   | `[FIJO]` | Do not touch. Changing it breaks something that does not look like this variable |
   | `—` | It has a considered default and **most installations never change it**. If in doubt, leave it as it is |

3. **The “Affects hours calculation?” column** says `Yes` when changing it moves
   minutes of the legal record or opens and closes incidents. They are few, and
   they are the only ones you should document in writing when you touch them:
   the change is not audited like the panel ones, because the `.env` is a file
   on your server.

To apply a change (`<version>` is the version installed in `/opt`):

```bash
sudo nano /opt/kronoqr-<version>/.env
cd /opt/kronoqr-<version>
sudo docker compose up -d
sudo docker compose exec app php artisan product:doctor
```

> **Never paste here a value from another installation**, and most especially
> none of the `[INSTALADOR]` ones. Each server generates its own; sharing them
> means whoever has one can read the backups, sign cards or open the sealed
> PINs of the other.

### 6.0 The nine keys that are NOT environment variables

Nine properties of the installation do not live in the `.env` but in the
`installation_settings` table, are edited **from the panel** and take effect on
the next request without restarting anything:

| Key | Where it is changed | Where it is explained |
| --- | --- | --- |
| `ATTENDANCE_MAX_SHIFT_HOURS` | Panel → **Operational settings** (`/settings`) | Section 2.1 |
| `ATTENDANCE_DEBOUNCE_SECONDS` | Panel → **Operational settings** (`/settings`) | Section 2.1 |
| `ATTENDANCE_MAX_CLOCK_SKEW_MINUTES` | Panel → **Operational settings** (`/settings`) | Section 2.1 |
| `ATTENDANCE_MIN_TRANSIT_SECONDS` | Panel → **Operational settings** (`/settings`) | Section 2.1 |
| `BRANDING_APP_NAME` | Panel → **Branding** (`/branding`) | Section 2.2 |
| `BRANDING_LOGO_PATH` | Panel → **Branding** (`/branding`) | Section 2.2 |
| `BRANDING_ACCENT_COLOR` | Panel → **Branding** (`/branding`) | Section 2.2 |
| `LOCALE_DEFAULT` | Panel → **Operational settings** (`/settings`) | Section 2.3 |
| `LOCALE_AVAILABLE` | Panel → **Operational settings** (`/settings`) | Section 2.3 |

Both screens require an **installation administrator** account and both save
with the same button: the change takes effect on the next request and is
recorded in the audit trail with your name, the date and the previous value. If
you cannot see those entries in the menu, they are not missing: your account is
not an administrator one.

**The database wins** (section 1). Five of the nine —the branding and language
ones— no longer exist as environment variables: they were removed so that there
were not two places to write the same piece of data.

**The four `ATTENDANCE_*` do still appear in `.env.example`, and it is worth
knowing exactly what they are:** a copy of the default value, written there so
that whoever reads the file knows what numbers the system works with. **The
application does not read them.** The four thresholds always come from
`installation_settings`, which the migration seeded with those same values (12,
60, 15 and 120). Practical consequence, and the cause of half the *“but I have
it set to something else”*:

> **Editing `ATTENDANCE_DEBOUNCE_SECONDS` in the `.env` changes nothing.** Not
> even after a restart. It is changed in the panel, section 2.1.

`product:doctor` detects it: if the `.env` and the database say different things
for one of those four keys, the `settings.env_differs_from_db` check comes out
as a **warning** and tells you which one. It is not a failure —nothing is
broken— but it means the file is misleading whoever reads it. The right thing
is to leave the `.env` with the same value as the panel, or delete those four
lines.

### 6.1 Application

| Variable | Marker | What it does | Default | When to change it | Affects hours calculation? |
| --- | --- | --- | --- | --- | --- |
| `APP_NAME` | — | **Technical** name of the process. It is not the brand that is seen: that is `BRANDING_APP_NAME` (section 2.2) | `KronoQR` | Never. It is used to derive the cache and session prefixes in Redis; changing it closes every open session | No |
| `APP_ENV` | `[CLIENTE]` | Runtime environment | `local` in the template; **`production` on your server** | The installer sets it. If you see it as `local` in a hotel, it is an installation error: correct it and restart | No |
| `APP_DEBUG` | `[CLIENTE]` | Shows the stack trace and the full configuration on any error | `false` | **Never in production.** With `true`, anyone who triggers an error sees the keys, included. The application **refuses to start** with `APP_ENV=production` and this set to `true`, and says how to fix it | No |
| `APP_KEY` | `[INSTALADOR]` | Encrypts sessions and the encrypted data in the database | (empty; `install.sh` generates it) | Never by hand. Changing it makes the sessions and the already encrypted data unreadable | No |
| `APP_URL` | `[CLIENTE]` | The `https` URL through which the kiosks, the panel and the portal arrive | `https://localhost` in the template | On installing, and if you change the server name. **It has to match the name on the TLS certificate.** Example: `https://fichaje.tuhotel.local` | No |
| `APP_TIMEZONE` | `[FIJO]` | Time zone of the process | `UTC` | **Never.** Every instant is stored in UTC and the conversion to local time happens on presentation. The time zone **is configured per site** in the panel. Changing this invalidates the working-day calculation and strips the working-time record of its legal value; the installer does not even offer to touch it | **Yes** — changing it invalidates the record |
| `APP_LOCALE` | — | Fallback language of the API and of the documents if the database does not answer | `es` | Almost never: `LOCALE_DEFAULT` in the panel rules (section 2.3) | No |
| `APP_FALLBACK_LOCALE` | — | Language fallen back on if a translation is missing | `es` | Almost never | No |
| `APP_SUPPORTED_LOCALES` | — | Languages the API accepts negotiating, as a fallback | `es,en` | Almost never: `LOCALE_AVAILABLE` in the panel rules (section 2.3) | No |

### 6.2 Database

They are **three distinct PostgreSQL roles**, and it is not bureaucracy: the
application role cannot modify or delete the audit log, and only the
maintenance one can drop an expired partition.

| Variable | Marker | What it does | Default | When to change it | Affects hours calculation? |
| --- | --- | --- | --- | --- | --- |
| `DB_CONNECTION` | — | Database engine | `pgsql` | Never. The product is PostgreSQL 17 | No |
| `DB_HOST` | — | Name of the PostgreSQL container | `postgres` | Only if you move the database to a separate server | No |
| `DB_PORT` | — | Port | `5432` | Same as above | No |
| `DB_DATABASE` | — | Database name | `fichaje` | Never after installing | No |
| `DB_USERNAME` | — | Runtime role. **No DDL and no `UPDATE` or `DELETE` on the audit log** | `fichaje_app` | Never | No |
| `DB_PASSWORD` | `[INSTALADOR]` | Password of that role | (empty; `install.sh` generates it) | Only in a secrets rotation; there is a runbook ([`rotacion-secretos.md`](../../runbooks/rotacion-secretos.md), in Spanish) | No |
| `DB_MIGRATION_USERNAME` | — | Owner role, the only one with DDL. Runs the migrations | `fichaje_migrator` | Never | No |
| `DB_MIGRATION_PASSWORD` | `[INSTALADOR]` | Password of that role | (empty; `install.sh` generates it) | Same as the previous one | No |
| `DB_MAINTENANCE_USERNAME` | — | Role of the retention purge, the only one that drops expired partitions | `fichaje_maintenance` | Never | No |
| `DB_MAINTENANCE_PASSWORD` | — | Password of that role | **Empty on purpose** | **It is never written here.** It is supplied at the moment of running the annual purge: see [`operation.md`](operation.md) §6 and §9 | No |
| `BACKUP_DB_USERNAME` | — | User the backups are made with. It is the migration one because backing up and restoring require attributes the application one does not have | `fichaje_migrator` | Never | No |
| `BACKUP_DB_PASSWORD` | `[INSTALADOR]` | Its password, the same as the migration one | (empty; `install.sh` generates it) | Never separately: with a different value, the daily backup fails from day one | No |

### 6.3 Redis, queues, cache and sessions

| Variable | Marker | What it does | Default | When to change it | Affects hours calculation? |
| --- | --- | --- | --- | --- | --- |
| `REDIS_HOST` | — | Name of the Redis container | `redis` | Only if you move Redis to a separate server | No |
| `REDIS_PORT` | — | Port | `6379` | Same as above | No |
| `REDIS_PASSWORD` | — | Redis password | *(empty)* | Empty is correct in the standard installation: Redis **publishes no port** and is reachable only from Docker's internal network. Fill it in only if you move Redis to another machine, and configure it there too | No |
| `QUEUE_CONNECTION` | — | Where background jobs live | `redis` | Never. If Redis goes down, those jobs wait for it to come back; **clocking does not depend on them** | No |
| `CACHE_STORE` | — | Where the cache lives | `redis` | Never | No |
| `SESSION_DRIVER` | — | Where sessions live | `redis` | Never | No |

### 6.4 Real-time presence

Live presence is an **accessory feature**. If this is misconfigured, the screen
switches to polling and says so. Nobody is left unable to clock in.

| Variable | Marker | What it does | Default | When to change it | Affects hours calculation? |
| --- | --- | --- | --- | --- | --- |
| `BROADCAST_CONNECTION` | — | Event broadcasting engine | `reverb` | Never | No |
| `REVERB_APP_ID` | `[INSTALADOR]` | Identifier of the application in the real-time service | (`install.sh` generates it) | Never by hand | No |
| `REVERB_APP_KEY` | `[INSTALADOR]` | **Public** key that identifies the application in the WebSocket handshake. It authorises nothing by itself | (`install.sh` generates it) | Never by hand | No |
| `REVERB_APP_SECRET` | `[INSTALADOR]` | **The secret.** Signs the authorisation of each private channel | (empty; `install.sh` generates it) | Never by hand. Without it the service does not start, which is the noisy failure preferred to a silent one with a known key | No |
| `REVERB_HOST` | — | How the server reaches the service over the internal network. **The browser does not use this value**: it enters through the panel's own origin | `reverb` | Never | No |
| `REVERB_PORT` | — | Internal port | `8080` | Never | No |
| `REVERB_SCHEME` | — | Internal scheme, inside the Docker network | `http` | Never. Browser traffic is encrypted at the edge | No |
| `REVERB_ALLOWED_ORIGINS` | — | Origins allowed to open the WebSocket | *(empty: all)* | Only if you want to close it to your domain, for example `fichaje.tuhotel.local`. Open by default because each customer sets the domain, and the real defence is that every channel is private and the server signs each subscription | No |
| `REALTIME_ENABLED` | — | Whether the presence view uses WebSocket | `true` | Set it to `false` if the hotel's corporate proxy breaks WebSockets. **It does not switch the view off**: it leaves it polling, with a notice on screen | No |
| `REALTIME_POLL_INTERVAL_SECONDS` | — | How many seconds between polls of the view when there is no WebSocket | `15` | Lower it if you want fresher presence at the cost of more requests | No |
| `REALTIME_PATH` | — | WebSocket path on the panel's origin | `/app` | Never, unless a proxy of yours already uses that path | No |
| `REALTIME_AUTH_ENDPOINT` | — | Address that authorises the subscription to a private channel | `/api/v1/broadcasting/auth` | Never | No |
| `REALTIME_EVENT` | — | Name of the presence event the panel listens for | `presence.updated` | Never | No |

### 6.5 QR credential and logo location

| Variable | Marker | What it does | Default | When to change it | Affects hours calculation? |
| --- | --- | --- | --- | --- | --- |
| `QR_SIGNING_KEY_CURRENT_ID` | `[INSTALADOR]` | Two-character identifier of the key new cards are signed with | (`install.sh` generates it) | Only when rotating the key, following the runbook [`rotacion-clave-qr.md`](../../runbooks/rotacion-clave-qr.md) (in Spanish) | No |
| `QR_SIGNING_KEY_CURRENT` | `[INSTALADOR]` | The active signing key | (empty; `install.sh` generates it) | Same as above. **If it is lost, every card has to be reprinted** | No |
| `QR_SIGNING_KEY_PREVIOUS_ID` | — | Identifier of the **outgoing** key during a rotation | *(empty)* | Only during a rotation. Empty is the normal state | No |
| `QR_SIGNING_KEY_PREVIOUS` | — | The outgoing key. It no longer signs, but it still **verifies** the cards printed with it, which is why reprinting is spread over weeks | *(empty)* | Same as above. An identifier without its key creates no overlap: either both go, or neither | No |
| `QR_ERROR_CORRECTION` | — | How much wear a card withstands before it stops being readable | `Q` | Never. Lowering it produces cards that fail after months in a pocket | No |
| `QR_SIZE_MM` | — | Side of the printed QR, in millimetres | `26` | Only if you change card format. It is the minimum size at which reading is guaranteed | No |
| `IDENTITY_CREDENTIAL_REJECTION_FLOOR_MS` | — | Time floor consumed by **every** credential rejection, so that from outside “does not exist” cannot be told from “revoked” or from “bad signature” | `25` | Almost never. Raising it hardens the control and adds latency **only to rejections**; at `0` it is disabled and that must not be done in production | No |
| `BRANDING_LOGO_ROOT` | — | Directory **inside the container** where the logo has to be. It is what prevents the logo's public address from turning into a read of any file on the server | `/var/kronoqr/branding` | Never, unless you also change the `docker-compose` mount. See **section 2.2** | No |
| `BRANDING_PATH` | — | Folder **on your server** that is mounted there, read-only. It is where you put the PNG or the SVG | *(empty: `./branding` next to the `docker-compose.yml`)* | When placing the hotel's logo. See **section 2.2** | No |

### 6.6 PDF generation

All three are paths **inside the product image**: the browser that draws the
PDFs travels included, nothing is downloaded on start-up and no internet access
is needed.

| Variable | Marker | What it does | Default | When to change it | Affects hours calculation? |
| --- | --- | --- | --- | --- | --- |
| `LARAVEL_PDF_CHROME_PATH` | — | Path of the browser that draws the PDFs | `/usr/bin/chromium-browser` | Never | No |
| `LARAVEL_PDF_NODE_MODULES_PATH` | — | Path of the libraries that drive it | `/usr/local/lib/node_modules` | Never | No |
| `LARAVEL_PDF_NO_SANDBOX` | — | Disables the browser's own sandbox, which the container cannot grant it | `true` | Never. The container already runs unprivileged and the HTML handed to it is generated by the application itself, with no remote URL | No |

### 6.7 Clocking rules

**The first four are changed in the panel, not here** (section 6.0). The `.env`
line is a copy of the default value and **editing it does nothing**.

| Variable | Marker | What it does | Default | When to change it | Affects hours calculation? |
| --- | --- | --- | --- | --- | --- |
| `ATTENDANCE_DEBOUNCE_SECONDS` | — | Debounce window between two scans by the same person. See **section 2.1** | `60` | In the panel. Here, never | **Yes** |
| `ATTENDANCE_MAX_SHIFT_HOURS` | — | Duration from which a closed shift entry is anomalous. See **section 2.1** | `12` | In the panel. Here, never | **Yes** (opens incidents) |
| `ATTENDANCE_MAX_CLOCK_SKEW_MINUTES` | — | Drift tolerated between the tablet's clock and the server's. **Raises an incident, never rejects the clock-in** (RF-AT-10). See **section 2.1** | `15` | In the panel. Here, never | **Yes** (opens incidents) |
| `ATTENDANCE_MIN_TRANSIT_SECONDS` | — | Minimum credible transit between two kiosks. See **section 2.1** | `120` | In the panel. Here, never | **Yes** (opens incidents) |
| `ATTENDANCE_PATTERN_WINDOW_SECONDS` | — | Seconds below which two consecutive clock-ins at the same kiosk will be considered an anomalous pattern | `10` | **Nothing reads it yet**: that detector arrives in a later version. The variable is reserved so the file does not have to change then | Not yet |
| `ATTENDANCE_PATTERN_MIN_REPEATS` | — | Systematic coincidences between two people before opening an incident | `3` | Same as the previous one | Not yet |

### 6.8 Access to the management panel

| Variable | Marker | What it does | Default | When to change it | Affects hours calculation? |
| --- | --- | --- | --- | --- | --- |
| `IDENTITY_LOGIN_MAX_ATTEMPTS` | — | Failed passwords **per account** before locking it | `5` | Raise it if your people complain about lockouts; lower it if your policy is stricter | No |
| `IDENTITY_LOGIN_LOCKOUT_SECONDS` | — | How long that lockout lasts | `900` (15 min) | Same as above | No |
| `IDENTITY_SESSION_TOKEN_HOURS` | — | Lifetime of the panel session | `12` | Lower it if the management computers are shared. **It does not affect the kiosk token**, which lasts 90 days | No |
| `IDENTITY_PASSWORD_MIN_LENGTH` | — | Minimum length of the management password | `12` | Raise it if your policy requires it. **There is a floor of 8 in the code**: it cannot go below that | No |
| `IDENTITY_MANAGEMENT_RATE_LIMIT` | — | Requests per minute, per account and per origin, on the management routes that read or correct other people's data | `120` | Only if a large hotel sees `429` errors under normal use | No |

### 6.9 Second factor for management accounts

**Management accounts only.** The employee does not have and cannot have a
second factor: their credential is a physical card and their access to the
portal is code and PIN.

| Variable | Marker | What it does | Default | When to change it | Affects hours calculation? |
| --- | --- | --- | --- | --- | --- |
| `IDENTITY_2FA_REQUIRED_ROLES` | — | Roles required to carry a second factor | `admin,rrhh,auditor` | Add `responsable_departamento` if your policy is stricter. Removing a role from the list **does not disable the second factor of whoever already enabled it** | No |
| `IDENTITY_2FA_CHALLENGE_MINUTES` | — | Minutes the half-authentication between the password and the code lives | `10` | Almost never. In minutes and not in hours on purpose | No |
| `IDENTITY_2FA_MAX_ATTEMPTS` | — | Failed codes before locking | `5` | Almost never | No |
| `IDENTITY_2FA_LOCKOUT_SECONDS` | — | How long that lockout lasts | `900` (15 min) | Almost never | No |
| `IDENTITY_2FA_WINDOW` | — | Tolerance to the phone's clock drift, in 30 s steps either side | `1` (a code is valid for about 90 s) | Set it to `0` if your clocks are NTP-synchronised and you want to tighten it | No |
| `IDENTITY_2FA_SECRET_LENGTH` | — | Length of the authenticator secret | `32` (160 bits) | Never | No |
| `IDENTITY_2FA_ISSUER` | — | Name shown in the authenticator app next to the account's email | `KronoQR` | Change it to your hotel's if you prefer to see it that way on the phone | No |
| `IDENTITY_2FA_RATE_LIMIT` | — | Requests per minute to the second-factor routes, per account | `5` | Almost never. **It has to be greater than or equal to `IDENTITY_2FA_MAX_ATTEMPTS`**, so that the account counter locks and not the rate limiter | No |

### 6.10 Employee PIN and kiosk token

The PIN is **six digits** and that length **is not configurable**: the API
contract fixes it. What can be adjusted is which PINs are never issued and how
whoever tries them is slowed down. The counters are **per employee and per
origin**: the kiosk's and the portal's are separate, so that probing one door
does not leave anyone unable to clock in through the other.

| Variable | Marker | What it does | Default | When to change it | Affects hours calculation? |
| --- | --- | --- | --- | --- | --- |
| `IDENTITY_PIN_FORBIDDEN` | — | PINs the generator never issues, comma-separated | *(the default list: repeated digits and sequences)* | Almost never. **Writing it replaces the whole default list**; leaving it empty means “exclude none”, which is a bad idea | No |
| `IDENTITY_PIN_MAX_ATTEMPTS` | — | Failures of the first lockout tier | `3` | Almost never | No |
| `IDENTITY_PIN_LOCKOUT_SECONDS` | — | Duration of the first lockout | `300` (5 min) | Almost never | No |
| `IDENTITY_PIN_LOCKOUT_TIER2_ATTEMPTS` | — | Failures of the second tier | `5` | Almost never | No |
| `IDENTITY_PIN_LOCKOUT_TIER2_SECONDS` | — | Duration of the second lockout | `900` (15 min) | Almost never | No |
| `IDENTITY_PIN_LOCKOUT_TIER3_ATTEMPTS` | — | Failures of the third tier | `10` | Almost never | No |
| `IDENTITY_PIN_LOCKOUT_TIER3_SECONDS` | — | Duration of the third lockout | `3600` (60 min) | Almost never | No |
| `IDENTITY_PIN_LOCKOUT_RESET_HOURS` | — | With no failures during these hours, the counter goes back to zero | `24` | Almost never. Resetting someone's PIN also clears their counter immediately | No |
| `IDENTITY_PIN_SEALING_SECRET_KEY` | `[INSTALADOR]` | Private key with which the server opens the PINs the tablet seals. It is what allows clocking by PIN **without a network** without leaving the PIN in the clear on the tablet | (empty; `install.sh` generates it) | Never copy it from another server. **Empty is a legitimate case**: it means this installation does not offer clocking by PIN and the kiosk hides the numeric keypad | No |
| `IDENTITY_DEVICE_TOKEN_DAYS` | — | Days a paired tablet's token lives | `90` | Almost never | No |
| `IDENTITY_DEVICE_TOKEN_ROTATION_THRESHOLD` | — | Fraction of that lifetime from which the token renews itself | `0.8` | Almost never. Renewing it on the last day would leave a tablet that had spent a week disconnected unable to clock in | No |

### 6.11 Setup wizard and public branding

The two routes they govern are **public**, because they are needed before anyone
has signed in.

| Variable | Marker | What it does | Default | When to change it | Affects hours calculation? |
| --- | --- | --- | --- | --- | --- |
| `PRODUCT_SETUP_RATE_LIMIT` | — | Requests per minute and per origin on the two public routes of the setup wizard | `10` | Raise it if the panel shares a single IP with half the office through NAT. A setup is done by one person, once | No |
| `PRODUCT_BRANDING_RATE_LIMIT` | — | Requests per minute and per origin for the branding and the logo, which the three applications request on start-up | `120` | Raise it if a large hotel sees `429` errors when starting up in the morning. **Raising it a lot has a disk cost**: each request reads the logo file. If you needed several thousand, the right thing is to put a cache in front, not to raise this number | No |

### 6.12 Staff import from a file

The whole procedure is in **section 3 ter**. The delimiter and the encoding
**are not configured**: they are detected.

| Variable | Marker | What it does | Default | When to change it | Affects hours calculation? |
| --- | --- | --- | --- | --- | --- |
| `WORKFORCE_IMPORT_MAX_ROWS` | — | Maximum data lines per file. See **section 3 ter.7** | `500` | Only if your staff is larger, and first try splitting the file: it is faster | No |
| `WORKFORCE_IMPORT_MAX_FILE_KILOBYTES` | — | Maximum file size. See **section 3 ter.7** | `4096` | Almost never: a file of 500 people is around 60 KB | No |
| `WORKFORCE_IMPORT_COLUMN_ALIASES` | — | Additional column names, in `field=header` format separated by `;`. See **section 3 ter.6** | *(empty)* | When the export from your previous system calls the columns something else. **They are added to the default ones, they do not replace them** | No |

### 6.13 Employee portal

Where the portal can be accessed from **is not decided here**: that is
`PORTAL_INTERNAL_CIDR`, in section 6.15.

| Variable | Marker | What it does | Default | When to change it | Affects hours calculation? |
| --- | --- | --- | --- | --- | --- |
| `IDENTITY_PORTAL_SESSION_HOURS` | — | Lifetime of the portal session | `2` | Almost never. Shorter than the panel's on purpose: the portal is opened from a personal phone. Resetting someone's PIN **invalidates their sessions immediately**, so this number is not what answers a lost phone | No |
| `IDENTITY_PORTAL_RATE_LIMIT` | — | Requests per minute on the portal, per IP **and** per employee code at once | `10` | Almost never. It applies on both axes because in a hotel the whole staff goes out through the same line | No |

### 6.14 Limits on the kiosk path

They are the **application's** limits, and they do not replace the web server's
(section 6.15): those limit per origin and these **per tablet**, which is what
prevents a broken tablet from consuming the others' quota when they all go out
through the same IP.

| Variable | Marker | What it does | Default | When to change it | Affects hours calculation? |
| --- | --- | --- | --- | --- | --- |
| `KIOSK_SCAN_RATE_PER_DEVICE` | — | Clock-ins per minute and per tablet | `120` | Almost never | No |
| `KIOSK_BATCH_RATE_PER_DEVICE` | — | Batch sends per minute and per tablet, which is how the tablet empties its queue on getting the network back | `60` | Almost never | No |
| `KIOSK_TELEMETRY_RATE_PER_DEVICE` | — | Requests per minute and per tablet for the roster and the heartbeat | `60` | Almost never | No |
| `KIOSK_RATE_PER_IP` | — | The whole kiosk path, per origin | `600` | Almost never. It is set to the same value as the web server's internal zone: any lower, and the real ceiling would be set by the application | No |
| `KIOSK_PIN_SCAN_RATE_PER_DEVICE` | — | Clock-ins by PIN per minute and per tablet | `10` | Almost never. Two orders of magnitude below the rest on purpose: what is slowed down there is not a clocking rate, it is brute force on six digits | No |
| `KIOSK_PIN_SCAN_RATE_PER_IP` | — | Clock-ins by PIN per minute and per origin | `60` | Almost never, and for the same reason | No |
| `KIOSK_BATCH_MAX_SIZE` | — | Maximum scans in a synchronisation batch | `50` | Never: it is also in the API contract, so changing it here does not change it on the tablet. **Lowering it below 50 makes the server reject every batch from the tablets (422) and their offline queue never drains**: it does not shift minutes, it loses whole clock-ins | No |
| `KIOSK_HEALTH_FRESH_WITHIN_SECONDS` | — | Seconds of leeway before `php artisan kiosk:health` stops treating a kiosk's last contact as up to date | `120` | Almost never. The heartbeat runs every 60 s, so two minutes are two missed beats: a single one can be a flickering wi-fi | No |
| `KIOSK_HEALTH_SILENT_AFTER_SECONDS` | — | Seconds after which `php artisan kiosk:health` treats a kiosk as silent and exits with code 2 | `600` | Almost never. It is the same threshold as the "Kiosk with no heartbeat > 10 min" alert: separate them and the console and the alert will say different things about the same kiosk | No |

### 6.15 Network, TLS and edge

The three ranges are explained in detail, with symptoms and checks, in
[`installation.md`](installation.md) **§6**.

| Variable | Marker | What it does | Default | When to change it | Affects hours calculation? |
| --- | --- | --- | --- | --- | --- |
| `KIOSK_VLAN_CIDR` | `[CLIENTE]` | Range of the kiosk VLAN, for which the clocking limit is raised. See [`installation.md`](installation.md) §6 | `10.0.20.0/24` | **On installing, always.** If the kiosks fall outside it, the failure is silent and shows up as “the kiosk is slow at 06:00” | No |
| `PORTAL_INTERNAL_CIDR` | `[CLIENTE]` | Network from which the employee portal is allowed. Outside it, a `403` is answered before reaching the application. See [`installation.md`](installation.md) §6 | `172.28.0.0/16` (a development network) | **On installing, always**, with the hotel's real LAN or the VPN. Exposing it to the internet is an explicit decision taken by setting `0.0.0.0/0`, never by leaving the default; document it in the handover record | No |
| `METRICS_ALLOW_CIDR` | `[CLIENTE]` | The only origin allowed to read the metrics. Everything else gets `403`, the server itself included. See [`installation.md`](installation.md) §6 | `172.29.0.20/32` | On installing, if you move the metrics collector. It is a `/32` on purpose | No |
| `NGINX_CLIENT_MAX_BODY_SIZE` | — | Maximum body size the web server accepts | `8m` | Almost never. Raise it only if you also raise `WORKFORCE_IMPORT_MAX_FILE_KILOBYTES` above that | No |
| `TLS_ALLOW_SELF_SIGNED` | `[CLIENTE]` | Allows starting with a self-signed certificate | `true` in the template | **To `false` in production.** With `false` and no certificate, the web server does not start and says one has to be put in place. It is intentional. See [`installation.md`](installation.md) §6 | No |
| `TLS_CERT_FILE` | — | Path of the certificate **inside the container** | `/etc/nginx/certs/tls.crt` | Never. What is changed is the server folder, `TLS_CERT_DIR` | No |
| `TLS_KEY_FILE` | — | Path of the private key inside the container | `/etc/nginx/certs/tls.key` | Same as above | No |

### 6.16 Compliance and retention

> **The retention years of the working-time record are NOT here.** They are a
> legal threshold and come from the site's compliance profile
> (`retention_years`, four years in the profile shipped): **section 2.4** of
> this document and [`operation.md`](operation.md) §6 and §9.

| Variable | Marker | What it does | Default | When to change it | Affects hours calculation? |
| --- | --- | --- | --- | --- | --- |
| `COMPLIANCE_PROFILE` | `[CLIENTE]` | Name of the compliance profile the installer marks as the default profile | `ES-hosteleria` | On installing, if your collective agreement is a different one. **It is not read at runtime**: the thresholds come from the profile row, which is edited in the panel (section 2.4). Changing this without changing the row does nothing | No |
| `ERROR_HISTORY_RETENTION_DAYS` | — | Days the error history is kept. See [`operation.md`](operation.md) §6 and §15.4 | `90` | Almost never | No |
| `TECHNICAL_LOG_RETENTION_DAYS` | — | Days the technical log is kept, in a different store from the previous one. See [`operation.md`](operation.md) §6 | `90` | Almost never | No |
| `COMPLIANCE_RETENTION_BATCH_SIZE` | — | Rows per delete statement in the purge. See [`operation.md`](operation.md) §6 | `1000` | Only if the annual purge takes too long | No |
| `COMPLIANCE_RETENTION_REPORT_PATH` | — | Where the report of each proposal and of each purge is left. **It is not cleaned up automatically**: it is the proof that the purge was regular. See [`operation.md`](operation.md) §6 | `storage/app/retention-reports` (in the container) | Almost never | No |
| `COMPLIANCE_LEGAL_EXPORT_TEMP_RETENTION_HOURS` | — | Hours an orphaned temporary file from the legal export download may live before it is deleted automatically. **It does not affect** the deliberate copy generated by the export command: that one is kept by whoever generated it | `6` | Almost never | No |
| `COMPLIANCE_AUTHZ_DENIAL_WINDOW_SECONDS` | — | Window in which repeated denials for the same actor are grouped into a single audit entry. Protects the audit chain from an enumeration | `60` | Set it to `0` if you are investigating an incident and want one entry per denial | No |
| `COMPLIANCE_INCIDENT_LOOKBACK_DAYS` | — | Days back that the daily incident detection reviews. Shift entries **still open** are always reviewed, whatever their date | `7` | Almost never. **Raising it can open incidents for working days already handed to the staff or to the Labour Inspectorate**, which is exactly what the window avoids | **Yes** (opens incidents) |

### 6.17 Licence

Everything else about the licence is in **section 3 bis**. And first things
first, because it is what gets asked most: **leaving `LICENSE_KEY` empty does
not prevent clocking in**.

| Variable | Marker | What it does | Default | When to change it | Affects hours calculation? |
| --- | --- | --- | --- | --- | --- |
| `LICENSE_KEY` | `[CLIENTE]` | The signed key your provider gives you. See **section 3 bis** | *(empty)* | On installing, if you have it to hand. **It is not read at runtime**: only the installer looks at it the first time. From then on what was activated from the panel rules | No |
| `LICENSE_PUBLIC_KEY` | — | Vendor public key the signature is verified with. **A customer does not touch it**: it is compiled into the product and is the same in every installation | *(empty; the product ships it)* | Never, unless support asks you to because of an emergency rotation | No |
| `LICENSE_EXPIRY_WARNING_DAYS` | — | How far in advance the panel warns that the licence is expiring. **During those days nothing is degraded** | `30` | Raise it if your purchasing process is slow. `0` leaves the warning for the last day, which is not recommended | No |
| `LICENSE_HEALTH_PROBE_TTL_SECONDS` | — | Seconds the copy of the licence state read by the health probe lives. **It is not a licence cache**: a newly activated key takes effect immediately | `600` | Almost never. It exists so that the liveness probe does not query PostgreSQL, because then a database outage would restart the application container | No |

### 6.18 Diagnostics, support and full export

They are explained one by one in **section 3 quater**; the procedure is in
[`operation.md`](operation.md) §12 and §13. **The diagnostics package is
anonymised by default and there is no variable that changes that.**

| Variable | Marker | What it does | Default | When to change it | Affects hours calculation? |
| --- | --- | --- | --- | --- | --- |
| `PRODUCT_DIAGNOSTICS_MAX_BYTES` | — | Maximum size of the diagnostics package. See **section 3 quater** | `8388608` (8 MiB) | If your support channel cuts off before that | No |
| `PRODUCT_DIAGNOSTICS_RATE_LIMIT` | — | Packages per minute and per account. See **section 3 quater** | `3` | Almost never | No |
| `PRODUCT_DIAGNOSTICS_PERSONAL_DATA_MAX_PERIOD_DAYS` | — | Maximum days of clock-ins in a package **with personal data**. See **section 3 quater** | `31` | **Raising it is a legal decision, not a performance one** | No |
| `PRODUCT_DIAGNOSTICS_RETENTION_DAYS` | — | Days a package stays on disk before the command deletes it when generating the next one. See **section 3 quater** | `7` | Almost never | No |
| `PRODUCT_DIAGNOSTICS_PATH` | — | Where the package is written. Directory at `0700` and file at `0600` | `storage/app/diagnostics` (in the container) | Almost never. **Do not put it inside `BACKUP_PATH`**: a package is disposable material that may also carry personal data | No |
| `PRODUCT_DATA_EXPORT_PATH` | — | Where the ZIP of the full export is written. See **section 3 quater** | `storage/app/exports` (in the container) | Almost never, and **never inside `BACKUP_PATH`** | No |
| `PRODUCT_DATA_EXPORT_RETENTION_DAYS` | — | Days that ZIP can be downloaded before it is purged. The note that it existed is always kept. See **section 3 quater** | `7` | If you need it for longer, better take it off the server | No |
| `PRODUCT_DATA_EXPORT_RATE_LIMIT` | — | Requests per minute and per account to the export list and download. See **section 3 quater** | `30` | Do not lower it: the panel polls every five seconds while one is in progress | No |
| `PRODUCT_DATA_EXPORT_STALE_AFTER` | — | Seconds after which an interrupted export is considered failed and lets you request another. See **section 3 quater** | `3600` | **Never below what your largest export takes**: you would give up for dead one that is still writing | No |
| `PRODUCT_SUPPORT_GRANT_DEFAULT_HOURS` | — | Duration of a support access grant if no other is stated. See **section 3 quater** | `24` | If your policy is stricter | No |
| `PRODUCT_SUPPORT_GRANT_MAX_HOURS` | — | Maximum duration that can be requested. More is rejected. See **section 3 quater** | `72` | Lower it if your policy is stricter; the panel and the API adjust themselves. **It is not in the panel on purpose**: if it were, whoever grants the access could raise it before granting it | No |
| `PRODUCT_SUPPORT_USE_AUDIT_WINDOW_SECONDS` | — | How often a new use of the same support access grant is written to the audit log. The panel's “last use” date is updated on every request regardless. See **section 3 quater** | `900` | Set it to `0` if you are investigating an incident and want one entry per request | No |
| `PRODUCT_CLIENT_ERRORS_RATE_LIMIT` | — | Requests per minute and per session from the panel or the portal to report errors; four times more per IP address. See [`operation.md`](operation.md) §15 | `12` | Almost never | No |
| `PRODUCT_ERRORS_MAX_OPEN_GROUPS_PER_SOURCE` | — | Ceiling of **open** groups per origin in the error history; above it, the next occurrence that does not match an existing group goes into an overflow group for that origin instead of creating a new row. See [`operation.md`](operation.md) §15.5 | `500` | Almost never | No |

### 6.19 Telemetry

It ships switched off and stays that way if you do nothing. It is explained in
full in **section 3 quinquies**, including the closed list of what is sent.

| Variable | Marker | What it does | Default | When to change it | Affects hours calculation? |
| --- | --- | --- | --- | --- | --- |
| `TELEMETRY_ENABLED` | — | Whether telemetry is enabled. See **section 3 quinquies** | `false` | Only if you decide to enable it. The three conditions of that section are needed | No |
| `TELEMETRY_ENDPOINT` | — | Where it is sent. See **section 3 quinquies** | *(empty)* | Same as above. **It has to start with `https://`**; empty, or without that, nothing is sent | No |
| `TELEMETRY_STATE_PATH` | — | Where the installation's random identifier and the send history live. See **section 3 quinquies** | `storage/app/telemetry/state.json` | Almost never | No |
| `TELEMETRY_RETRY_DELAY_SECONDS` | — | Seconds between the attempt and its single retry. See **section 3 quinquies** | `5` | Almost never | No |

### 6.20 Observability

Exactly what you lose if you switch the observability services off is in
[`operation.md`](operation.md) §10.

| Variable | Marker | What it does | Default | When to change it | Affects hours calculation? |
| --- | --- | --- | --- | --- | --- |
| `LOG_CHANNEL` | — | Where the application writes its technical log | `stderr` | Never. It is what lets the log collector read it | No |
| `LOG_LEVEL` | — | How much detail it writes | `debug` in the template | **To `info` or `warning` in production.** With `debug` the log grows a lot and fills the disk before anyone looks at it | No |
| `LOG_STDERR_FORMATTER` | — | Log format: one JSON line per event, which is what can be searched and filtered | `Monolog\Formatter\JsonFormatter` | Never | No |
| `LOKI_URL` | — | Address of the log store | `http://loki:3100` | Almost never. **On its own it changes the destination of nothing**: the application writes to `stderr` and the dashboard already comes pointed. It is kept because it travels in the diagnostics package and tells support where you are looking | No |
| `OTEL_EXPORTER_OTLP_ENDPOINT` | — | Where traces are exported to | *(empty: disabled)* | Only if you have your own tracing system to send them to | No |
| `OTEL_SERVICE_NAME` | — | Name the service appears with in those traces | `kronoqr-api` | Only if the previous one is configured and you need to tell installations apart | No |
| `GRAFANA_ADMIN_USER` | — | Administration account of the dashboard | `admin` | Change it if your policy requires it | No |
| `GRAFANA_ADMIN_PASSWORD` | `[INSTALADOR]` | Its password | (empty; `install.sh` generates it) | It is rotated from the dashboard itself. **It is never exposed without authentication** | No |

### 6.21 Email

> **Staff names go out through this channel, daily.** The nightly incident
> digest goes to the manager of each department with the date, the name and the
> type of each finding. It is the only path by which personal data leaves the
> server without anyone pressing anything, and that is why it leaves an entry
> in the audit log. **If your mail relay is a third party's, that third party
> is a data processor and you have to have it under contract**: see
> [`legal-obligations.md`](legal-obligations.md).
>
> A send failure **breaks nothing in the record**: the incident stays open in
> the inbox and goes into the following night's digest.

| Variable | Marker | What it does | Default | When to change it | Affects hours calculation? |
| --- | --- | --- | --- | --- | --- |
| `MAIL_MAILER` | `[CLIENTE]` | How email is sent | `smtp` | On installing, if you use another method | No |
| `MAIL_HOST` | `[CLIENTE]` | Outgoing mail server | `mailpit` (the development one) | **On installing, always**, with your hotel's | No |
| `MAIL_PORT` | `[CLIENTE]` | Its port | `1025` (the development one) | **On installing, always.** With implicit TLS it is usually 465 | No |
| `MAIL_USERNAME` | `[CLIENTE]` | User of that account | *(empty)* | On installing, if your server requires it | No |
| `MAIL_PASSWORD` | `[CLIENTE]` | Its password | *(empty)* | Same as above. It is a secret of yours: it appears in no log and not in the diagnostics package | No |
| `MAIL_SCHEME` | — | Transport encryption | *(empty: opportunistic TLS)* | **Set it to `smtps` in production.** Empty, if the server does not advertise encryption the session stays in the clear and the email, with names inside, travels readable. With `smtps` the send **fails** instead of degrading silently | No |
| `MAIL_FROM_ADDRESS` | `[CLIENTE]` | Address it is sent from | `no-reply@kronoqr.local` | On installing, with one on your domain: `no-reply@tuhotel.local` | No |
| `MAIL_FROM_NAME` | — | Name shown as sender | `KronoQR` | Change it to your hotel's if you prefer to see it that way | No |

### 6.22 Backups

The backup stays on **your** infrastructure: the vendor neither receives nor
keeps it. The procedure and the restoration are in
[`operation.md`](operation.md) §6 and §9, and the destination, in
[`installation.md`](installation.md) §6.

| Variable | Marker | What it does | Default | When to change it | Affects hours calculation? |
| --- | --- | --- | --- | --- | --- |
| `BACKUP_PATH` | `[CLIENTE]` | Destination of the backups, mounted at the same path inside the containers. See [`installation.md`](installation.md) §6 | `/var/backups/fichaje` | **On installing, always**, with a destination that is not on the same disk as the database. If it is a network share, **it has to be mounted before** bringing the services up | No |
| `BACKUP_ENCRYPTION_KEY` | `[INSTALADOR]` | Encrypts the backups. **Without it there is no backup**: the script refuses to start | (empty; `install.sh` generates it) | Never by hand. **It is the only one that has to be kept safe off the server**: without it nothing can be restored. See [`operation.md`](operation.md) §9 | No |
| `BACKUP_RETENTION_DAYS` | — | Days the daily backups are kept. See [`operation.md`](operation.md) §6 | `30` | If your backup policy is different. **Mind the disk space** before raising it | No |
| `BACKUP_MIN_COPIES` | — | Backups that are never deleted, even if they have all expired | `3` | Almost never. It is the safety net that avoids being left with no backup at all | No |
| `BACKUP_WAL_RETENTION_DAYS` | — | Days of archived transaction log that are kept, which is what allows restoring to a point in time | `8` | **It has to be greater than the interval between full backups** (weekly by default): without the previous full backup, that archive rebuilds nothing | No |
| `BACKUP_DAILY_AT` | — | Time of the daily backup, **in UTC** | `03:15` | If it clashes with another task of yours. Never near a shift change. Remember it is UTC, not the hotel's time | No |
| `BACKUP_WEEKLY_AT` | — | Time of the weekly full backup, **in UTC** | `02:15` | Same as above | No |
| `BACKUP_WEEKLY_ON` | — | Day of the week for that full backup (`0` is Sunday) | `0` | If you prefer another quiet day | No |

### 6.23 Production only

The production `docker-compose` reads them. In development they are ignored.

| Variable | Marker | What it does | Default | When to change it | Affects hours calculation? |
| --- | --- | --- | --- | --- | --- |
| `IMAGE_REGISTRY` | `[CLIENTE]` | Registry the images are pulled from | `ghcr.io/kronoqr` | If you have your own internal registry, or if you install with no internet access. See [`installation.md`](installation.md) §7 | No |
| `IMAGE_TAG` | `[INSTALADOR]` | The deployed version: the tag of the images and what the health probe publishes | (`install.sh` writes it from the package's `VERSION` file) | Never by hand. **`latest` is forbidden in production** and there is no default: if this is empty, Compose stops before creating anything and says what to put. An installation that cannot say which version it runs makes `update.sh`'s rollback impossible | No |
| `COMPOSE_PROFILES` | — | Switches the observability services on | `observability` | Leave it set. They are what warns that last night's backup failed or that transaction-log archiving has stopped, the two failures that turn a healthy installation into data loss without anyone noticing. **Leaving it empty switches them off**, which is a supported configuration that frees about 700 MiB, and then verifying the backup becomes a weekly manual task of yours | No |
| `HTTP_PORT` | `[CLIENTE]` | Port the server listens on for unencrypted requests, to redirect them | `80` | Only if that port is already taken on the machine | No |
| `HTTPS_PORT` | `[CLIENTE]` | Encrypted port through which the panel, the portal and the tablets come in | `443` | Same as above. If you change it, it has to appear in `APP_URL` too | No |
| `TLS_CERT_DIR` | `[CLIENTE]` | Folder **on your server** with the certificate and its private key, mounted read-only. See [`installation.md`](installation.md) §6 | `./certs` | On installing, if you keep the certificates somewhere else on the server | No |

---

← [Installation](installation.md) · [Operation](operation.md) · [Legal obligations](legal-obligations.md)
