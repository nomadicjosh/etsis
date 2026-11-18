# Admission offers and applicant responses

An **admissions acceptance** is the institution’s decision. An **offer acceptance** is the applicant’s response to the institution’s letter. Matriculation creates the student and initial program pursuit. These are separate steps; accepting a letter does not create a second person or automatically create a student.

## Staff workflow

1. Open the application in the workspace and record the admissions decision using its existing review and confirmation.
2. Once accepted, open **Admission offer** and choose **Issue offer**. Enter a title, the letter and an optional response deadline, followed by the private reason. Review the complete letter and confirm it.
3. The applicant opens **Admission offer** on their portal application page, chooses **Accept offer** or **Decline offer**, reviews the response and confirms it. Admissions can then review matriculation under the applicable policy.

Issuing an offer adds a private admissions inbox update. The notice contains a reference to the application, not a copy of the letter. The inbox links to the application and offer. This workflow does not send email or call an external provider.

A deadline includes the entire selected date in the institution’s timezone. A response must still be available when confirmed. An accepted response remains valid after that deadline. Pending expired offers, declined offers and cancelled offers require staff review and a replacement offer before matriculation. Declining an offer does not itself withdraw the application or close any person, account or existing student record.

Staff with offer authority can record an acceptance or decline received outside the portal. They must supply a documented response and reason. History distinguishes this staff-recorded action from an applicant’s own portal response. An applicant cannot replace a letter, change institutional policy or perform a staff action through an owner endpoint.

## Institutional policy

Applicant acceptance is **required by default**. Authorized staff open **Admissions → Policies**, or **Admission offer → Admissions policy** on an application, to change whether new offers require acceptance before matriculation. The setting shares institutional Admissions policy setup with applicant withdrawal, with independent revisions. It is database controlled, revision checked and audited. It applies institution-wide; a letter retains the policy under which it was issued. Changing the default does not change an already issued letter or a completed matriculation.

Where acceptance is optional, staff may matriculate an accepted application without issuing an offer or waiting for a pending, unexpired offer response. An explicit decline, cancellation, expired pending letter or changed recipient still requires offer review. To change the acceptance requirement for an existing letter, issue a replacement under the new policy and obtain any newly required response.

Staff need `sis:admissions:manage` to read letters and review matriculation. Issuing, replacing, cancelling and recording responses also require the independent `sis:admissions:offers` permission. Changing policy also requires `sis:admissions:policy`. The protected system permission definitions do not automatically grant these duties to ordinary admissions users or the regular admin role. Super administrators retain their existing all-access authority; other assignments are deliberate.

Run the current database migrations before using this feature. Migration `20261117000006_CreateSisAdmissionOfferPolicy` introduces offer settings and permissions; `20261117000007_CreateSisAdmissionWithdrawalRequests` consolidates settings into `sis_admission_policy` while preserving the original offer value and revision. Neither migration converts application facts or creates another event journal.

## History and concurrent work

Letters and responses append to the application’s existing `admission_decision` stream in the single protected journal. Their event retains the recipient account, exact title and wording, deadline, policy requirement/revision, action, channel and admissions decision reference. Each operation advances the application revision even though the application remains accepted. Protected context retains the acting account, reason and accepted time. Matriculation retains the offer/policy proof used for its decision.

**Replace offer** keeps the earlier letter and response in history and starts a fresh pending offer. Correcting an admissions decision or returning an application to review does not carry an earlier offer acceptance into a later decision. That decision needs a new offer when acceptance is required. A person/account relink does not transfer an earlier addressed letter to the new account.

Staff application history includes earlier letters and responses. Applicant reads expose their current addressed offer, not private reasons or raw event streams. Earlier accepted applications can receive an explicitly recorded present-day offer, but the system does not invent their original acceptance date. Recorded matriculation receipts remain valid under their original facts.

Review makes no writes. Confirmation checks the selected application, current permissions or exact owner, revision, policy, deadline and protected history under locks. It commits the event, current application revision, required audit and any inbox update atomically. Expired or changed unconfirmed reviews require a fresh review. Retrying a successful confirmation returns its original result, even after a replacement or matriculation, without applying that action again. Current authority is still required to retrieve a receipt.

Offers load only when staff or an applicant opens the offer panel. Ordinary application lists continue using bounded read-model queries. Selected histories use the existing bounded replay; no per-row replay, browser storage, background email or antivirus process is added.

[Applicant withdrawal and staff resolution](admissions-decisions.md#applicant-withdrawal) now pause offers and matriculation while a request is pending. Deferment, editing/resubmission, deposits, financial awards and additional institutional approval stages remain separate workflows. A post-matriculation rescission must coordinate student/program and financial consequences and cannot be performed by cancelling an admissions offer.
