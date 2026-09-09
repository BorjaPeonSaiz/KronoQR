# The hotel's legal obligations when using KronoQR

> **What this document is, and what it is not.** It explains which obligations
> the hotel takes on by using the system and what the product does to help meet
> them. **It is not legal advice**: the framework it describes is validated by
> your employment law advisers and, if you have one, your data protection
> officer, who know your collective agreement and your situation. The product
> helps you comply; it does not comply for you (RL-21). En español:
> [`../obligaciones-legales.md`](../obligaciones-legales.md).

**Who answers for what, in one line:** the hotel is the **controller**
(responsable del tratamiento) and the keeper of the working-time record; the
vendor delivers a product that makes compliance possible, and **does not access
the data** (ADR-020). None of the obligations in this document can be delegated
to the vendor.

---

## 1. Record the working day, and keep it for four years

Art. 34.9 of the Workers' Statute (Estatuto de los Trabajadores, ET) requires
employers to **record daily** each person's working day — start and end time —
and to **keep** those records for **four years**, available to the staff, to
their representatives and to the Labour Inspectorate (Inspección de Trabajo).

What that means in practice, with this product:

| Obligation | What the system does | What you have to do |
| --- | --- | --- |
| Record every working day | Records every clock-in with its real time and its time of receipt, and never rejects a clock-in because of a technical failure | Make sure **everyone has their card** before their first shift, and deal with the incidents that the daily review opens |
| Keep for four years | No action deletes data, except the retention purge, which is manual and confirmed | **Do not purge ahead of time**, and authorise the purge when it falls due (§4) |
| Have the record available | `compliance:legal-export` and the admin panel produce the normalised file in minutes | Know who generates it and have the procedure rehearsed (`docs/runbooks/requerimiento-inspeccion.md`, in Spanish) |
| Do not alter the record | Corrections create a new version with author, time and reason | **Always correct with a reason**, never "fix" things in the database |

**The four-year period is configuration, not code** (`compliance_profiles`,
RF-PD-07). If your collective agreement or your jurisdiction requires a
different one, the site's profile is changed: the system recalculates the
cut-off on its own and the retention report says so.

---

## 2. Protect the personal data the system processes

**What this product processes**, and it is worth having it stated this briefly
in your record of processing activities under art. 30 of the GDPR (RGPD):

- Minimal staff identification: first name, surname, employee code, site,
  department, start and end dates. Email is **optional**.
- Clock-in and clock-out timestamps, their origin (QR card or PIN) and the
  device they were recorded from.
- Log of access and of actions with legal relevance (`audit_log`).

**What it does NOT process, and cannot process:**

- **Nothing biometric** (ADR-009). No fingerprint, no face, no voice. It is not
  a disabled option: it does not exist.
- **No geolocation** of individuals.
- **No special-category data** under art. 9 GDPR.

**Legal basis**: compliance with a legal obligation of the employer
(art. 6.1.c GDPR, in connection with art. 34.9 ET). **It is not consent**, and
this matters: nobody can refuse to clock in, and there is no need to ask
permission to record the working day.

### The only thing that leaves the server on its own: the incident email

Everything else stays in your installation. **Every night** the system sends a
summary of the pending incidents to the **manager of each department**, and
that email carries **the date, the person's name and the incident type** of
each line. Nothing else: no specific hours, no contract data, no full
working-time record.

Three consequences that are yours, not the vendor's:

1. **If the email is delivered by a third party's server** — Microsoft 365,
   Google Workspace, your hosting provider's SMTP — that third party is a
   **processor** (encargado del tratamiento) (art. 28 GDPR). You need the
   signed data processing agreement and it must appear in your record of
   processing activities. If you use your own internal mail server, there is no
   new processor.
2. **Configure the encrypted channel.** In the configuration file,
   `MAIL_SCHEME=smtps` forces TLS and **fails** if the mail server does not
   support it, which is what you want. Leaving it empty negotiates encryption
   "if possible", and with a relay that does not offer it the email travels
   across the network in the clear.
3. **Every send is recorded** in the audit trail, with who received it and
   which people it concerned. That is what lets you answer if one day you have
   to reconstruct where some data went out.

