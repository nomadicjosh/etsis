<?php

declare(strict_types=1);

use Application\View\WebsiteNotice;

use function Codefy\Framework\Helpers\trans;

$notice = WebsiteNotice::fromSettings([
    'alert-context' => $block->setting('alert-context', true),
    'alert-label' => $block->setting('alert-label', true),
    'alert-dismissible' => $block->setting('alert-dismissible', true),
]);
$labelId = 'website-notice-' . bin2hex(random_bytes(8));
$dismissible = $notice->dismissible && !phpb_in_editmode();
?>
<aside
    class="alert website-notice <?= phpb_e($notice->context) ?> <?= $dismissible ? 'alert-dismissible' : '' ?>"
    role="note"
    aria-labelledby="<?= $labelId ?>"
    data-website-notice
>
    <?php if ($dismissible) : ?>
        <button
            type="button"
            class="btn-close"
            aria-label="<?= phpb_e(trans('Dismiss notice') . ': ' . $notice->label) ?>"
            data-website-notice-dismiss
            hidden
        ></button>
    <?php endif; ?>
    <div class="website-notice-label d-flex flex-wrap align-items-baseline gap-2 fw-semibold mb-2" id="<?= $labelId ?>">
        <i class="bi bi-<?= phpb_e($notice->icon) ?>" aria-hidden="true"></i>
        <span><?= phpb_e($notice->label) ?></span>
        <?php if ($notice->label !== $notice->tone) : ?>
            <span class="small fw-normal">(<?= phpb_e($notice->tone) ?>)</span>
        <?php endif; ?>
    </div>
    <div class="website-notice-body">
        [block slug="alert-text"]
    </div>
</aside>
