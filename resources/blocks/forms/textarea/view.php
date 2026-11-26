<?php

declare(strict_types=1);

use Application\View\WebsiteFormControls;

use function Codefy\Framework\Helpers\trans_html;

$id = WebsiteFormControls::id('message');
$name = WebsiteFormControls::name($block->setting('textarea-name'), 'message');
$required = $block->setting('textarea-required') === '1';
$label = trim($block->setting('textarea-label')) ?: trans_html('Long answer');
$help = $block->setting('textarea-help');
$rows = WebsiteFormControls::length($block->setting('textarea-rows'), 20) ?? 4;
$maximum = WebsiteFormControls::length($block->setting('textarea-max-length'), 20000) ?? 4000;
?>
<div class="website-form-field mb-3">
    <label for="<?= $id ?>" class="form-label">
        <?= $label ?>
        <?php if ($required) : ?>
            <span class="website-required"><?= trans_html('(required)') ?></span>
        <?php endif; ?>
    </label>
    <textarea
        class="form-control"
        name="<?= phpb_e($name) ?>"
        id="<?= $id ?>"
        rows="<?= $rows ?>"
        maxlength="<?= $maximum ?>"
        placeholder="<?= $block->setting('textarea-placeholder') ?>"
        <?= $required ? 'required' : '' ?>
        <?= $help !== '' ? 'aria-describedby="' . $id . '-help"' : '' ?>
    ></textarea>
    <?php if ($help !== '') : ?>
        <p class="form-text" id="<?= $id ?>-help"><?= $help ?></p>
    <?php endif; ?>
</div>
