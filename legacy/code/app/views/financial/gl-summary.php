<?php if ( ! defined('BASE_PATH') ) exit('No direct script access allowed');
/**
 * General Ledger Summary View
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
	
	<title><?=_t( 'General Ledger Summary' );?></title>
	
	<link rel='stylesheet' href='<?=get_base_url();?>static/assets/css/style.css'>
	<link rel='stylesheet' href='<?=get_base_url();?>static/assets/css/print.css' media="print">

</head>

<body>

	<div id="page-wrap">

		<div id="header"><?=_t( 'General Ledger Summary' );?></div>
		
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
		      <th><?=_t( 'GL Number' );?></th>
		      <th><?=_t( 'Account Title' );?></th>
		      <th><?=_t( 'Type' );?></th>
		      <th><?=_t( 'Opening' );?></th>
		      <th><?=_t( 'Debit' );?></th>
		      <th><?=_t( 'Credit' );?></th>
		      <th><?=_t( 'Closing' );?></th>
		  </tr>
		  <?php if($glfilter != '') : foreach($glfilter as $k => $v) { ?>
		  <?php $opening_balance = money_format("%i",(double)0+_h($glSum[0]['Debit'])+_h($glSum[0]['Credit'])); ?>
		  <tr class="item-row">
		      <td><?=_h($v['gl_acct_number']);?></td>
		      <td><?=_h($v['gl_acct_name']);?></td>
	      	  <td><?=_h($v['gl_acct_type']);?></td>
	      	  <td><?=$opening_balance;?></td>
	      	  <td><?=money_format("%i",(double)0+_h($v['Debit']));?></td>
	      	  <td><?=money_format("%i",(double)0+_h($v['Credit']));?></td>
	      	  <td><?=money_format("%i",(double)$opening_balance+0+_h($v['Debit'])+_h($v['Credit']));?></td>
		  </tr>
		  <?php } endif; ?>
		  
		  <tr id="hiderow">
		    <td colspan="7">&nbsp;</td>
		  </tr>
		  
		  <tr>
		      <td colspan="2" class="blank"> </td>
		      <td class="total-line balance"><?=_t( 'Total' );?></td>
		      <td class="total-value balance"><div class="due"><?=$opening_balance;?></div></td>
		      <td class="total-value balance"><div class="due"><?=money_format("%i",(double)0+_h($glfilter[0]['Debit']));?></div></td>
		      <td class="total-value balance"><div class="due"><?=money_format("%i",(double)0+_h($glfilter[0]['Credit']));?></div></td>
		      <td class="total-value balance"><div class="due"><?=money_format("%i",(double)0+_h($glfilter[0]['Debit'])+_h($glfilter[0]['Credit']));?></div></td>
		  </tr>
		
		</table>
		
		<div id="terms">
		  <div><?=_t( 'This computer generated statement does not need signature.' );?></div>
		</div>
	
	</div>
	
</body>

</html>
<?php $app->view->stop(); ?>