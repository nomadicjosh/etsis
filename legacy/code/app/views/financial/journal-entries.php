<?php if ( ! defined('BASE_PATH') ) exit('No direct script access allowed');
/**
 * Journal Entries View
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
$screen = 'jent';
?>

<ul class="breadcrumb">
	<li><?=_t( 'You are here' );?></li>
	<li><a href="<?=get_base_url();?>dashboard/" class="glyphicons dashboard"><i></i> <?=_t( 'Dashboard' );?></a></li>
	<li class="divider"></li>
	<li><?=_t( 'Journal Entries' );?></li>
</ul>

<h3><?=_t( 'Journal Entries' );?> <a href="<?=get_base_url();?>financial/add-jentry/" title="Add Journal Entry" class="btn btn-default"><i class="fa fa-plus"></i></a></h3>
<div class="innerLR">
    
    <?=_etsis_flash()->showMessage();?>
    
    <?php jstree_sidebar_menu($screen); ?>

	<!-- Widget -->
	<div class="widget widget-heading-simple widget-body-gray <?=($app->hook->has_filter('sidebar_menu')) ? 'col-md-12' : 'col-md-10';?>">
        
        <!-- Tabs Heading -->
        <div class="tabsbar">
            <ul>
                <li class="glyphicons notes active"><a href="<?=get_base_url();?>financial/journal-entries/" data-toggle="tab"><i></i> <?=_t( 'Entries' );?></a></li>
                <li class="glyphicons circle_plus"><a href="<?=get_base_url();?>financial/add-jentry/"><i></i> <?=_t( 'Add Entry' );?></a></li>
            </ul>
        </div>
        <!-- // Tabs Heading END -->
            
		<div class="widget-body">
			
			<!-- Table -->
			<table class="dynamicTable tableTools table table-striped table-bordered table-condensed table-white">
			
				<!-- Table heading -->
				<thead>
					<tr>
						<th class="text-center"><?=_t( 'Date' );?></th>
						<th class="text-center"><?=_t( 'Manual ID' );?></th>
						<th class="text-center"><?=_t( 'Title' );?></th>
						<th class="text-center"><?=_t( 'Description' );?></th>
						<th class="text-center"><?=_t( 'Posted By' );?></th>
						<th class="text-center"><?=_t( 'Amount' );?></th>
						<th class="text-center"><?=_t( 'Actions' );?></th>
					</tr>
				</thead>
				<!-- // Table heading END -->
				
				<!-- Table body -->
				<tbody>
				<?php if($jentry != '') : foreach($jentry as $k => $v) { ?>
                <tr class="gradeX">
                    <td class="text-center"><?=_h($v['gl_jentry_date']);?></td>
                    <td class="text-center"><?=_h($v['gl_jentry_manual_id']);?></td>
                    <td class="text-center"><?=_h($v['gl_jentry_title']);?></td>
                	<td class="text-center"><?=_h($v['gl_jentry_description']);?></td>
                	<td class="text-center"><?=get_name(_h($v['gl_jentry_personID']));?></td>
                	<td class="text-center"><?=_h(money_format("%i",(double)$v['Debits']));?></td>
                    <td class="text-center">
                    	<div class="btn-group dropup">
                            <button class="btn btn-default btn-xs" type="button"><?=_t( 'Actions' ); ?></button>
                            <button data-toggle="dropdown" class="btn btn-xs btn-primary dropdown-toggle" type="button">
                                <span class="caret"></span>
                                <span class="sr-only"><?=_t( 'Toggle Dropdown' ); ?></span>
                            </button>
                            <ul role="menu" class="dropdown-menu dropup-text pull-right">
                                <li><a href="<?=get_base_url();?>financial/journal-entries/<?=_h($v['jeID']);?>" target="_blank"><?=_t( 'View' ); ?></a></li>
                                <li><a href="<?=get_base_url();?>financial/deljEntry/<?=_h($v['jeID']);?>"><?=_t( 'Delete' ); ?></a></li>
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
	<div class="separator bottom"></div>
	
	<!-- // Widget END -->
	
</div>	
	
		
		</div>
		<!-- // Content END -->
<?php $app->view->stop(); ?>