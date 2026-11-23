<?php

use function Application\Shared\Helpers\menu_items;
use function Codefy\Framework\Helpers\config;
use function Codefy\Framework\Helpers\view;

//phpcs:disable
?>

<footer class="bg-dark py-4 mt-auto">
    <div class="container px-5">
        <div class="row align-items-center justify-content-between flex-column flex-sm-row">
            <div class="col-auto"><div class="small m-0 text-white">Copyright &copy; <?=config()->string(key: 'app.name');?> <?=date('Y');?></div></div>
            <div class="col-auto">
                <ul class="nav flex-wrap">
                    <?= view('framework::shared/site-navigation', [
                        'items' => menu_items('secondary'), 'prefix' => 'website-secondary',
                    ])->getBody() ?>
                    <?= view('framework::shared/site-navigation', [
                        'items' => menu_items('footer'), 'prefix' => 'website-footer',
                    ])->getBody() ?>
                </ul>
                <a class="link-light small" href="<?=phpb_full_url(urlRelativeToBaseUrl: '/login/');?>">Admin</a>
            </div>
        </div>
    </div>
</footer>
