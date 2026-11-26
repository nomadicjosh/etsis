# Newsletter and lead-list connections

A website form can optionally send a consenting visitor's email and mapped name to **Sendy**, **Mailchimp**, **Mautic** or a **signed webhook**. This is independent of admissions applications, campus person accounts and academic history. Contacts are delivered by a bounded server worker, after the form submission has been accepted. The browser never calls a newsletter provider.

## Enable and configure a connection

1. An operator enables server delivery with `SIS_NEWSLETTER_ENABLED=true`. It is off by default. Add exact trusted Sendy, Mautic or webhook hostnames to `SIS_NEWSLETTER_HOSTS`, separated by commas; do not include schemes or paths. Mailchimp's `usN.api.mailchimp.com` hosts are recognized directly. Connections can be prepared while global delivery is off.
2. Grant the separate **`sis:website:forms:integrations`** permission to the staff responsible for credentials and destinations. This does not grant inquiry read/manage access or department membership. The migration creates its protected definition without granting it to ordinary staff.
3. Open **Newsletter connections** from the workspace or website manager (`/sis/website/newsletters/view/`) and choose **Add connection**. Enter a meaningful name, provider, HTTPS service address, list reference and credentials. A paused connection can be prepared before its host is allowed; enabling it requires the allowed host. Webhook secrets must contain at least 32 bytes.
4. Enable the connection when ready. Enable the existing application scheduler to process `sis:website-forms:subscribe --apply --limit=1` each minute. Without `--apply` the command is a dry run and contacts no provider. Manual batches allow at most three jobs and stop within the worker's execution budget. Run one subscription worker per institution; provider-side email deduplication remains important when other integrations also create contacts.

| Service | Service address | Destination and credentials |
| --- | --- | --- |
| Sendy | Installation root, such as `https://news.example.edu/sendy` | Sendy list ID and API key. |
| Mailchimp | Server root, such as `https://us19.api.mailchimp.com` | Audience/list ID and API key or access token. Omit `/3.0` from the configured root. |
| Mautic | Installation root, such as `https://leads.example.edu` | Numeric segment ID. Use an OAuth access token, or an API username/password when Basic authentication is enabled in Mautic. Leave the username blank for Bearer-token authentication. |
| Signed webhook | Exact HTTPS receiver URL, including any required trailing slash | A destination/list reference and a signing secret. The receiver must honor `Idempotency-Key`. |

Credentials and private destination details are encrypted on the server. Listings and form designs contain no API keys, passwords, signing secrets or upstream responses. Editing a connection displays an empty credential control; leave it blank to retain the stored value or enter a replacement to rotate it. A Mautic Basic username is also retained when blank; create a new connection when switching between Basic and Bearer authentication. Bearer tokens are supplied/rotated by the operator; an OAuth browser authorization/refresh wizard is not included.

The provider and destination are fixed for each connection. Use a new connection to change a list or endpoint, then review the form's new choice through page publication. Relabelling, pausing or rotating credentials does not silently retarget an existing form or queued subscription. HTTPS certificate verification stays enabled; redirects, URL credentials and query-string credentials are rejected. Custom hosts require explicit operator configuration rather than being arbitrary outbound request destinations.

## Connect a form and collect consent

Select the **Website form** on the canvas and choose **Settings → Edit website form → Newsletter subscription (optional)**. Choose an enabled connection, map a required unconditional email field, optionally map a short-answer name field and choose a separate **optional** consent checkbox. **Add newsletter consent checkbox** creates an unchecked, clearly labelled newsletter choice. An existing required contact/privacy checkbox cannot be used as subscription consent. Conditional consent and conditional email fields are not supported.

The visitor can submit the inquiry without subscribing. Only a checked boolean consent answer creates a delivery job. No name is sent when the name mapping is empty. Mapped names are limited to 120 characters for provider compatibility; the original inquiry retains the full answer. No message body, other form answers, person/student identifiers, academic records, IP address or browser metadata is sent. A custom webhook additionally receives the original public consent label and server receipt time.

