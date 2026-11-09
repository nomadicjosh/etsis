<?php if ( ! defined('BASE_PATH') ) exit('No direct script access allowed');
/**
 * Calendar of Events View
 *  
 * @license GPLv3
 * 
 * @since       4.1.7
 * @package     eduTrac SIS
 * @author      Joshua Parker <joshmac3@icloud.com>
 */

$app = \Liten\Liten::getInstance();
$app->view->extend('_layouts/dashboard');
$app->view->block('dashboard');
$screen = 'cal';
?>

<script type="text/javascript">
$(document).ready(function() { 
    var date = new Date();
    var d = date.getDate();
    var m = date.getMonth();
    var y = date.getFullYear();
    var calendar = $('#calendar').fullCalendar({
        eventMouseover: function( event, jsEvent, view ) { 
            var item = $(this);
            if(item.find('.nube').length == 0){
                var info = '<span class="nube"><p><strong>End:</strong> '+event.end+' <br /> <strong>Room:</strong> '+event.roomCode+' <br /> <strong>Description:</strong> '+event.description+'</p></span>';
                item.append(info);
            }
            if(parseInt(item.css('top')) <= 200){
                item.find('.nube').css({'top': 20,'bottom':'auto'});
                item.parent().find('.fc-event').addClass('z0');
            }
            item.find('.nube').stop(true,true).fadeIn();
        },
        eventMouseout: function( event, jsEvent, view ) { 
            var item = $(this);
            item.find('.nube').stop(true,true).fadeOut();
        },
    //configure options for the calendar
       header: {
          left: 'title',
          center: '',
          right: ''
       },
       selectable: true,
       selectHelper: true,


       // this is where you specify where to pull the events from.

       events: "<?=get_base_url();?>calendar/getEvents/",
       editable: false,
       defaultView: 'agendaWeek',
       allDayDefault: false,
       //etc etc
   });
   	$(".change-view").click(function(){
		 var data=$(this).data();
		$('#calendar').fullCalendar( 'changeView', data.view ); 
	});
	$(".change-today").click(function(){
		$('#calendar').fullCalendar( 'today' );
	});
	$(".change").click(function(){
		  var data=$(this).data();
		$('#calendar').fullCalendar( data.change );
	});
   
});
</script>

<ul class="breadcrumb">
	<li><?=_t( 'You are here' );?></li>
	<li><a href="<?=get_base_url();?>dashboard/" class="glyphicons dashboard"><i></i> <?=_t( 'Dashboard' );?></a></li>
	<li class="divider"></li>
	<li><?=_t( 'Events Calendar' );?></li>
</ul>

<h3><?=_t( 'Events Calendar' );?></h3>
<div class="innerLR">

	<?php jstree_sidebar_menu($screen); ?>

	<!-- Widget -->
	<div class="widget widget-heading-simple widget-body-gray <?=($app->hook->has_filter('sidebar_menu')) ? 'col-md-12' : 'col-md-10';?>">
		<div class="widget-body">
        
			<div id="content">
				<div class="tabbable">
						<ul class="nav nav-tabs" data-provide="tabdrop">
								<li><a href="#" class="change" data-change="prev"><i class="fa fa-chevron-left"></i></a></li>
								<li><a href="#" class="change" data-change="next"><i class="fa fa-chevron-right"></i></a></li>
								<li><a href="#" data-view="month" data-toggle="tab" class="change-view"><?=_t( 'Month' );?></a></li>
								<li class="active"><a href="#" data-view="agendaWeek" data-toggle="tab" class="change-view"><?=_t( 'Week' );?></a></li>
								<li><a href="#" data-view="agendaDay" data-toggle="tab" class="change-view"><?=_t( 'Day' );?></a></li>
								<li><a href="#" class="change-today"><?=_t( 'Today');?></a></li>
						</ul>
						<div class="tab-content">
								<div class="row">
                        
	                                <div class="col-lg-12">
	                                        <section class="panel">
	                                                <div class="panel-body">
	                                                	<section class="panel">
																<div class="col-lg-12" >
																		<div id="calendar"></div>
																</div>
														</section>
	                                                </div>
	                                        </section>
	                                </div>

                        		</div>
                        		<!-- //content > row-->
                		</div>
                       
				</div> 
            </div>
			
		</div>
	</div>
	
	<!-- // Widget END -->
	
</div>	
	
		
		</div>
		<!-- // Content END -->
<?php $app->view->stop(); ?>