<?php if ( ! defined('BASE_PATH') ) exit('No direct script access allowed');
/**
 * myetSIS CSS Editor View
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

if(file_exists(BASE_PATH.'static/assets/css/admin/custom/myetsis.custom.css')) {
	$fn = BASE_PATH.'static/assets/css/admin/custom/myetsis.custom.css';
	$file = fopen($fn, "a+"); 
	$size = filesize($fn);
	if($size <= 0) {
		$header = 
'/**
* myetSIS Custom CSS
* 
* Visit the Pro Support site for the css selectors you
* will need in order to make your changes:
* https://www.edutracsis.com/manual/myetsis-css-editor/
* Please note that after you\'ve saved your changes,
* you may need to refresh your browser before they
* will take affect.
*/';
		fwrite($file,$header);
	}
}

$text = fread($file, $size); 
fclose($file);
?>

		<div class="col-md-12">
            
            <?= _etsis_flash()->showMessage(); ?>
			
			<!-- Form -->
			<form class="form-horizontal margin-none" action="<?=get_base_url();?>admin/csseditor/" id="validateSubmitForm" method="post" autocomplete="off">
			<div class="widget widget-heading-simple widget-body-white">
				<div class="widget-body">
					<div class="row">	
						<div class="col-md-12">
							<h5 class="strong"><?=_t( 'CSS Editor' );?></h5>
							<div class="separator bottom"></div>
							<textarea class="form-control" name="csseditor" rows="20"><?=$text;?></textarea>
							<div class="separator bottom"></div>
							<button class="btn btn-primary btn-icon glyphicons circle_ok"><i></i> <?=_t( 'Save' );?></button>
						</div>
					</div>
				</div>
			</div>
			</form>
			<!-- // Form END -->
		
		</div>
	</div>
</div>

<?php
if($app->req->_post('csseditor')) {
	$submit = stripslashes(file_put_contents(BASE_PATH.'static/assets/css/admin/custom/myetsis.custom.css',$app->req->_post('csseditor')));
	echo $submit;
	etsis_redirect( get_base_url() . 'admin/csseditor/' );     
}
?>
		</div>
		<!-- // Content END -->
<?php $app->view->stop(); ?>