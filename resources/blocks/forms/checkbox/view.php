<?php

declare(strict_types=1);

use Application\View\WebsiteFormControls;

use function Codefy\Framework\Helpers\trans_html;

$id = WebsiteFormControls::id('confirmation');
$name = WebsiteFormControls::name($block->setting('checkbox-name'), 'confirmation');
$required = $block->setting('checkbox-required') === '1';
$label = trim($block->setting('checkbox-label')) ?: trans_html('Confirmation');
$help = $block->setting('checkbox-help');
?>
<div class="website-form-field mb-3">
    <div class="form-check">
        <input
            class="form-check-input"
            type="checkbox"
            name="<?= phpb_e($name) ?>"
            value="<?= $block->setting('checkbox-value') ?>"
            id="<?= $id ?>"
            <?= $required ? 'required' : '' ?>
            <?= $help !== '' ? 'aria-describedby="' . $id . '-help"' : '' ?>
        >
        <label class="form-check-label" for="<?= $id ?>">
            <?= $label ?>
            <?php if ($required) : ?>
                <span class="website-required"><?= trans_html('(required)') ?></span>
            <?php endif; ?>
        </label>
    </div>
    <?php if ($help !== '') : ?>
        <p class="form-text" id="<?= $id ?>-help"><?= $help ?></p>
    <?php endif; ?>
</div>