**Use this form design**, save the page and complete its normal publication review. The saved definition binds the exact connection and destination fingerprint. Changing a connection selection requires fresh publication; a credential rotation for the same destination does not. Use a confirmation message that acknowledges receipt rather than promising immediate subscription, because provider confirmation may still be pending.

## Provider behavior

Sendy is queried for existing membership before subscription. The adapter keeps the list's own opt-in behavior and does not bypass double opt-in. Existing unconfirmed members are not sent a fresh subscription request; unsubscribed, bounced or complained addresses are not forced back onto a list. See the [Sendy API](https://www.sendy.co/api).

Mailchimp uses the deterministic member endpoint and `status_if_new=pending`. It does not change an existing contact's status or override unsubscribed/cleaned/transactional restrictions. A new contact normally needs the provider's confirmation email before becoming subscribed. Existing merge-field requirements remain provider requirements; they are not bypassed. See [Mailchimp member upsert](https://mailchimp.com/developer/marketing/api/lists/members/upsert-member) and [server-specific API addresses](https://mailchimp.com/developer/marketing/guides/run-async-requests-batch-endpoint/).

Mautic looks up an exact email, refuses ambiguous matches, creates a missing contact and adds that contact to the configured segment. It preserves email Do Not Contact restrictions. The name is sent as one first-name field; the application does not guess a surname. Additional confirmation/campaign behavior belongs to Mautic. See [contacts](https://devdocs.mautic.org/en/7.2/rest_api/contacts.html), [segment membership](https://devdocs.mautic.org/en/7.2/rest_api/segments.html) and [authentication](https://devdocs.mautic.org/en/7.3/rest_api/authentication.html).

The adapters were checked with injected fictional HTTP responses, not live customer service accounts. Qualify the configured provider and deployment with an authorized test contact before opening a form publicly.

## Signed webhook contract

The receiver gets an HTTPS JSON POST containing `operation_id`, `list`, `email`, `name`, `consented_at` and `consent_label`. It gets no inquiry message or unrelated answers. Headers include:

- `Idempotency-Key`: the stable subscription operation ULID.
- `X-ETSIS-Signature`: `sha256=` followed by the hexadecimal HMAC-SHA256 of the exact request bytes, using the configured signing secret.

Verify the signature with a constant-time comparison, apply an operation once and retain the deduplication receipt for as long as retries may occur. Repeating that key returns the original acknowledgement. The operation, body and signature stay identical on retries. Respond with a successful 2xx status only after accepting responsibility for the subscription. The receiver must implement its own provider mapping and suppression/opt-out behavior. This permits another newsletter service or a trusted integration platform without exporting a complete inquiry.

## Delivery status, retries and deletion

Open an authorized inquiry in **Form inbox** to see **Newsletter delivery**. Department/form membership and read permission still apply. **Sent to service** means that the provider accepted the request or already had the member; a pending confirmation is shown separately. It does not claim that the visitor opened an email or finished provider confirmation.

Temporary failures receive bounded backoff and no more than three automatic attempts. Uncertain mutation results and interrupted processing stop for review instead of silently resending. Staff with current inquiry manage permission and matching scope can choose **Queue subscription retry**, inspect the guidance and explicitly confirm. Reusing a stale job version fails. The worker uses the current enabled connection and original retained consent/mapping, with no new recipient or destination chosen by the browser.

Jobs contain only record references, destination fingerprints, versions and fixed operational codes. Contact data is read from the existing encrypted inquiry only while needed. External calls have connection/response timeouts and response-size limits; raw upstream content is never retained in job state, logs or ordinary responses. One delivery and deletion of its inquiry serialize on that inquiry row; external calls hold that lock only for their bounded execution. Operators should retry a conflicting deletion after delivery finishes.

Deleting an inquiry physically removes its answers and subscription job together, including pending or completed job metadata. A lost-browser-response retry cannot recreate either. An already accepted provider subscription is separate from the local inquiry: deleting the local record does not silently unsubscribe or delete a provider contact. Use the provider's unsubscribe/contact-management process for that separate action. Inquiry consent evidence is retained only while the deletable encrypted inquiry exists; it is not copied to an event store.
