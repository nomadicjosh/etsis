# Current student contact email in reports

Authorized staff can add **Current account email** to existing Report Studio reports. For example, a report can list active students, their names and the email addresses on their linked sign-in accounts. This completes the email-selection part of the old system's active-student reporting example.

The field is available in five existing sources:

- Students & current primary program
- Student programs & catalog cohorts
- Applications & admissions
- Academic credits & final grades
- Student account balances

This adds a field and its **Linked account contact** relationship. It does not add a report type or template. Applications remain student-related admissions data; their independent admissions authority still applies.

## Grant contact-reporting access

In **Roles & access**, explicitly grant `sis:reporting:contact` to an approved custom role or account. The permission description is **Include current linked-account contact email in student-related reports**.

Staff also need their usual report and source permissions. For example, a student contact report requires report execution, student read access and this contact permission. An application report retains its admissions permission; a financial account report retains its finance and student permissions. Report design and sharing keep their existing separate duties.

The permission migration adds a protected definition and its self-implication only. It grants no role or user access, and the ordinary `admin` role does not automatically acquire this future permission. The protected `super` role retains its existing authorization behavior. Viewing or editing a person's account through another workflow does not itself grant contact-reporting access.

Without the contact permission, the field and relationship are absent from the report designer's metadata. Supplying their names directly cannot bypass that boundary. Hidden filters, matching conditions, conditional counts, custom labels and named relationship copies require the same permission as a visible email column.

## Example: an active-student contact list

1. Open **Reports → Report Studio** and start **Students & current primary program**.
2. Select **Institutional ID** and **Student full name** as needed.
3. Add **Linked account contact** and select **Current account email**.
4. Set **Active student → equal → Yes**. The source can include inactive students unless the report explicitly filters them out.
5. Preview the report. If you need only known addresses, add an explicit **Current account email → is not empty** condition.
6. Save the definition for reuse, or use the existing private CSV or complete background-export workflow.

Running or exporting the report sends no message. To contact selected students, use the existing [reviewed messaging handoff](workflow-review.md#message-students-selected-from-a-saved-report), which independently resolves current recipients and checks Communications authority. A report's email column does not replace those delivery checks.

## What the email value means

The value comes from the exact sign-in account linked to the canonical person. It is a **current account email**, not an alternate contact address, mailing address, email from an application answer, historical correspondence address or proof of verified delivery. A disabled sign-in account can still retain an address.

No account is adopted by matching a name or email. An older person without an account link has an unknown value; a linked account's missing or blank email is never filled from another source. Reading a report creates no account, observation or historical event. The join adds at most one account row per person and does not multiply registration, credit or financial quantities. Existing distinct-count and monetary-grain controls continue to apply.

Account email corrections remain in the normal protected [person/account workflow](person-identity-and-accounts.md). A fresh dynamic report reflects the accepted current email. A static result keeps the value captured when it was prepared; a later email correction does not rewrite it. This report field does not independently replay or certify the person's historical identity or account stream.

## Sharing, exports and retention

Sharing a report or keeping a static result does not grant the contact permission. Current source and contact authority are checked on later execution and export. Complete background exports also check requester authority before preparation and publication, and again before download. Revoking access blocks those paths, including access to previously prepared outputs.

Existing static results and export chunks remain encrypted and subject to the report-retention controls. CSV output preserves spreadsheet-formula protection, including an email beginning with a formula-significant character. Browser responses remain private/no-store and suppress debug collection. Jobs retain the existing protected references rather than copying contact email into queue metadata.

Migration `20261126000006_AddSisReportContactPermission` adds only the permission metadata. It changes no person, account, student, application, academic or financial record, copies no email and rewrites no event. Postal-address reporting, further contact fields and remaining admissions/financial sources retain their separate [roadmap status](development-roadmap.md#5-reporting-populations-and-conversion).
