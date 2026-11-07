<?php

use Spatie\Menu\Link;

use function Application\Shared\Helpers\nav_links;
use function Codefy\Framework\Helpers\config;

//phpcs:disable
?>

<footer class="bg-dark py-4 mt-auto">
    <div class="container px-5">
        <div class="row align-items-center justify-content-between flex-column flex-sm-row">
            <div class="col-auto"><div class="small m-0 text-white">Copyright &copy; <?=config()->string(key: 'app.name');?> <?=date('Y');?></div></div>
            <div class="col-auto">
                <?php foreach (nav_links(type: 'secondary') as $page) : ?>
                    <?=Link::to(url: $page['route'], text: $page['title'])->addClass(class: 'link-light small');?>
                    <span class="text-white mx-1">&middot;</span>
                <?php endforeach; ?>
                <a class="link-light small" href="<?=phpb_full_url(urlRelativeToBaseUrl: '/login/');?>">Admin</a>
            </div>
        </div>
    </div>
</footer>
