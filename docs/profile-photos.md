# Profile photos

Photos belong to the canonical person. The same photo appears in that person's workspace record and personal-information editor; changing student, staff or faculty capabilities does not create another photo identity.

Staff open **People → person → Manage profile photo**, or use **More person actions → Profile photo**. Owners use **Personal information → Profile photo** in the portal or the photo section of **My profile**. Workspace drawers retain **Back to record**.

## Enable private photo handling

Photo changes start disabled. Install the optional `fileinfo` and `imagick` PHP extensions; ZIP imports also need `zip`. Build the browser assets with `npm ci` and `npm run build:assets`. The cropper is the pinned MIT-licensed Cropper.js package; its code and license are served locally, without a CDN.

Configure the institution deliberately:

```dotenv
SIS_PERSON_PHOTOS_ENABLED=true
SIS_PERSON_PHOTO_SCANNER_REVIEWED=true
SIS_PERSON_PHOTO_RETENTION_DAYS=30
SIS_PERSON_GRAVATAR_ENABLED=false
```

Review the deployed scanner and image-processing security policy before enabling changes. Photo scanning reuses the admissions scanner adapter and `SIS_ADMISSION_DOCUMENT_SCANNER` executable setting. A missing, unavailable or unsuccessful scanner blocks a new image; it never publishes an unscanned photo. Scanner deployment is not performed by enabling the flag. ImageMagick's [security policy](https://imagemagick.org/security-policy/) describes deployment-level decoder, delegate and resource restrictions.

Choose the superseded-image retention period under the institution's policy: one through 365 days. This configuration concerns profile-display artifacts; formal identity/evidence documents retain their separate document policies. Current images remain until explicitly replaced or removed. Recorded photo-selection facts and their reasons remain protected history.

For the stated upload limits, configure the web PHP interpreter and front-end server to accept a 10 MiB upload, with `post_max_size` above the complete multipart request size, for example 16 MiB. PHP CLI and web settings may differ. Use HTTPS for camera access; camera availability and permission remain controlled by the browser and device.

## Choose, crop and review

1. Choose one `.png` or `.jpg`, or drop it into the chooser. Each original is limited to 5 MiB and four million pixels; the server also rejects invalid or mismatched content. `.jpeg`, GIF, SVG, WebP and other formats are not accepted.
2. Move the square crop by dragging or using the named move/zoom buttons. All buttons support ordinary keyboard activation. The server independently decodes, orients, scans and normalizes the result to a static 512 × 512 JPEG, removing private image metadata.
3. Enter the reason for the change and choose **Review photo change**. Nothing becomes the selected photo yet.
4. Check the preview, person and reason, then choose **Confirm photo change**. Editing invalidates that review, including a delayed response to an earlier draft.

**Use camera** requests video only after a deliberate click. Capture a frame and crop it like an upload. **Stop camera**, capturing, hiding the page, leaving the drawer and page exit stop the acquired tracks. Declined camera permission leaves the file chooser available. Camera capture is a source of an image, not an identity-verification decision.

If another change makes a confirmation stale, **Reload current photo** keeps the local crop and reason while updating the selected record. Review again before confirming. Lost access clears private images and controls. **Discard photo draft** removes the unaccepted working artifact; page exit clears local buffers, while interrupted server drafts expire after twenty minutes. No image, reason or proof is saved in browser storage.

## Institution-managed photos

An accepted staff upload, camera capture or import is an **institution-managed photo**. Staff require both `sis:person:manage` and the separate `sis:person:photo:manage` duty. No ordinary role receives that duty automatically. Existing protection of super-administrator person records also applies.

An owner cannot replace an institution-managed photo with an upload, camera frame or Gravatar, or remove it. These restrictions are enforced on the server even if a caller bypasses the screen. An authorized staff correction uses a new reasoned review and preserves the earlier choice. Staff can explicitly remove the institution photo; that releases the personal-photo lock without erasing the staff decision.

An owner changing an ordinary personal photo needs their current enabled sign-in account and its exact person link. A staff permission does not turn an owner request into an institutional one; the entry points and confirmation proofs remain distinct.

## Optional Gravatar

Enable `SIS_PERSON_GRAVATAR_ENABLED` only if the institution permits this option. An owner must explicitly consent before **Review my Gravatar** sends a SHA-256 hash of their current email to the fixed Gravatar image endpoint. A staff member cannot consent on another person's behalf.