The notice is a convenience, not the record: **no incident is lost or changes
state because the email does not go out**. They all remain in the panel's
inbox, which is where they are worked on, and the next night's summary includes
them again. If you decide not to use the email channel, discuss it with whoever
installs the system for you: it is a configuration decision for your
installation.


**And a second channel, which ships switched off: telemetry.** The product can
send to a destination you set a **weekly** report with versions, licence
status, installation size in bands and aggregate counters; **never** data about
people or working days, nor names, nor emails, nor the company name. It is
disabled by default and is only enabled if you decide so in the `.env`, with
the exact list of fields in front of you
([`configuration.md`](configuration.md) §3 quinquies); `php artisan
product:telemetry` shows you the document that would be sent before enabling
anything. If you enable it, note it in your record of processing activities as
what it is: a transfer of technical data, with no personal data, to a
destination you choose.

### The data protection impact assessment (DPIA): advisable, and the decision has to be written down

Art. 35 GDPR requires a **data protection impact assessment** (DPIA, EIPD)
when the processing entails a high risk to people's rights, and expressly cites
the **systematic monitoring** of individuals. A working-time record monitors
your entire workforce every day, so the question has to be asked.

**With this product, the usual answer is that it is not mandatory, but it is
advisable.** The factors that trigger high risk are absent: there is no
biometrics (ADR-009: it is not a disabled option, it does not exist), no
geolocation of individuals, no profiling or automated decisions, and the data
does not leave your infrastructure — with the sole exception of the incident
email described above. What remains is the minimum data — who clocks in, when
and on which device — processed under the legal basis of art. 6.1.c.

**What you do have to do**, and it is yours because you are the controller:

- **Leave a written record of the analysis**, even if you conclude that it is
  not required. A DPIA that was not done and that nobody reasoned about is
  indistinguishable from an oversight; half a page, dated, with the reason is
  enough.
- **Redo the analysis if you combine KronoQR with something else**: video
  surveillance, door access control, fleet geolocation or any system that,
  cross-referenced with the timestamps, makes it possible to reconstruct a
  person's movements around the site. The risk is not created by this product,
  it is created by the combination, and then the DPIA usually is required.
- **Consult the workers' legal representatives**: art. 34.9 ET itself asks for
  it for the organisation of the record, and their opinion forms part of the
  analysis.
- If, when you carry it out, you find a high risk you cannot mitigate, there is
  **prior consultation** with the Spanish Data Protection Authority (AEPD)
  (art. 36 GDPR) before starting to process.

The vendor **cannot carry out this assessment for you**: it depends on your
site, your workforce and what other systems you have (ADR-020). What it does
deliver is the material to do it: what data the product processes (above), how
long it keeps it (§4), who accesses it and with what record (§5) and what
security measures exist ([`hardening.md`](hardening.md) and §5 of this
document; the vendor provides a summary of the product's security measures on
request).

---

## 3. Inform the workers and give them access

- **Inform** the staff, before going live, of what is recorded, for what
  purpose, how long it is kept and before whom to exercise their rights
  (arts. 13 and 14 GDPR). It is your communication, not the product's.
- **Consult the workers' legal representatives** on the organisation and
  documentation of the record, as art. 34.9 itself requires.
- **Give access to their own record**: the employee portal exists for that
  purpose (employee code and PIN, ADR-015). That it exists does not replace
  informing people that it exists.
- **Handle the data subject rights requests** (access, rectification, erasure,
  restriction, portability, objection) with the procedure in
  [`docs/runbooks/solicitud-derechos-rgpd.md`](../../runbooks/solicitud-derechos-rgpd.md)
  (in Spanish). You have **one month** to respond.

---

## 4. Keep, and delete when the time comes

**Keeping for too long is also non-compliance.** The GDPR (art. 5.1.e) requires
that personal data not be kept longer than necessary, and after four years the
working-time record no longer has a purpose to support it.

Policy per data type, which is the one the system applies:

