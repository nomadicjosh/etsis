<?php if ( ! defined('BASE_PATH') ) exit('No direct script access allowed');
/**
 * Billing Table View
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
$screen = 'btbl';
?>

<ul class="breadcrumb">
	<li><?=_t( 'You are here');?></li>
	<li><a href="<?=get_base_url();?>dashboard/" class="glyphicons dashboard"><i></i> <?=_t( 'Dashboard' );?></a></li>
	<li class="divider"></li>
	<li><?=_t( 'Billing Table' );?></li>
</ul>

<h3><?=_t( 'Billing Table' );?></h3>
<div class="innerLR">
    
    <?=_etsis_flash()->showMessage();?>
    
    <?php jstree_sidebar_menu($screen); ?>

	<!-- Form -->
	<form class="form-horizontal margin-none" action="<?=get_base_url();?>financial/billing-table/" id="validateSubmitForm" method="post" autocomplete="off">
		
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
					
					<!-- Column -->
					<div class="col-md-6">
					
						<!-- Group -->
						<div class="form-group">
							<label class="col-md-3 control-label" for="name"><font color="red">*</font> <?=_t( 'Name' );?></label>
							<div class="col-md-8"><input class="form-control"<?=saio();?> id="name" name="name" type="text" required /></div>
						</div>
						<!-- // Group END -->
						
						<!-- Group -->
						<div class="form-group">
							<label class="col-md-3 control-label" for="amount"><font color="red">*</font> <?=_t( 'Amount' );?></label>
							<div class="col-md-8">
                                <input class="form-control"<?=saio();?> name="amount" type="text" placeholder="100,000" required />
						    </div>
						</div>
						<!-- // Group END -->
                        
                        <!-- Group -->
                        <div class="form-group">
                            <label class="col-md-3 control-label" for="type"><font color="red">*</font> <?=_t( 'Type' );?></label>
                            <div class="col-md-8">
                                <select name="type"<?=saio();?> class="selectpicker form-control" data-style="btn-info" data-size="10" data-live-search="true" required>
                                    <option value="">&nbsp;</option>
                                    <option value="F"><?=_t( 'Fee' );?></option>
                                    <option value="T"><?=_t( 'Tuition' );?></option>
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
                            <label class="col-md-3 control-label" for="term"><?=_t( 'Term' );?></label>
                            <div class="col-md-8">
                                <input type="hidden"<?=saio();?> id="input-tags" name="term" value="<?=_h($btbl->term);?>" />
                            </div>
                        </div>
                        <!-- // Group END -->
						
						<!-- Group -->
                        <div class="form-group">
                            <label class="col-md-3 control-label" for="status"><font color="red">*</font> <?=_t( 'Status' );?></label>
                            <div class="col-md-8">
                                <select name="status"<?=saio();?> class="selectpicker form-control" data-style="btn-info" data-size="10" data-live-search="true" required>
                                    <option value="">&nbsp;</option>
                                    <option value="A"><?=_t( 'Active' );?></option>
                                    <option value="I"><?=_t( 'Inactive' );?></option>
                                </select>
                            </div>
                        </div>
                        <!-- // Group END -->
						
					</div>
					<!-- // Column END -->
					
				</div>
				<!-- // Row END -->
			
				<hr class="separator" />
                
                 <div class="separator bottom"></div>
				
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
	
    <div class="separator bottom"></div>
	
	<!-- Widget -->
    <div class="widget widget-heading-simple widget-body-gray <?=($app->hook->has_filter('sidebar_menu')) ? 'col-md-12' : 'col-md-10';?>">
        <div class="widget-body">
        
            <!-- Table -->
            <table class="dynamicTable tableTools table table-striped table-bordered table-condensed table-primary">
            
                <!-- Table heading -->
                <thead>
                    <tr>
                        <th class="text-center"><?=_t( 'Name' );?></th>
                        <th class="text-center"><?=_t( 'Fee Type' );?></th>
                        <th class="text-center"><?=_t( 'Amount' );?></th>
                        <th class="text-center"><?=_t( 'Status' );?></th>
                        <th class="text-center"><?=_t( 'Term' );?></th>
                        <th class="text-center"><?=_t( 'Action' );?></th>
                    </tr>
                </thead>
                <!-- // Table heading END -->
                
                <!-- Table body -->
                <tbody>
                <?php if($billing != '') : foreach($billing as $key => $value) { ?>
                <tr class="gradeX">
                    <td class="text-center"><?=_h($value['name']);?></td>
                    <td class="text-center"><?=_h($value['FeeType']);?></td>
                    <td class="text-center">$<?=_h($value['amount']);?></td>
                    <td class="text-center">
                        <span class="label <?=etsis_btbl_status_label(_h($value['status']));?>" style="font-size:1em;font-weight: bold;">
                            <?=_h($value['Stat']);?>
                        </span>
                    </td>
                    <td class="text-center"><?=str_replace(',',', ',_h($value['term']));?></td>
                    <td class="text-center">
                        <a<?=ae('access_student_accounts');?> href="<?=get_base_url();?>financial/billing-table/<?=_h($value['id']);?>/" data-toggle="tooltip" data-placement="top" title="View/Edit"><button type="button" class="button bg-yellow"><i class="fa fa-edit"></i></button></a>
                        <a<?=ae('manage_business_rules');?> href="<?=get_base_url();?>financial/billing-table/<?=_h($value['id']);?>/btrl/" data-toggle="tooltip" data-placement="top" title="Business Rule"><button type="button" class="button bg-blue"><i class="fa fa-barcode"></i></button></a>
                    </td>
                </tr>
                <?php } endif; ?>
                    
                </tbody>
                <!-- // Table body END -->
                
            </table>
            <!-- // Table END -->
            
        </div>
    </div>
    <!-- // Widget END -->
	
</div>

<script src="<?=get_base_url();?>static/assets/components/modules/querybuilder/selectize/js/standalone/selectize.min.js" type="text/javascript"></script>
<script type="text/javascript">
$('#input-tags').selectize({
    plugins: ['remove_button'],
    delimiter: ',',
    persist: false,
    maxItems: null,
    valueField: 'code',
    labelField: 'name',
    searchField: ['name','code'],
    options: [
        <?php get_btbl_terms(); ?>
    ],
    rrender: {
        item: function(item, escape) {
            return '<div>' +
                (item.name ? '<span class="name">' + escape(item.name) + '</span>' : '') +
                (item.code ? '<span class="code">' + escape(item.code) + '</span>' : '') +
            '</div>';
        },
        option: function(item, escape) {
            var label = item.name || item.code;
            var caption = item.name ? item.code : null;
            return '<div>' +
                '<span class="label">' + escape(label) + '</span>' +
                (caption ? '<span class="caption">' + escape(caption) + '</span>' : '') +
            '</div>';
        }
    },
    create: function(input) {
        return {
            code: input
        };
    }
});
</script>
		
		</div>
		<!-- // Content END -->
<?php $app->view->stop(); ?>