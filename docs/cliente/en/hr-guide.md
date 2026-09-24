# HR guide — the working-time record, day by day

This guide is for whoever **operates the product day to day**: adding a person,
handing them their card, watching the incident inbox, correcting a missed
clocking and answering a requirement from the Labour Inspectorate. **You do not
need to know anything about systems.**

> **What is NOT here, on purpose.** Installing, backups, updates and
> diagnostics belong to the IT staff and live in
> [`operation.md`](operation.md). The parameters and their consequences, in
> [`configuration.md`](configuration.md). What the law requires of the hotel, in
> [`legal-obligations.md`](legal-obligations.md). Each thing is explained in one
> single place; here it is linked.

The ten sections of this guide, in case you are looking for one in
particular:

1. [Vocabulary first](#1-vocabulary-first)
2. [Adding a person, from start to finish](#2-adding-a-person-from-start-to-finish)
3. [Live presence and the time record](#3-live-presence-and-the-time-record)
4. [The incident inbox](#4-the-incident-inbox)
    - [4 bis. The compliance view](#4-bis-the-compliance-view)
5. [Corrections: changing an hour without breaking the record](#5-corrections-changing-an-hour-without-breaking-the-record)
    - [5 bis. Absences: holidays, sick leave and time off](#5-bis-absences-holidays-sick-leave-and-time-off)
6. [Reports, exports and the Labour Inspectorate hand-over](#6-reports-exports-and-the-labour-inspectorate-hand-over)
7. [The compliance profile](#7-the-compliance-profile)
8. [What to do if…](#8-what-to-do-if)

---

## 1. Vocabulary first

Nine words. Without them, the rest of the guide has to be read twice.

| Word | What it is | Where you see it |
| --- | --- | --- |
| **Clocking** | The act of holding the card up to the tablet. It is always recorded, whether it is accepted or not | Kiosk |
| **Shift entry** | A clock-in/clock-out pair. It is the smallest unit of the record: what gets corrected, voided or added is an entry | Time record |
| **Working day** | The set of entries for one day. **A night shift that clocks in at 22:00 and clocks out at 06:00 is a single entry and belongs to the day it started on**, it is not split in two | Time record |
| **Day total** | The sum of the entries of a working day. It recalculates itself; you never have to touch it | Time record |
| **Incident** | Something in the record that **a person has to look at**. It is not a system error | Incident inbox |
| **Correction** | The change to an entry made by an authorised person, with a reason and a signature. It never deletes what was there before | Time record |
| **Credential (card)** | The link between a person and their physical card with its QR code. It is issued, printed, handed over and can be revoked | Credentials |
| **Kiosk** | The tablet fixed to the wall where people clock | — |
| **Employee portal** | The website where each person consults and downloads **their own** record. It is a legal obligation, not a courtesy | [`employee-portal-guide.md`](employee-portal-guide.md) |

**Two ideas that explain almost everything the product does:**

- **Nothing is deleted and nothing is overwritten.** Correcting creates a new
  version and keeps the previous one, with who, when and why. That is what
  makes the record hold up in an inspection.
- **The system never decides on behalf of a person.** It does not close shifts,
  it does not invent clock-out times and it does not resolve incidents by
  itself. When something does not add up, it puts it on the table and waits.

---

## 2. Adding a person, from start to finish

The whole journey, in the order in which it is done. At the end of it, the
person can clock and consult their record.

> **Start with a few days to spare.** Between issuing the card and having it
> printed and in hand, real time goes by: it has to be printed and laminated.
> Somebody who starts working without a card clocks all the same —with their
> code and their PIN— but those are entries that somebody will have to review
> afterwards.

### 2.1 The record

**Workforce → "Add employee".**

![Workforce screen with the add employee button](../img/en/rrhh-01-empleados.png)

You fill in first name, last name and hire date. Everything else is optional
and it is worth knowing why:

| Field | Required | What is worth knowing |
| --- | --- | --- |
| First name and last name | Yes | — |
| Hire date | Yes | Legal retention of their record counts from it. Changing it later is done deliberately, not in passing |
| Department | No | It is used to filter the inbox and the reports, and for the scope of department managers |
| Email address | **No** | **No feature of the product needs it.** The card, the PIN and portal access are never sent by email |
| National identity document | No | **It is not stored as such**: the server keeps only a digest calculated from it, which serves to avoid adding the same person twice and not to read it back |
| Language | No | The language in which they will see the portal and in which their instructions sheet is printed |

**The employee code is generated by the system**, and it is opaque on purpose:
it is printed on the card, so it cannot be the payroll number or anything else
with a meaning. It cannot be chosen.

![Employee creation form](../img/en/rrhh-02-alta-empleado.png)

### 2.2 The PIN: shown once only

When the person is added, the system issues the **six-digit PIN** for the
portal and **shows it once only, at that moment**. It cannot be looked up
afterwards: if it is lost, the only way out is to reset it, which generates a
different one and voids the previous one on the spot.

![Dialog with the PIN, shown once only](../img/en/rrhh-03-pin-una-vez.png)

Have somewhere to write it down **before** pressing "Add employee". The PIN
serves two purposes: signing in to the portal, and clocking at the tablet when
the card is not to hand.

**The PIN is handed over in person, face to face.** There is no electronic
delivery at all: no message, no recovery link. It is not an oversight: a key
that travels through a mailbox ends up clocking for its owner without anyone
noticing.

### 2.3 Contracted hours

The hours-per-period report compares what was worked against **what was agreed
for that day**, and for that it needs the person's contract. Today the **panel
has no contracts screen**: whoever administers the system can record them, but
it is not done from the panel. As long as there is no contract on record, the
period report says so clearly —"there are X person-days with no contract on
record"— and those rows come out with the deviation incomplete. The hours
worked and the legal record **are not affected**.

### 2.4 Issuing, printing and handing over the card

**Credentials.** This is the board where you see who can clock and who cannot
yet.

![Credentials board](../img/en/rrhh-05-credenciales.png)

There are three acts, in this order, and each one changes the status:

| Act | Button | Status when finished | Can they clock? |
| --- | --- | --- | --- |
| **Issue** | "Issue credential" | Waiting to be printed | **No** |
| **Print** | "Print the card" | Waiting to be handed over | **Yes, as soon as they receive it** |
| **Hand over** | "Record the handover" | Handed over | Yes |

> **Printing is what activates the card, and there is no reprint.** The QR code
> does not exist until "Generate the PDF" is pressed: it is minted at that
> moment, inside the PDF, and **it is not stored anywhere it could be taken
> from again**. That is why the button warns you first: *"Printing mints the QR
> and there is no way back: reprinting does not exist."* If the PDF is lost
> —the window is closed, the printer fails, it is downloaded to a computer that
> is not yours—, the only way out is to **revoke that credential and issue
> another one**:
> [`../../runbooks/tarjeta-perdida-o-rota.md`](../../runbooks/tarjeta-perdida-o-rota.md)
> (in Spanish). **Print only with the printer ready.**

**The card PDF is a bearer document**: whoever has it can manufacture somebody
else's card. It is not stored on the server, it is never sent by email, and it
is worth deleting it from the computer as soon as it has been printed.

For a seasonal intake, the **"Print the pending cards"** button produces every
card waiting to be printed on a single A4 sheet. The same warning applies,
multiplied by the number of cards.

### 2.5 The instructions sheet

On the same credentials board there is **"Instructions sheet"**, with a
**"Download in …" button for each language active** in the installation. It is
a one-side PDF, the same for the whole workforce, with the hotel's branding and
the address of **this** portal. It is printed and handed over with the card.

What it says exactly, and why it is worth reading once before handing it out:
[`employee-sheet.md`](employee-sheet.md).

### 2.6 The handover: a single act

**The card, the PIN and the sheet are handed over together, in person, at the
same moment.** The handover dialog says so on screen. Afterwards the two
handovers are recorded —"Record the handover" for the card and "Record the PIN
handover"—, and they are logged with the date and with you as the person
responsible.

![Employee record showing the status of their card and their PIN](../img/en/rrhh-04-ficha-empleado.png)

**This is not bureaucracy.** That log entry is what tells "the card was lost
before we gave it to them" apart from "the employee lost it", and it is what
answers, months later, why a person could not clock on a Tuesday. It cannot be
repeated or undone: mark it only once the handover has actually happened.

### 2.7 What that person will see when clocking

It is worth having seen it once so that you can explain it without the tablet
in front of you.

A correct clocking: the screen says "Clock-in" or "Clock-out" with the time.

![Kiosk with a confirmed clocking](../img/en/quiosco-fichaje-confirmado.png)

The tablet had no network: it says "Pending validation". **The clocking is
saved and it will be sent by itself.** It does not have to be repeated, and the
legal record uses the real time of the clocking, not the time it reached the
server.

![Kiosk with a clocking pending validation](../img/en/quiosco-fichaje-pendiente.png)

Without the card to hand: "Clock in with your code and PIN" on the tablet
itself.

![Kiosk with the code and PIN keypad](../img/en/quiosco-pin-respaldo.png)

---

## 3. Live presence and the time record

### 3.1 Presence: who is in right now

**Presence** shows who has an open shift at this moment, since what time and
through which kiosk they clocked. It updates by itself.

![Live presence screen](../img/en/rrhh-06-presencia.png)

**What "In now" means:** that this person clocked in and has not clocked out
yet.

**What it does NOT mean:**

- It does not mean they are physically in the hotel. It means their last
  clocking was a clock-in. Someone who left without clocking out keeps showing
  as in until somebody corrects it.
- It does not mean the hours already count. An open shift keeps growing: its
  total **cannot be used for payroll** until it is closed.
- **It is not the screen for fixing anything.** Presence is read-only.
  Correcting an entry is done from the person's time record.

### 3.2 A person's time record

From their record, **"View this person's time record"**. This is the screen
where the truth of each day is seen —and corrected.

![Detail of a working day with its entries](../img/en/rrhh-07-jornada.png)

What to look at:

- **The times are in the site's time zone**, not in the one of the computer you
  are looking from.
- Each entry carries its **source**: "Card scan", "PIN at the kiosk" or
  "Entered by hand". An entry written by hand is worth exactly as much as a
  scanned one; the difference is that it has a correction behind it explaining
  it.
- **"Open shift"**: the clock-out is missing. The day total is going to go up.
- **"Incident"**: some entry was flagged for review. It is not a system error:
  it means someone has to look at it.
- **Consulting somebody else's record is logged** in the audit log, with who
  looked, at whom and at which period. That is normal and it is intentional.

### 3.3 The break in the record

If the hotel has **break clocking** turned on, a working day with a break is not
shown as one entry with a hole inside it: it is shown as **two entries with the
"Break" mark between them**. That is all that changes, and it is what you need
to know in order to explain it to somebody:

- **The break does not count as worked time.** There is no subtraction: the
  break time is simply not inside any entry, so the day's total comes out right
  without touching anything.
- **A break in the early hours does not split the working day.** Somebody who
  clocks in at 22:00, takes a break at 02:00 and comes back at 02:30 is still in
  the previous day's working day, and the next day comes out at zero. It is the
  same rule as always: the working day belongs to the day it started.
- **But a break does not stay open for ever.** The return continues the previous
  working day only if it arrives **before** the minimum rest between working
  days you have set in the profile (`min_rest_hours`, 12 h out of the box —§7—).
  After that time, the next clocking **opens a new working day**, which is the
  right thing: somebody who pressed "Break" at 15:00 and clocks in the next day
  at 08:00 is starting their shift, not coming back from a seventeen-hour break.
- **A break with no return opens no incident.** If somebody presses "Break",
  scans their card and goes home, the working day is **closed at that moment**,
  with the "Break" mark and with no return entry. The system neither invents a
  clock-out time nor warns you: **it is corrected by hand like any other entry**
  (§5), and the usual reason is "Forgotten clock-out". Look at it when a day's
  total comes out shorter than expected.
- **It is told apart from an unexplained gap.** Two entries in a row without the
  "Break" mark are something else —a clock-out and a clock-in—, and that is
  exactly what break clocking is there to clarify.
- **A badly clocked break is corrected like any other entry**, from the same
  screen and with its reason (§5). There is no separate procedure: what has to
  be corrected is a clock-in or clock-out time, as always.
- **If the hotel does not have break clocking turned on, nothing changes here**
  and you will see no mark.

---

## 4. The incident inbox

**Incidents** is the list of what the system has found and cannot resolve on
its own. It fills itself every night, when the record is reviewed.

![Incident inbox](../img/en/rrhh-10-incidencias.png)

### 4.1 What generates each type, and which one is urgent

| Type | Severity | What generates it | What it usually means |
| --- | --- | --- | --- |
| **Insufficient rest** | **High** | Between the end of one shift and the start of the next there are fewer hours than the profile's minimum (12 h out of the box) | A closing and an opening back to back, or a missed clocking that joins two working days. **It carries a penalty risk: look at it the same day** |
| **Open shift not closed** | Medium | A shift has been open for longer than the maximum (12 h out of the box) | Almost always, a forgotten clock-out |
| **Shift too long** | Medium | The day's sum, or a single entry, exceeds the profile's maximum working day | Real extra hours, or two entries that were really one |
| **Shift too short** | Low | An entry below the minimum countable duration | A double scan, or a clock-in and a clock-out one after the other by mistake |
| **Clock skew** | Low | The tablet's clock was off when the clocking happened | **The clocking was recorded all the same.** It is a warning for IT about that tablet, not a problem for the person |
| **Missing clock-out** | Medium | It describes a forgotten clock-out **already closed by hand** | **Nobody opens it automatically.** While the shift is still open, what you have is "Open shift not closed" |
| **No break registered** | Medium | A continuous entry above the collective agreement's threshold | **It only opens by itself if the hotel has break clocking turned on.** Without it, the system cannot tell "they did not rest" from "they rested and did not clock it", and warns about none |
| **Out-of-order clocking** | Medium | A clocking arrived that does not fit that person's record: a **clock-out** with a time earlier than the clock-in that was already open, or a **clock-in** that would fall inside or before an entry that is already closed —even if that entry belongs to another working day, such as after a night shift— | Almost always, a tablet that was offline: its queue arrived late and out of order. **The clocking is kept and flagged for review, and the working day does not change on its own** (§4.4) |
| **Anomalous credential usage pattern** | High | Two cards of two different people are scanned **at the same tablet seconds apart on several days** (3 out of the box), or the **same card** is scanned at **two different tablets** sooner than it takes to walk from one to the other | Almost always, two colleagues who come in together, or two tablets too close to each other. **It is a clue for a person to look at, not a conclusion.** It changes no clocking and nobody outside the inbox sees it (§4.5) |

> **The "Type" filter shows all nine, and how many open by themselves depends
> on a setting.** Seven always do —insufficient rest, open shift, shift too
> long, shift too short, clock skew, out-of-order clocking and anomalous
> credential usage pattern—, and **"No break registered" joins them as soon as
> the hotel turns on break clocking** (Panel → "Operational settings" → "Break
> clocking"; it is explained in
> [`configuration.md`](configuration.md) §2.1). "Missing clock-out" is in the
> list because the system has to be able to record it without changing anything
> when its time comes. It is not a fault in the installation.
>
> **Turning break clocking on does not flood the inbox at once.** The review
> starts opening "No break registered" on its next pass and only over the last
> few days; it does not go back over history. And turning it off **does not
> close** the ones already open: no new ones are opened and the existing ones
> are resolved like any other.
>
> **Two things the inbox will NOT tell you, worth knowing so you do not wait for
> them:** the gap between two entries of the same working day —the split working
> day— **is not looked at** (§4 bis.2), and a **break with no return** —somebody
> presses "Break" and goes home— **opens no incident**: the working day is
> closed there and it is corrected by hand (§3.3).

### 4.2 The system never closes a shift on its own

It is the question that always comes up: *"if it has been open for 14 hours,
why does the system not close it?"*.

**Because closing it would mean inventing a clock-out time.** If the system
closed the shift at 12 h, the record would say that this person worked 12 hours
when they probably worked 8 and forgot to clock. A working-time record that
invents hours is not defensible before the Labour Inspectorate, and on top of
that it overpays or underpays the payroll.

What the system does is open the incident and wait for **a person to sign** the
correct time. It is a guarantee, not a shortcoming: every hour in the record
was either clocked by somebody or written by somebody with their name next to
it.

In the meantime, **nobody is left unable to clock**: whoever has the open shift
carries on using their card as normal, and their next scan will close that
shift.

### 4.3 How one is resolved

An incident is closed in two steps, and the order matters:

1. **First the record is fixed**, if there is something to fix: the entry is
   corrected from the person's time record (§5).
2. **Then the incident is closed**: "Resolve" button, and you choose what
   happened.

![Incident resolution dialog](../img/en/rrhh-11-resolver-incidencia.png)

There are two outcomes and they are different:

- **"It has been corrected"** — something was wrong and it has been put right.
- **"Reviewed: there was nothing to correct"** — the data was odd but true. An
  11-hour shift can be true.

**The note is required** and it stays in the incident's history. Write what was
done, or why nothing needed doing: six months from now, "reviewed" explains
nothing and "swapped shifts with the afternoon colleague, confirmed with the
head waiter" does.

> **Resolving an incident does not change any hour.** They are two separate
> actions on purpose: closing the inbox without correcting the record leaves
> the inbox clean and the record wrong.

If two people resolve it at the same time, the system tells you who closed it
first and with which outcome, instead of overwriting anybody's work.

### 4.4 "Out-of-order clocking": a clocking that does not fit the working day

It is the only type in the table that does not describe an excess or a
forgotten action, but a clocking that **arrived late and out of order**, so it
deserves its own section.

**What happened.** When a tablet loses the network it does not stop clocking:
it saves every scan with its **real time** and sends them as soon as it gets
the network back. Almost always they fit without further ado. Every now and
then one arrives that **does not fit into any working day**, and it happens in
two ways:

- **A clock-out earlier than the clock-in that was already open.** The person
  has a shift open since 14:00 and, from the queue of a tablet that was offline,
  their 13:40 clock-out arrives. It would be an entry that ends before it
  begins.
- **A clock-in that falls inside or before an entry that is already closed.**
  The person clocked from 09:00 to 13:00 on the reception tablet and, in the
  afternoon, the kitchen tablet empties its queue with an 08:00 clock-in. It
  would be two entries stepping on the same stretch of time.

In both cases the result would be an impossible record, and **there is no hour
the system can calculate** out of that clocking. So it does not invent one.

**What the system does with it.** Three things, and all three are worth
knowing:

- **It keeps it**, with its time exactly as it arrived, and **flags it for
  review**. It is not silently discarded, and it is not adjusted to make it
  fit. That time reaches you in the incident, not in the working day: the
  reason is just below.
- **It does not touch the working day.** That day's entries and totals stay
  exactly as they were. Nothing is closed, nothing is invented.
- **It does not retry it.** The tablet stops insisting on that clocking and its
  queue empties normally; the rest of the clockings in that same queue are
  recorded without any problem.

The following night, the review opens the **"Out-of-order clocking"** incident
for that person and that working day: one only, even if several arrived.

**Where the time is: in the incident, not in the time record.** It is the first
thing to know, because it saves you looking where it is not. A person's time
record shows **the entries of their working day**, not the scans: that clocking
**does not appear there**, and the working day looks exactly as it did before it
arrived.

What does carry the data is the **incident itself**. Press "Resolve" on its row
and, above the form, the **"Close incident"** window shows:

| What it says | What it is |
| --- | --- |
| **Clock time** | The real time it arrived with, **in the site's time zone**. It is the clue to what really happened |
| **Scan ID** | The code of that particular clocking. Copy it if you are going to write a report or ask IT |
| **Out-of-order scans** | How many arrived like that on that working day. The incident is a single one even if there were several, and the time shown is that of the first |

**Opening that window does not resolve anything**: you can read the data and
close it without choosing an outcome or writing a note. Resolving is pressing
the confirm button, not opening the dialog.

**How it is resolved.** Four steps, and the order saves work:

1. **Open "Resolve" and note down the clock time.** Close the window without
   confirming.
2. **Open the person's time record** for that working day and compare that time
   with the day's entries. If you need to, ask the person or their manager.
3. **Correct the record** (§5) with whichever action applies: **"Add an entry"**
   if an earlier clock-in was missing, **"Correct the times"** if the existing
   entry does not have the real hours, or **"Void the entry"** if that is the
   surplus one. The reason is usually **"Kiosk technical failure"**, **"Missed
   clock-in"** or **"Duplicate scan"**.
4. **Go back to "Resolve" and close the incident** with its note (§4.3). If in
   the end the record was right, "Reviewed: there was nothing to correct" is a
   legitimate outcome and it stays explained.

> **The legal record does not change on its own here either.** The system
> records what arrived and warns; the hour is signed by a person, with their
> name and their reason, and the previous value is kept (§5.3).

**If it always happens on the same tablet** it is not a problem with the staff,
it is the network or the clock at that point: pass it on to IT with the kiosk's
name and the date. The procedure is
[`../../runbooks/cola-offline-atascada.md`](../../runbooks/cola-offline-atascada.md)
(in Spanish).

> **What happened before this version is not in the record.** Until now a
> clocking like this never got saved: the tablet retried it over and over and
> the server never accepted it. **There is nothing earlier to review or to
> recover**, and no past day is reprocessed.
>
> **But you will see incidents with old dates in the first few days, and that is
> not a mistake.** What decides whether a clocking is reviewed is **when it
> reaches the server**, not what its time says. Tablets that had been stuck with
> one for weeks hand it over as soon as the server is updated, so **they go into
> that very night's review** — and the incident is opened on **the working day
> that real time belongs to**, which may be the one from a fortnight ago. They
> are resolved just like today's: open, compare and correct. Once the tablets
> have finished emptying, this type shows up only now and then again.

### 4.5 "Anomalous credential usage pattern": what you will see and what to do

It is the only incident in the inbox that **talks about two people at once**,
and that is why it gets its own section. It is opened by a review of its own,
every night, over the clockings made at the tablets during the last 30 days.
There are two variants, and the **"Close incident"** window tells you which one
it is and shows you what was observed:

| Variant | What was observed | What the screen shows you |
| --- | --- | --- |
| **Coincidence at the same tablet** | Two different people scanned their card or PIN at **the same tablet less than 10 seconds apart** (adjustable), and that happened on **several days** (3 out of the box; it counts as at most one day even if they coincided twice the same morning) | The tablet; **the other person**, with a link to their inbox, and "and N more people" if there are several; **how many days had a coincidence against how many are needed**; the seconds window applied; and four figures instead of a list: the **first** and the **last** coincidence, the **gap on the last day** and the **smallest gap** in the whole series. That last one is the one that matters: 9 seconds every day is a queue; 1 second one day is a question |
| **Impossible sequence between two tablets** | The **same card** (or PIN) was scanned at **two different tablets** in less time than it takes to walk from one to the other (120 seconds out of the box, adjustable). Once is enough. The second scan that the tablet did not accept for coming too soon after the first counts too. Any clocking whose time is in doubt because that tablet's clock had drifted **is not looked at** | The two tablets, the moment of each clocking **with seconds**, the gap between them and the minimum transit that was applied |

**In the coincidence there is one incident per person**, each in the inbox of
the manager of **their** department, and each one names the person they
coincide with most and says whether there are more. If they belong to
different departments, each manager sees their own: talk to each other before
talking to anybody else, and piece the group together between you, not from a
single incident.

**And it does not come back every night.** While you have one of these open on
a person, no other one is opened on them. Once you close it, **3 new days** with
a coincidence (the ones after the closing) will be needed for it to show up
again: if the pattern was "they come in the same car" and it goes on, you will
see it again in a few days with new data, and you will close it the same way.

**What this incident does NOT say.** It does not say who lent anything to whom,
nor that anybody lent anything. Two colleagues who arrive in the same car and
walk in together produce **exactly** the same clue every day, and two tablets
at the same door produce "impossible" sequences that are perfectly possible.
The system cannot tell the difference from the tablet; you can, because you
have the rota and the shift leader.

**What the system does not do, and will not do:** it cancels or flags no
clocking, changes no hour, imposes no penalty, blocks no card and warns nobody
outside the inbox. It puts the clue in front of a person and stops there. It
is, on purpose, what there is instead of a machine deciding who scanned.

**What to do, in this order:**

1. **Rota.** Did the two people have the same shift on those days? If so, the
   coincidence is what you would expect.
2. **Shift leader.** Was the person at their post on those days? If they were,
   they scanned the card themselves.
3. **In the impossible sequence, look at the tablets first.** If you really can
   get from one to the other in less than the setting says, the problem is the
   setting: ask IT to lower it in Operational settings
   ([`configuration.md`](configuration.md) §2.1) and close the incident saying
   so.
4. **Only if something is still unclear after that** do you ask the person,
   openly and describing what was observed ("on these days your card and X's
   were scanned seconds apart; do you come in together?"). Whoever asks is the
   company following its own procedure —you, or HR—, **never "the system"**.
   And nothing is decided on the incident alone.
5. **Close the incident** (§4.3), almost always as "Reviewed: there was nothing
   to correct". **The note describes what you checked, not what you concluded
   about anybody**: "same morning shift according to the rota, they walk in
   together from the car park, confirmed with the head waitress" is useful two
   years from now; a label is not. The person can read that note if they ask
   for access to their data.

The full procedure, with what may be asked and what may not, is in
[`../../runbooks/patron-anomalo-credencial.md`](../../runbooks/patron-anomalo-credencial.md)
(in Spanish).

---

## 4 bis. The compliance view

**Compliance** reviews the record against the legal thresholds of the site's
profile and shows, for your scope, the working days and the weeks that fall
outside them. The inbox (§4) tells you **what is pending resolution**; this
screen tells you **whether what is recorded complies with the law**, whether or
not anybody has opened anything.

They are not the same list: the inbox has eight incident types and this screen
four legal rules. They overlap on the first two, and that is on purpose.

### 4 bis.1 The four warnings, and with which threshold

At the top there are four cards, one per rule, with the count for the period and
the threshold that has been applied:

| Warning | What it looks at | Profile field | Default in `ES-hosteleria` |
| --- | --- | --- | --- |
| **Minimum rest between shifts** | The hours between the last clock-out of one working day and the first clock-in of the next | `min_rest_hours` | 12 h |
| **Ordinary daily working time** | The sum of the shift entries of the same day | `max_daily_hours` | 9 h |
| **Maximum continuous stretch without a break** | The longest closed shift entry of the day | `break_required_after_hours` | 6 h |
| **Ordinary weekly working time** | The sum of the hours of the week | `max_weekly_hours` | 40 h |

Each card is named exactly like the profile field it comes from, so that nothing
has to be translated between the two screens. And the threshold is not hidden:
under the name, the card spells it out —"**12 h 00 min under the ES-hosteleria
profile**"—. That is deliberate: **a warning whose criterion is not visible is a
warning nobody can defend in front of an employee**. If you adjust a threshold in
the compliance profile (§7), this screen changes with it from the moment it is
saved.

The filters are period, department and rule. **With no dates, the last 28 days
are shown** —four weeks, which is what gets reviewed—. The period has a cap, 92
days by default; if you ask for more, the screen says so and does not query.
When there is nothing to warn about it says that too, and with the criteria
applied in plain sight: "No alerts in the period" without saying which period
would be of no use at all.

### 4 bis.2 What counts and what does not

This is what raises the most questions, and it is worth being clear about it
**before** discussing a warning with anybody:

- **Rest is measured between working days, not within the day.** It is the gap
  between the last clock-out of one working day and the first clock-in of the
  next. The time somebody spends outside mid-morning is not rest between
  working days and is not counted here. And with no previous working day there
  is nothing to measure: the first day of a person's record never warns.
- **⚠️ The gap WITHIN a working day is not looked at, and the system will never
  warn you about it.** A split working day —leaving at 15:00 and coming back at
  23:00 the same day— is two entries of the same day with eight hours in
  between, and **it appears neither here nor in the inbox**. If that return fell
  at 00:30 it would already be another working day and it would warn. It is not
  a fault: inside a split working day that gap is the break, and warning about
  all of them would turn every split shift in the hotel into an alert. **But it
  is a case the product does not cover**, so if your collective agreement says
  anything about rests within a split working day, that is reviewed by hand:
  **the system applies the threshold you have set, it does not rule on whether a
  working day complies with the Workers' Statute or with your agreement**.
  Checking that is your labour advisers' job, not the vendor's —
  [`legal-obligations.md`](legal-obligations.md) §7.
- **Night shifts are not split.** A shift from 22:00 to 06:00 is a single one
  and belongs to the day it started. The following rest is measured from its
  real end, 06:00, not from midnight.
- **Only closed shift entries count.** A shift still open is worth zero hours
  and the row comes out flagged **"Open shift"**. It does not mean that day
  complies: it means it cannot be known yet. When somebody closes the shift, the
  total goes up and the warning may appear.
- **The totals are the same ones you see in the person's record.** The screen
  does not recalculate the hours on its own: it reads them.
- **"Maximum continuous stretch without a break" is evaluated only if the hotel
  has break clocking turned on.** While it is not, its card appears flagged
  **"Not evaluated"**, with the reason written out —"Not evaluated while break
  clocking is switched off in Operational settings"— and produces no rows. The reason is the same as in the
  inbox (§4.1): if the kiosk does not record the break, the system cannot tell
  "did not rest" from "rested and did not clock it", and warning under those
  conditions would mean warning about almost everybody almost every day. The
  threshold is stored and audited all the same, and the rule starts counting on
  its own as soon as the setting is turned on, with nothing else to touch. It is
  shown rather than hidden so that you know the rule exists and with which
  threshold it will apply. Your IT turns it on in Panel → "Operational settings"
  → "Break clocking" ([`configuration.md`](configuration.md) §2.1), and it is
  worth discussing first: the inbox starts receiving warnings it does not
  receive today.
- **The first two count exactly like the inbox.** Minimum rest between shifts
  and ordinary daily working time are measured with the same criterion as the
  nightly review —the one that opens "Insufficient rest" and "Shift too long" in
  the inbox (§4.1)—, so when
  there is already an incident open for that person, that day and that rule, the
  row links to it. The weekly one **never opens an incident**
  (§4 bis.3).

**The week is the one from the profile, and always a whole one.** It starts on
the day the `week_starts_on` field says —Monday by default— and it is seven
dates. If the period you asked for cuts a week in half, **that week is evaluated
in full anyway**, with the days outside the period included. The opposite would
give a weekly total that does not match the one the person sees in their own
record, and there is no way to explain that difference.

### 4 bis.3 What the weekly warning means

The **ordinary weekly working time** warning is informative, and it is the only
one of the four that **does not open an incident**. That is not an
oversight: the Workers' Statute sets the forty weekly hours **as an annual
average** (art. 34.1), so a forty-four-hour week is not in itself a breach —it
may be offset by a thirty-six-hour one—.

What the screen does is flag it **so that you look at it with the collective
agreement in front of you**. Many hospitality agreements set their own rules on
uneven distribution, on a weekly maximum or on compensatory rest, and those can
indeed be breached by a week like that. That reading belongs to the hotel: the
system does not know your agreement and cannot do it for you.

### 4 bis.4 How to read a row

Each row is an employee and a working day —or an employee and a week, in the
weekly warning—, with the hours in the site's time zone, as everywhere in the
panel. These are the columns, and the three numbers always come in hours and
minutes:

| Column | What it is |
| --- | --- |
| **Employee** | Who |
| **Shift day or week** | The day of the warning, or the Monday-to-Sunday of the week |
| **Measured** | What the record says: the rest there was, the hours that were worked |
| **Threshold** | What the profile requires |
| **Difference** | What separates the two: "**Short by** 2 h 00 min" when the rest falls short, "**Over by** 0 h 40 min" when the working day goes over |
| **Incident** | The link to the one in the inbox, if there is one |

Read straight through, a rest row says: *Measured 10 h 00 min · Threshold
12 h 00 min · Short by 2 h 00 min*. That subtraction is the one you have to be
able to explain, and that is why all three numbers are visible and not just the
last one.

And two links:

- **The employee's name** leads to their time record, placed at the working day
  —or the week— of the warning, which is where it is looked at and where it is
  corrected (§5).
- **"View incident"** only appears when the inbox already has one open for that
  same case, and it leads to **the incident inbox narrowed to that person**,
  which is where it gets resolved (§4.3). It does not resolve it for you: no
  link on this screen writes anything.

### 4 bis.5 Who sees it, and what gets logged

- A **department manager** sees the people in their department and nobody else;
  the counts on the cards are only for their people too.
- **HR** and **administrator** see everything.
- The **auditor** does not get into this screen. Auditing is reviewing what was
  written down, not managing the day-to-day.

**Every query is logged in the audit log**, just like consulting a person's time
record (§3.2): who looked, which period and with which filters. The scope of the
query is logged, never the names of the people who appeared in it. That is
normal and it is intentional.

**This screen does not depend on the licence.** It is a reading of the legal
record against the legal thresholds: even with an expired licence it keeps
working just the same (§8, "there is a licence notice in the panel").

### 4 bis.6 What to do about a warning

A warning is neither a penalty nor a system failure: it is a working day
somebody has to look at. The order that works:

1. **Check it against the person's record.** Go in through their name and look
   at the day. The explanation is often in plain sight: a missed clock-out that
   joins two working days, a double scan, a shift that was closed the next day.
2. **If the record is wrong, correct it** from there, with its reason (§5). The
   warning disappears the next time the screen is opened, because it is
   calculated from the record and not from a stored list.
3. **If the record is right, talk to the person and to their manager** and check
   the case against the collective agreement that applies to you. An eleven-hour
   rest can be true and still be a problem; a forty-four-hour week can be true
   and be perfectly offset.
4. **If there is also an incident open**, close it when you are done, with the
   note of what happened (§4.3). Resolving the incident does not change any
   hour: they are two separate actions.

> **The screen corrects nothing and stores no verdict.** It is recalculated
> every time it is opened, from the record and the thresholds of that moment. If
> tomorrow you change a threshold in the profile, what is seen tomorrow will be
> what the new threshold says, for last week's working days too.

**The inbox and this screen behave differently when a threshold changes, and you
have to be able to explain that.** It is not an inconsistency: they are two
things with two purposes.

| | The incident inbox (§4) | The compliance view (§4 bis) |
| --- | --- | --- |
| What it is | A work list: each incident was opened on a given day and somebody has to close it | A query that is calculated on the spot |
| When a threshold changes | **History is not reprocessed.** Incidents already open keep the criterion they were opened with, and none is closed or reopened on its own | **It always recalculates with the threshold in force** at the moment of the query |
| How you know which criterion applied | From the profile's audit log: who changed which value and when (§7) | From the screen itself, which shows the profile and the threshold of each rule |

That is why this screen always shows the name of the profile and the thresholds
it calculated with: it is what lets you say, in front of an employee or an
inspector, **which criterion the warning used and since when that criterion has
been in force**. And that is why the inbox is not reprocessed: reopening today
incidents for working days already handed to the staff or to the Labour
Inspectorate, with a threshold that did not exist back then, would help nobody.

---

## 5. Corrections: changing an hour without breaking the record

### 5.1 When to correct

You correct when the record **does not say what happened**: a missed clocking,
a double scan, a day worked before having the card. You do not correct to "make
a total add up" or to adjust a payslip: that has another name and another
consequence.

From the person's time record there are three actions:

| Action | When |
| --- | --- |
| **"Add an entry"** | The person worked and there is no clocking at all: they did not clock in, they had no card, or the working day is earlier than the go-live |
| **"Correct the times"** | The entry exists but the clock-in or the clock-out are not the real ones |
| **"Void the entry"** | The entry should not exist: a double scan, somebody else's clocking |

![Entry correction form](../img/en/rrhh-08-correccion.png)

### 5.2 The nine reasons, with an example of each

The reason is required, **it stays in the legal record and a labour inspection
can read it**. No health data and no value judgements about the person.

| Reason (exactly as it appears in the panel) | A real example |
| --- | --- |
| **Missed clock-in** | They started at 07:00 in the kitchen, did not use the card, and their first clocking is the 15:00 clock-out |
| **Missed clock-out** | They finished at 22:00 and left without clocking; the shift shows as open the next morning |
| **Kiosk technical failure** | The tablet at the staff entrance had no power all morning and that shift is entered by hand |
| **Card not available** | They left the card in the locker and could not clock in |
| **Card not handed over yet** | First day at work: the card was printed but it was handed over at the end of the shift |
| **Duplicate scan** | They used the card twice in a row and two entries came out where there was only one; the surplus one is voided |
| **Adjustment agreed with HR** | Correction agreed with the person after reviewing the roster: it is not a system error |
| **Retroactive entry** | Working days from the week before the system went live, loaded by hand |
| **Other reason** | None of the above. **It requires at least 20 characters**: "error" and "adjustment" explain nothing to an inspection |

### 5.3 What happens underneath, and what you need to be able to explain

**The previous value is always kept.** The correction does not rewrite the
entry: it creates a new version and leaves the previous one visible, with who
made it, when and why. In the working day's "Amendment history" you see the
**before** and the **after**, side by side.

![Amendment history of a working day](../img/en/rrhh-09-historial-correcciones.png)

Before an inspection, the sentence is this one: *"the record keeps every
version; this hour was corrected on such a date, by this person, for this
reason, and here is what it said before"*. A record without that history is a
record that could have been changed the day before the visit, and that is how
whoever reviews it will read it.

Two warnings you will see and what they mean:

- **"This entry is no longer the current version"** — somebody else corrected
  or voided it while you had the screen open. Reload the working day and check
  how it stands now.
- **"That clock-in time would move the working day to another date"** — moving
  hours from one day to another is **two actions**: void the entry on the day
  where it is and create it on the day it belongs to, each one with its own
  reason. The system does not do it in a single step because moving a working
  day to another date changes what gets paid in which month.

**The employee does not correct their own record**, and that is why the portal
is read-only. A record that the interested party can edit proves nothing. What
they can do is raise it, and HR corrects it with their signature.

---

## 5 bis. Absences: holidays, sick leave and time off

**Absences** is the screen where it gets written down that a person was not
there, and why. It sits in the menu right after "Employees", because an absence
belongs to the workforce and not to one particular day of the record.

It serves one purpose, and it is worth saying before anything else: **so that
the reports do not count as an unexplained absence a day on which the person
was not supposed to be there**. With no absences on record, the hours-per-period
report only knows that there was no clocking that day, and it cannot tell
holidays from a no-show.

### 5 bis.1 What an absence is and what it is not

An absence is **a fact that gets written down**, not a request that gets
processed.

**There is no approval workflow.** There is no "pending approval", no
"approved", and no approve button, and that is not an oversight: the decision is
taken outside the system —when closing the roster, talking to the person, with
the medical certificate in front of you— and here it is only recorded. Whoever
registers an absence is saying "this is already decided", not "this has been
requested".

There are four types, and no more can be added:

| Type | When it is used |
| --- | --- |
| **Holiday** | Holidays already granted, whichever period they belong to |
| **Sick leave** | Temporary incapacity, accident, any leave with a certificate |
| **Time off** | Paid and unpaid leave: moving house, an exam, personal matters, caring for a relative |
| **Other** | None of the above. **It requires a note** saying what it is about |

The rest of what you need to know fits in a list:

- **Whole days only.** You give the first day and the last one, and **both
  count**: an absence from the 3rd to the 5th is three days. There are no half
  days and no absences by the hour. If somebody misses half an afternoon, that
  is not an absence: it is a shorter working day, and it shows in their record.
- **It can be registered backwards and forwards.** Sick leave is almost always
  known after it has started, and holidays are written down months ahead. Both
  are fine, and neither waits for the day to arrive.
- **Registering an absence does not stop anyone clocking.** If that person turns
  up and uses their card, the clocking is recorded as normal: the absence
  neither blocks it nor opens any incident by itself. What has to be sorted out
  then is the fact, not the system — and if the absence was wrong, you correct
  it (§5 bis.2).
- **It does not track a holiday balance and it notifies nobody.** The product
  does not work out how many days each person has left, and it sends no notice
  to the manager when an absence is registered for their people.
- **The employee does not see them in their portal.** The portal is their time
  record and nothing else
  ([`employee-portal-guide.md`](employee-portal-guide.md)). Absences are
  consulted by HR and by their manager.

### 5 bis.2 Registering, correcting and voiding

Three actions, and none of them deletes anything:

| Action | When |
| --- | --- |
| **"Register"** | Write down a new absence: person, type, first day, last day and, if needed, a note |
| **"Correct"** | The dates or the type are not what happened: the sick leave lasted two days longer, or what were holidays got written down as time off |
| **"Void"** | The absence should not exist: the person did work those days, or it was registered against the wrong person |

**When registering**, you look the person up by name or by their code and you
give the days. If those dates overlap another absence already in force for the
same person, **it is not saved and the screen says so**: two overlapping
absences would make the same day count twice in the report.

**When correcting**, the screen shows **from which value to which** before you
confirm —the type before and the type now, the dates before and the dates now—
and it **asks for a reason**. That is not red tape: it is what gets read six
months later to understand why the March report says something different today
from what it said in March.

**Nothing is deleted.** Correcting does not rewrite the absence: it creates a
new version and keeps the previous one, with who made it, when and why. Opening
an absence shows its full history, from the first version to the current one,
just like the amendment history of a working day (§5.3).

**Voiding also asks for a reason, and it does not delete either.** The absence
stays where it was, marked as voided, with who voided it, when and why. It stops
counting in the reports from that moment on, but it remains available to
consult — which is exactly what makes it possible to explain why last week's
report carried a day of absence that today's no longer carries.

> **Voiding is not the same as correcting.** If what you want is to change some
> dates or the type, **correct**: the absence happened and still stands, it just
> says something else. **Void** only what should never have been registered.

### 5 bis.3 Loading from a file

For a big batch —the whole summer's holidays, the year's history you had in a
spreadsheet— there is loading from a CSV or Excel file, with **the same two
steps as the workforce load**: first it is checked, then it is applied.

1. **Check.** You upload the file and the system reads it right through
   **without saving anything**: it gives you back, line by line, which ones
   would go in and which ones would not and why.
2. **Apply.** Only if you confirm. The valid lines are registered and the rest
   are rejected, with the same detail.

The first row holds the **column names**. The order does not matter and spare
columns do no harm:

| Field | Required | Names that are recognised |
| --- | --- | --- |
| Employee code | **Yes** | `employee_code`, `codigo` |
| Absence type | **Yes** | `type`, `tipo` |
| First day | **Yes** | `starts_on`, `desde` |
| Last day | **Yes** | `ends_on`, `hasta` |
| Note | No, except for "Other" | `note`, `nota` |

- **The employee code** is the one the system generated when the person was
  added, and the one printed on their card. You have it in the employee list.
- **The type is written by its name**: `vacation`, `sick_leave`, `leave` or
  `other`, or their Spanish equivalents `vacaciones`, `baja`, `permiso` and
  `otro`.
- **Dates** are accepted as `2026-07-01` or as `01/07/2026`. Never
  month/day/year, for the same reason as in the workforce load.
- **The separator and the encoding are detected on their own**, just as there:
  it is explained in [`configuration.md`](configuration.md) §3 ter.3.

**Uploading the same file again is safe.** A line identical to an absence
already registered —same person, same type, same dates— **raises no error and
does not duplicate it**: it comes out marked as "unchanged" and the system moves
on to the next one. That is what lets you fix three lines of a two-hundred-line
file and upload the whole thing again without thinking twice.

A line is rejected when the employee code does not exist, when the dates are the
wrong way round or cannot be read, when the type is not one of the four, when
the absence overlaps another one already registered for that person **or another
line of the same file**, or when the type is "Other" and it carries no note.

> **The file is not kept on the server.** It is read, applied and discarded.
> What remains is each absence registered, with its log entry (§5 bis.6).

### 5 bis.4 What changes in the hours-per-period report

The hours-per-period report (§6.1) carries **three columns** that depend on what
has been registered here:

| Column | What it counts |
| --- | --- |
| **Absence days** | Days on which the person was on the books and there was an absence on record, whether there were clockings or not |
| **Public holidays** | Days on the books that appear in the compliance profile's holiday calendar (§7) and are **not** already covered by an absence |
| **Unexplained absence** | Days on the books **with no clocking, no absence and no public holiday** |

Each day counts **in one column only**: if a public holiday falls inside some
holidays, that day is an absence day and not a public holiday. And only days on
which the person was on the books count, as in the rest of the report: anything
before their start date or after they left appears in none of the three.

> **⚠️ The limit you have to know before showing this report to anybody.**
> **The product does not know your roster.** It knows when people clocked, not
> which days each person has off. So **weekly rest days come out counted as
> unexplained absence**, exactly like a no-show: nobody clocked and there is no
> absence or public holiday to explain it. It is not a fault: it is all the
> system can know with what it has. That column is **a starting point** —the
> days nobody has explained— and it has to be checked against the shift calendar
> before drawing any conclusion about a person. If somebody reads it as "days
> missed", the number will be wrong every time.

Two more things:

- **The report does not break the figure down by type, and that is on purpose.**
  There is no "sick leave days" column per department: it would be aggregated
  health data that nobody asked for and that would end up in a shared
  spreadsheet. The breakdown by type is seen on the **Absences** screen, with
  each person's scope (§5 bis.5).
- **The three columns also come out in CSV, in Excel and in PDF**, and the
  criteria the report declares below the table say how many of the profile's
  public holidays fell in the period and repeat the warning about the roster.

### 5 bis.5 Who sees what

| Who | What they can do |
| --- | --- |
| **HR** and **administrator** | They see every absence, with its note. They register, correct and void |
| **Department manager** | Sees the ones for their people, with the type and the dates. **They do not see the note**, and they cannot register, correct or void |
| **Auditor** and **employee** | Do not enter this screen |

The manager **does** see the type, and that is deliberate: whoever organises a
shift has to know who is missing and under which category, or they cannot cover
it. The **note** does not reach them —neither empty nor blanked out: the field
does not exist for them, so that nobody confuses "they are not showing it to me"
with "there is no note".

> **Sick leave is health data. Write only what is needed.**
> **Do not put the diagnosis or the medical reason in the note.** The **Sick
> leave** type is enough: it is what the report needs and the only thing needed
> to cover the shift. The same goes for the reason of a correction or of a
> voiding, which in addition **stays in the legal record and a labour inspection
> can read it**, just like the correction reasons of §5.2: no health data and no
> value judgements about the person.
>
> The medical certificate, the supporting document and everything the
> regulations oblige you to keep go wherever you keep the person's file, not
> here. What you may keep and for how long is explained in
> [`legal-obligations.md`](legal-obligations.md).

### 5 bis.6 What gets logged

**Every registration, every correction and every voiding leaves an entry in the
audit log**, with who did it, when, what changed and why. In a correction the
entry carries **the values before and the values after**, so that the complete
history of an absence can be reconstructed without opening the screen.

A file load leaves **one entry per absence registered**, not a summary one: a
hundred imported absences are a hundred log entries.

**The note never goes into the entry.** It may carry information about a
person's health, and the entry does not need it to explain what happened: the
type, the dates, who and when are enough. For the same reason, neither the type
nor the note appear in the system's technical logs or in the diagnostics package
that is sent to the vendor.

And the usual: **nothing is deleted**. A corrected absence keeps every one of
its versions, and a voided absence is still there, marked as such. It is the
same rule that makes the working-time record hold up in an inspection (§1).

---

## 6. Reports, exports and the Labour Inspectorate hand-over

They are three different things and they are easily confused:

| | **Hours-per-period report** | **Labour Inspectorate export** | **Payroll export** |
| --- | --- | --- | --- |
| What for | Management: how much has been worked, by whom, with what deviation | Meeting a requirement under art. 34.9 of the Spanish Workers' Statute | Taking the month's hours to the hotel's payroll software |
| Where | Reports | Inspectorate | Reports → **Payroll** tab |
| What it carries | Totals aggregated by person, department or site | **Every entry, one by one, and every correction with its author and its reason** | One row per person and period: hours worked, contracted, excess and absences. **No amounts** |
| Format | CSV, Excel or PDF | Normalised CSV, with its criteria and its legal basis declared inside | CSV or Excel, with the columns, the separator and the hours format your payroll software asks for |

The hours report and the payroll export are downloaded on the spot almost
always; when the period or the workforce are large, they are **generated in the
background** (§6.3). The Labour Inspectorate export does not change: it is
always generated on the spot.

### 6.1 The hours-per-period report

![Hours-per-period report](../img/en/rrhh-12-informe-periodo.png)

You choose the period, the granularity (day, week, month or the whole period)
and the grouping (employee, department or site). Below the table, the report
itself declares **which criteria it was calculated with**: that is what lets
you defend a number six months later.

Two warnings worth reading:

- **"Count days with an open shift"**: if you turn it on, days whose total is
  still going to change are included. For payroll, close them first.
- **"Person-days with no contract on record"**: those rows have the hours
  worked right and the deviation incomplete (§2.3).

The report also carries **three columns that come from the absences**: absence
days, public holidays and unexplained absence. What each one counts exactly
—and, above all, **what the product cannot know** about rest days— is in
§5 bis.4. Read it before showing that last column to anybody.

### 6.2 The export for the Labour Inspectorate

![Labour Inspectorate export screen](../img/en/rrhh-13-exportacion-legal.png)

You choose the dates and, if the requirement names a person, that person. Left
blank, the whole workforce comes out. What the file contains:

- One row per **entry**, with clock-in, clock-out, duration and the working
  day's total. A night shift is a single entry, on the working day it started
  on.
- One row per **correction**, with its author, its moment and its reason.
- **Voided entries are included**, marked as such, and they add no hours.
  Nothing is hidden: hiding them would be exactly what the Labour Inspectorate
  is looking for.
- The times go in the site's time zone **and also** in UTC, which is how they
  are stored.
- Durations are written HH:MM, never in decimal.

**Every generation is logged** with who exported, which period and which scope.

> **The full procedure, with the deadlines and what to do with the file after
> handing it over, is in
> [`../../runbooks/requerimiento-inspeccion.md`](../../runbooks/requerimiento-inspeccion.md)
> (in Spanish).** Read it **before** the requirement arrives, not when it
> arrives: it is five minutes that save the hour.

### 6.3 Large reports: in the background

**When it happens.** When you ask for an hours-per-period report or a payroll
export, the panel may tell you that **that period or that workforce does not fit
on the spot**. It is not an error nor a fault in the installation: a report
calculated while you wait has a time limit, and above it the system would rather
tell you than leave the screen hanging. It mostly happens with periods of
several months and with large workforces.

**What to do.** The notice itself carries the **"Generate in the background"**
button, with the same parameters you had already chosen: nothing has to be filled
in again. When you press it, the request goes into the **"Background exports"**
block on that same screen.

**How you follow it.** That list refreshes on its own while something is running
and shows the state of each request: "Queued", "Generating", **"Ready to
download"**, "Failed" or "Expired". You can close the screen, leave the panel and
come back later: the work carries on in the server. Only **one request of
yours** is processed at a time; if you ask for another before the previous one is done, the
panel shows you the one already under way instead of starting a second.

**The email notice, if your installation sends email.** When the file is ready
you get a notice at the address of your management account. That message
**carries neither the file nor the download link**: it carries the link to the
reports screen, and the download is requested from there. If your installation
has no outgoing email, nothing is lost: the screen is the source, the message
only saves you from watching. Whether there is email or not is decided by IT at
installation time.

**Downloading.** When the row says "Ready to download", the **"Download"** button
fetches the file. Two things worth knowing before pressing it:

- **The link expires after 15 minutes and is good for one use only.** As soon as
  it is used, it stops working: open it again and the answer says that link has
  already been spent and that you should ask for another. There is no point in
  saving it, forwarding it by email or pasting it into a chat: by the time the
  other person opens it, it will no longer be valid.
- **If the download is cut off** —the wifi drops, you close the laptop— nothing
  is lost: go back to the screen and press "Download" again. A fresh link is
  issued over the same file, which has not been generated again.

**How long the file lasts.** It is kept for **7 days** and then disappears on its
own. The row stays in the list saying that it expired, with what was asked for
and when, so that there is a record; what is no longer there is the file. If you
need it later, ask for it again: the same result comes out, unless something in
the record has been corrected in the meantime.

**Who can see it.** **Only the person who asked for it**, even if the person
looking is an administrator. It is not a shared folder: it is your request and
your file. If a colleague needs that same report, she asks for it herself, with
her account and her scope —a department manager always gets only her own people—.

**What is logged.** That you asked for it, which period and which scope; that it
was generated, with how many rows; and **every download, one by one**, with who
and when. That is what makes it possible to answer "who took what" if one day it
has to be answered.

> The deadlines —the 15 minutes of the link and the 7 days of the file— can be
> adjusted by IT on the server. They are in
> [`configuration.md`](configuration.md) §6.25.

### 6.4 The payroll export

**What it exports.** One row per person and period with the **hours worked**, the
**contracted hours**, the **excess** over what was contracted and the **absence
days**. The hours are exactly the ones the hours-per-period report shows (§6.1):
it is not a second calculation that could give a different number.

**What it does NOT do, and it is worth saying out loud.** It does not calculate
amounts, bonuses, supplements, seniority, social security, or anything carrying a
currency sign. The hotel's payroll software does that; this hands it the starting
hours. If somebody expects a payslip out of this, they expect something this
product does not do and has never promised to do.

**Where it is and who can.** Reports → **"Payroll"** tab. You choose the period
and the granularity, you see the configured columns before downloading anything,
and there are two buttons: **"Download"**, which fetches it on the spot, and
**"Generate in the background"** for large periods (§6.3). The tab is seen by
**HR and administration**; a department manager does not see it, although she
does see the hours report for her people.

**IT configures the format once.** Which columns come out and in what order, how
hours and dates are written, which separator the CSV carries and which encoding
it is written with are installation settings, not monthly decisions: they are
tuned on the day the payroll software is connected and are not touched again.
They are explained one by one in [`configuration.md`](configuration.md) §2.5. If
your payroll software rejects the file, that is what to look at.

**The criteria do not go inside the file, and that is on purpose.** The
hours-per-period report carries its criteria printed below the table; this one
does not, because a line of explanation in the middle of a CSV breaks the import
into the payroll software. The criteria are shown **on the screen, next to the
download button**. Copy them and keep them with the file you send: they are what
lets a number be explained six months later, and the file alone does not explain
it.

**What to cross-check before sending it.** Five minutes here save a payroll
correction later:

- **Days with an open shift.** A working day with no clock-out has a total that
  is still going to change. Look at them in the incident inbox (§4) and close
  them before exporting.
- **Unresolved incidents** in the period. Each one is an hour that may move when
  somebody resolves it.
- **Days with no contract on record.** In those rows the hours worked are right,
  but the contracted hours and the excess are incomplete (§2.3).
- **Absences up to date.** Holidays, sick leave and leave in the period have to
  be recorded first (§5 bis), or the absence column will come out short.

> **It is an accessory feature.** If the licence expires, the payroll export may
> become unavailable until it is renewed. The time record, clocking in and the
> Labour Inspectorate export **are never stopped because of that** (§8, "there is
> a licence notice in the panel").

### 6.5 The weekly summary by email

**What it is.** An **optional** email that every department manager receives on
Monday morning with the previous week —Monday to Sunday— for their team. It is a
summary to read in two minutes, not a report: it is there so you can spot a
deviation or an incident early without having to open the panel every Monday,
and for nothing else.

**Who receives it.** Department managers with an active account and an email
address, and **each one only their own**: the Kitchen manager sees nobody from
Reception, exactly as in the panel. HR and administration do not receive it:
they have the whole panel, and a weekly email with the entire workforce would be
a periodic copy of the record outside the system.

**What it carries.**

- One line per person with the **hours worked**, the **contracted hours** and
  the **deviation**, the **days with activity**, the **absences** and the
  **public holidays** of the week. They are the same columns as the
  hours-per-period report (§6.1), calculated the same way and with the same
  caveats (§5 bis.4): the email calculates nothing new.
- The department **totals**.
- The **number of open incidents** in the department, with no detail: the
  detail already arrives in the daily incident notice and is in the inbox (§4).
- **Where to see it in full**: "Panel → Reports, from <start> to <end>". It is
  a pointer, not a link: the email carries no addresses to click on.

If the department has more than **50 people**, the email carries the first 50
and says how many are left: the rest is in the panel.

**What it is not.** It does not replace the panel or the report: days with an
open shift do not count, and a correction or an incident resolved after Monday
changes the report, but not the email that already went out. No text in the
email compares people with each other or rates them: they are the same numbers
as the screen, in your inbox.

**How it is enabled.** From **Operational settings**, with an administrator
account, "Weekly summary by email" → "Enabled". It ships switched off. The
installation needs outgoing email —IT decides that at installation time, as
with the notice for background reports (§6.3)— and the licence needs to include
this feature; if either is missing, nothing happens: the email does not arrive
and everything else carries on as usual. It is enabled for every manager at
once; in this version there is no individual opt-out.

> **That email carries people's names and hours, and it leaves the system.**
> That is why every send is recorded as an access to personal data, just like
> downloading a report: who it was sent to, which week and which people it
> included. It is an internal communication that is legitimate for its
> purpose, but it is worth treating it as what it is: do not forward it outside
> the hotel or print it to leave it on a noticeboard. And once delivered **it is
> a copy outside the product**: it lives in your mailbox, and how long it stays
> there is decided by the hotel with its advisers, not by the system's retention
> ([`legal-obligations.md`](legal-obligations.md) §4).

### 6.6 The impact dashboard: what it measures and what it does not

**What it is.** A screen in the panel —Reports → "Impact and adoption"— that
answers a single question: **is the system working?** It does not say how much
anybody has worked: it says whether the record is being kept complete, whether
people clock with the card or things have to be fixed by hand, how long
incidents take to be resolved and how many people are still without a card. It
is visible to administrator and HR accounts; department managers do not see it.

**The period, and what it is compared against.** You choose a closed period —by
default, the previous full calendar month— and every indicator comes with its
value and with the **change against the previous period**, which is always
**the same number of days immediately before** the one you chose: a
thirty-day month is compared with the thirty days before it, a fortnight with
the previous fortnight. If there is nothing to compare against in that
previous period —because the system was not running yet, or there were no
clockings— **the change is left blank, not set to zero**: a zero would say
nothing changed, when what happens is that there is nothing to measure it
against. Two indicators (open incidents and people without a card) are a
snapshot of today and have no change figure.

**The indicators, one by one, with the target the product sets itself three
months after going live.** The target sits next to the value and the dashboard
says in words, not only with a colour, whether you are inside or outside it.

- **Working days with a complete record — target: 99 % or more.** Of all the
  working days in the period on which somebody clocked, how many have **all
  their entries closed**: in and out, no forgotten shift. It is the main
  indicator, because it is what the product is for: a complete working-time
  record. A working day with an open shift that is later closed with a
  correction counts as complete from that moment on: the dashboard is
  calculated on what exists when you open it.
- **Clockings by card — target: 98 % or more**, with the split by **card, PIN,
  manual correction and import**. Only **accepted** clockings count —a scan
  that produced no entry contributes nothing— and the split always adds up to
  100 %. A low card percentage is not a system failure: it is people without a
  card handed over, or people using the PIN out of habit, and the fix is in
  §2.4 and §2.6.
- **Corrections over clockings — target: under 2 %.** How many corrections
  (§5) were made in the period for every hundred accepted clockings of the same
  period. It measures trust in the data: if one in ten has to be fixed by hand,
  the record is being built after the fact, which is exactly what the product
  is there to avoid.
- **Incidents open today, and time to close a forgotten shift — target: under
  24 hours.** The first is a snapshot of the moment, with no change figure. The
  second is the **average time** between the system detecting an unclosed
  shift (§4.1) and somebody resolving it (§4.3), counting only those resolved
  within the period; next to it is the **median**, which is not dragged by one
  incident that sat forgotten for a month. It measures whether the inbox is
  attended to promptly, not whether there are few incidents.
- **People without a card handed over, today.** How many active people do not
  yet have a card with its handover recorded (§2.6). A snapshot of today, with
  no change figure. Until it is zero, somebody is clocking with a PIN or not
  clocking at all.
- **Hours worked against contracted** in the period, for the whole hotel: the
  same figures and the same criteria as the hours-per-period report (§6.1),
  added up for the entire workforce, without days with an open shift and with
  public holidays treated the same way. It has no target: it is context, so
  that you read the other indicators knowing how much activity there was.
- **Availability of the act of clocking — target: 99.9 % or more.** Read this
  one carefully, because it does not measure what it seems to. **It is not
  "the server was up": it is "the person was able to clock".** The tablet
  stores the clocking without a network and uploads it when the connection
  comes back (§8, "…somebody cannot clock"), so the server may have been down
  for half a morning and availability still be at 100 %: nobody was left
  unable to clock. It is calculated as the clockings the tablet **handled**
  —including those it stored offline and uploaded later, which the dashboard
  shows separately as **"resolved without the server"**, and also those a rule
  rejected, because they were handled— against the attempts the tablet itself
  **reported as failed**: the camera that does not start, the reader that does
  not load, the storage that will not save. Two caveats, and the dashboard
  states them: it is an **approximation in favour of reliability**, because an
  attempt that never even produced an error on the tablet is seen by nobody;
  and the tablets' error history **is trimmed over time**, so on old periods
  the figure may come out somewhat better than reality.
- **Hours per month consolidating timesheets — reference: 80 % less.** This is
  the only indicator that **the system cannot measure**, because it measures
  the work HR did **before** the system was installed, and no application
  observes what happened before it existed. The figure is declared by the
  hotel in Operational settings (the `BASELINE_MANUAL_HOURS_PER_MONTH`
  setting): how many hours a month went into gathering and reconciling
  timesheets. The dashboard shows it as declared, with the target of reducing
  it by 80 % as a reference for your own comparison. If it has not been
  declared, the box **is left empty**: the product does not invent an
  improvement it has not measured.

**What it is not.** The values are **for the whole installation and never per
person**: there is no "who corrects most" or "who forgets to clock" column, and
there will not be one, because the dashboard exists to evaluate the system,
not the staff. A number outside its target points to a process to fix —cards
not handed over, an inbox nobody looks at, a badly placed tablet—, not to a
person. Nor is it a real-time view: it is a closed period, and a correction
made today on last month changes last month's dashboard the next time you open
it. At the bottom, the dashboard states its criteria as the hours report does.

**Exporting.** It downloads as CSV, Excel or PDF; the PDF carries a timestamp,
issuer and a digest of its content, like the others. Although it carries no names,
**every export is recorded** in the audit trail —who, which period and in which
format—, because it is a document that leaves the system and the one your
provider will ask you for when the renewal comes up. When the document is going
to leave the hotel —to the provider, for instance—, **export closed periods of a
month, not loose days**: in a small installation, a very short period stops
being an aggregate and can be read as one particular person's working day.

**Licence.** The plan needs to include the impact dashboard. If it does not,
or the licence has expired, the screen says so and shows nothing else;
clocking, the record, corrections and the export for the Labour Inspectorate
are not affected (§8, "…there is a licence notice in the panel").

**If IT shows you another dashboard with the same name.** The system also
carries a technical dashboard called "Impact and adoption" in the server
monitoring tool. It uses the same definitions —what a complete working day is,
what an accepted clocking is, what a correction is— but it **looks at the last
seven days** on a rolling basis, whereas this dashboard looks at a closed
period that you choose. That is why they may not match to the decimal, and it
is not an error: the one on the panel screen is the one that counts when
talking to your provider.

---

## 7. The compliance profile

**Compliance profile** holds the legal thresholds the record is reviewed
against: minimum rest between working days, ordinary daily and weekly working
hours, maximum continuous stretch without a break, first day of the week, public
holidays and years records are kept. Do not confuse it with **Compliance**
(§4 bis), which is the screen that *applies* these thresholds to the record:
here they are decided, there the consequences are seen.

![Compliance profile screen](../img/en/rrhh-14-perfil-cumplimiento.png)

**Changing a threshold changes what counts as an incident.** Lowering the
minimum rest from 12 h to 10 h does not change a single hour of the record: it
changes **which working days get flagged for review**. It is a change with
legal effect, and that is why:

- It applies **from the moment it is saved**. History is not recalculated and
  no incident already recorded is closed or reopened.
- The nightly review looks again at the last few days, so **tightening** a
  threshold may open incidents for recent working days that have already
  passed.
- It is logged in the audit log with the previous value, the new one, who
  changed it and when. Without that, there is no way to explain why a working
  day three months ago raised no alert.

**Where each threshold shows up.** Minimum rest between working days and maximum
daily working time move both things: the incident inbox (§4) and the compliance
view (§4 bis). **Ordinary weekly working hours** and the **first day of the
week** are applied only by the compliance view, which warns but opens no
incident. The **holiday calendar** is applied by the **hours-per-period
report**: the days listed in it are not counted as unexplained absence
(§5 bis.4). It **opens and closes no incident** and it does not change a single
hour of the record; if you leave it empty, the report simply discounts no public
holiday.

**`break_required_after_hours` —the maximum stretch without a break— also
depends on a setting that is not on this screen.** It only applies if the hotel
has **break clocking** turned on (Panel → "Operational settings" → "Break
clocking"; it is explained in [`configuration.md`](configuration.md) §2.1).
Without it the threshold is stored and audited all the same, but it **opens no
incident** and the compliance view shows it flagged "Not evaluated". That is
deliberate: with no breaks clocked, nobody can tell "did not rest" from "rested
and did not clock it", and an inbox with one warning per long shift stops being
read. As soon as it is turned on, the rule starts counting on its own with
whichever threshold you have saved here, so review the number **before** asking
for it to be switched on.

The product ships with the Spanish hospitality profile. **Adjusting it to the
collective agreement that applies to you is the hotel's responsibility**, not
the vendor's: it is explained in
[`legal-obligations.md`](legal-obligations.md) §7. The detail of each
threshold, value by value, is in [`configuration.md`](configuration.md) §2.4.

> **The years records are kept is the only threshold that can destroy data.**
> Lowering it widens what the purge considers expired, over data there is a
> legal obligation to keep for four years. No purge ever runs on its own —it is
> proposed first and it has to be confirmed—, but do not touch that number
> without reading [`legal-obligations.md`](legal-obligations.md) §4.

---

## 8. What to do if…

### …a person says their record is wrong

1. Open **their time record** and look at the specific day. Check the **source**
   of the entries first: an entry marked "Entered by hand" already has a
   correction behind it explaining where it came from.
2. If something is missing or left over, correct it with the reason that fits
   (§5) and explain to them that their previous version is kept.
3. Tell them they can check it themselves in the portal: they will see the
   change and the correction's history, with the reason.

**Never correct "just in case".** If it is not clear what happened, ask the
shift manager before signing an hour.

### …somebody forgot to clock out

It will show up as an **"Open shift not closed"** incident. The system does not
close it by itself (§4.2).

1. Find out the real clock-out time: ask the shift manager; do not deduce it
   from the roster.
2. In that person's time record, **"Correct the times"** and write the
   clock-out, with the reason **"Missed clock-out"**.
3. Close the incident as **"It has been corrected"**, with a note on who
   confirmed the time.

If the forgotten clocking is weeks old, the procedure is the same: open shifts
are always reviewed, with no age limit, and they do not disappear by
themselves.

### …a card is lost or broken

It is the same procedure in both cases, and also when the print PDF is lost
before it is printed:
**[`../../runbooks/tarjeta-perdida-o-rota.md`](../../runbooks/tarjeta-perdida-o-rota.md)**
(in Spanish).

In short: **revoke with a reason → issue another → print → hand over with its
sheet**. And tell the person that **in the meantime they can clock with their
code and their PIN** on the tablet itself: nobody is left unable to clock
because they lost a card.

### …a person asks for their time record

The ordinary route is **the portal**: they sign in with their code and their
PIN and download their history whenever they want, without asking anybody. It
is what the law requires and what stops every request from becoming an errand.
Give them [`employee-portal-guide.md`](employee-portal-guide.md).

If the request arrives **in writing as the exercise of a right** —access,
rectification, portability, erasure—, it has deadlines and a form:
[`../../runbooks/solicitud-derechos-rgpd.md`](../../runbooks/solicitud-derechos-rgpd.md)
(in Spanish). Watch out for one in particular: **erasure does not apply** while
the four-year duty to keep the record lasts, and the runbook explains how that
is answered without denying the right.

### …a requirement from the Labour Inspectorate arrives

Do not improvise: there is a written and tested procedure, designed to be
completed in less than an hour:
**[`../../runbooks/requerimiento-inspeccion.md`](../../runbooks/requerimiento-inspeccion.md)**
(in Spanish).

The essentials: the export is generated from **Inspectorate** (§6.2), it
includes entries, corrections and voided entries, and it declares its own
criteria. Before handing the file over, the runbook says what to check and what
to do with it afterwards.

### …a person leaves

From their record, the **"Offboarding"** section (it is not done by changing
the employment status field: it has its own section because it carries a
termination date and consequences). You choose the termination date and the
reason, which goes into the audit log —no health data and no value judgements.

What happens when you confirm it:

- **Their card is revoked** and stops working at the kiosk.
- **From the termination date on, they cannot clock.**
- **Nothing is deleted.** Their record, their entries and their working days
  are kept for four years, because an inspection can ask for the records of
  someone who no longer works here, and they will keep appearing in the reports
  for the period they worked.

Collect the physical card if you can; if it does not turn up, revoke it anyway
—it already is, because of the offboarding— and note it down.

### …somebody cannot clock

Before moving anything, look at their row in **Credentials**:

| Card status | What it means | What to do |
| --- | --- | --- |
| No credential | None has been issued to them | Issue, print and hand over (§2.4) |
| Waiting to be printed | The entitlement exists, the card does not | Print it |
| Waiting to be handed over | It is printed and they do not have it in hand | Give it to them and record the handover |
| Handed over | They should be able to clock | See below |
| Revoked | It was revoked, through loss or offboarding | Issue another (§2.4) or check whether the offboarding is correct |

If it shows as **Handed over** and they still cannot clock, **they can clock
with their code and their PIN at the tablet** while what is going on is worked
out: that does not wait. If it happens to several people at once, or the board
warns that there are cards signed with a key the server no longer recognises,
**it is a matter for IT**: [`operation.md`](operation.md).

### …the inbox fills up with identical incidents

It is usually one of two things, and neither is fixed by resolving them one by
one:

- **A threshold that does not fit your collective agreement.** If the whole
  workforce generates "Shift too long", the threshold is badly adjusted, not
  the workforce (§7).
- **A tablet with the wrong time** generates "Clock skew" one after another.
  The clockings are recorded; what has to be fixed is the tablet, and that
  belongs to IT ([`operation.md`](operation.md)).

### …there is a licence notice in the panel

**Everything keeps clocking and you keep having access to the whole record.** An
expired licence never stops clocking, nor consulting, nor correcting, nor the
export for the Labour Inspectorate: what gets degraded are accessory features
—for example, your own branding goes back to the product's, or the impact
dashboard (§6.6) stops being shown. Leaving you without
a working-time record over a commercial matter would leave you in breach of the
law, and this product does not do that. Tell whoever handles the relationship
with the provider; the detail is in
[`configuration.md`](configuration.md) §3 bis.3.
