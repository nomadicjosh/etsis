<?php if ( ! defined('BASE_PATH') ) exit('No direct script access allowed');
/**
 * View Bill Payments View
 *  
 * @license GPLv3
 * 
 * @since       6.0.00
 * @package     eduTrac SIS
 * @author      Joshua Parker <joshmac3@icloud.com>
 */

$app = \Liten\Liten::getInstance();
$app->view->extend('_layouts/dashboard');
$app->view->block('dashboard');
$screen = 'inv';
$stu = get_student(_h($bill[0]['stuID']));
?>

<script type="text/javascript">
function addMsg(text,element_id) {
	document.getElementById(element_id).value += text;
}
</script>

<ul class="breadcrumb">
    <li><?=_t( 'You are here' );?></li>
    <li><a href="<?=get_base_url();?>dashboard/" class="glyphicons dashboard"><i></i> <?=_t( 'Dashboard' );?></a></li>
    <li class="divider"></li>
    <li><a href="<?=get_base_url();?>financial/" class="glyphicons search"><i></i> <?=_t( 'Bill Lookup' );?></a></li>
    <li class="divider"></li>
    <li><?=_t( 'View Bill Payments' );?></li>
</ul>

<div class="innerLR">
    
    <?php get_stu_header($stu->stuID); ?>
    
    <div class="separator line bottom"></div>
    
    <?=_etsis_flash()->showMessage();?>
    
    <?php jstree_sidebar_menu($screen); ?>

    <!-- Form -->
    <form class="form-horizontal margin-none" action="<?=get_base_url();?>financial/<?=$bill[0]['billID'];?>/payments/" id="validateSubmitForm" method="post" autocomplete="off">
        
        <!-- Widget -->
        <div class="widget widget-heading-simple widget-body-gray <?=($app->hook->has_filter('sidebar_menu')) ? 'col-md-12' : 'col-md-10';?>">
            
            <!-- Tabs Heading -->
            <div class="tabsbar">
                <ul>
                    <li class="glyphicons calculator"><a href="<?=get_base_url();?>financial/<?=$bill[0]['billID'];?>/"><i></i> <?=_t( 'Student Bill' );?></a></li>
                    <li class="glyphicons money active"><a href="<?=get_base_url();?>financial/<?=$bill[0]['billID'];?>/payments/" data-toggle="tab"><i></i> <?=_t( 'Payments' );?></a></li>
                    <li class="glyphicons undo tab-stacked"><a href="<?=get_base_url();?>financial/<?=$bill[0]['billID'];?>/refunds/"><i></i> <?=_t( 'Refunds' );?></a></li>
                    <li class="glyphicons chat tab-stacked"><a href="<?=get_base_url();?>financial/<?=$bill[0]['billID'];?>/comments/"><i></i> <span><?=_t( 'Comments' );?></span></a></li>
                </ul>
            </div>
            <!-- // Tabs Heading END -->
            
            <div class="widget-body">
            
                <!-- Row -->
                <div class="row">
                    
                    <!-- Column -->
                    <div class="col-lg-12">
                        
                        <!-- Group -->
                        <div class="form-group">
                            <label class="col-lg-2 control-label"><?=_t( 'Invoice # / Date / Term' );?></label>
                            <div class="col-lg-3">
                                <input class="form-control center" type="text" readonly value="<?=_h($bill[0]['billID']);?>" />
                            </div>
                            
                            <div class="col-lg-3">
                                <input class="form-control center" type="text" readonly value="<?=\Jenssegers\Date\Date::parse(_h($bill[0]['billingDate']))->format("D, M d, o");?>" />
                            </div>
                            
                            <div class="col-lg-3">
                                <input class="form-control center" type="text" readonly value="<?=_h($bill[0]['termName']);?>" />
                            </div>
                        </div>
                        <!-- // Group END -->
                        
                    </div>
                    <!-- // Column END -->
                    
                    <!-- Column -->
                    <div class="col-lg-12">
                        <?php if(count($bill[0]['paymentID']) > 0) : foreach($bill as $k => $v) : ?>
                    	<!-- Group -->
                        <div class="form-group">
                            <label class="col-lg-2 control-label"><?=_t( 'Date / Type / Check / Term / Payment' );?></label>
                            <div class="col-lg-2">
                                <div class="input-group date" id="datepicker6">
                                    <input class="form-control" name="paymentDate[]" type="text" value="<?=_h($v['paymentDate']);?>" required/>
                                    <span class="input-group-addon"><i class="fa fa-th"></i></span>
                                </div>
                            </div>
                            <div class="col-lg-2">
                                <select name="paymentType[]"<?=saio();?> class="selectpicker form-control" data-style="btn-info" data-size="10" data-live-search="true" required>
                                    <option value="">&nbsp;</option>
                                    <?php payment_type_dropdown(_h($v['ptID'])); ?>
                                </select>
                            </div>
                            <div class="col-lg-1">
                                <input class="form-control center" type="text"<?=saio();?> name="checkNum[]" value="<?=_h($v['checkNum']);?>" />
                            </div>
                            <div class="col-lg-2">
                                <select name="termCode[]"<?=saio();?> class="selectpicker form-control" data-style="btn-info" data-size="10" data-live-search="true" required>
                                    <option value="">&nbsp;</option>
                                    <?php table_dropdown('term','active = "1" AND termCode <> "NULL"','termCode','termCode','termName',_h($v['termCode'])); ?>
                                </select>
                            </div>
                            <div class="col-lg-1">
                                <input class="form-control center" type="text" name="amount[]" value="<?=_h($v['amount']);?>" required/>
                            </div>
                            <a href="#note<?=_h($v['paymentID']);?>" data-toggle="modal" title="Notes" class="btn btn-primary"><i class="fa fa-file-text-o"></i></a>
                            <div class="modal fade" id="note<?=_h($v['paymentID']);?>">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <!-- Modal heading -->
                                        <div class="modal-header">
                                            <button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
                                            <h3 class="modal-title"><?=_t( 'Payment Note:' );?> $<?=money_format("%i",(double)_h($v['amount']));?></h3>
                                        </div>
                                        <!-- // Modal heading END -->
                                        <div class="modal-body">
                                            <p><textarea name="comment[]"<?=saio();?> id="<?=_h($v['paymentID']);?>" class="form-control" style="width:97.5%" cols="10" rows="5"><?=_h($v['comment']);?></textarea></p>
                                            <input type="button"<?=saids();?> class="btn btn-default" value="Insert Timestamp" onclick="addMsg('<?=\Jenssegers\Date\Date::now()->format('D, M d, o @ h:i A');?> <?=get_name(get_persondata('personID'));?>',<?=_h($v['paymentID']);?>); return false;" />
                                            <input type="hidden" name="id[]" value="<?=_h($v['paymentID']);?>" />
                                        </div>
                                        <div class="modal-footer">
                                            <a href="#" data-dismiss="modal" class="btn btn-primary"><?=_t( 'Cancel' );?></a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <a<?=gids();?> href="#payment<?=_h($v['paymentID']);?>" data-toggle="modal" title="Delete Payment" class="btn btn-danger"><i class="fa fa-trash-o"></i></a>
                            <div class="modal fade" id="payment<?=_h($v['paymentID']);?>">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <!-- Modal heading -->
                                        <div class="modal-header">
                                            <button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
                                            <h3 class="modal-title">$<?=money_format("%i",(double)_h($v['amount']));?> <?=_t( 'Payment' );?></h3>
                                        </div>
                                        <!-- // Modal heading END -->
                                        <div class="modal-body">
                                            <?=_t( 'Are you sure you want to delete this payment?' );?>
                                        </div>
                                        <div class="modal-footer">
                                            <a<?=saids();?> href="<?=get_base_url();?>financial/deletePayment/<?=_h($v['paymentID']);?>/" class="btn btn-default"><?=_t( 'Delete' );?></a>
                                            <a href="#" data-dismiss="modal" class="btn btn-primary"><?=_t( 'Cancel' );?></a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <!-- // Group END -->
                        <?php endforeach; endif; ?>
                        
                    </div>
                    <!-- // Column END -->
                    
                </div>
                <!-- // Row END -->
                
                <hr class="separator" />
                
                <!-- Form actions -->
                <div class="form-actions">
                    <input type="hidden" name="stuID" value="<?=_h($bill[0]['stuID']);?>" />
                    <button type="submit"<?=saids();?> class="btn btn-icon btn-primary glyphicons circle_ok"><i></i><?=_t( 'Update' );?></button>
                    <button type="button"<?=saids();?> href="#payment" data-toggle="modal" class="btn btn-icon btn-primary glyphicons circle_plus"><i></i><?=_t( 'Add Payment' );?></button>
                    <button type="button" class="btn btn-icon btn-primary glyphicons circle_minus" onclick="window.location='<?=get_base_url();?>financial/'"><i></i><?=_t( 'Cancel' );?></button>
                </div>
                <!-- // Form actions END -->
                
            </div>
        </div>
        <!-- // Widget END -->
        
    </form>
    <!-- // Form END -->
    
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
    
    <!-- Modal -->
	<div class="modal fade" id="payment">
		<form class="form-horizontal margin-none" action="<?=get_base_url();?>financial/add-payment/" id="validateSubmitForm" method="post" autocomplete="off">
		<div class="modal-dialog">
			<div class="modal-content">
				<!-- Modal heading -->
				<div class="modal-header">
					<button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
					<h3 class="modal-title"><?=_t( 'Add Payment' );?></h3>
				</div>
				<!-- // Modal heading END -->
				<!-- Modal body -->
				<div class="modal-body">
                    
                    <!-- Group -->
		            <div class="form-group">
		                <label class="col-md-3 control-label"><?=_t( 'Student' );?></label>
		                <div class="col-md-8">
		                    <input class="form-control" type="text" readonly value="<?=get_name(_h($bill[0]['stuID']));?> - <?=_h($bill[0]['stuID']);?>" />
		                </div>
		            </div>
		            <!-- // Group END -->
                    
                    <!-- Group -->
		            <div class="form-group">
		                <label class="col-md-3 control-label"><font color="red">*</font> <?=_t( 'Term' );?></label>
		                <div class="col-md-8">
                            <select name="termCode"<?=saio();?> class="selectpicker form-control" data-style="btn-info" data-size="10" data-live-search="true" required>
                                <option value="">&nbsp;</option>
                                <?php table_dropdown('term','active = "1" AND termCode <> "NULL"','termCode','termCode','termName',_h($bill[0]['termCode'])); ?>
                            </select>
                        </div>
		            </div>
		            <!-- // Group END -->
                    
					<!-- Group -->
		            <div class="form-group">
		                <label class="col-md-3 control-label"><font color="red">*</font> <?=_t( 'Payment' );?></label>
		                <div class="col-md-8">
		                    <input class="form-control" type="text"<?=saio();?> name="amount" required/>
		                </div>
		            </div>
		            <!-- // Group END -->
                    
                    <!-- Group -->
                    <div class="form-group">
                        <label class="col-md-3 control-label"><font color="red">*</font> <?=_t( 'Payment Date' );?></label>
                        <div class="col-md-8">
                            <div class="input-group date" id="datepicker6">
                                <input class="form-control" name="paymentDate" type="text" required/>
                                <span class="input-group-addon"><i class="fa fa-th"></i></span>
                            </div>
                        </div>
                    </div>
                    <!-- // Group END -->
                    
                    <!-- Group -->
                    <div class="form-group">
                        <label class="col-md-3 control-label"><font color="red">*</font> <?=_t( 'Payment Type' );?></label>
                        <div class="col-md-8">
                            <select <?=saio();?> name="paymentTypeID" class="selectpicker form-control" data-style="btn-info" data-size="10" data-live-search="true" required>
                                <option value="">&nbsp;</option>
                                <?php payment_type_dropdown(); ?>
                            </select>
                        </div>
                    </div>
                    <!-- // Group END -->
                    
                    <!-- Group -->
                    <div class="form-group">
                        <label class="col-md-3 control-label"><?=_t( 'Check Number' );?></label>
                        <div class="col-md-8">
                            <input class="form-control" type="text"<?=saio();?> name="checkNum" id="checkNum" />
                        </div>
                    </div>
                    <!-- // Group END -->
		            
		            <!-- Group -->
		            <div class="form-group">
		                <label class="col-md-3 control-label"><?=_t( 'Comment' );?></label>
		                <div class="col-md-8">
		                    <div class="col-md-8">
                                <textarea style="width: 370px !important;"<?=saio();?> class="form-control" id="comment" name="comment" cols="15" rows="5"></textarea>
                                <input type="button"<?=saids();?> class="btn btn-default" value="Insert Timestamp" onclick="addMsg('<?=\Jenssegers\Date\Date::now()->format('D, M d, o @ h:i A');?> <?=get_name(get_persondata('personID'));?>','comment'); return false;" />
                            </div>
		                </div>
		            </div>
		            <!-- // Group END -->
				</div>
				<!-- // Modal body END -->
				<!-- Modal footer -->
				<div class="modal-footer">
                    <input type="hidden" name="stuID" value="<?=_h($bill[0]['stuID']);?>" />
		            <button type="submit"<?=saids();?> class="btn btn-default"><?=_t( 'Submit' );?></button>
					<a href="#" class="btn btn-primary" data-dismiss="modal"><?=_t( 'Cancel' );?></a>
				</div>
				<!-- // Modal footer END -->
			</div>
		</div>
		</form>
	</div>
	<!-- // Modal END -->
    
</div>  
        
        </div>
        <!-- // Content END -->
<?php $app->view->stop(); ?>