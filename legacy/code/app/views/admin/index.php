<?php if ( ! defined('BASE_PATH') ) exit('No direct script access allowed');
/**
 * myetSIS Administration View
 *  
 * @license GPLv3
 * 
 * @since       4.3
 * @package     eduTrac SIS
 * @author      Joshua Parker <joshmac3@icloud.com>
 */

$app = \Liten\Liten::getInstance();
$app->view->extend('_layouts/myetsis/' . _h(get_option('myetsis_layout')) . '.layout');
$app->view->block('myetsis');
?>

		<div class="col-md-12">
            
            <?= _etsis_flash()->showMessage(); ?>
		
			<div class="widget widget-heading-simple widget-body-white">
				<div class="widget-body">
					<div class="row">	
						<div class="col-md-12">
							<h5 class="strong"><?=_t( 'Admin' );?></h5>
							<div class="separator bottom"></div>
							<p<?=ae('manage_myetsis_pages');?> class="margin-none strong">
								<a href="<?=get_base_url();?>admin/pages/" class="glyphicons single chevron-right"><i></i><?=_t( 'Manage Pages' );?></a>
							</p>
							<div class="separator bottom"></div>
							<p<?=ae('manage_myetsis_links');?> class="margin-none strong">
								<a href="<?=get_base_url();?>admin/links/" class="glyphicons single chevron-right"><i></i><?=_t( 'Manage Links' );?></a>
							</p>
							<div class="separator bottom"></div>
							<p<?=ae('manage_myetsis_news');?> class="margin-none strong">
								<a href="<?=get_base_url();?>admin/news/" class="glyphicons single chevron-right"><i></i><?=_t( 'Manage News' );?></a>
							</p>
							<div class="separator bottom"></div>
							<p<?=ae('edit_myetsis_css');?> class="margin-none strong">
								<a href="<?=get_base_url();?>admin/csseditor/" class="glyphicons single chevron-right"><i></i><?=_t( 'CSS Editor' );?></a>
							</p>
							<div class="separator bottom"></div>
							<p<?=ae('clear_screen_cache');?>><?=_t( 'If you are having issues with results not updating after changes have been made, click the clear cache button below.' );?></p>
							<p<?=ae('clear_screen_cache');?> class="margin-none">
								<a href="<?=get_base_url();?>admin/flushCache/" class="btn btn-primary btn-icon glyphicons database_minus"><i></i><?=_t( 'Clear Cache' );?></a>
							</p>
						</div>
					</div>
				</div>
			</div>
		
		</div>
	</div>
</div>
	
		</div>
		<!-- // Content END -->
<?php $app->view->stop(); ?>