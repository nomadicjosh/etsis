<?php

declare(strict_types=1);

$instance = 'accordion-' . bin2hex(random_bytes(8));
$count = filter_var($block->setting('accordion_item_count'), FILTER_VALIDATE_INT);
$itemCount = max(1, min(20, $count === false ? 2 : $count));
?>
<div class="accordion" id="<?= $instance ?>">
    <?php for ($index = 0; $index < $itemCount; $index++) : ?>
        <div class="accordion-item">
            <h2 class="accordion-header" id="<?= $instance ?>-heading-<?= $index ?>">
                <button
                    class="accordion-button collapsed"
                    type="button"
                    data-bs-toggle="collapse"
                    data-bs-target="#<?= $instance ?>-panel-<?= $index ?>"
                    aria-expanded="false"
                    aria-controls="<?= $instance ?>-panel-<?= $index ?>"
                >
                    [block slug="accordion-header" id="heading-<?= $index ?>"]
                </button>
            </h2>
            <div
                id="<?= $instance ?>-panel-<?= $index ?>"
                class="accordion-collapse collapse"
                aria-labelledby="<?= $instance ?>-heading-<?= $index ?>"
                data-bs-parent="#<?= $instance ?>"
            >
                <div class="accordion-body">
                    [block slug="accordion-body" id="body-<?= $index ?>"]
                </div>
            </div>
        </div>
    <?php endfor; ?>
</div>