| Data | Period | Who sets it |
| --- | --- | --- |
| Working-time record and `audit_log` | **4 years** | The site's compliance profile (jurisdiction) |
| Technical log | **90 days** | Your installation |
| Error history | **90 days** | Your installation |
| Backups | 30 days by default | Your installation (`BACKUP_RETENTION_DAYS`) |
| Contract data (agreed hours, type of working day, validity period) | **Employment relationship + 4 years**, indicative | **Pending confirmation with your employment law advisers.** Today **it is kept**: the system does not purge it |

**Contract data still has no automatic purge, and that is deliberate.** The
indicative period — the duration of the employment relationship plus four
years, by reference to art. 21 of the LISOS (Ley sobre Infracciones y Sanciones
en el Orden Social, the Act on Infringements and Penalties in the Social
Order) — **is not validated**, and deleting on the basis of a period that later
turns out to be too short is worse than keeping for a bounded time too long.
Confirm it with your employment law advisers; until then that data remains and
appears in any response to an access request.

**Purging is never automatic** (RF-PR-03). Every week the system **proposes**
and leaves a report; deleting requires explicit confirmation from the person
responsible and a database credential that **is not in the application
configuration**. The full procedure is in
[`operation.md`](operation.md) §3.

**What you have to do:**

1. Decide **who** authorises a purge. It must be a person, with name and
   position, not "the IT department".
2. **Read the report before authorising.** It says which tables, which date
   range and how many records.
3. **File the purge report** together with the authorisation. It is what
   proves, two years later, that what had to be deleted was deleted, and only
   that.
4. **Keep the password of the `fichaje_maintenance` role** off the application
   server — password manager, sealed envelope, whatever you use for the rest of
   your critical credentials.

---

## 5. Keep the proof that the record has not been tampered with

The system hash-chains every action with legal relevance and **verifies the
chain daily**. If that verification fails, it is not a malfunction: it is a
security incident, and it has its own procedure
([`rotura-cadena-auditoria.md`](../../runbooks/rotura-cadena-auditoria.md), in
Spanish).

What falls to you:

- That **someone receives** the alert and knows it is critical.
- **Do not give the application database permissions it does not need.** The
  product is installed with three separate roles for this reason; if someone
  "simplifies" by giving the application the owner role, the guarantee ceases
  to exist without anything visibly failing.
- **Do not edit the database by hand.** No legitimate correction needs it, and
  any made that way will show up the next day as a broken chain.

---

## 6. Backups and continuity

Keeping the record for four years means being able to **recover it**. The
product takes a daily and a weekly backup and verifies that they can be
restored, but:

- **Take them off the server.** A copy on the same disk as the database is not
  a backup.
- **Run the quarterly restore drill**
  ([`restaurar-backup.md`](../../runbooks/restaurar-backup.md), in Spanish). A
  backup that has never been restored is a hypothesis.
- **Align the expiry**: backups expire after 30 days by default, so an erasure
  applied today disappears completely within a month. Say so when you respond
  to an erasure request.

---

## 7. Adjusting the compliance profile to your collective agreement is your job

The system ships with the **`ES-hosteleria`** profile, whose thresholds come
from the Workers' Statute: **12 h** of rest between working days (art. 34.3),
**9 h** of ordinary daily working time, **6 h** in a continuous shift entry
before requiring a break (art. 34.4), **40 h** of weekly working time
(art. 34.1) and **4 years** of retention (art. 34.9).

**That profile is a legal starting point, not your collective agreement.**
Provincial and company collective agreements often set different — and more
favourable — working hours, rest periods and calculation rules than the legal
minimum. Checking which ones apply to you and writing them into the profile is
**your responsibility**, not the vendor's: you are the one with the collective
agreement in front of you and the one who answers to the Inspectorate and to
your staff.

| Obligation | What the system does | What you have to do |
| --- | --- | --- |
| Apply the thresholds of your collective agreement | Reads them from an editable row, never from code, and applies them from the moment of the change | **Check the profile against your collective agreement** when commissioning the system and every time it is renewed |
| Load the site's public holidays | Stores the calendar and audits it | Load it every year: public holidays depend on the municipality and the year, and the product ships **with none** |
| Justify why a working day did not raise an alert | Stores every threshold change with its previous value, its author and its time | Know where that record is and be able to show it |
| Keep for the right period | Takes the years from the profile and never purges without explicit confirmation | **Do not lower `retention_years` unless your advisers say so**: below the legal period you would be destroying evidence |

