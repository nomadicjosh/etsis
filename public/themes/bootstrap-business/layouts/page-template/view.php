<?php

declare(strict_types=1);

use Codefy\Framework\Proxy\Codefy;

use function Codefy\Framework\Helpers\trans_html;

?>
<!DOCTYPE html>
<html lang="<?= phpb_e(Codefy::$PHP->configContainer->string('app.language')) ?>">
    <head>
        <meta charset="<?= phpb_e(Codefy::$PHP->configContainer->string('app.charset')) ?>">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="<?= phpb_e($page->getTranslation('meta_description')) ?>">
        <title><?= phpb_e($page->getTranslation('meta_title')) ?></title>
        <link rel="icon" type="image/x-icon" href="<?= phpb_e(phpb_theme_asset('images/favicon.ico')) ?>">
        <link href="/assets/vendor/icons/bootstrap-icons.min.css" rel="stylesheet">
        <link href="/assets/css/site-navigation.css" rel="stylesheet">
        <link href="<?= phpb_e(phpb_theme_asset('css/style.css')) ?>" rel="stylesheet">
        <link href="/assets/css/website-blocks.css" rel="stylesheet">
        <script defer src="/assets/js/website-blocks.js"></script>
        <script defer src="/assets/js/website-forms.js"></script>
    </head>
    <body class="d-flex flex-column h-100">
        <a class="website-skip-link" href="#website-main"><?= trans_html('Skip to main content') ?></a>
        [block slug="bb-navbar"]
        <main id="website-main" tabindex="-1" class="flex-shrink-0">
            <?= $body ?>
        </main>
        [block slug="bb-footer"]
        <script src="/assets/vendor/jquery.min.js"></script>
        <script src="/assets/vendor/bootstrap.bundle.min.js"></script>
        <script src="/assets/js/site-navigation.js"></script>
        <script src="<?= phpb_e(phpb_theme_asset('js/script.js')) ?>"></script>
    </body>
</html>
