# Withdrawal credit schedules

Institutions can publish dated account-credit percentages alongside the existing late-withdrawal grade periods. A withdrawal keeps its original registration charges and posts the scheduled reduction as linked credits. It does not send money to the student. [Payment refunds](workflow-review.md#refund-a-payment) remain separate reviewed Finance decisions.

## Configure a schedule

Open **Courses → Terms → Term record → Drop / withdrawal policy**. Each late-withdrawal period has a **Through date (inclusive)**, a catalog grade and an optional **Account credit (% of original registration charges)**. Enter a percentage from 0 through 100 with at most two decimal places. The last period covers all later eligible dates. You can repeat the same grade in several periods when the credit percentage changes before the grade does.

For example, after the drop/add deadline, retain W and credit 75% through September 1, 50% through September 8 and 0% afterward. A withdrawal effective September 1 receives the 75% schedule. The policy uses the retained institutional timezone and effective withdrawal date; it does not measure elapsed days from a student's registration.

Enter a reason, choose **Review policy**, inspect the dates, grades and percentages, then **Publish policy**. Publishing, replacing or removing a nonzero credit schedule requires both `sis:catalog:manage` and `sis:finance:post`. Catalog staff without finance posting permission can read the schedule; its percentage fields are disabled. No existing role receives new permission automatically.

Sections follow the term policy unless staff publish an override from **Courses → Sections → Section record → Drop policy**. An override can set its own drop/add deadline and late periods. Replacing an inherited financial schedule also requires finance posting permission. A changed inherited policy invalidates an unconfirmed section review. Returning to term inheritance makes later term publications govern new decisions for that section.

Blank percentages preserve the existing no-scheduled-credit behavior. Explicit zero also posts no scheduled credit. No earlier policy or completed withdrawal is retrospectively assigned a percentage. Early removal continues to reverse its original registration charges under the existing drop/add rule.

## Review and confirm a withdrawal

The individual and selected-course withdrawal reviews in the workspace and student portal show the additional account credit due before confirmation. The institution must publish the financial policy first; students and registration staff do not need finance posting authority to apply that approved policy through an otherwise authorized withdrawal. They cannot enter a different credit amount.

The guided hiatus/withdrawal plan shows the same additional credit for each eligible course, using the plan's effective date. Only selected courses receive credits. Its confirmation binds the reviewed charge evidence and rechecks every selected financial outcome after the final case/activity audit. The retained case references each original drop operation; the exact credit and policy evidence remain in that drop receipt and the protected account history. Section changes remain restricted to early removal and keep their existing charge-reversal behavior.

The percentage applies separately to each original charge explicitly linked to that enrollment: the section fee and retained per-credit tuition/fee charges. It excludes independent flat term fees and flat term tuition. Today's billing rates do not reprice the original charges.

For each charge, round the scheduled percentage to the nearest currency minor unit, with an exact half rounded upward. Subtract all earlier active linked credits from that target. For example, a $100.01 charge at 50% has a $50.01 target. If Finance already credited $20, the withdrawal adds $30.01. An earlier $70 credit stays intact and produces no additional scheduled credit. Reversed charges receive no new credit. A free registration does not create a financial account solely to record zero.

Confirmation saves the withdrawal, academic outcome, linked account credits, projections, required audit and original receipt in one transaction. A changed relevant charge, policy, identity or authority requires another review. A failed confirmation leaves no partial withdrawal or credit. Selected courses are confirmed together; their reviews show each course's amount rather than combining unrelated currencies. Whole-account balances, account identities and unrelated receipts are not included in the registration review.

If the response is interrupted, use **Check saved drop** or **Check saved drops**. Repeating an accepted operation returns its original outcome without another posting, even after later policy changes or financial resolution. The existing student statement shows the linked charge credit; any cash refund still requires its own authorized payment-refund workflow.

## Resolve a withdrawal credit before reinstatement

A tracked withdrawn attempt with an active withdrawal-policy credit cannot be reinstated. Finance must first resolve that credit deliberately through the existing reviewed reversal workflow, checking any related payment refund separately. Then the registrar can obtain a new reinstatement review. Reinstatement itself never reverses money, reprices a course or repeats a charge. Earlier manual credits and withdrawals without policy credits retain their existing behavior.

## Scope and historical facts

The immutable policy reference, percentage, original amount, earlier credited amount, effective date and withdrawal operation are retained with each new linked credit in the existing protected account journal. Earlier unmarked account credits retain their original meaning. Original receipts and issued documents remain unchanged. No schema migration, additional event table or historical backfill is required.

This completes dated registration-charge account credits. Broader institution-wide/flat-term refund rules, retrospective financial reassessments, installment arrangements and external payment settlement remain separate roadmap work.
