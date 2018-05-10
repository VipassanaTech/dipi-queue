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
   $search = array('[subdomain]', '[event-id]', '[course-start]', '[course-end]', '[enrol-date]', '[course-type]', '[status-nm]', '[status-nf]', 
		    '[status-of]', '[status-om]', '[cancelled]', '[new-event]', '[list-only]', '[special]', '[comments]', '[date-changed]', '[description]', '[course-type-template]' );
   $replace = array( $data['c_subdomain'], $data['c_id'], $data['c_start'], $data['c_end'], $data['c_enrol_date'], $data['course_type'], $data['c_status_nm'], $data['c_status_nf'], 
		     $data['c_status_of'], $data['c_status_om'], make_bool($data['c_cancelled']), 'false', make_bool($data['c_list_only']), 'false', $data['c_comments'],
		     make_bool($data['c_date_change']), $data['c_description'], $data['course_template'] );
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

$q = "select c.*,td.td_key as 'course_type', td.td_val4 as 'course_template', cc.c_subdomain from dh_course c left join dh_type_detail td on c.c_course_type=td.td_id  
	left join dh_center cc on c.c_center=cc.c_id where c_processed=0 and cc.c_subdomain <> '' limit $BATCH_SIZE";
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
        mysql_query("update dh_course set c_processed='1', c_finalized_tstamp=NOW() where c_id=".$row['c_id']);
        $old = getcwd();
        chdir($APP_ROOT);
        $cmd = "/usr/bin/php action.php ".$row['c_id']." 'Finalize'";
        exec($cmd);
        chdir($old);
        continue;
    }
    if ( $row['c_deleted'] )
    {
	$response = delete_event($row);
    }
    else
       $response = add_update_event( $row );
    if ( !$response['status'] )
        $data = 'Error: '.$response['error'];
    else
    {
//	 print $response['response'];
         $xml_obj = new SimpleXMLElement( str_replace('xmlns=', 'ns=', $response['response']) );
  	 $err = $xml_obj->xpath('Errors/Error');
         if ( $err )
         {
	     $node = $err[0];
	     $msg = $node->Message;
	     /*while(list( , $node) = each($err)) {
		    $msg .=  $node." -> ";
	     }*/
	     echo "Course Id: ".$row['c_id']." -> ".$msg."\n";
	     logit("Events: Request Error for course_id: ".$row['c_id'].": $msg\n");
         }
	 else
	 {
	    print "Processed course id ".$row['c_id']."\n";
            if (! mysql_query("update dh_course set c_processed='1' where c_id=".$row['c_id']))
		logit("Error: Course id ".$row['c_id']." - ".mysql_error());
	 }
     }
}

remove_running();
db_disconnect();

?>
