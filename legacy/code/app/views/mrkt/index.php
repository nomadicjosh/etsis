<?php if ( ! defined('BASE_PATH') ) exit('No direct script access allowed');

use app\src\Core\NodeQ\etsis_NodeQ as Node;
use app\src\Core\NodeQ\NodeQException;
use app\src\Core\Exception\Exception;
/**
 * Marketing Index View
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
$screen = 'mrkt';
?>

<?php if($count > 0) : ?>
<script type="text/javascript">
    $(document).ready(function () {
        setInterval(function () {
            <?php foreach($msgs as $j) : ?>
            $("#msg<?=_h($j->id);?>").load(location.href + " #msg<?=_h($j->id);?>>*", "");
            <?php endforeach; ?>
        }, 10000);
    });
</script>
<?php endif; ?>

<ul class="breadcrumb">
	<li><?=_t( 'You are here' );?></li>
	<li><a href="<?=get_base_url();?>dashboard/" class="glyphicons dashboard"><i></i> <?=_t( 'Dashboard' );?></a></li>
	<li class="divider"></li>
	<li><?=_t( 'Campaigns' );?></li>
</ul>

<h3><?=_t( 'Campaigns' );?></h3>
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
						<th class="text-center"><?= _t('Subject'); ?></th>
                        <th class="text-center"><?= _t('Status'); ?></th>
                        <th class="text-center"><?= _t('Send Start'); ?></th>
                        <th class="text-center"><?= _t('Send Finish'); ?></th>
                        <th class="text-center"><?= _t('Recipients'); ?></th>
                        <th class="text-center"><?= _t('Action'); ?></th>
					</tr>
				</thead>
				<!-- // Table heading END -->
				
				<!-- Table body -->
				<tbody>
                <?php foreach ($msgs as $msg) : ?>
                <?php
                try {
                    $all = Node::table('campaign_queue')
                        ->where('cid','=',_h($msg->id))
                        ->findAll()
                        ->count();
                    $sent = Node::table('campaign_queue')
                        ->where('is_sent','=','true')
                        ->andWhere('cid','=',_h($msg->id))
                        ->findAll()
                        ->count();
                    $to_send = Node::table('campaign_queue')
                        ->where('is_sent','=','false')
                        ->andWhere('cid','=',_h($msg->id))
                        ->findAll()
                        ->count();
                    $recipients = Node::table('campaign_queue')
                        ->where('cid', '=', _h($msg->id))
                        ->findAll()
                        ->count();
                } catch (NodeQException $e) {
                    _etsis_flash()->error($e->getMessage());
                } catch (Exception $e) {
                    _etsis_flash()->error($e->getMessage());
                }

                ?>
                <tr class="gradeX" id="msg<?=_h($msg->id);?>">
                    <td class="text-center"><?= _h($msg->subject); ?></td>
                    <td class="text-center">
                        <span class="label <?=etsis_cpgn_status_label(_h($msg->status));?>" style="font-size:1em;font-weight: bold;">
                            <?= ucfirst(_h($msg->status)); ?>
                        </span>
                        <?php if($to_send > 0) : ?>
                        <div class="progress progress-striped active">
                            <div class="progress-bar" style="width: <?=percent($sent, _h($all));?>%"><?=percent($sent, _h($all));?>%</div>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td class="text-center"><?= Jenssegers\Date\Date::parse(_h($msg->sendstart))->format('M. d, Y @ h:i A'); ?></td>
                    <td class="text-center"><?= (_h($msg->sendfinish) != '' ? Jenssegers\Date\Date::parse(_h($msg->sendfinish))->format('M. d, Y @ h:i A') : ''); ?></td>
                    <?php if (_h((int)$msg->recipients) < Node::table('campaign_queue')->where('cid','=',_h($msg->id))->findAll()->count()) : ?>
                    <td class="text-center">
                        <span class="label label-inverse" style="font-size:1em;font-weight: bold;">
                            <?= _h((int)$msg->recipients); ?> / <?= Node::table('campaign_queue')->where('cid','=',_h($msg->id))->findAll()->count(); ?>
                        </span>
                    </td>
                    <?php else : ?>
                    <td class="text-center">
                        <span class="label label-inverse" style="font-size:1em;font-weight: bold;">
                            <?=($recipients <= 0 ? _h((int)$msg->recipients) : $recipients); ?>
                        </span>
                    </td>
                    <?php endif; ?>
                    <td class="text-center">
                        <a href="<?= get_base_url(); ?>mrkt/campaign/<?= _h((int)$msg->id); ?>/" data-toggle="tooltip" data-placement="top" title="View/Edit"><button type="button" class="btn bg-yellow"><i class="fa fa-edit"></i></button></a>
                        <a<?= (is_status_ready(_h((int)$msg->id)) == false ? ' style="display:none !important;"' : ''); ?> href="<?= get_base_url(); ?>mrkt/campaign/<?= _h((int)$msg->id); ?>/queue/" data-toggle="tooltip" data-placement="top" title="Send to Queue"><button type="button" class="btn bg-green"><i class="fa fa-arrow-right"></i></button></a>
                        <a<?= (is_status_processing(_h((int)$msg->id)) == true ? '' : ' style="display:none !important;"'); ?> href="<?= get_base_url(); ?>mrkt/campaign/<?= _h((int)$msg->id); ?>/pause/" data-toggle="tooltip" data-placement="top" title="Pause Queue"><button type="button" class="btn bg-orange"><i class="fa fa-pause"></i></button></a>
                        <a<?= (is_status_paused(_h((int)$msg->id)) == true ? '' : ' style="display:none !important;"'); ?> href="<?= get_base_url(); ?>mrkt/campaign/<?= _h((int)$msg->id); ?>/resume/" data-toggle="tooltip" data-placement="top" title="Resume Queue"><button type="button" class="btn bg-orange"><i class="fa fa-play"></i></button></a>
                        <a href="<?= get_base_url(); ?>mrkt/campaign/<?= _h((int)$msg->id); ?>/report/" data-toggle="tooltip" data-placement="top" title="Report"><button type="button" class="btn bg-blue"><i class="fa fa-bar-chart-o"></i></button></a>
                        <?php if(get_persondata('personID') == _h($msg->owner)) : ?>
                        <a<?= (is_status_processing(_h((int)$msg->id)) == false ? '' : ' style="display:none !important;"'); ?> href="#" data-toggle="modal" data-target="#delete-<?= _h((int)$msg->id); ?>" title="Delete"><button type="button" class="btn btn-danger"><i class="fa fa-trash-o"></i></button></a>
                        <?php endif; ?>

                        <div class="modal" id="delete-<?= _h((int)$msg->id); ?>">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                            <span aria-hidden="true">&times;</span></button>
                                        <h4 class="modal-title"><?= _h($msg->subject); ?></h4>
                                    </div>
                                    <div class="modal-body">
                                        <p><?= _t('Are you sure you want to delete this campaign?'); ?></p>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-default" data-dismiss="modal"><?= _t('Close'); ?></button>
                                        <button type="button" class="btn btn-danger pull-left" onclick="window.location = '<?= get_base_url(); ?>mrkt/campaign/<?= _h((int)$msg->id); ?>/d/'"><?= _t('Confirm'); ?></button>
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
                <button type="button" class="btn btn-icon btn-primary glyphicons circle_plus" onclick="window.location='<?=get_base_url();?>mrkt/campaign/create/'"><i></i><?=_t( 'Create Campaign' );?></button>
            </div>
            <!-- // Form actions END -->
			
		</div>
	</div>
	
	<!-- // Widget END -->
	
</div>	
	
		
		</div>
		<!-- // Content END -->
<?php $app->view->stop(); ?>