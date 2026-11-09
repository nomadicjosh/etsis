<?php if ( ! defined('BASE_PATH') ) exit('No direct script access allowed');
/**
 * Batch Fees View
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
?>

<script type='text/javascript'>
$(document).ready(function(){ 
    $("input[name='population']").change(function() {
        var student = $(this).val();
        $(".student").hide();      
        $("#"+student).show(function(){
            $(this).html($(this).html());
            });
    });
});
</script>

<ul class="breadcrumb">
    <li><?=_t( 'You are here' );?></li>
    <li><a href="<?=get_base_url();?>dashboard/" class="glyphicons dashboard"><i></i> <?=_t( 'Dashboard' );?></a></li>
    <li class="divider"></li>
    <li><?=_t( 'Batch Fees' );?></li>
</ul>

<h3><?=_t( 'Batch Fees' );?></h3>
<div class="innerLR">
    
    <?=_etsis_flash()->showMessage();?>

    <!-- Form -->
    <form class="form-horizontal margin-none" action="<?=get_base_url();?>financial/batch/" id="validateSubmitForm" method="post" autocomplete="off">
        
        <!-- Widget -->
        <div class="widget widget-heading-simple widget-body-gray">
        
            <!-- Widget heading -->
            <div class="widget-head">
                <h4 class="heading"><font color="red">*</font> <?=_t( 'Indicates field is required' );?></h4>
            </div>
            <!-- // Widget heading END -->
            
            <!-- Tabs Heading -->
            <div class="tabsbar">
                <ul>
                    <li class="glyphicons barcode"><a href="<?=get_base_url();?>financial/create-bill/"><i></i> <?=_t( 'Generate Bill/Add Fee' );?></a></li>
                    <li class="glyphicons sort active"><a href="<?=get_base_url();?>financial/batch/" data-toggle="tab"><i></i> <?=_t( 'Batch Fees' );?></a></li>
                    <li class="glyphicons credit_card tab-stacked"><a href="<?=get_base_url();?>financial/add-payment/"><i></i> <?=_t( 'Add Payment' );?></a></li>
                    <li class="glyphicons money tab-stacked"><a href="<?=get_base_url();?>financial/issue-refund/"><i></i> <span><?=_t( 'Issue Refund' );?></span></a></li>
                    <li class="glyphicons leaf tab-stacked"><a href="<?=get_base_url();?>financial/payment-plan/"><i></i> <span><?=_t( 'Payment Plan' );?></span></a></li>
                </ul>
            </div>
            <!-- // Tabs Heading END -->
            
            <div class="widget-body">
            
                <!-- Row -->
                <div class="row">
                    <!-- Column -->
                    <div class="col-md-6">
                        
                        <!-- Group -->
                        <div class="form-group" id="hiddenDiv">
                            <label class="col-md-3 control-label"><font color="red">*</font> <?=_t( 'Population' );?></label>
                            <div class="col-md-8">
                                <div class="widget-body uniformjs">
                                    <label class="radio">
                                        <input type="radio" class="radio" name="population"<?=saio();?> value="prog" /> <?=_t( 'Program' );?>
                                    </label><br/>
                                    <label class="radio">
                                        <input type="radio" class="radio" name="population"<?=saio();?> value="major" /> <?=_t( 'Major' );?>
                                    </label><br/>
                                    <label class="radio">
                                        <input type="radio" class="radio" name="population"<?=saio();?> value="level" /> <?=_t( 'Academic Level' );?>
                                    </label>
                                </div>
                            </div>
                        </div>
                        <!-- // Group END -->
                        
                        <!-- Group -->
                        <div class="form-group student" style="display:none;" id="prog">
                            <label class="col-md-3 control-label"><?=_t( 'Academic Program' );?></label>
                            <div class="col-md-8">
                                <select name="stu_program"<?=saio();?> class="form-control">
                                    <option value="">&nbsp;</option>
                                    <?php table_dropdown('acad_program','currStatus = "A"','acadProgCode','acadProgCode','acadProgTitle'); ?>
                                </select>
                            </div>
                        </div>
                        <!-- // Group END -->
                        
                        <!-- Group -->
                        <div class="form-group student" style="display:none;" id="major">
                            <label class="col-md-3 control-label"><?=_t( 'Major' );?></label>
                            <div class="col-md-8">
                                <select name="major"<?=saio();?> class="form-control">
                                    <option value="">&nbsp;</option>
                                    <?php table_dropdown('major','majorCode <> "NULL"','majorCode','majorCode','majorName'); ?>
                                </select>
                            </div>
                        </div>
                        <!-- // Group END -->
                        
                        <!-- Group -->
                        <div class="form-group student" style="display:none;" id="level">
                            <label class="col-md-3 control-label"><?=_t( 'Academic Level' );?></label>
                            <div class="col-md-8">
                                <select name="acadLevelCode"<?=saio();?> class="form-control">
                                    <option value="">&nbsp;</option>
                                    <?php table_dropdown('aclv',null,'code','code','name'); ?>
                                </select>
                            </div>
                        </div>
                        <!-- // Group END -->
                        
                    </div>
                    <!-- // Column END -->
                    
                    <!-- Column -->
                    <div class="col-md-6">
                        
                        <!-- Group -->
                        <div class="form-group">
                            <label class="col-md-3 control-label"><font color="red">*</font> <?=_t( 'Bill / Fee Date' );?></label>
                            <div class="col-md-8">
                                <div class="input-group date col-md-12" id="datepicker6">
                                    <input class="form-control" name="billDate" type="text" required/>
                                    <span class="input-group-addon"><i class="fa fa-th"></i></span>
                                </div>
                            </div>
                        </div>
                        <!-- // Group END -->
                        
                    </div>
                    <!-- // Column END -->
                    
                </div>
                <!-- // Row END -->
                
                <hr class="separator" />
                
                <!-- Row -->
                <div class="row">
                    <!-- Column -->
                    <div class="col-md-6">
                        
                        <!-- Group -->
                        <div class="form-group">
                            <label class="col-md-3 control-label"><font color="red">*</font> <?=_t( 'Fees' );?></label>
                            <div class="col-md-8">
                                <select multiple id="pre-selected-options"<?=saio();?> class="multiselect" name="feeID[]" required>
                                    <?php fee_table_dropdown(); ?>
                                </select>
                            </div>
                        </div>
                        <!-- // Group END -->
                        
                    </div>
                    <!-- // Column END -->
                    
                    <!-- Column -->
                    <div class="col-md-6">
                        
                        <!-- Group -->
                        <div class="form-group">
                            <label class="col-md-3 control-label"><font color="red">*</font> <?=_t( 'Term' );?></label>
                            <div class="col-md-8">
                                <select name="termCode"<?=saio();?> class="selectpicker form-control" data-style="btn-info" data-size="10" data-live-search="true" required>
                                    <option value="">&nbsp;</option>
                                    <?php table_dropdown('term','active = "1" AND termCode <> "NULL"','termCode','termCode','termName'); ?>
                                </select>
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
                    <button type="submit"<?=saids();?> class="btn btn-icon btn-primary glyphicons circle_ok"><i></i><?=_t( 'Submit' );?></button>
                </div>
                <!-- // Form actions END -->
                
            </div>
        </div>
        <!-- // Widget END -->
        
    </form>
    <!-- // Form END -->
    
</div>  
        
        </div>
        <!-- // Content END -->
<?php $app->view->stop(); ?>