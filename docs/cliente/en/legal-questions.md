# Questions for your employment law advisers — what the product cannot answer

**Eight closed questions.** Each one carries a line of context and says what is
done with the answer. The product cannot answer them: they depend on the
collective agreement, on the site and on the judgement of whoever is
responsible for the processing (RL-16). Take them to your employment law
advisers and to your data protection officer, if you have one, **before**
closing the data protection impact assessment
([`legal-obligations.md`](legal-obligations.md) §2) and as the first pass of
the regulatory watch
([`../../runbooks/vigilancia-normativa.md`](../../runbooks/vigilancia-normativa.md),
in Spanish).

This document is not legal advice: it is the list of what has to be asked.

| # | Question | Context in one line | Where the answer lands |
| --- | --- | --- | --- |
| 1 | For how many years, exactly, must the **contract data** (agreed hours, type of working day, validity period) and the **person's file** be kept after the end of the employment relationship? | Since 2.3.0 the retention purge deletes the contracts and removes name, email, ID document, PIN and photo from the file after the years set in the compliance profile. It is 4 by default, by reference to art. 21 LISOS, and **has not been validated by anybody**. | A number, which goes into the compliance profile (years the personnel file is kept) without changing the product. `legal-obligations.md` §4. |
| 2 | For how many years must **absences** be kept, and is the period the same for the "Sick leave" type and its note as for a holiday? | Same case as the previous one with an aggravating factor: "Sick leave" and the note are **data concerning health**, a special category under art. 9 GDPR, and art. 5.1.e requires not keeping data longer than necessary. Today **they are deleted with the file, with the same period**. | One or two numbers. If it is one and matches question 1, it goes into the same profile field. If sick leave needs its own period, **it forces a change to the product**: today it does not tell absences apart from the file. `legal-obligations.md` §4. |
| 3 | Is the legal basis for processing absences, and in particular **sick leave**, the one in art. 9.2.b GDPR (obligations under employment law), or is another one needed? | The working-time record is processed under art. 6.1.c in connection with art. 34.9 of the Workers' Statute, and that is written down; for the health data the product **asserts no basis** and it is not its place to. | One sentence for the art. 30 record of processing activities and for the DPIA. `legal-obligations.md` §1 and §2. |
| 4 | Is a **data protection impact assessment** (DPIA, art. 35 GDPR) required at this site: yes or no? | There is systematic observation of the whole workforce every day, there are no biometrics, no geolocation and no automated decisions, and **there is a special category** since absences are recorded. The conclusion has to be written down even if it is "not required". | Half a dated page, from the hotel. `legal-obligations.md` §2, DPIA section. |
| 5 | Is the **automatic detection of credential usage patterns** admissible within the employer's supervisory powers under art. 20.3 of the Workers' Statute and arts. 87 and 91 LOPDGDD, and with what prior information to the staff and their legal representatives? | Every night the system opens an incident when two people clock at the same tablet seconds apart on several days, or when one card shows up at two tablets without enough time to walk between them. **It cancels no clocking, sanctions nobody and notifies nobody outside the inbox and the manager's email**; it puts an indication in front of a person. | Yes/no + what has to be communicated and to whom, before leaving it switched on. `configuration.md` §2.1 and `legal-obligations.md` §3. |
| 6 | Can an indication from that detection be used in a **disciplinary procedure**, and with what safeguards under the collective agreement? | The product does not prevent it and cannot: the indication reaches a department manager, with a name, and nothing in the system labels it a conclusion. There is a written procedure that forbids treating it as evidence ([`../../runbooks/patron-anomalo-credencial.md`](../../runbooks/patron-anomalo-credencial.md), in Spanish), but it is a product procedure, not a legal safeguard. | Yes/no + conditions. If the answer is no, the department managers have to be told in writing. |
| 7 | Is it admissible for the **daily incident notice** to go out by email with the person's name and the incident type —including "Anomalous credential usage pattern"— to their department manager's mailbox? | It is the only path by which personal data leaves the server without anybody clicking anything, and since the latest version that notice can carry a suspicion of fraud next to a name. The weekly hours summary is the second channel and **ships switched off**. | Yes/no, and if no, you have to decide whether the notification is switched off or what it carries is changed (a request to the vendor). `legal-obligations.md` §2, "What leaves the server on its own". |
| 8 | Do you confirm that purging the working-time record after **4 years** is correct for this site, and what do we do with a period affected by an **open claim or inspection**? | The period comes from the compliance profile and the purge is **never automatic**: the system proposes and a person confirms. What the product does **not** have is a forced litigation hold: today the safeguard is that somebody does not confirm. | A number + an operating instruction for whoever confirms the purge (`operation.md`). If a forced hold is needed, it is a request to the vendor. |

**Question 2 is the only one that may force a change to the product**, if sick
leave needs a period different from the file's; question 1, and question 2 when
its answer matches, are applied in the compliance profile. While there is no
answer, do not let the first purge that reaches a personnel file apply the
default period: raise it in the profile (it accepts up to 30 years) and lower it
when your advisers answer. The others are answered in writing and filed with the DPIA.

While an answer is missing, the risk stands as accepted, with an owner and a
date, in the vendor's security review, which is where it is looked at again at
every phase close and every security review.

---

← [Legal obligations](legal-obligations.md) · [Configuration](configuration.md) · [Operation](operation.md)
