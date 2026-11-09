<?php if ( ! defined('BASE_PATH') ) exit('No direct script access allowed');
/**
 * View Bill View
 *  
 * @license GPLv3
 * 
 * @since       3.0.0
 * @package     eduTrac SIS
 * @author      Joshua Parker <joshmac3@icloud.com>
 */

$app = \Liten\Liten::getInstance();
$app->view->extend('_layouts/dashboard');
$app->view->block('dashboard');
$screen = 'inv';
$balance = bcadd($begin,$sumPayments,2);
$endingBalance = bcsub($balance,$sumRefund,2);
$stu = get_student(_h($bill[0]['stuID']));
?>

<ul class="breadcrumb">
    <li><?=_t( 'You are here' );?></li>
    <li><a href="<?=get_base_url();?>dashboard/" class="glyphicons dashboard"><i></i> <?=_t( 'Dashboard' );?></a></li>
    <li class="divider"></li>
    <li><a href="<?=get_base_url();?>financial/" class="glyphicons search"><i></i> <?=_t( 'Bill Lookup' );?></a></li>
    <li class="divider"></li>
    <li><?=_t( 'View Bill' );?></li>
</ul>

<div class="innerLR">
    
    <?php get_stu_header($stu->stuID); ?>
    
    <div class="separator line bottom"></div>
    
    <?=_etsis_flash()->showMessage();?>
    
    <?php jstree_sidebar_menu($screen); ?>

    <!-- Form -->
    <form class="form-horizontal margin-none" action="<?=get_base_url();?>financial/<?=$bill[0]['billID'];?>/" id="validateSubmitForm" method="post" autocomplete="off">
        
        <!-- Widget -->
        <div class="widget widget-heading-simple widget-body-gray <?=($app->hook->has_filter('sidebar_menu')) ? 'col-md-12' : 'col-md-10';?>">
            
            <!-- Tabs Heading -->
            <div class="tabsbar">
                <ul>
                    <li class="glyphicons user calculator active"><a href="<?=get_base_url();?>financial/<?=$bill[0]['billID'];?>/" data-toggle="tab"><i></i> <?=_t( 'Student Bill' );?></a></li>
                    <li class="glyphicons money"><a href="<?=get_base_url();?>financial/<?=$bill[0]['billID'];?>/payments/"><i></i> <?=_t( 'Payments' );?></a></li>
                    <li class="glyphicons undo tab-stacked"><a href="<?=get_base_url();?>financial/<?=$bill[0]['billID'];?>/refunds/"><i></i> <?=_t( 'Refunds' );?></a></li>
                    <li class="glyphicons chat tab-stacked"><a href="<?=get_base_url();?>financial/<?=$bill[0]['billID'];?>/comments/"><i></i> <span><?=_t( 'Comments' );?></span></a></li>
                </ul>
            </div>
            <!-- // Tabs Heading END -->
            
            <div class="widget-body">
            
                <!-- Row -->
                <div class="row">
                    
                    <!-- Column -->
                    <div class="col-md-6">
                        <?php if(hasPermission('delete_student')) : ?>
                        <!-- Group -->
                        <div class="form-group">
                            <label class="col-md-3 control-label"><?=_t( 'Delete Bill / Invoice' );?></label>
                            <div class="col-md-8">
                                <a<?=saids();?> href="#deleteBill" data-toggle="modal" title="Delete Bill/Invoice" class="btn btn-danger"><i class="fa fa-trash-o"></i></a>
                            </div>
                        </div>
                        <!-- // Group END -->
                        <?php endif; ?>
                        <!-- Group -->
                        <div class="form-group">
                            <label class="col-md-3 control-label"><?=_t( 'Invoice #' );?></label>
                            <div class="col-md-8">
                                <input class="form-control" type="text" readonly value="<?=_h($bill[0]['billID']);?>" />
                            </div>
                        </div>
                        <!-- // Group END -->
                        
                        <!-- Group -->
                        <div class="form-group">
                            <label class="col-md-3 control-label"><?=_t( 'Invoice Date / Term' );?></label>
                            <div class="col-md-4">
                                <div class="input-group date col-md-12" id="datepicker6">
                                    <input class="form-control" name="billDate" type="text" value="<?=_h($bill[0]['billingDate']);?>" required/>
                                    <span class="input-group-addon"><i class="fa fa-th"></i></span>
                                </div>
                            </div>
                            
                            <div class="col-md-4">
                                <select name="invTerm"<?=saio();?> class="selectpicker form-control" data-style="btn-info" data-size="10" data-live-search="true" required>
                                    <option value="">&nbsp;</option>
                                    <?php table_dropdown('term','active = "1" AND termCode <> "NULL"','termCode','termCode','termName',_h($bill[0]['termCode'])); ?>
                                </select>
                            </div> <a href="#termCode" data-toggle="modal" title="Term" class="btn btn-primary"><i class="fa fa-info-circle"></i></a>
                        </div>
                        <!-- // Group END -->
                        <?php if(_h($tuition1[0]['total']) !== '' && $tuition2 !== '') : ?>
                        <?php if(_h($tuition1[0]['total']) !== $tuition2 && $tuition2 !== '0.00') : ?>
                        <!-- Group -->
                        <div class="form-group">
                            <label class="col-md-3 control-label"><?=_t( 'Tuition / Adjusted' );?></label>
                            <div class="col-md-4">
                                <input class="form-control" type="text" name="total" value="<?=money_format('%n', (double)_h($tuition1[0]['total'])+0);?>" />
                                <input class="form-control" type="hidden" name="tuitionID" value="<?=_h($tuition1[0]['id']);?>" />
                            </div>
                            <div class="col-md-4">
                                <input class="form-control" type="text" readonly value="<?=money_format('%n', (double)bcsub(_h($tuition1[0]['total']),_h($tuition2),2)+0);?>" />
                            </div>
                        </div>
                        <!-- // Group END -->
                        <?php else : ?>
                        <!-- Group -->
                        <div class="form-group">
                            <label class="col-md-3 control-label"><?=_t( 'Tuition' );?></label>
                            <div class="col-md-8">
                                <input class="form-control" type="text" name="total" value="<?=money_format('%n', (double)_h($tuition1[0]['total'])+0);?>" />
                                <input class="form-control" type="hidden" name="tuitionID" value="<?=_h($tuition1[0]['id']);?>" />
                            </div>
                        </div>
                        <!-- // Group END -->
                        <?php endif; else : ?>
                        <!-- Group -->
                        <div class="form-group">
                            <label class="col-md-3 control-label"><?=_t( 'Tuition' );?></label>
                            <div class="col-md-8">
                                <input class="form-control" type="text" readonly value="<?=money_format('%n',(double) _h($tuition1[0]['total'])+0);?>" />
                            </div>
                        </div>
                        <!-- // Group END -->
                        <?php endif; ?>
                        
                        <?php if($fee != '') : foreach($fee as $k => $v) { ?>
                        
                        <!-- Group -->
                        <div class="form-group">
                            <label class="col-md-3 control-label"><?=_h($v['description']);?></label>
                            <div class="col-md-2">
                                <input type="text"<?=saio();?> class="form-control" name="amount[]" value="<?=_h($v['Fee']);?>" />
                                <input type="hidden" name="feeID[]" value="<?=_h($v['id']);?>" />
                                <div class="modal fade" id="fee<?=_h($v['id']);?>">
                                	<div class="modal-dialog">
										<div class="modal-content">
											<!-- Modal heading -->
											<div class="modal-header">
												<button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
												<h3 class="modal-title"><?=_h($v['description']);?></h3>
											</div>
											<!-- // Modal heading END -->
		                                    <div class="modal-body">
		                                        <?=_t( 'Are you sure you want to delete this fee?' );?>
		                                    </div>
		                                    <div class="modal-footer">
		                                        <a<?=saids();?> href="<?=get_base_url();?>financial/deleteFee/<?=_h($v['id']);?>" class="btn btn-default"><?=_t( 'Delete' );?></a>
		                                        <a href="#" data-dismiss="modal" class="btn btn-primary"><?=_t( 'Cancel' );?></a>
		                                    </div>
	                                	</div>
                                	</div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="input-group date" id="datepicker6">
                                    <input class="form-control" name="feeDate[]" type="text" value="<?=_h($v['feeDate']);?>" required/>
                                    <span class="input-group-addon"><i class="fa fa-th"></i></span>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <select name="feeTerm[]"<?=saio();?> class="selectpicker form-control" data-style="btn-info" data-size="10" data-live-search="true" required>
                                    <option value="">&nbsp;</option>
                                    <?php table_dropdown('term','active = "1" AND termCode <> "NULL"','termCode','termCode','termName',_h($v['termCode'])); ?>
                                </select>
                            </div> <a<?=saids();?> href="#fee<?=_h($v['id']);?>" data-toggle="modal" title="Delete Fee" class="btn btn-danger"><i class="fa fa-trash-o"></i></a>
                        </div>
                        <!-- // Group END -->
                        
                        <?php } endif; ?>
                        
                    </div>
                    <!-- // Column END -->
                    
                    <!-- Column -->
                    <div class="col-md-6">
                    	<!-- Group -->
                        <div class="form-group">
                            <label class="col-md-3 control-label"><?=_h($v['Term']);?> <?=_t( 'Begin / End Balance' );?></label>
                            <div class="col-md-4">
                                <input type="text" style="color:red;" class="form-control center" readonly value="<?=$begin;?>" />
                            </div>
                            <div class="col-md-4">
                                <input type="text"<?php if($endingBalance < 0) { echo ' style="color:red;"'; } ?> class="form-control center" readonly value="<?=_h($endingBalance);?>" />
                            </div>
                        </div>
                        <!-- // Group END -->
                        
                        <!-- Group -->
                        <div class="form-group">
                            <label class="col-md-3 control-label"><?=_t( 'Credit / Refund Totals' );?></label>
                            <div class="col-md-4">
                                <input type="text" class="col-md-4 form-control center" readonly value="$<?=money_format("%i",(double)_h($sumPayments)+0);?>" />
                            </div>
                            
                            <div class="col-md-4">
                                <input type="text" class="form-control center" readonly value="$<?=money_format("%i",(double)_h($sumRefund)+0);?>" />
                            </div>
                        </div>
                        <!-- // Group END -->
                        
                        <?php if(past_balance(_h($bill[0]['stuID']),_h($bill[0]['termCode'])) != '') : foreach(past_balance(_h($bill[0]['stuID']),_h($bill[0]['termCode'])) as $k => $v) { ?>
                        <!-- Group -->
                        <div class="form-group">
                            <label class="col-md-3 control-label"><?=_h($v['Term']);?> <?=_t( 'Balance' );?></label>
                            <div class="col-md-8">
                                <input type="text" style="color:red;" class="form-control center" readonly value="<?=_h($v['Balance']);?>" />
                            </div>
                        </div>
                        <!-- // Group END -->
                        <?php } endif; ?>
                        
                        <?php if(_h($bill[0]['stu_comments']) != '') : ?>
                        <!-- Group -->
                        <div class="form-group">
                            <label class="col-md-3 control-label"><?=_t( 'Student Comments' );?></label>
                            <div class="col-md-8 alerts alerts-warn">
	                                <?=nl2br(_h($bill[0]['stu_comments']));?>
	                        </div>
                        </div>
                        <!-- // Group END -->
                        <?php endif; ?>
                        
                        <?php if(_h($bill[0]['staff_comments']) != '') : ?>
                        <!-- Group -->
                        <div class="form-group">
                            <label class="col-md-3 control-label"><?=_t( 'Staff Comments' );?></label>
                            <div class="col-md-8 alerts alerts-warn">
	                                <?=nl2br(_h($bill[0]['staff_comments']));?>
	                        </div>
                        </div>
                        <!-- // Group END -->
                        <?php endif; ?>
                        
                    </div>
                    <!-- // Column END -->
                    
                </div>
                <!-- // Row END -->
                
                <hr class="separator" />
                
                <!-- Form actions -->
                <div class="form-actions">
                    <input type="hidden" name="stuID" value="<?=_h($bill[0]['stuID']);?>" />
                    <button type="submit" class="btn btn-icon btn-primary glyphicons circle_ok"><i></i><?=_t( 'Update' );?></button>
                    <button type="button" class="btn btn-icon btn-primary glyphicons circle_minus" onclick="window.location='<?=get_base_url();?>financial/'"><i></i><?=_t( 'Cancel' );?></button>
                    <?php if(prev_stu_acct_record(_h($bill[0]['id']),_h($bill[0]['stuID'])) != '') : ?>
                    <button type="button" class="btn btn-icon btn-primary glyphicons rewind" onclick="window.location='<?=get_base_url();?>financial/<?=prev_stu_acct_record(_h($bill[0]['id']),_h($bill[0]['stuID']));?>/'"><i></i><?=_t( 'Previous' );?></button>
                    <?php endif; ?>
                    <?php if(next_stu_acct_record(_h($bill[0]['id']),_h($bill[0]['stuID'])) != '') : ?>
                    <button type="button" class="btn btn-icon btn-primary glyphicons forward" onclick="window.location='<?=get_base_url();?>financial/<?=next_stu_acct_record(_h($bill[0]['id']),_h($bill[0]['stuID']));?>/'"><i></i><?=_t( 'Next' );?></button>
                    <?php endif; ?>
                </div>
                <!-- // Form actions END -->
                
            </div>
        </div>
        <!-- // Widget END -->
        
    </form>
    <!-- // Form END -->
    
    <!-- Modal -->
    <div class="modal fade" id="deleteBill">
        <div class="modal-dialog">
            <div class="modal-content">
                <!-- Modal heading -->
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
                    <h3 class="modal-title"><?=_t( 'Delete Invoice: ' );?><?=$bill[0]['billID'];?></h3>
                </div>
                <!-- // Modal heading END -->
                <!-- Modal body -->
                <div class="modal-body">
                    <p><?=_t('There is no undoing this, so are you sure you want to delete this bill / invoice.');?></p>
                </div>
                <!-- // Modal body END -->
                <!-- Modal footer -->
                <div class="modal-footer">
                    <a<?=saids();?> href="<?=get_base_url();?>financial/<?=$bill[0]['billID'];?>/delete/" class="btn btn-default"><?=_t( 'Delete' );?></a>
                    <a href="#" class="btn btn-primary" data-dismiss="modal"><?=_t( 'Close' );?></a> 
                </div>
                <!-- // Modal footer END -->
            </div>
        </div>
    </div>
    <!-- // Modal END -->
    
    <!-- Modal -->
    <div class="modal fade" id="termCode">
        <div class="modal-dialog">
            <div class="modal-content">
                <!-- Modal heading -->
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
                    <h3 class="modal-title"><?=_t( 'Bill / Invoice Term' );?></h3>
                </div>
                <!-- // Modal heading END -->
                <!-- Modal body -->
                <div class="modal-body">
                    <p><?=_t("A bill/invoice is connected to a term and there is ONE bill per/term, per/student. It is strongly encouraged to not change the term on a student's bill. If you do change the term, make sure to change it to a term where a bill does not currently exists. If you update the bill to a term where a bill already exists for a student, this will have disastrous effects and it cannot be undone.");?></p>
                    <p><?=_t("Moreover, if you created the bill using the wrong term, and you need to move the bill to a term where a current bill <strong>*does not*</strong> exist for the student, you first need to change/update the term on fees, payments, and refunds first, and then change/update the term on the bill.");?></p>
                </div>
                <!-- // Modal body END -->
                <!-- Modal footer -->
                <div class="modal-footer">
                    <a href="#" class="btn btn-primary" data-dismiss="modal"><?=_t( 'Close' );?></a> 
                </div>
                <!-- // Modal footer END -->
            </div>
        </div>
    </div>
    <!-- // Modal END -->
    
    <!-- Modal -->
    <div class="modal fade" id="FERPA">
        <div class="modal-dialog">
            <div class="modal-content">
                <!-- Modal heading -->
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
                    <h3 class="modal-title"><?=_t( 'Family Educational Rights and Privacy Act (FERPA)' );?></h3>
                </div>
                <!-- // Modal heading END -->
                <!-- Modal body -->
                <div class="modal-body">
                    <p><?=_t('"FERPA gives parents certain rights with respect to their children\'s education records. 
                    These rights transfer to the student when he or she reaches the age of 18 or attends a school beyond 
                    the high school level. Students to whom the rights have transferred are \'eligible students.\'"');?></p>
                    <p><?=_t('If the FERPA restriction states "Yes", then the student has requested that none of their 
                    information be given out without their permission. To get a better understanding of FERPA, visit 
                    the U.S. DOE\'s website @ ') . 
                    '<a href="http://www2.ed.gov/policy/gen/guid/fpco/ferpa/index.html">http://www2.ed.gov/policy/gen/guid/fpco/ferpa/index.html</a>.';?></p>
                </div>
                <!-- // Modal body END -->
                <!-- Modal footer -->
                <div class="modal-footer">
                    <a href="#" class="btn btn-default" data-dismiss="modal"><?=_t( 'Close' );?></a> 
                </div>
                <!-- // Modal footer END -->
            </div>
        </div>
    </div>
    <!-- // Modal END -->
    
</div>  
        
        </div>
        <!-- // Content END -->
<?php $app->view->stop(); ?>