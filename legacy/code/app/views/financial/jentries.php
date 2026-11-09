<?php if ( ! defined('BASE_PATH') ) exit('No direct script access allowed');
/**
 * General Ledger Journal Entries View
 *  
 * @license GPLv3
 * 
 * @since       3.0.0
 * @package     eduTrac SIS
 * @author      Joshua Parker <joshmac3@icloud.com>
 */

$app = \Liten\Liten::getInstance();
$app->view->extend('_layouts/blank');
$app->view->block('blank');
?>
<!DOCTYPE html>
<html lang="en">

<head>
	<meta charset='UTF-8'>
	
	<title><?=_t( 'General Journal Entries' );?></title>
	
	<link rel='stylesheet' href='<?=get_base_url();?>static/assets/css/style.css'>
	<link rel='stylesheet' href='<?=get_base_url();?>static/assets/css/print.css' media="print">

</head>

<body>

	<div id="page-wrap">

		<div id="header"><?=_t( 'Journal Entry Summary' );?></div>
		
		<div style="clear:both"></div>
		
		<div id="customer">

            <table id="meta-right">
                <tr>
                    <td class="meta-head"><?=_t( 'Today\'s Date' );?></td>
                    <td><?=\Jenssegers\Date\Date::now()->format('Y-m-d');?></td>
                </tr>
                <tr>

                    <td class="meta-head"><?=_t( 'Statement Period' );?></td>
                    <td><?=\Jenssegers\Date\Date::parse(_h($from))->format("D, M d, o");?> - <?=\Jenssegers\Date\Date::parse(_h($to))->format("D, M d, o");?></td>
                </tr>

            </table>
		
		</div>
		
		<table id="items">
		
		  <tr>
		      <th><?=_t( 'Date' );?></th>
		      <th><?=_t( 'Manual ID' );?></th>
		      <th><?=_t( 'Title' );?></th>
		      <th colspan="2"><?=_t( 'Description' );?></th>
		      <th><?=_t( 'Posted by' );?></th>
		      <th><?=_t( 'Amount' );?></th>
		  </tr>
		  <?php if($jfilter != '') : foreach($jfilter as $k => $v) { ?>
		  <tr class="item-row">
		      <td><?=_h($v['gl_jentry_date']);?></td>
		      <td><?=_h($v['gl_jentry_manual_id']);?></td>
	      	  <td><?=_h($v['gl_jentry_title']);?></td>
	      	  <td colspan="2"><?=_h($v['gl_jentry_description']);?></td>
	      	  <td><?=get_name(_h($v['gl_jentry_personID']));?></td>
	      	  <td><?=money_format("%i",(double)0+_h($v['gl_trans_debit']));?></td>
		  </tr>
		  <?php } endif; ?>
		  
		  <tr id="hiderow">
		    <td colspan="7">&nbsp;</td>
		  </tr>
		  
		  <tr>
		      <td colspan="3" class="blank"> </td>
		      <td colspan="3" class="total-line balance"><?=_t( 'Total Amount' );?></td>
		      <td class="total-value balance"><div class="due"><?=money_format("%i",(double)0+_h($jSum[0]['Debit']));?></div></td>
		  </tr>
		
		</table>
		
		<div id="terms">
		  <div><?=_t( 'This computer generated statement does not need signature.' );?></div>
		</div>
	
	</div>
	
</body>

</html>
<?php $app->view->stop(); ?>