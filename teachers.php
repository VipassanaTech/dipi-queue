<?php
include_once('constants.inc');
$debug = false;

function send_request( $xml )
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
      logit( "send_request: ".curl_error($ch) );
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

db_connect();
//$res = send_request( file_get_contents("xml/teacher-all.xml"));
$res = send_request( file_get_contents("xml/teacher-updated-last-x-mins.xml"));
//$xml = file_get_contents('/tmp/a.xml');


$xml = simplexml_load_string($res['response']);
foreach( $xml->Teachers->Teacher as $t )
{
   unset($out);
   $out['t_code'] = (string)$t->TeacherKey->DhammaCode;
   $out['t_unique_code'] = (string)$t->Person->PersonKey->PersonReferenceCode;
   $out['t_f_name'] = (string)$t->Person->FirstName;
   $out['t_l_name'] = (string)$t->Person->LastName;
   $out['t_status'] = (string)$t->Person->Status;
   $out['t_gender'] = ((string)$t->Person->Gender == 'Male')?'M':'F';
   $out['t_address'] = (string)$t->Person->HomeAddress;
   $out['t_country'] = (string)$t->Person->HomeCountry->CountryKey->IsoCode;
   $out['t_res_phone'] = $out['t_mob_phone'] = $out['t_off_phone'] = '';
   if ( isset($t->Person->Contacts) )
   foreach( $t->Person->Contacts->Contact as $c)
   {
	switch ( $c->ContactMethod )
	{
	   case 'Phone':
	     if ((string)$c->ContactType == 'Home') $out['t_res_phone'] .= $c->ContactInformation;
	     if ( (string)$c->ContactType == 'Mobile') $out['t_mob_phone'] .= $c->ContactInformation;
	     if ( (string)$c->ContactType == 'Work') $out['t_off_phone'] .= $c->ContactInformation;
	   break;
	   case 'Email':
	     $out['t_email'] = (string)$c->ContactInformation;
	   break;
	}
   }

   if ( isset($t->Person->ServiceRoles) )
   {
      foreach( $t->Person->ServiceRoles->ServiceRole as $s)
      {
	 if (!isset( $s->EndDate1 ))
	 {
	    $temp = explode("-", (string)$s->StartDate1);
	    $out['t_year_appointed'] = $temp[0];
	    $out['t_current_status'] = (string)$s->ServiceRoleKey->SubRole;
	    $out['t_responsibility'] = (string)$s->Responsibility;
	 }
      }
   }
   $out['t_created_by'] = $out['t_updated_by'] = 1;
   $out['t_updated'] = date('Y-m-d H:i:s');
   $id = my_result("select t_id from dh_teacher where t_unique_code='".$out['t_unique_code']."'");
   if ( $id > 0 )
      exec_query('dh_teacher', $out, " t_id=$id", $debug);
   else
      exec_query('dh_teacher', $out, '', $debug);
  // print_r($out);
}

//print_r( $res );

?>