**What the vendor does NOT do, and it is worth having it in writing:** it does
not validate your collective agreement, it does not warn you that a threshold
is more lenient than the one that applies to you, and it cannot know which
collective agreement applies to you. The product makes it possible to comply;
the decision on which number to enter is the hotel's, with its employment law
advisers (RL-16, RL-21).

> **If you change a threshold, the change applies from that moment.** The
> history is not recalculated and incidents already open are not closed. The
> procedure and the reasoning are in [Configuration](configuration.md),
> section 2.4.

### The setup wizard forces you to look at it, and that is the only step that cannot be skipped

Of the wizard's eight steps, the licence can be skipped, the kiosk can be
skipped and the staff upload can be skipped. **The collective agreement profile
cannot.**

It is not rigidity: that step is the only guaranteed moment at which someone in
your organisation has those five numbers in front of them before the system
starts calculating hours with them. Confirming it does not mean "I have
validated them with my advisers" — that remains yours — but "I have seen them
and I know they exist".

Who confirmed it and when is recorded, just like any later change.

---

## 7 ter. Loading the staff from a file is not publishing data

If you use the bulk upload (CSV or Excel), three things worth being clear
about, because they affect what you can state in a data protection audit:

- **The identity document is not stored.** Its cryptographic fingerprint is
  stored, which serves to recognise the same person across two imports and to
  cross-reference with payroll, and **the number cannot be read back**. If a
  backup ends up where it should not, there are no identity documents in it
  (RL-08).
- **The file does not stay on the server.** It is read during the request and
  disappears with it. That is why it has to be uploaded again to confirm: the
  product does not keep a file with the names and identity documents of your
  staff waiting for someone to press a button.
- **Nobody receives any email.** Neither the imported people nor their
  managers. The credential is a physical card that has to be printed and
  handed over in person, and the product does not send invitations to anyone.

**What remains yours:** informing the staff of the processing before starting
(section 3) and deleting from your own computers the file you imported from,
which does carry the documents in the clear.

---

## 7 bis. Your obligation to keep the record does not depend on the licence

It is worth knowing before you need it, because it is the first fear when an
expiry notice arrives.

**Art. 34.9 ET requires you to keep the daily working-time record of your
entire workforce, and that record is yours, not the vendor's.** This product is
built so that no commercial decision can leave you non-compliant:

- With the licence **expired, absent or unreadable**, clocking in continues,
  the record can still be consulted, it can still be exported for the Labour
  Inspectorate, the employee portal remains open (RL-05) and backups keep
  being taken.
- **Exceeding the plan limits does not block anything either.** You can
  register the person who starts today even if you are above what you
  contracted, and they can clock in from day one. If the product prevented
  you, that person would be working without a record and the infringement
  would be **yours**.
- What does happen is warnings, a cutback of **accessory** features — the
  reports by period and the real-time presence update — and an entry in the
  audit log with the date from which you are out of contract.

**None of this exempts you from the part that remains yours**: paying for the
licence if you have contracted it, and keeping the record for four years even
if the commercial relationship ends. For the latter, the product includes a
full data export that you can run at any time and take with you (§7 quater).

> If you ever find that **you cannot clock in or cannot access the record** and
> the cause is the licence, **that is not by design**: it is a malfunction.
> Notify the vendor, attaching the output of `php artisan license:show` and of
> `GET /api/v1/health`.

---

## 7 quater. Taking all your data with you (RL-20)

Your obligation to keep the record for four years does not end when the
contract with the vendor ends, nor when you change products. So that this
depends on nobody, KronoQR includes a **full data export**: a single ZIP file
with **everything** in your installation, in open formats (one CSV per table,
JSON for the configuration) and with a `README` that explains each file and
each column. You can generate it whenever you want, without asking anyone's
permission, and **it works just the same with the licence expired, absent or
unreadable** (§7 bis).

