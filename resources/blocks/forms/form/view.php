<?php

declare(strict_types=1);

use Application\View\WebsiteFormControls;
use Domain\WebsiteForms\ValueObject\WebsiteFormDefinition;

use function Codefy\Framework\Helpers\trans_html;
use function Codefy\Framework\Helpers\view;

try {
    $encoded = $block->setting('website-definition', true);
    $definition = new WebsiteFormDefinition(json_decode($encoded, true, 32, JSON_THROW_ON_ERROR));
    echo view('framework::frontend/website/form', [
        'definition' => $definition->facts,
        'instance' => WebsiteFormControls::id($definition->facts['key']),
        'endpoint' => '/portal/api/website-forms/' . rawurlencode((string) $page->getId()) . '/'
            . rawurlencode($definition->facts['key']) . '/',
        'locale' => phpb_current_language(),
        'preview' => phpb_in_editmode(),
    ])->getBody();
} catch (\JsonException | \TypeError | \Domain\Shared\Exception\ValidationException) {
    ?>
    <div class="alert alert-secondary" role="status"><?= trans_html('This website form is not available yet.') ?></div>
    <?php
}
