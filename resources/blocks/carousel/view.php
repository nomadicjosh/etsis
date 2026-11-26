<?php

declare(strict_types=1);

use function Codefy\Framework\Helpers\trans_attr;
use function Codefy\Framework\Helpers\trans_html;

$instance = 'carousel-' . bin2hex(random_bytes(8));
$count = filter_var($block->setting('carousel_item_count'), FILTER_VALIDATE_INT);
$itemCount = max(1, min(20, $count === false ? 2 : $count));
$status = trans_html('Slide {current} of {total}.');
$label = $block->setting('carousel_label') ?: trans_attr('Featured information');
?>
<div
    id="<?= $instance ?>"
    class="carousel slide website-carousel"
    role="region"
    aria-roledescription="<?= trans_attr('carousel') ?>"
    aria-label="<?= $label ?>"
    data-bs-interval="false"
    data-bs-touch="false"
>
    <div class="carousel-inner">
        <?php for ($index = 0; $index < $itemCount; $index++) : ?>
            <div
                class="carousel-item<?= $index === 0 ? ' active' : '' ?>"
                role="group"
                aria-roledescription="<?= trans_attr('slide') ?>"
                aria-label="<?= phpb_e(strtr($status, [
                    '{current}' => (string) ($index + 1), '{total}' => (string) $itemCount,
                ])) ?>"
            >
                [block slug="carousel-item" id="carousel-item-<?= $index ?>"]
            </div>
        <?php endfor; ?>
    </div>
    <div class="website-carousel-controls d-flex flex-wrap align-items-center gap-3 mt-3">
        <?php if ($itemCount > 1) : ?>
            <button
                class="btn btn-outline-primary"
                type="button"
                data-bs-target="#<?= $instance ?>"
                data-bs-slide="prev"
                aria-controls="<?= $instance ?>"
            >
                <i class="bi bi-chevron-left" aria-hidden="true"></i> <?= trans_html('Previous slide') ?>
            </button>
            <button
                class="btn btn-outline-primary"
                type="button"
                data-bs-target="#<?= $instance ?>"
                data-bs-slide="next"
                aria-controls="<?= $instance ?>"
            >
            <?= trans_html('Next slide') ?> <i class="bi bi-chevron-right" aria-hidden="true"></i>
            </button>
        <?php endif; ?>
        <p
            class="mb-0"
            role="status"
            aria-live="polite"
            aria-atomic="true"
            data-carousel-status
            data-slide-status-template="<?= phpb_e($status) ?>"
        ><?= phpb_e(strtr($status, ['{current}' => '1', '{total}' => (string) $itemCount])) ?></p>
    </div>
</div>
