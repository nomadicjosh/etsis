<?php

use Codefy\Framework\Proxy\Codefy;

//phpcs:disable
?>

<!DOCTYPE html>
<html lang="<?=phpb_e(Codefy::$PHP->configContainer->string('app.language'));?>">
<head>
    <meta charset="<?=phpb_e(Codefy::$PHP->configContainer->string('app.charset'));?>">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="description" content="<?=phpb_e($page->getTranslation('meta_description'));?>">
    <title><?=phpb_e($page->getTranslation('meta_title'));?></title>
    <!-- Favicon-->
    <link rel="icon" type="image/x-icon" href="<?=phpb_theme_asset('images/favicon.ico');?>">
    <!-- Bootstrap icons-->
    <link href="/assets/vendor/icons/bootstrap-icons.min.css" rel="stylesheet">
    <link href="/assets/css/site-navigation.css" rel="stylesheet">
    <!-- Core theme CSS (includes Bootstrap)-->
    <link href="<?=phpb_theme_asset('css/style.css');?>" rel="stylesheet">
</head>
<body class="d-flex flex-column h-100">
<main class="flex-shrink-0">
    [block slug="bb-navbar"]
    <?=$body;?>
</main>
[block slug="bb-footer"]
<!-- JQuery-->
<script src="/assets/vendor/jquery.min.js"></script>
<!-- Bootstrap core JS-->
<script src="/assets/vendor/bootstrap.bundle.min.js"></script>
<script src="/assets/js/site-navigation.js"></script>
<!-- Core theme JS-->
<script src="<?=phpb_theme_asset('js/script.js');?>"></script>
</body>
</html>
