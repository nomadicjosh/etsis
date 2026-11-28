<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\trans;

return [
    "title" => trans("Profile photo"),
    "edit" => trans("Manage profile photo"),
    "current" => trans("Current profile photo"),
    "none" => trans("No profile photo has been selected."),
    "institution" => trans("Institution-managed photo"),
    "locked" => trans(
        "Your institution manages this photo. Contact staff for a correction.",
    ),
    "staff_help" => trans(
        "Staff changes are institution-managed. Review the person and image before confirming a correction.",
    ),
    "disabled" => trans(
        "Photo changes are unavailable until private storage, scanning and retention are configured.",
    ),
    "readonly" => trans(
        "You can view this photo. Changing an institution photo requires the photo-management duty.",
    ),
    "upload" => trans("Choose a PNG or JPG"),
    "drop" => trans(
        "Drop one PNG or JPG here, or use the file chooser. Maximum 5 MiB and four million pixels.",
    ),
    "crop" => trans("Crop your photo"),
    "crop_help" => trans(
        "Drag the square or use the move and zoom buttons. The saved image is a square with private metadata removed.",
    ),
    "left" => trans("Move crop left"),
    "right" => trans("Move crop right"),
    "up" => trans("Move crop up"),
    "down" => trans("Move crop down"),
    "zoom_in" => trans("Zoom crop in"),
    "zoom_out" => trans("Zoom crop out"),
    "reset" => trans("Reset crop"),
    "camera" => trans("Use camera"),
    "camera_view" => trans("Live camera preview"),
    "camera_help" => trans(
        "Camera access starts only when requested. Capture a frame or stop the camera when finished.",
    ),
    "capture" => trans("Capture photo"),
    "stop" => trans("Stop camera"),
    "camera_error" => trans(
        "The camera is unavailable or permission was declined. You can choose a photo file.",
    ),
    "gravatar" => trans("Review my Gravatar"),
    "consent" => trans(
        "I agree to send a hash of my current email address to Gravatar to retrieve my photo.",
    ),
    "consent_required" => trans(
        "Confirm your Gravatar consent before requesting a photo.",
    ),
    "gravatar_help" => trans(
        "Gravatar is contacted only after your request. The reviewed photo is saved privately; later "
        . "visits do not contact Gravatar.",
    ),
    "reason" => trans("Reason for photo change"),
    "reason_default" => trans("Update my profile photo."),
    "review" => trans("Review photo change"),
    "confirm" => trans("Confirm photo change"),
    "review_title" => trans("Review the selected photo"),
    "review_help" => trans(
        "Confirm only after checking the person, cropped photo and reason. Editing requires a new review.",
    ),
    "clear" => trans("Review removing the photo"),
    "removed" => trans(
        "The current photo will be removed. Its selection history remains retained.",
    ),
    "cancel" => trans("Discard photo draft"),
    "saved" => trans("Profile photo updated."),
    "refresh" => trans("Reload current photo"),
    "reload_help" => trans(
        "Reloading keeps your cropped image and reason. Review again against the current photo.",
    ),
    "denied" => trans(
        "Photo access is no longer available. Reopen the person or sign in again.",
    ),
    "error" => trans(
        "The photo task could not be completed. Check the file and current record, then review again.",
    ),
    "image_error" => trans("The private photo could not be loaded."),
    "file_error" => trans(
        "Choose a valid PNG or JPG up to 5 MiB and four million pixels.",
    ),
    "back" => trans("Back to record"),
    "imports" => trans("Import institution photos"),
    "import_help" => trans(
        "Review exact filename matches, then queue server processing. A private notification announces "
        . "completion or items needing attention.",
    ),
    "import_limits" => trans(
        "One PNG/JPG or a flat ZIP up to 10 MiB; at most 200 photos, each up to 5 MiB. No folders or "
        . "duplicate filenames.",
    ),
    "import_file" => trans("Photo or ZIP file"),
    "match" => trans("Match filenames using"),
    "match_id" => trans("Exact institutional ID or person ULID"),
    "match_name" => trans("Exact first_last or first,last name"),
    "match_help" => trans(
        "Names must identify exactly one person. Unmatched, ambiguous or duplicate people block "
        . "submission; correct the filenames and review again.",
    ),
    "import_reason" => trans("Reason for institution photo import"),
    "import_review" => trans("Review photo matches"),
    "import_queue" => trans("Queue photo import"),
    "import_ready_one" => trans("One photo match is ready for server processing."),
    "import_discard" => trans("Discard import draft"),
    "filename" => trans("Filename"),
    "person" => trans("Matched person"),
    "matched" => trans("Exact match"),
    "unmatched" => trans("No unique match"),
    "duplicate" => trans("Person selected more than once"),
    "import_ready" => trans(
        "{count} photo matches are ready for server processing.",
    ),
    "import_blocked" => trans(
        "Resolve the filename matches before queuing this import.",
    ),
    "import_saved" => trans(
        "Photo import queued. Processing continues on the server after you leave this screen.",
    ),
    "activity" => trans("Open import activity"),
    "next" => trans("Next photo matches"),
    "previous" => trans("Previous photo matches"),
];
