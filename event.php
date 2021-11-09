<?php

include_once('constants.inc');

function remove_running()
{
	global $RUNNING_EVENT;
	if ( file_exists($RUNNING_EVENT) )
	  unlink( $RUNNING_EVENT );
}

function make_bool( $val )
{
	if ($val)
	return 'true';
	else
	return 'false';
}

function get_response( $xml )
{
	global $EVENT_URL, $MQ_PASSWD;
	$fields = array('format' => 'xml', 'auth' => $MQ_PASSWD, 'reqxml' => $xml);
	$ch = curl_init($EVENT_URL);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);

	$response = curl_exec($ch);

	if(curl_errno($ch))
	{
	  $res['status'] = false;
	  $res['error'] = curl_error($ch);
	  logit( "curl_exec: ".curl_error($ch) );
	}
	else
	{
	  //print $data;
	  $res['status'] = true;
	  $res['response'] = $response;
	  curl_close($ch);
	}
	return $res;
}

function delete_event( $data )
{
	$xml = file_get_contents("xml/event-delete.xml");
	$search = array('[subdomain]', '[event-id]');
	$replace = array($data['c_subdomain'], $data['c_id']);
	$xml = str_replace( $search, $replace, $xml );
	$res = get_response( $xml );
}

function add_update_event( $data )
{
  $xml = file_get_contents("xml/event-create-update.xml");
  $data['c_comments'] = str_replace(array('&','>','<','"'), array('&amp;','&gt;','&lt;','&quot;'), nl2br($data['c_comments']));
  $data['c_description'] = str_replace(array('&','>','<','"'), array('&amp;','&gt;','&lt;','&quot;'), nl2br($data['c_description']));

	$search = array('[subdomain]', '[event-id]', '[course-start]', '[course-end]', '[enrol-date]', '[course-type]', '[status-nm]', '[status-nf]', 
			'[status-of]', '[status-om]', '[cancelled]', '[new-event]', '[list-only]', '[special]', '[comments]', '[date-changed]', '[description]', '[course-type-template]',
			'[status-server-om]', '[status-server-of]', '[apply-link]' );
	$replace = array( $data['c_subdomain'], $data['c_id'], $data['c_start'], $data['c_end'], $data['c_enrol_date'], $data['course_type'], $data['c_status_nm'], $data['c_status_nf'], 
			 $data['c_status_of'], $data['c_status_om'], make_bool($data['c_cancelled']), 'false', make_bool($data['c_list_only']), 'false',$data['c_comments'],
			 make_bool($data['c_date_change']), $data['c_description'], $data['course_template'], $data['c_status_svr_m'], $data['c_status_svr_f'], $data['apply-link'] );
	$xml = str_replace( $search, $replace, $xml );
//   file_put_contents("/tmp/event-req" , $xml, FILE_APPEND);
//   print $xml;
	$res = get_response( $xml );
	return $res;
}

if ( file_exists( $RUNNING_EVENT ) )
{
	logit("Event Cron Already running, exiting...\n");
	exit(1);
}


if ( !db_connect() )
  exit(1);

$q = "select c.*,td.td_key as 'course_type', td.td_val4 as 'course_template', cc.c_subdomain, cct.cct_course_template, cc.c_vri 
  from dh_course c left join dh_type_detail td on c.c_course_type=td.td_id  
	left join dh_center cc on c.c_center=cc.c_id
  left join dh_center_course_template cct on (c.c_center=cct.cct_center and td.td_key=cct.cct_course_type) 
  where c_processed=0 and cc.c_subdomain <> '' limit $BATCH_SIZE";
$res = mysql_query( $q );
if ( !$res )
{
	logit("Events: Cannot read dh_course: ".mysql_error()."\n");
	exit(1);
}

touch( $RUNNING_EVENT );
register_shutdown_function('remove_running');

while ( $row = mysql_fetch_array($res) )
{
	if ( $row['c_finalized'] )
	{
		mysql_query("update dh_course set c_processed='1', c_finalized_tstamp=NOW(), c_updated=NOW() where c_id=".$row['c_id']);
		$old = getcwd();
		chdir($APP_ROOT);
		$cmd = "/usr/bin/php action.php ".$row['c_id']." 'Finalize'";
		exec($cmd);
		chdir($old);
		continue;
	}
	echo $row['c_id']."\n";
	if ( $row['c_deleted'] )
	{
		$response = delete_event($row);
		if (! mysql_query("update dh_course set c_processed='1', c_updated=NOW() where c_id=".$row['c_id']))
			logit("Error: Course id ".$row['c_id']." - ".mysql_error());
		continue;
	}
	else
	{
		if ($row['cct_course_template'] <> '')
			$row['course_template'] = $row['cct_course_template'];
		$row['apply-link'] = "";
		if ($row['c_vri'])
		{
			//If c_vri is enabled, then we stop updates to dhamma.org
				$prefix = '';
			if (in_array($row['course_type'], array('20-DayOSC', '30-DayOSC', '45-DayOSC', '60-DayOSC', '10-DaySpecial', 'TSC')) )
			{
				$prefix = "long-course-" ;
				mysql_query("update dh_course set c_processed='1', c_updated=NOW() where c_id=".$row['c_id']);
				continue;
			}
			$row['apply-link'] = "https://schedule.vridhamma.org/form/".$prefix."application-form?centre=".$row['c_center']."&amp;course=".$row['c_id']; 
		}

		$response = add_update_event( $row );
	}
	if ( !$response['status'] )
	{
		print $response['error']."\n";
		logit("Error: Response err ".$response['error']);
		$data = 'Error: '.$response['error'];
	}
	else
	{
	 //print $response['response'];
		 $xml_obj = new SimpleXMLElement( str_replace('xmlns=', 'ns=', $response['response']) );
		 $err = $xml_obj->xpath('Errors/Error');
		 if ( $err )
		 {
			 $node = $err[0];
			 $msg = $node->Message;
			 //var_dump($xml_obj);
			 /*while(list( , $node) = each($err)) {
				$msg .=  $node." -> ";
			 }*/
			 echo "Course Id: ".$row['c_id']." -> ".$msg." -> ".$node->Context."\n";
			 logit("Events: Request Error for course_id: ".$row['c_id'].": $msg\n");
		 }
		else
		{
			print "Processed course id ".$row['c_id']."\n";
			if (! mysql_query("update dh_course set c_processed='1', c_updated=NOW() where c_id=".$row['c_id']))
				logit("Error: Course id ".$row['c_id']." - ".mysql_error());
		}
	 }
}

remove_running();
db_disconnect();

?>
