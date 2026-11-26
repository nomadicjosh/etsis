<?php

declare(strict_types=1);

use Application\View\WebsiteFormControls;

use function Codefy\Framework\Helpers\trans_html;

$id = WebsiteFormControls::id($block->setting('input-id'));
$type = WebsiteFormControls::inputType($block->setting('input-field-type'));
$name = WebsiteFormControls::name($block->setting('input-field-name'), 'text');
$required = $block->setting('input-required') === '1';
$label = trim($block->setting('input-label-text')) ?: trans_html('Text field');
$help = $block->setting('input-help');
$maximum = WebsiteFormControls::length($block->setting('input-max-length'), 4000);
?>
<?php if ($type === 'hidden') : ?>
    <input type="hidden" name="<?= phpb_e($name) ?>" id="<?= $id ?>">
<?php else : ?>
    <div class="website-form-field mb-3">
        <label for="<?= $id ?>" class="form-label">
            <?= $label ?>
            <?= $required ? trans_html('(required)') : '' ?>
        </label>
        <input
            type="<?= $type ?>"
            name="<?= phpb_e($name) ?>"
            class="form-control"
            id="<?= $id ?>"
            placeholder="<?= $block->setting('input-placeholder') ?>"
            <?= $required ? 'required' : '' ?>
            <?= $help !== '' ? 'aria-describedby="' . $id . '-help"' : '' ?>
            <?= $maximum !== null && in_array($type, ['text', 'email', 'password', 'tel', 'url'], true)
                ? 'maxlength="' . $maximum . '"' : '' ?>
        >
        <?php if ($help !== '') : ?>
            <p class="form-text" id="<?= $id ?>-help"><?= $help ?></p>
        <?php endif; ?>
    </div>
<?php endif; ?>
