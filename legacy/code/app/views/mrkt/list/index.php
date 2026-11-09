<?php if ( ! defined('BASE_PATH') ) exit('No direct script access allowed');
/**
 * Lists View
 *
 * @license GPLv3
 * 
 * @since       6.3.0
 * @package     eduTrac SIS
 * @author      Joshua Parker <joshmac3@icloud.com>
 */
$app = \Liten\Liten::getInstance();
$app->view->extend('_layouts/dashboard');
$app->view->block('dashboard');
$screen = 'lists';
?>

<ul class="breadcrumb">
	<li><?=_t( 'You are here' );?></li>
	<li><a href="<?=get_base_url();?>dashboard/" class="glyphicons dashboard"><i></i> <?=_t( 'Dashboard' );?></a></li>
	<li class="divider"></li>
	<li><a href="<?=get_base_url();?>mrkt/" class="glyphicons inbox"><i></i> <?=_t( 'Campaigns' );?></a></li>
	<li class="divider"></li>
	<li><?=_t( 'Email Lists' );?></li>
</ul>

<h3><?=_t( 'Email Lists' );?></h3>
<div class="innerLR">
    
    <?=_etsis_flash()->showMessage();?>
    
    <?php jstree_sidebar_menu($screen); ?>

	<!-- Widget -->
	<div class="widget widget-heading-simple widget-body-gray <?=($app->hook->has_filter('sidebar_menu')) ? 'col-md-12' : 'col-md-10';?>">
		<div class="widget-body">
            
			<!-- Table -->
			<table class="dynamicTable tableTools table table-striped table-bordered table-condensed table-white">
			
				<!-- Table heading -->
				<thead>
					<tr>
						<th class="text-center"><?= _t('Code'); ?></th>
                        <th class="text-center"><?= _t('Name'); ?></th>
                        <th class="text-center"><?= _t('Status'); ?></th>
                        <th class="text-center"><?= _t('Records'); ?></th>
                        <th class="text-center"><?= _t('Action'); ?></th>
					</tr>
				</thead>
				<!-- // Table heading END -->
				
				<!-- Table body -->
				<tbody>
				<?php foreach ($lists as $list) : ?>
                <tr class="gradeX">
                	<td class="text-center"><?= _h($list->code); ?></td>
                    <td class="text-center"><?= _h($list->name); ?></td>
                    <td class="text-center">
                        <span class="label <?=etsis_list_status_label(_h($list->status));?>" style="font-size:1em;font-weight: bold;">
                            <?= ucfirst(_h($list->status)); ?>
                        </span>
                    </td>
                    <td class="text-center">
                        <span class="label label-inverse" style="font-size:1em;font-weight: bold;">
                            <?=get_list_subscribers_count(_escape($list->rule));?>
                        </span>
                    </td>
                    <td class="text-center">
                        <a href="<?= get_base_url(); ?>mrkt/list/<?= _h((int)$list->id); ?>/" data-toggle="tooltip" data-placement="top" title="View/Edit"><button type="button" class="btn bg-yellow"><i class="fa fa-edit"></i></button></a>
                        <a href="#" data-toggle="modal" data-target="#delete-<?= _h((int)$list->id); ?>"><button type="button" class="btn btn-danger"><i class="fa fa-trash-o"></i></button></a>
                        
                        <div class="modal" id="delete-<?= _h((int)$list->id); ?>">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                            <span aria-hidden="true">&times;</span></button>
                                        <h4 class="modal-title"><?= _h($list->name); ?></h4>
                                    </div>
                                    <div class="modal-body">
                                        <p><?=_t('Are you sure you want to delete this email list?');?></p>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-default" data-dismiss="modal"><?= _t('Close'); ?></button>
                                        <button type="button" class="btn btn-danger pull-left" onclick="window.location='<?=get_base_url();?>mrkt/list/<?= _h((int)$list->id); ?>/d/'"><?= _t('Confirm'); ?></button>
                                    </div>
                                </div>
                                <!-- /.modal-content -->
                            </div>
                            <!-- /.modal-dialog -->
                        </div>
                        <!-- /.modal -->
                    </td>
                </tr>
				<?php endforeach; ?>
				</tbody>
				<!-- // Table body END -->
				
			</table>
			<!-- // Table END -->
            
            <div class="separator bottom"></div>
            
            <!-- Form actions -->
            <div class="form-actions">
                <button type="button" class="btn btn-icon btn-primary glyphicons circle_plus" onclick="window.location='<?=get_base_url();?>mrkt/list/create/'"><i></i><?=_t( 'Create List' );?></button>
            </div>
            <!-- // Form actions END -->
			
		</div>
	</div>
	<div class="separator bottom"></div>
	
	<!-- // Widget END -->
	
</div>	
	
		
		</div>
		<!-- // Content END -->
<?php $app->view->stop(); ?>