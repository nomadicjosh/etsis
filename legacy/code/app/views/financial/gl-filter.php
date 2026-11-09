<?php if ( ! defined('BASE_PATH') ) exit('No direct script access allowed');
/**
 * General Ledger Filter View
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
$screen = 'genl';
?>

<ul class="breadcrumb">
    <li><?=_t( 'You are here' );?></li>
    <li><a href="<?=get_base_url();?>dashboard/" class="glyphicons dashboard"><i></i> <?=_t( 'Dashboard' );?></a></li>
    <li class="divider"></li>
    <li><?=_t( 'Search General Ledger' );?></li>
</ul>

<h3><?=_t( 'Search General Ledger' );?></h3>
<div class="innerLR">
    
    <?=_etsis_flash()->showMessage();?>

	<?php jstree_sidebar_menu($screen); ?>

    <!-- Form -->
    <form class="form-horizontal margin-none" action="<?=get_base_url();?>financial/gl-filter/" id="validateSubmitForm" target="_blank" method="post" autocomplete="off">
        
        <!-- Widget -->
        <div class="widget widget-heading-simple widget-body-gray <?=($app->hook->has_filter('sidebar_menu')) ? 'col-md-12' : 'col-md-10';?>">
            
            <!-- Tabs Heading -->
            <div class="tabsbar">
                <ul>
                    <li class="glyphicons charts"><a href="<?=get_base_url();?>financial/gl-accounts/"><i></i> <?=_t( 'GL Accounts' );?></a></li>
                    <li class="glyphicons search"><a href="<?=get_base_url();?>financial/jentry-filter/"><i></i> <?=_t( 'Journal Entries' );?></a></li>
                    <li class="glyphicons search tab-stacked active"><a href="<?=get_base_url();?>financial/gl-filter/" data-toggle="tab"><i></i> <span><?=_t( 'General Ledger Summary' );?></span></a></li>
                </ul>
            </div>
            <!-- // Tabs Heading END -->
        
            <!-- Widget heading -->
            <div class="widget-head">
                <h4 class="heading"><?=_t( 'Select statement period' );?></h4>
            </div>
            <!-- // Widget heading END -->
            
            <div class="widget-body">
            
                <!-- Row -->
                <div class="row">
                    <!-- Column -->
                    <div class="col-md-6">
                        
                        <!-- Group -->
                        <div class="form-group">
                            <label class="col-md-3 control-label"><?=_t( 'From' );?></label>
                            <div class="col-md-8">
                                <div class="input-group date col-md-8" id="datepicker6">
						    		<input id="from" class="form-control center" name="from" type="text" value="<?=date('Y-m-d',strtotime("-3 Months"));?>" required />
				    				<span class="input-group-addon"><i class="fa fa-th"></i></span>
								</div>
                            </div>
                        </div>
                        <!-- // Group END -->
                        
                    </div>
                    <!-- // Column END -->
                    
                    <!-- Column -->
                    <div class="col-md-6">
                        
                        <!-- Group -->
                        <div class="form-group">
                            <label class="col-md-3 control-label"><?=_t( 'To' );?></label>
                            <div class="col-md-8">
                                <div class="input-group date col-md-8" id="datepicker7">
						    		<input id="to" class="form-control" name="to" type="text" value="<?=\Jenssegers\Date\Date::now()->format('Y-m-d');?>" required />
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
                
                <!-- Form actions -->
                <div class="form-actions">
                    <button type="submit" class="btn btn-icon btn-primary glyphicons circle_ok"><i></i><?=_t( 'Submit' );?></button>
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