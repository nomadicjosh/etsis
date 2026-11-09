<?php if ( ! defined('BASE_PATH') ) exit('No direct script access allowed');
/**
 * Calendar Settings View
 *  
 * @license GPLv3
 * 
 * @since       4.1.7
 * @package     eduTrac SIS
 * @author      Joshua Parker <joshmac3@icloud.com>
 */

$app = \Liten\Liten::getInstance();
$app->view->extend('_layouts/dashboard');
$app->view->block('dashboard');
$screen = 'calset';
?>

<ul class="breadcrumb">
	<li><?=_t( 'You are here' );?></li>
	<li><a href="<?=get_base_url();?>dashboard/" class="glyphicons dashboard"><i></i> <?=_t( 'Dashboard' );?></a></li>
	<li class="divider"></li>
	<li><a href="<?=get_base_url();?>calendar/" class="glyphicons calendar"><i></i> <?=_t( 'Events Calendar' );?></a></li>
	<li class="divider"></li>
	<li><?=_t( 'Room Request Settings' );?></li>
</ul>

<h3><?=_t( 'Room Request Settings' );?></h3>
<div class="innerLR">
    
    <?=_etsis_flash()->showMessage();?>
    
    <?php jstree_sidebar_menu($screen); ?>
	
	<div class="widget widget-heading-simple widget-body-white <?=($app->hook->has_filter('sidebar_menu')) ? 'col-md-12' : 'col-md-10';?>">
		<div class="widget-body">
			<div class="alerts alerts-info">
				<p><?=_t( 'If you would like to change the background colors, please refer to the <a href="http://www.colorpicker.com/">online color picker</a>.' );?></p>
			</div>
		</div>
	</div>
		
		<!-- Widget -->
		<div class="widget widget-heading-simple widget-body-gray <?=($app->hook->has_filter('sidebar_menu')) ? 'col-md-12' : 'col-md-10';?>">
		
			<!-- Widget heading -->
			<div class="widget-head">
				<h4 class="heading"><font color="red">*</font> <?=_t( 'Indicates field is required' );?></h4>
			</div>
			<!-- // Widget heading END -->
			
			<div class="widget-body">
			
				<!-- Row -->
				<div class="row">
					<!-- Table -->
					<table class="table table-striped table-bordered table-condensed table-primary">
					
						<!-- Table heading -->
						<thead>
							<tr>
								<th class="text-center"><?=_t( 'Category' );?></th>
								<th class="text-center"><?=_t( 'Background Color' );?></th>
								<th class="text-center"><?=_t( 'Actions' );?></th>
							</tr>
						</thead>
						<!-- // Table heading END -->
						
						<!-- Table body -->
						<tbody>
						<?php if($category != '') : foreach($category as $k => $v) { ?>
		                <tr class="gradeX">
		                    <td class="center"><?=_h($v['cat_name']);?></td>
		                    <td class="center" style="background:<?=_h($v['bgcolor']);?>;color:#333;font-weight:600;"><?=_h($v['bgcolor']);?></td>
		                    <td class="text-center">
		                    	<a href="#category_<?=_h($v['catID']);?>" data-toggle="modal" title="Edit Category" class="btn btn-default"><i class="fa fa-edit"></i></a>
		                    	<!-- Modal -->
								<div class="modal fade" id="category_<?=_h($v['catID']);?>">
									<form class="form-horizontal margin-none" action="<?=get_base_url();?>calendar/setting/" id="validateSubmitForm" method="post" autocomplete="off">
									<div class="modal-dialog">
										<div class="modal-content">
											<!-- Modal heading -->
											<div class="modal-header">
												<button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
												<h3 class="modal-title"><?=_t( 'Edit Category' );?></h3>
											</div>
											<!-- // Modal heading END -->
											<!-- Modal body -->
											<div class="modal-body">
												<!-- Group -->
									            <div class="form-group">
									                <label class="col-md-3 control-label"><font color="red">*</font> <?=_t( 'Category' );?></label>
									                <div class="col-md-8">
									                    <input class="form-control" type="text" name="cat_name" value="<?=_h($v['cat_name']);?>" required/>
									                </div>
									            </div>
									            <!-- // Group END -->
									            
									            <!-- Group -->
									            <div class="form-group">
									                <label class="col-md-3 control-label"><font color="red">*</font> <?=_t( 'Background Color' );?></label>
									                <div class="col-md-8">
									                    <input class="form-control" type="text" name="bgcolor" value="<?=_h($v['bgcolor']);?>" required/>
									                </div>
									            </div>
									            <!-- // Group END -->
											</div>
											<!-- // Modal body END -->
											<!-- Modal footer -->
											<div class="modal-footer">
												<input type="hidden" name="catID" value="<?=_h($v['catID']);?>" />
									            <button type="submit" class="btn btn-default"><?=_t( 'Save' );?></button>
												<a href="#" class="btn btn-primary" data-dismiss="modal"><?=_t( 'Cancel' );?></a>
											</div>
											<!-- // Modal footer END -->
										</div>
									</div>
									</form>
								</div>
								<!-- // Modal END -->
		                    </td>
		                </tr>
						<?php } endif; ?>
						</tbody>
						<!-- // Table body END -->
						
					</table>
					<!-- // Table END -->
				</div>
				<!-- // Row END -->
			
				<hr class="separator" />
				
				<div class="separator line bottom"></div>
	
</div>
		
		</div>
		<!-- // Content END -->
<?php $app->view->stop(); ?>