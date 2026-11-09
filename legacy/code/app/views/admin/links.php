<?php if ( ! defined('BASE_PATH') ) exit('No direct script access allowed');
/**
 * myetSIS Manage Links View
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

	<h3 class="glyphicons bookmark"><i></i><?=_t( 'Manage Quick Links' );?> <a href="#add" data-toggle="modal" class="btn btn-default btn-icon glyphicons single plus"><i></i><?=_t( 'Add Link' );?></a></h3>
	<div class="separator bottom"></div>
	
	<?=_etsis_flash()->showMessage();?>
	
	<!-- Modal -->
	<form class="form-horizontal margin-none" action="<?=get_base_url();?>admin/links/" id="validateSubmitForm" method="post" autocomplete="off">
	<div class="modal fade" id="add">
		<div class="modal-dialog">
			<div class="modal-content">
				<!-- Modal heading -->
				<div class="modal-header">
					<button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
					<h3 class="modal-title"><?=_t( 'Add Link' );?></h3>
				</div>
				<!-- // Modal heading END -->
				<!-- Modal body -->
				<div class="modal-body">
					<h4 class="heading small"><font color="red">*</font> <?=_t( 'Indicates field is required.' );?></h4>
					<!-- Group -->
		            <div class="row innerB">
		                <div class="col-md-12">
							<label class="control-label"><font color="red">*</font> <?=_t( 'Link Title' );?></label>
							<input type="text" name="link_title" class="form-control" required/>
						</div>
						<div class="col-md-12">
							<label class="control-label"><font color="red">*</font> <?=_t( 'Link Source' );?></label>
							<input type="text" name="link_src" class="form-control" required/>
						</div>
						<div class="col-md-12">
							<label class="control-label"><font color="red">*</font> <?=_t( 'Status' );?></label>
							<select name="status" class="form-control" required>
								<option value="">&nbsp;</option>
								<option value="active"><?=_t( 'Active' );?></option>
								<option value="inactive"><?=_t( 'Inactive' );?></option>
							</select>
						</div>
						<div class="col-md-4">
							<label class="control-label"><?=_t( 'Sort' );?></label>
							<input type="text" name="sort" class="form-control" />
						</div>
		            </div>
		            <!-- // Group END -->
				</div>
				<!-- // Modal body END -->
				<!-- Modal footer -->
				<div class="modal-footer">
					<button class="btn btn-default btn-icon"><?=_t( 'Submit' );?></button>
					<button class="btn btn-primary btn-icon" data-dismiss="modal"><?=_t( 'Cancel' );?></button>
				</div>
				<!-- // Modal footer END -->
			</div>
		</div>
	</div>
	</form>
	<!-- // Modal END -->
	
<!-- Widget -->
<div class="widget widget-heading-simple widget-body-gray">
	<div class="widget-body">
	<!-- Table -->
		<table class="dynamicTable tableTools table table-striped table-bordered table-condensed table-primary">
		
			<!-- Table heading -->
			<thead>
				<tr>
					<th class="text-center"><?=_t( 'Link Title' );?></th>
					<th class="text-center"><?=_t( 'Link Source' );?></th>
					<th class="text-center"><?=_t( 'Status' );?></th>
					<th class="text-center"><?=_t( 'Actions' );?></th>
				</tr>
			</thead>
			<!-- // Table heading END -->
			
			<!-- Table body -->
			<tbody>
			<?php if(count($links[0]['id']) > 0) : foreach($links as $k => $v) { ?>
            <tr class="gradeX">
                <td class="text-center"><?=_h($v['link_title']);?></td>
                <td class="text-center"><?=_h($v['link_src']);?></td>
                <td class="text-center"><?=ucfirst(_h($v['status']));?></td>
                <td class="text-center">
					<div class="btn-group dropup">
                            <button class="btn btn-default btn-xs" type="button"><?=_t( 'Actions' );?></button>
                            <button data-toggle="dropdown" class="btn btn-xs btn-primary dropdown-toggle" type="button">
                                <span class="caret"></span>
                                <span class="sr-only"><?=_t( 'Toggle Dropdown' );?></span>
                            </button>
                            <ul role="menu" class="dropdown-menu dropup-text pull-right">
                                <li><a href="#link-<?=_h($v['id']);?>" data-toggle="modal"><?=_t( 'Edit' );?></a></li>
                                <li><a href="#delete-<?=_h($v['id']);?>" data-toggle="modal"><?=_t( 'Delete' );?></a></li>
                            </ul>
                        </div>
                </td>
            </tr>
			<?php } endif; ?>
			</tbody>
			<!-- // Table body END -->
			
		</table>
		<!-- // Table END -->
		</div>
	</div>
		
		<?php if(count($links[0]['id']) > 0) : foreach($links as $k => $v) { ?>
			<!-- Modal -->
			<form class="form-horizontal margin-none" action="<?=get_base_url();?>admin/links/" id="validateSubmitForm" method="post" autocomplete="off">
			<div class="modal fade" id="link-<?=_h($v['id']);?>">
				<div class="modal-dialog">
					<div class="modal-content">
						<!-- Modal heading -->
						<div class="modal-header">
							<button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
							<h3 class="modal-title"><?=_h($v['link_title']);?></h3>
						</div>
						<!-- // Modal heading END -->
						<!-- Modal body -->
						<div class="modal-body">
							<h4 class="heading small"><font color="red">*</font> <?=_t( 'Indicates field is required.' );?></h4>
							<!-- Group -->
				            <div class="row innerB">
				                <div class="col-md-12">
									<label class="control-label"><font color="red">*</font> <?=_t( 'Link Title' );?></label>
									<input type="text" name="link_title" class="form-control" value="<?=_h($v['link_title']);?>" required/>
								</div>
								<div class="col-md-12">
									<label class="control-label"><font color="red">*</font> <?=_t( 'Link Source' );?></label>
									<input type="text" name="link_src" class="form-control" value="<?=_h($v['link_src']);?>" required/>
								</div>
								<div class="col-md-12">
									<label class="control-label"><font color="red">*</font> <?=_t( 'Status' );?></label>
									<select name="status" class="form-control" required>
										<option value="">&nbsp;</option>
										<option value="active"<?=selected('active',_h($v['status']),false);?>><?=_t( 'Active' );?></option>
										<option value="inactive"<?=selected('inactive',_h($v['status']),false);?>><?=_t( 'Inactive' );?></option>
									</select>
								</div>
								<div class="col-md-4">
									<label class="control-label"><?=_t( 'Sort' );?></label>
									<input type="text" name="sort" class="form-control" value="<?=_h($v['sort']);?>" />
								</div>
				            </div>
				            <!-- // Group END -->
						</div>
						<!-- // Modal body END -->
						<!-- Modal footer -->
						<div class="modal-footer">
                            <input type="hidden" name="id" value="<?=_h($v['id']);?>" />
							<button class="btn btn-default btn-icon"><?=_t( 'Submit' );?></button>
							<button class="btn btn-primary btn-icon" data-dismiss="modal"><?=_t( 'Cancel' );?></button>
						</div>
						<!-- // Modal footer END -->
					</div>
				</div>
			</div>
			</form>
			<!-- // Modal END -->
			
			<!-- Modal -->
			<div class="modal fade" id="delete-<?=_h($v['id']);?>">
				<div class="modal-dialog">
					<div class="modal-content">
						<!-- Modal heading -->
						<div class="modal-header">
							<button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
							<h3 class="modal-title"><?=_h($v['link_title']);?></h3>
						</div>
						<!-- // Modal heading END -->
						<!-- Modal body -->
						<div class="modal-body">
							<p><?=_t( 'Are you sure you want to delete this quick link?' );?></p>
						</div>
						<!-- // Modal body END -->
						<!-- Modal footer -->
						<div class="modal-footer">
							<a href="<?=get_base_url();?>admin/deleteLink/<?=_h($v['id']);?>/" class="btn btn-default"><?=_t( 'Delete' );?></a>
							<button class="btn btn-primary" data-dismiss="modal"><?=_t( 'Cancel' );?></button>
						</div>
						<!-- // Modal footer END -->
					</div>
				</div>
			</div>
			<!-- // Modal END -->
		<?php } endif; ?>

</div>
	</div>
</div>

	
		</div>
		<!-- // Content END -->
<?php $app->view->stop(); ?>