**What it contains.** Everything that is yours: staff and contracts, cards and
kiosks, the shift entries with **all their versions** — every correction keeps
the previous one, with who made it and why (§5) —, the daily totals, the
incidents, all the scans, the complete audit log **with its hash chain** (you
can verify outside the product that nobody has altered it), the management
accounts, the support accesses, the configuration, the compliance profile and
the licence data. **No secrets**: no passwords, no PINs, no hashes, no licence
key. No internal numbers: references between files use public identifiers
(`uuid`), the same ones as the API.

**What it implies.** It is a complete copy of the personal data of your entire
workforce, so you treat it as such:

- Only the **installation administrator** can request it; not HR, not the
  auditor, and **vendor support never**, under any scope.
- Requesting it, generating it and **every download** are recorded in your
  audit log (`data_export.requested`, `data_export.generated`,
  `data_export.downloaded`): in the event of a personal data breach you can
  answer who took what and when (§2).
- The file **expires**: after seven days (configurable) the system deletes it
  from the server; the note that it existed is kept.
- Once outside the system, the file is yours and so are the obligations that
  come with it: encryption, custody and deletion when the retention period
  ends (§4). Do not send it to anyone without a basis for doing so.

How it is generated, how it is taken off the server and when it is better to
do it from the console rather than the panel: [`operation.md`](operation.md)
§13.

---

## 8. What the vendor cannot do for you

| Cannot | Why |
| --- | --- |
| Go in and look at your data | The system runs on your server and there is no remote access by default (ADR-016, ADR-020) |
| Handle a data subject rights request | You are the controller |
| Answer a requirement from the Inspectorate | The hotel signs it |
| Recover data already purged | The purge is irreversible; that is what the confirmation is for |
| Tell you which thresholds your collective agreement sets | It does not know it; the compliance profile is yours (§7) |
| Switch off your clocking-in over an unpaid licence | The mechanism does not exist: there is no way to express the deactivation of the legal record, neither by mistake nor on purpose (§7 bis) |
| Revoke your licence remotely | Verification is local and without internet: your installation queries nobody. The lever is the expiry of the key and the contract |
| Keep your data or make it hard for you to take it with you | The full data export is yours: it does not depend on the licence, you request it and support cannot generate it under any scope (§7 quater) |


If you need support with an incident, the diagnostic bundle is **anonymised by
default** (RL-19): including personal data is your decision, express, warned
on screen and noted in your audit log; and any extended access is express,
temporary and audited.

### Who is what when there is support (RL-16, RL-17, RL-18)

| Situation | The hotel | The vendor |
| --- | --- | --- |
| Ordinary operation | **Controller** and operator: hosts the data, controls access, answers to the Inspectorate and to the staff (RL-16) | **Is not a processor**: it neither hosts nor accesses the data (RL-17). There is no data processing agreement to sign in order to use the product |
| You send an **anonymised** diagnostic bundle | You remain the controller; you have not disclosed personal data | Receives version, configuration without secrets, service status, tablet health and counts. **No personal data**, and an automated test in the product guarantees it |
| You send a bundle **with personal data** ("Include personal data" checkbox, or `--with-personal-data`) | You decide to disclose data to a third party for a specific purpose: it is recorded in your audit log (`diagnostics.personal_data_included`) and you must be able to justify it | **Processor for that specific case** (RL-18): it may only use the data for that incident, under confidentiality, and must delete it when finished |
| You grant **support access** (panel → "Support", or `support:grant`) | You set the reason, the scope and the duration; you can revoke it at any time; your audit log keeps the grant, the revocation and **the activity of the access grouped into 15-minute windows**, with the family of routes used in each window —not one line per request—, and the panel tells you when it was last used | **Processor for that specific case** (RL-18), with the same obligations; the access expires on its own and cannot extend itself |

For the last two cases a **data processing agreement** (art. 28 GDPR) limited
to support is needed, with documented instructions, confidentiality and a
prohibition on retaining data when finished. The vendor delivers it with the
product contract; if you do not have it signed, ask for it **before** ticking
the checkbox or granting the access, not after. The anonymised bundle does not
need it: it is the normal support route, and that is why it is the default.
