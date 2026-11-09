<?php if ( ! defined('BASE_PATH') ) exit('No direct script access allowed');
/**
 * View Bill Comments View
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
    <li><a href="<?=get_base_url();?>financial/" class="glyphicons search"><i></i> <?=_t( 'Search Bill' );?></a></li>
    <li class="divider"></li>
    <li><?=_t( 'View Bill' );?></li>
</ul>

<div class="innerLR">
    
    <?php get_stu_header($stu->stuID); ?>
    
    <div class="separator line bottom"></div>
    
    <?=_etsis_flash()->showMessage();?>
    
    <?php jstree_sidebar_menu($screen); ?>

    <!-- Form -->
    <form class="form-horizontal margin-none" action="<?=get_base_url();?>financial/<?=$bill[0]['billID'];?>/comments/" id="validateSubmitForm" method="post" autocomplete="off">
        
        <!-- Widget -->
        <div class="widget widget-heading-simple widget-body-gray <?=($app->hook->has_filter('sidebar_menu')) ? 'col-md-12' : 'col-md-10';?>">
            
            <!-- Tabs Heading -->
            <div class="tabsbar">
                <ul>
                    <li class="glyphicons calculator"><a href="<?=get_base_url();?>financial/<?=$bill[0]['billID'];?>/"><i></i> <?=_t( 'Student Bill' );?></a></li>
                    <li class="glyphicons money"><a href="<?=get_base_url();?>financial/<?=$bill[0]['billID'];?>/payments/"><i></i> <?=_t( 'Payments' );?></a></li>
                    <li class="glyphicons undo tab-stacked"><a href="<?=get_base_url();?>financial/<?=$bill[0]['billID'];?>/refunds/"><i></i> <?=_t( 'Refunds' );?></a></li>
                    <li class="glyphicons chat tab-stacked active"><a href="<?=get_base_url();?>financial/<?=$bill[0]['billID'];?>/comments/" data-toggle="tab"><i></i> <span><?=_t( 'Comments' );?></span></a></li>
                </ul>
            </div>
            <!-- // Tabs Heading END -->
            
            <div class="widget-body">
            
                <!-- Row -->
                <div class="row">
                    
                    <!-- Column -->
                    <div class="col-md-6">
                        
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
                            <label class="col-md-3 control-label"><?=_t( 'Invoice Date' );?></label>
                            <div class="col-md-8">
                                <input class="form-control" type="text" readonly value="<?=\Jenssegers\Date\Date::parse(_h($bill[0]['billTimeStamp']))->format("D, M d, o @ g:i A");?>" />
                            </div>
                        </div>
                        <!-- // Group END -->
                        
                        <!-- Group -->
                        <div class="form-group">
                            <label class="col-md-3 control-label"><?=_t( 'Term' );?></label>
                            <div class="col-md-8">
                                <input class="form-control" type="text" readonly value="<?=_h($bill[0]['termName']);?>" />
                            </div>
                        </div>
                        <!-- // Group END -->
                        
                    </div>
                    <!-- // Column END -->
                    
                    <!-- Column -->
                    <div class="col-md-6">
                        
                    	<!-- Group -->
                        <div class="form-group">
                            <label class="col-md-3 control-label"><?=_t( 'Student Comments' );?></label>
                            <div class="col-md-8">
                                <textarea id="stu_comments" class="col-md-8 form-control" name="stu_comments" rows="10"><?=_h($bill[0]['stu_comments']);?></textarea>
                                <input type="button" class="btn btn-default" value="Insert Timestamp" onclick="addMsg('<?=\Jenssegers\Date\Date::now()->format('D, M d, o @ h:i A');?> <?=get_name(get_persondata('personID'));?>','stu_comments'); return false;" />
                            </div>
                        </div>
                        <!-- // Group END -->
                        
                        <!-- Group -->
                        <div class="form-group">
                            <label class="col-md-3 control-label"><?=_t( 'Staff Comments' );?></label>
                            <div class="col-md-8">
                                <textarea id="staff_comments" class="col-md-8 form-control" name="staff_comments" rows="10"><?=_h($bill[0]['staff_comments']);?></textarea>
                                <input type="button" class="btn btn-default" value="Insert Timestamp" onclick="addMsg('<?=\Jenssegers\Date\Date::now()->format('D, M d, o @ h:i A');?> <?=get_name(get_persondata('personID'));?>','staff_comments'); return false;" />
                            </div>
                        </div>
                        <!-- // Group END -->
                        
                    </div>
                    <!-- // Column END -->
                    
                </div>
                <!-- // Row END -->
                
                <hr class="separator" />
                
                <!-- Form actions -->
                <div class="form-actions">
                    <button type="submit" class="btn btn-icon btn-primary glyphicons circle_ok"><i></i><?=_t( 'Update' );?></button>
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
    
</div>  
        
        </div>
        <!-- // Content END -->
<?php $app->view->stop(); ?>