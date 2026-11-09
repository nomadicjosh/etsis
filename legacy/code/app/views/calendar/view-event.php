<?php if ( ! defined('BASE_PATH') ) exit('No direct script access allowed');
/**
 * View Event View
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
$screen = 'calevent';
$dowMap = array('Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday');
?>

<ul class="breadcrumb">
	<li><?=_t( 'You are here' );?></li>
	<li><a href="<?=get_base_url();?>dashboard/" class="glyphicons dashboard"><i></i> <?=_t( 'Dashboard' );?></a></li>
	<li class="divider"></li>
	<li><a href="<?=get_base_url();?>calendar/" class="glyphicons calendar"><i></i> <?=_t( 'Events Calendar' );?></a></li>
	<li class="divider"></li>
	<li><a href="<?=get_base_url();?>calendar/events/" class="glyphicons list"><i></i> <?=_t( 'Events List' );?></a></li>
	<li class="divider"></li>
	<li><?=_h($series[0]['title']);?></li>
</ul>

<h3><?=_h($series[0]['title']);?></h3>
<div class="innerLR">
    
    <?=_etsis_flash()->showMessage();?>
    
    <?php jstree_sidebar_menu($screen); ?>

	<!-- Widget -->
	<div class="widget widget-heading-simple widget-body-gray <?=($app->hook->has_filter('sidebar_menu')) ? 'col-md-12' : 'col-md-10';?>">
		<div class="widget-body">
			<?php if($series[0]['eventID'] != '') : ?>
			<!-- Table -->
			<table cellpadding="0" cellspacing="0" border="0" class="table table-striped table-bordered table-condensed table-primary" id="table-example">
                    <thead>
                            <tr>
                                    <th class="center"><?=_t( 'Meeting Day' );?></th>
                                    <th class="center"><?=_t( 'Time' );?></th>
                                    <th class="center"><?=_t( 'Event Title' );?></th>
                                    <th class="center"><?=_t( 'Event Description' );?></th>
                                    <th class="center"><?=_t( 'Room' );?></th>
                                    <th class="center"><?=_t( 'Actions' );?></th>
                            </tr>
                    </thead>
                    <tbody>
                            <?php if($series != '') : foreach($series as $k => $v) { ?>
                            <tr>
                                <td class="center"><?=$dowMap[_h($v['weekday'])];?></td>
                                <td class="center"><?=\Jenssegers\Date\Date::parse(_h($v['startTime']))->format("h:i A");?> - <?=\Jenssegers\Date\Date::parse(_h($v['endTime']))->format("h:i A");?></td>
                                <td class="center"><?=_h($v['title']);?></td>
                                <td class="center"><?=_h($v['description']);?></td>
                                <td class="center"><?=_h($v['roomCode']);?></td>
                                <td class="center">
                                	<a href="#series_<?=_h($v['eventID']);?>" data-toggle="modal" class="btn btn-danger"><i class="fa fa-trash-o"></i></a>
                                	<!-- Modal -->
									<div class="modal fade" id="series_<?=_h($v['eventID']);?>">
										<div class="modal-dialog">
											<div class="modal-content">
												<!-- Modal heading -->
												<div class="modal-header">
													<button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
													<h3 class="modal-title"><?=_h($v['title']);?>, <?=$dowMap[_h($v['weekday'])];?> <br /> <?=\Jenssegers\Date\Date::parse(_h($v['startTime']))->format("h:i A");?> - <?=\Jenssegers\Date\Date::parse(_h($v['endTime']))->format("h:i A");?></h3>
												</div>
												<!-- // Modal heading END -->
												<!-- Modal body -->
												<div class="modal-body">
													<p><?= _t( "Are you sure you want to delete this booking?" );?></p>
												</div>
												<!-- // Modal body END -->
												<!-- Modal footer -->
												<div class="modal-footer">
													<a href="<?=get_base_url();?>calendar/deleteBookingSeries/<?=_h($v['eventID']);?>" class="btn btn-default"><?=_t( 'Delete' );?></a>
													<a href="#" class="btn btn-primary" data-dismiss="modal"><?=_t( 'Cancel' );?></a> 
												</div>
												<!-- // Modal footer END -->
											</div>
										</div>
									</div>
									<!-- // Modal END -->
                                </td>
                            </tr>
                            <?php } endif; ?>
                    </tbody>
            </table>
			<!-- // Table END -->
			<?php endif; ?>
			
			<?php if($meta[0]['eventMetaID'] != '') : ?>
			<table cellpadding="0" cellspacing="0" border="0" class="table table-striped table-bordered table-condensed table-primary" id="table-example">
                    <thead>
                            <tr>
                                    <th class="center"><?=_t( 'Meeting Day' );?></th>
                                    <th class="center"><?=_t( 'Start Date/Time' );?></th>
                                    <th class="center"><?=_t( 'End Date/Time' );?></th>
                                    <th class="center"><?=_t( 'Room' );?></th>
                                    <th class="center"><?=_t( 'Actions' );?></th>
                            </tr>
                    </thead>
                    <tbody>
                            <?php if($meta != '') : foreach($meta as $k => $v) { ?>
                        	<?php $start = explode( " ", _h($v['start']) ); $end = explode( " ", _h($v['end']) ); ?>
                            <tr>
                                <td class="center"><?=$dowMap[_h($v['weekday'])];?></td>
                                <td class="center"><?=\Jenssegers\Date\Date::parse(_h($v['start']))->format("F d, o h:i A");?></td>
                                <td class="center"><?=\Jenssegers\Date\Date::parse(_h($v['end']))->format("F d, o h:i A");?></td>
                                <td class="center"><?=_h($v['roomCode']);?></td>
                                <td>
                            		<a href="#editMeta_<?=_h($v['eventMetaID']);?>" data-toggle="modal" class="btn btn-default"><i class="fa fa-edit"></i></a>
                            		<!-- Modal -->
									<div class="modal fade" id="editMeta_<?=_h($v['eventMetaID']);?>">
										<form class="form-horizontal margin-none" action="<?=get_base_url();?>calendar/editMeta/" id="validateSubmitForm" method="post" autocomplete="off">
										<div class="modal-dialog">
											<div class="modal-content">
												<!-- Modal heading -->
												<div class="modal-header">
													<button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
													<h3 class="modal-title"><?=_t( 'Edit Event Meta' );?></h3>
												</div>
												<!-- // Modal heading END -->
												<!-- Modal body -->
												<div class="modal-body">
													<div class="row">
													<div class="col-md-12">
													<!-- Group -->
							                        <div class="form-group">
							                            <label class="col-md-3 control-label"><?=_t( 'Room' );?></label>
							                            <div class="col-md-8">
							                                <select name="roomCode" class="selectpicker form-control" data-style="btn-info" data-size="10" data-live-search="true">
							                                    <option value="NULL">&nbsp;</option>
							                                    <?=table_dropdown('room', 'roomCode <> "NULL"', 'roomCode', 'roomCode', 'roomNumber', _h($v['roomCode']) );?>
							                                </select>
							                            </div>
							                        </div>
							                        <!-- // Group END -->
										            
										            <!-- Group -->
							                        <div class="form-group">
							                            <label class="col-md-3 control-label"><font color="red">*</font> <?=_t( 'Start Date' );?></label>
							                            <div class="col-md-8">
							                                <div class="input-group date" id="datepicker6">
							                                    <input class="form-control" name="startDate" type="text" value="<?=$start[0];?>" required/>
							                                    <span class="input-group-addon"><i class="fa fa-th"></i></span>
							                                </div>
							                            </div>
							                        </div>
							                        <!-- // Group END -->
							                        
							                        <!-- Group -->
							                        <div class="form-group">
							                            <label class="col-md-3 control-label"><font color="red">*</font> <?=_t( 'Start Time' );?></label>
							                            <div class="col-md-8">
							                                <div class="input-group bootstrap-timepicker">
								        				        <input id="convertTime1" type="text" name="startTime" class="form-control" value="<?=\Jenssegers\Date\Date::parse($start[1])->format("h:i A");?>" required/>
								        				        <span class="input-group-addon"><i class="fa fa-clock-o"></i></span>
													        </div>
							                            </div>
							                        </div>
							                        <!-- // Group END -->
							                        
							                        <!-- Group -->
							                        <div class="form-group">
							                            <label class="col-md-3 control-label"><font color="red">*</font> <?=_t( 'End Date' );?></label>
							                            <div class="col-md-8">
							                                <div class="input-group date" id="datepicker7">
							                                    <input class="form-control" name="endDate" type="text" value="<?=$end[0];?>" required/>
							                                    <span class="input-group-addon"><i class="fa fa-th"></i></span>
							                                </div>
							                            </div>
							                        </div>
							                        <!-- // Group END -->
							                        
							                        <!-- Group -->
							                        <div class="form-group">
							                            <label class="col-md-3 control-label"><font color="red">*</font> <?=_t( 'End Time' );?></label>
							                            <div class="col-md-8">
							                                <div class="input-group bootstrap-timepicker">
								        				        <input id="convertTime2" type="text" name="endTime" class="form-control" value="<?=\Jenssegers\Date\Date::parse($end[1])->format("h:i A");?>" required/>
								        				        <span class="input-group-addon"><i class="fa fa-clock-o"></i></span>
													        </div>
							                            </div>
							                        </div>
							                        <!-- // Group END -->
										            
										             <!-- Group -->
							                        <div class="form-group">
							                            <label class="col-md-3 control-label"><font color="red">*</font> <?=_t( 'Title' );?></label>
							                            <div class="col-md-8">
							                                <input type="text" name="title" class="form-control" value="<?=_h($v['title']);?>" required/>
							                            </div>
							                        </div>
							                        <!-- // Group END -->
							                        
							                         <!-- Group -->
							                        <div class="form-group">
							                            <label class="col-md-3 control-label"><font color="red">*</font> <?=_t( 'Description' );?></label>
							                            <div class="col-md-8">
							                                <input type="text" name="description" class="form-control" maxlength="40" value="<?=_h($v['description']);?>" required/>
							                            </div>
							                        </div>
							                        <!-- // Group END -->
                        
										           	</div>
										           	</div>
												</div>
												<!-- // Modal body END -->
												<!-- Modal footer -->
												<div class="modal-footer">
													<input type="hidden" name="eventMetaID" value="<?=_h($v['eventMetaID']);?>" />
										        	<button type="submit" class="btn btn-default"><?=_t( 'Submit' );?></button>
													<a href="#" class="btn btn-primary" data-dismiss="modal"><?=_t( 'Cancel' );?></a>
												</div>
												<!-- // Modal footer END -->
											</div>
										</div>
										</form>
									</div>
									<!-- // Modal END -->
	
                                	<a href="#deleteMeta_<?=_h($v['eventMetaID']);?>" data-toggle="modal" class="btn btn-danger"><i class="fa fa-trash-o"></i></a>
                                	<!-- Modal -->
									<div class="modal fade" id="deleteMeta_<?=_h($v['eventMetaID']);?>">
										<div class="modal-dialog">
											<div class="modal-content">
												<!-- Modal heading -->
												<div class="modal-header">
													<button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
													<h3 class="modal-title center"><?=$dowMap[_h($v['weekday'])];?>, <?=\Jenssegers\Date\Date::parse(_h($v['start']))->format("F d, o");?> <br /> <?=\Jenssegers\Date\Date::parse(_h($v['start']))->format("h:i A");?> - <?=\Jenssegers\Date\Date::parse(_h($v['end']))->format("h:i A");?></h3>
												</div>
												<!-- // Modal heading END -->
												<!-- Modal body -->
												<div class="modal-body">
													<p><?= _t( "Are you sure you want to delete this booking?" );?></p>
												</div>
												<!-- // Modal body END -->
												<!-- Modal footer -->
												<div class="modal-footer">
													<a href="<?=get_base_url();?>calendar/deleteBookingDay/<?=_h($v['eventMetaID']);?>" class="btn btn-default"><?=_t( 'Delete' );?></a>
													<a href="#" class="btn btn-primary" data-dismiss="modal"><?=_t( 'Cancel' );?></a> 
												</div>
												<!-- // Modal footer END -->
											</div>
										</div>
									</div>
									<!-- // Modal END -->
                                </td>
                            </tr>
                            <?php } endif; ?>
                    </tbody>
            </table>
            <?php endif; ?>
			
		</div>
	</div>
	
	<!-- // Widget END -->
	
</div>	
	
		
		</div>
		<!-- // Content END -->
<?php $app->view->stop(); ?>