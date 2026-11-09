<?php if ( ! defined('BASE_PATH') ) exit('No direct script access allowed');
/**
 * Add General Ledger Journal Entry View
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
	
	<title><?=_t( 'General Journal Entry' );?></title>
	
	<link rel='stylesheet' href='<?=get_base_url();?>static/assets/css/style.css'>
	<link rel='stylesheet' href='<?=get_base_url();?>static/assets/css/print.css' media="print">

</head>

<body>

	<div id="page-wrap">

		<div id="header"><?=_t( 'General Journal Entry' );?></div>
		
		<div style="clear:both"></div>
		
		<div id="customer">

            <div id="customer-title"><?=SITE_TITLE;?></div>
            
            <div style="clear:both"></div>
            
            <table id="meta-left">
                <tr>
                    <td class="meta-head"><?=_t( 'Journal ID' );?></td>
                    <td><?=_h($jentry[0]['jeID']);?></td>
                </tr>
                <tr>
                    <td class="meta-head"><?=_t( 'Manual ID' );?></td>
                    <td><?=_h($jentry[0]['gl_jentry_manual_id']);?></td>
                </tr>
                <tr>
                    <td class="meta-head"><?=_t( 'Title' );?></td>
                    <td><?=_h($jentry[0]['gl_jentry_title']);?></td>
                </tr>
                <tr>

            </table>

            <table id="meta-right">
                <tr>
                    <td class="meta-head"><?=_t( 'Description' );?></td>
                    <td><?=_h($jentry[0]['gl_jentry_description']);?></td>
                </tr>
                <tr>
                    <td class="meta-head"><?=_t( 'Posted by' );?></td>
                    <td><?=get_name(_h($jentry[0]['gl_jentry_personID']));?></td>
                </tr>
                <tr>

                    <td class="meta-head"><?=_t( 'Date' );?></td>
                    <td><?=\Jenssegers\Date\Date::parse(_h($jentry[0]['gl_jentry_date']))->format("D, M d, o");?></td>
                </tr>

            </table>
		
		</div>
		
		<table id="items">
		
		  <tr>
		      <th><?=_t( 'Account' );?></th>
		      <th><?=_t( 'Memo' );?></th>
		      <th><?=_t( 'Amount' );?></th>
		  </tr>
		  <?php if($jentry != '') : foreach($jentry as $k => $v) { ?>
		  <tr class="item-row">
		      <td class="item-name"><?=_h($v['gl_acct_name']);?></td>
		      <td class="description"><?=_h($v['gl_trans_memo']);?></td>
		      <?php if(_h($v['gl_trans_debit']) == 0) { ?>
		      <td><?=_h($v['gl_trans_credit']);?></td>
		      <?php } else { ?>
	      	  <td><?=_h($v['gl_trans_debit']);?></td>
	      	  <?php } ?>
		  </tr>
		  <?php } endif; ?>
		  
		  <tr id="hiderow">
		    <td colspan="3">&nbsp;</td>
		  </tr>
		  
		  <tr>
		  		<td colspan="2" class="blank"></td>
		      	<td class="total-line"><?=_t( 'Debit' );?> <?=number_format(0+_h($jtrans[0]['Debit']));?></td>
		  </tr>
		  <tr>
				<td colspan="2" class="blank"></td>
		    	<td class="total-line"><?=_t( 'Credit' );?> <?=number_format(0+_h($jtrans[0]['Credit']));?></td>
		  </tr>
		  <tr>
		  		<td colspan="2" class="blank"></td>
		      	<td class="total-line balance"><?=_t( 'Balance' );?> <?=number_format(0+_h($jtrans[0]['Credit'])+_h($jtrans[0]['Debit']));?></td>
		  </tr>
		
		</table>
		
		<div id="terms">
		  <div><?=_t( 'This computer generated statement does not need signature.' );?></div>
		</div>
	
	</div>
	
</body>

</html>
<?php $app->view->stop(); ?>