The server retrieves the image with bounded HTTPS, scans and normalizes it, then presents the same private review. It saves a local protected copy after confirmation. Later page visits read that copy and do not contact Gravatar. A new request is required to refresh it. Changing the sign-in email invalidates an unfinished Gravatar review. The temporary email hash is removed with the review metadata after acceptance. See [Gravatar's image API](https://docs.gravatar.com/sdk/images/) for its hash and image-request contract.

## Import institution photos

Open **Import data → Import institution photos**, or the same action under a person's **More person actions**. Importing additionally requires `sis:import:manage`.

1. Choose or drop one PNG/JPG or a flat ZIP up to 10 MiB. A ZIP can contain at most 200 photos, with no folders, nested archives, paths, hidden files, encrypted entries, symlinks or duplicate filenames. Expanded content is bounded to 100 MiB, and each image must meet the individual limits.
2. Select **Exact institutional ID or person ULID** or **Exact first_last or first,last name**. Examples are `01M85RGDAGE378W3TF41NTR156.jpg`, `S-EXAMPLE-001.png` and `joshua_parker.jpg`. Use the person ULID when an institutional ID or name cannot safely form a filename.
3. Review the paged matches and reason. A name must identify exactly one current person; comparison preserves accents and punctuation and does not guess from a database's looser comparison. Ambiguous names, unmatched files or two files targeting the same person block submission. Correct the source file and review again. No person or account is created by matching.
4. Choose **Queue photo import**. This queues work; it does not process images in the browser. Keep the standard server worker running:

```sh
php codex sis:imports:run --limit=2
```

The worker processes one photo per step, checks current requester permissions and exact reviewed person/photo versions, scans the image and records its institutional selection atomically with the checkpoint. It can continue after the browser closes. A changed remaining source or failed image stops the remaining work for attention; earlier completed photos stay accepted. Correct the problem and review the remaining files in a new import rather than replaying completed files.

**Import activity** shows the owner-only state and counts. **Cancel remaining import steps** stops a queued/running import at a transaction boundary: an already-running step can finish first, and accepted photos remain. The cancelled job cannot be restarted by retrying its original submission; review a new import for the remaining files. This stop action is shared with the other background import destinations.

The existing notification bell announces completion, cancellation or a stop needing attention. Deleting that personal notification permanently removes the notice, not the person's photo or selection history. Original submission retries do not create a second job. Exact accepted per-image retries cannot restore an earlier photo over a later correction.

## Storage and retention

Working manifests and bounded image/archive chunks are encrypted in `sis_photo_files` and `sis_photo_payloads`. They are private CRUD artifacts, with no public directory, arbitrary file URL or image bytes in the business-event journal. Artifact identity, chunk ordering/content and disposition deadlines are authenticated. The selected photo, source, institution flag, original actor/reason and import source reference use `person_photo` streams in the existing protected journal. Lookup metadata cannot silently disable the institution-photo lock.

The final successful import step deletes its original source archive in the same transaction. Abandoned or interrupted import archives expire after two days. Unaccepted individual reviews expire after twenty minutes; replaced image bytes expire on their captured institutional deadline. Changing today's policy does not rewrite an earlier deadline. Accepted removal immediately discards its working review while retaining the selection event.

An enabled authorized operator performs bounded cleanup:

```sh
php codex sis:photos:purge --actor=OPERATOR_ACCOUNT_ULID --limit=10
```

The operator needs current person/photo duties. Cleanup checks authenticated deadlines, refuses to delete the current protected image, and records its required metadata audit. The same command removes up to twenty abandoned private ZIP processing scratch files older than a day; a normal read removes its own scratch immediately. Keep `storage/framework/photos` private and outside public assets and backups of authoritative material. No uploaded archive entry is extracted to a filename supplied by a user.

Back up the database, the application artifact-encryption key and the configured historical keyring together. Deleting superseded pixels does not erase the recorded choice, and a retained event does not recreate pixels intentionally disposed of under policy. S3 artifact offload, full institutional backup/restore, live scanner/provider/device acceptance, production load/native locking and independent accessibility qualification remain separate release work. No student report or new notification category is introduced by photos.
