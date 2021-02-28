<?php

include_once('vendor/autoload.php');
include_once( "constants.inc" );
include_once("map.php");

function get_course_data( $domain, $start, $end )
{
   $q = "select c.c_id, td.td_key from dh_course c left join dh_center cc on (c.c_center = cc.c_id) left join dh_type_detail td on (c.c_course_type=td.td_id)
	 where c.c_start='$start' and c.c_end='$end' and cc.c_subdomain='$domain'";
   $hand = mysql_query( $q ) or logit("get_course_data: Could not get course: ".mysql_error()."\n");
   $id = 0; $type_code = '';

   if ( ! $hand )
	   return array('id' => $id, 'type' => $type_code);
   if ( mysql_num_rows($hand) > 0 )
   {
	   $r = mysql_fetch_array($hand);
		 $id = $r['c_id'];
	   $type_code = $r['td_key'];
   }
   return array('id' => $id, 'type' => $type_code);
}

function get_centre_config( $centre )
{
  $settings = my_result("select cs_course_config from dh_center_setting where cs_center=$centre");

  $ini = array();
  if ($settings <> '')
	$ini = parse_ini_string($settings, true, INI_SCANNER_RAW);
  return $ini;
}

function course_status_check( $center_id, $course_type, $gender, $course_id )
{
	$ini = get_centre_config( $center_id );
	$g = ($gender == 'M')?"Male":"Female";
	$count_full = 0; $count_wait = 0;
	if ( is_array($ini[$course_type]) )
	{
		if ( isset($ini[$course_type]['MaxApps-'.$g]) && (trim($ini[$course_type]['MaxApps-'.$g]) <> '') )
		  $count_full = trim($ini[$course_type]['MaxApps-'.$g]);
		if ( isset($ini[$course_type]['Waitlist-'.$g]) && (trim($ini[$course_type]['Waitlist-'.$g]) <> '') )
		  $count_wait = trim($ini[$course_type]['Waitlist-'.$g]);
	}
	if ( ( $count_full > 0) || ($count_wait > 0))
	{
		$SYSTEM_USER_ID = my_result("select td_val1 from dh_type_detail where td_type='COURSE-APPLICANT' and td_key='COURSE-SYSTEM-UID'");
		$count = my_result("select count(a_id) from dh_applicant where a_center=$center_id and a_course=$course_id and a_gender='$gender'" );
		if ( $count >= $count_full )
		{
			logit("Centre: $center_id, Course Id: $course_id, Gender: $gender, Count: $count, Count Full: $count_full");
			$q = "update dh_course set c_status_o".strtolower($gender)." = 'Course Full', c_status_n".strtolower($gender)." = 'Course Full', 
			  c_processed=0, c_updated='".date("Y-m-d H:i:s")."', c_updated_by='$SYSTEM_USER_ID' where c_id=$course_id";
			mysql_query($q);
		}
		elseif ( $count > $count_wait )
		{
			if ($count_wait > 0 )
			{
				logit("Centre: $center_id, Course Id: $course_id, Gender: $gender, Count: $count, Count Wait: $count_wait");
				$q = "update dh_course set c_status_o".strtolower($gender)." = 'Wait List', c_status_n".strtolower($gender)." = 'Wait List', 
				  c_processed=0, c_updated='".date("Y-m-d H:i:s")."', c_updated_by='$SYSTEM_USER_ID' where c_id=$course_id";
				mysql_query($q);
			}
		}
	}
}

function create_course( $center_id, $course_type_id, $data )
{
   if ( ! is_array($data) )
   {
	  logit("create_course: No Data\n");
	  return 0;
   }
   if ( $center_id == 0)
   {
	  logit("create_course: Center Id is zero\n");
	  return 0;
   }
   if ( $course_type_id == 0 )
   {
	  logit("create_course: Course Type Id is zero\n");
	  return 0;
   }
   $rec = array();
   $rec['c_center'] = $center_id;
   $rec['c_course_type'] = $course_type_id;
   $rec['c_start'] = $data['start_date'];
   $rec['c_end'] = $data['end_date'];
   $rec['c_enrol_date'] = $data['enrol_date'];
   $rec['c_status_om'] = $data['status_om'];
   $rec['c_status_of'] = $data['status_of'];
   $rec['c_status_nm'] = $data['status_nm'];
   $rec['c_status_nf'] = $data['status_nf'];
   $rec['c_status_svr_m'] = $data['status_svr_m'];
   $rec['c_status_svr_f'] = $data['status_svr_f'];
   $rec['c_cancelled'] = $data['cancelled'];
   $rec['c_list_only'] = $data['list_only'];
   $rec['c_updated_by'] = 15;

   $id = exec_query('dh_course', $rec);

   return $id;
}

function is_in_referal_list( $row )
{
	$referal = 0;
	$q = "select r_id from dh_referral r left join dh_student s on (r.r_student=s.s_id) 
		where s_f_name = '".$row['a_f_name']."' and s_l_name='".$row['a_l_name']."' and 
		( s_email='".$row['a_email']."' or s_phone_mobile='".$row['a_phone_mobile']."' ) limit 1";
	$referal = my_result($q);
	return $referal;
}

function process_row( $rec )
{
   global $CRON_USER_ID, $PHOTO_DIR;
   if ( !is_array($rec) )
	 return;
   if ( trim($rec['x_body']) == '' )
	 return;
   $data = process_xml( $rec['x_body'] );

   //print_r($data);
   $data['dh_applicant']['a_xml_id'] = $rec['x_id'];
   //$data['dh_applicant']['a_xml_msg_id'] = $rec['x_msg_id'];
   $data['dh_applicant']['a_updated_by'] = $CRON_USER_ID;
   $data['dh_applicant']['a_updated'] = date("Y-m-d H:i:s");
   $id = 0;
   if ( $rec['x_redelivery'] )
   {
		$q = "select a_id from dh_applicant where a_xml_msg_id='".$rec['x_msg_id']."'";
		$hand = mysql_query( $q ) or logit("process_row: redelivery: Cant query: ", mysql_error()."\n");
		if ( !$hand )
		   return;
		if ( mysql_num_rows($hand) > 0 )
		{
			$r = mysql_fetch_array($hand);
			$id =  $r['a_id'];
		}
   }
   $data['dh_applicant']['a_f_name'] = ucwords(strtolower( $data['dh_applicant']['a_f_name'] ) );
   $data['dh_applicant']['a_l_name'] = ucwords(strtolower( $data['dh_applicant']['a_l_name'] ) );
   if ( $id )
	  update_application( $id, $data);
   else
   {
	  $data['dh_applicant']['a_m_name'] = '';
	  $data['dh_applicant']['a_created_by'] = $CRON_USER_ID;
	  $data['dh_applicant']['a_status'] = '';
	  $id = insert_application( $data );
	  if ( isset($data['dh_applicant']['a_photo'])  )
	  {
	 $filename = $data['dh_applicant']['a_center']."/".$data['dh_applicant']['a_course']."/app-".sprintf("%06d", $id).$data['photo-ext'];
	 $full_path = $PHOTO_DIR."/".$filename;
	 $photo = 'private://photo-id/'.$filename;
	 if ( $id > 0 )
	 {
		$dir = dirname($full_path);
		if ( !is_dir($dir))
		{
		if (posix_getuid() == 0)
		   posix_setuid(33);
		mkdir($dir, 0755, true);
		}
		rename( $data['dh_applicant']['a_photo'] ,$full_path);

		mysql_query("update dh_applicant set a_photo='$photo' where a_id=$id");
	 }
	 ///echo "$filename\n";
	  }
	  $city_id = get_city( $data['dh_applicant']['a_city_str'] );
	  if ( $city_id <> ''  )
	 $data['dh_applicant']['a_city'] = $city_id;
	  else
	  {
	  $q = "select ci.c_id from dh_pin_code p left join dh_city ci on p.pc_city=ci.c_id left join 
		dh_state s on (ci.c_state=s.s_code and ci.c_country=s.s_country) left join dh_country co on ci.c_country=co.c_code where 
		pc_pin='".$data['dh_applicant']['a_zip']."' limit 0,1";
	  $city = my_result($q);
	  if ($city > 0)
		 $data['dh_applicant']['a_city'] = $city;
	  }
	  //echo $data['dh_applicant']['a_photo']."\n";
   }
   if ($id > 0)
   {
	$q = "update dh_xml set x_processed=1 where x_id='".$rec['x_id']."'";
	mysql_query( $q ) or logit("process_row: Cannot set processed to true: ".mysql_error()."\n");
   }
}

function process_xml( $xml )
{
  global $MAP, $PHOTO_DIR;
  $xml = new SimpleXMLElement($xml);
  $appid = $xml->UniqueAppId;
  $ref_code = $xml->ApplicationReferenceCode;
  $subdomain = (string)$xml->Event->EventKey->LocationKey->SubDomain;
  $event_id = (string)$xml->Event->EventKey->EventId;
  $template = (string)$xml->Event->ApplicationTemplateName;
  $data['start_date'] = (string)$xml->Event->StartDate;
  $data['end_date'] = (string)$xml->Event->EndDate;
  $data['enrol_date'] = (string)$xml->Event->EnrollmentOpenDate;
  $course_type = (string)$xml->Event->EventType;
  if ( in_array((string)$xml->Event->Cancelled->Value, array('Yes', 'YES', 'True', 'TRUE') ) )
	 $data['cancelled'] = 1;
  else
	 $data['cancelled'] = 0;
  if ( in_array((string)$xml->Event->ListOnly->Value, array('Yes', 'YES', 'True', 'TRUE') ) )
	 $data['list_only'] = 1;
  else
	 $data['list_only'] = 0;
  $data['status_om'] = (string)$xml->Event->OldMaleStatus;
  $data['status_of'] = (string)$xml->Event->OldFemaleStatus;
  $data['status_nm'] = (string)$xml->Event->NewMaleStatus;
  $data['status_nf'] = (string)$xml->Event->NewFemaleStatus;
  $data['status_svr_m'] = (string)$xml->Event->MaleServerStatus;
  $data['status_svr_f'] = (string)$xml->Event->FemaleServerStatus;

  $center_id = my_result("select c_id from dh_center where c_subdomain='$subdomain'");
  if ( $center_id == 0)
	logit("process_xml: Cant find center $subdomain");
  $course_type_id = my_result("select td_id from dh_type_detail where td_type='COURSE-TYPE'  and  td_key='$course_type'");
  if ( $course_type_id == 0)
	logit("process_xml: Cant find Course Type $course_type");
  $course_id = my_result("select c_id from dh_course where c_center='$center_id' and c_id='$event_id'"); 
  if ( $course_id == 0 )
  {
	  $course_id = my_result("select c_id from dh_course where c_center='$center_id' and c_course_type='$course_type_id' and c_start='".$data['start_date']."' and c_end='".$data['end_date']."' limit 1"); 
	  if ($course_id == 0 )
		$course_id = create_course( $center_id, $course_type_id, $data );
  }
  $ROW['dh_applicant']['a_source'] = 'dhamma.org';
  $ROW['dh_applicant']['a_source_id'] = (string)$appid;
  $ROW['dh_applicant']['a_center'] = $center_id;
  $ROW['dh_applicant']['a_course'] = $course_id;
  $teacher = ''; $id_type = "";

  foreach( $xml->AppItems->AppItem as $item )
  {
		$key = (string)$item->AppItemKey->IntegrationReference;
		if ( array_key_exists($key, $MAP ) )
		{
			$field = $MAP[$key];
			switch( $field['type'] )
			{
			   case 'option':
				$value = $item->AppItemAnswer->OptionValue;
			   case 'optionbool':
				$value = $item->AppItemAnswer->OptionValue;
				break;
			   case 'checkbox':
				$value = $item->AppItemAnswer->CheckboxValue;
				break;				
			   case 'text':
				$value = $item->AppItemAnswer->TextValue;
				break;
			   case 'integer':
				$value = $item->AppItemAnswer->IntegerValue;
				break;
			   case 'date':
				$value = $item->AppItemAnswer->DateValue;
				break;
			   case 'country':
				$value = $item->AppItemAnswer->CountryValue->CountryKey->IsoCode;
				//$value = get_country($value);
				break;
			   case 'state':
				$value = $item->AppItemAnswer->StateProvinceValue->StateProvinceKey->IsoCode;
				//$value = get_state($value);
				break;
			   case 'phone':
				$value = (string)$item->AppItemAnswer->PhoneValue;
				if ( $field['field'] == 'a_phone_mobile' )
				{
					try {
						$mobile_format = \libphonenumber\PhoneNumberUtil::getInstance();
						$m_phone = $mobile_format->parse("+".$value, null, null, true);
						$m_country_code = $m_phone->getCountryCode();
						$ROW[ $field['table'] ]['a_mob_country'] = (string)$m_country_code;
						$num = str_replace( array("+", $m_country_code), "", $value);
						$value = $num;
						
					} catch (Exception $e) {
						logit($value." -> ".$e->getMessage());					
					}
				}
				break;
			   case 'language':
				$value = $item->AppItemAnswer->LanguageValue->LanguageKey->IsoCode;
				$value = get_language($value);
				break;
			   case 'proficiency':
				$value = $item->AppItemAnswer->LanguageProficiencyLevelValue->LanguageProficiencyLevel->LanguageKey->IsoCode;
				$value = get_language($value);
				$pl = $item->AppItemAnswer->LanguageProficiencyLevelValue->LanguageProficiencyLevel->ProficiencyLevel; 
				$ROW[ $field['table'] ][ $field['field'].'_level' ] = (string) $pl;
				break;
			   case 'proficiency_multi':
				$a = 1;
				foreach( $item->AppItemAnswer->LanguageProficiencyLevelValue->LanguageProficiencyLevel as $proficiency )
				{
					if ($a > 3) continue;
				   $value = $proficiency->LanguageKey->IsoCode;
				   $pl = $proficiency->ProficiencyLevel;
				   $ROW[ $field['table'] ][ $field['field'].'_'.$a ] = get_language($value);
				   $ROW[ $field['table'] ][ $field['field'].'_'.$a.'_level' ] = (string) $pl;
				   $a++;
				}
				//$value = $item->AppItemAnswer->LanguageValue->LanguageKey->IsoCode;
				//$value = get_language($value);
				break;
				case 'multi_idtype':
				$value = (string) $item->AppItemAnswer->MultiChoiceValue->Value;
				switch($value)
				{
					case 'Aadhar': $id_type = "a_aadhar";
						break;
					case 'National ID': $id_type = "a_voter_id";
						break;
					case 'Pan Card': $id_type = "a_pancard";
						break;
					case 'Passport': $id_type = "a_passport";
						break;
				}
				break;
				case 'multi_occupation':
				$value = (string) $item->AppItemAnswer->MultiChoiceValue->Value;
				break;
			   case 'teacher':
				$teacher = $item->AppItemAnswer->TeacherValue->TeacherKey->DhammaCode;
				break;
			   case 'eventtype':
				$value = $item->AppItemAnswer->EventTypeValue;
				break;
			   case 'image':
				$c_type = (string)$item->AppItemAnswer->ImageValue->ContentType;
				$img_txt = (string)$item->AppItemAnswer->ImageValue->Value;
				$img_data = base64_decode($img_txt);
				$ext  = "";
				if ( strstr($c_type, "jpeg") )
					$ext = ".jpg";
				elseif ( strstr($c_type, "png") )
					$ext = ".png";
				//$file = "$PHOTO_DIR/$centre_id/$course_id/".$ext;
				$tmp_file = tempnam("/tmp", "PHOTO");
				$ROW['photo-ext'] = $ext;
				$value = $tmp_file;
				$handle = fopen($tmp_file, "w");
				fwrite($handle, $img_data);
				fclose($handle);
				chown($tmp_file, "www-data");
				break;
			   case 'notes':
				$value = $item->AppItemAnswer->NotesValue;
				foreach( $item->AppItemAnswer->NotesValue->Note as $note )
				{
					$comment = (string)$note->Comment;
					if ( $comment == 'System note:Recommending AT Review' )
					{
					$ROW[ $field['table'] ]['al_recommending'] = (string)$note->To;
					}
					elseif( in_array($comment, array('System note:Area Teacher Review', 'System note:Approved')) )
					{
						$ROW[ $field['table'] ]['al_area_at'] = (string)$note->To;
						if ( $ROW[ $field['table'] ]['al_area_at'] == '' )
					        $ROW[ $field['table'] ]['al_area_at'] = (string)$note->From;
					}
				}
				break;
			   default:
				$value = $item->AppItemAnswer->StringValue;
				break;
			} // end of switch

			if ( $field['type'] == 'optionbool')
			{
			   $temp = (string) $value;
			   if ( in_array($temp, array('YES', 'Yes', 'TRUE', 'True')) )
				$temp_val = 1;
			   else
				$temp_val = 0;
			   //echo "$key => $temp_val => $value\n";
			   $ROW[ $field['table'] ][ $field['field'] ] = $temp_val?1:0;
			   /*if ( $key == 'QuestionQualifyOldStudent' )
			   {
					if (!$temp_val)
					  $ROW[ $field['table'] ][ 'a_type' ] = 'Student';
			   }*/
			}
			else
			{
			   if ( $field['field'] == 'a_type' )
			   {
					if ( (string) $value == 'Sit' )
					  $value = 'Student';
					else
					  $value = 'Sevak';
			   }
			   elseif ( in_array($field['field'], array('ac_first_year', 'ac_last_year') ))
			   {
					  $temp = explode("/", (string) $value );
				  if ( count($temp) > 1 )
					$ROW[ $field['table'] ][ str_replace('year', 'month', $field['field'] ) ] = $temp[1];
			   }
			   elseif( in_array($key, array('QuestionArrivalDateTime', 'QuestionDepatureDateTime') ) )
			   {
			   		$temp = (string) $value;
			   		if ( $temp <> '')
			   		{
			   			$seva_part_time[str_replace("Question", "", $key)] = $value; 
			   		}
			   }
			   elseif ($key == 'QuestionProfileNationalId.India' ) 
			   {
			   		if ( $id_type <> '' )
			   			$field['field'] = $id_type;
			   }
			   elseif ($key == 'QuestionProfileOccupationOther' ) 
			   {
			   		$temp = (string) $value;
			   		if (trim($value) <> '')
			   			$field['field'] = 'a_occupation';
			   }

			   if ( !in_array( $field['type'], array('notes', 'proficiency_multi', 'teacher', 'multi_idtype') ) )
			   		if ( isset($field['table']) && isset($field['field']) && ($field['field'] <> '') )
						$ROW[ $field['table'] ][ $field['field'] ] = (string) $value;


			}
		}
  } // end of foreach
  if (! isset($seva_part_time) )
  		$seva_part_time = array();

  if (!empty($seva_part_time))
  		$ROW['dh_applicant']['a_extra'] .= "\nPartTime Seva: Arrival: ".$seva_part_time['ArrivalDateTime'].", Departure: ".$seva_part_time['DepatureDateTime'];
  if ($template == 'AT Workshop')
  {

	  $ROW['dh_applicant']['a_type'] = 'Student';
	  if (!isset($ROW['dh_applicant']['a_gender'])) $ROW['dh_applicant']['a_gender'] = 'M';
	  $hand = mysql_query("select * from dh_teacher where t_code='$teacher' and t_gender='".$ROW['dh_applicant']['a_gender']."'");
	  if ( mysql_num_rows($hand) > 0 )
	  {
		 $r = mysql_fetch_array($hand);
		 $ROW['dh_applicant']['a_f_name'] = $r['t_f_name'];
		 $ROW['dh_applicant']['a_m_name'] = $r['t_m_name'];
		 $ROW['dh_applicant']['a_l_name'] = $r['t_l_name'];
		 $ROW['dh_applicant']['a_country'] = $r['t_country'];
		 $ROW['dh_applicant']['a_state'] = $r['t_state'];
		 $ROW['dh_applicant']['a_city'] = $r['t_city'];
		 $ROW['dh_applicant']['a_address'] = $r['t_address'];
		 $ROW['dh_applicant']['a_zip'] = $r['t_pincode'];
		 $ROW['dh_applicant']['a_phone_home'] = $r['t_res_phone'];
		 $ROW['dh_applicant']['a_phone_mobile'] = $r['t_mob_phone'];
		 $ROW['dh_applicant']['a_phone_office'] = $r['t_off_phone'];
		 $ROW['dh_applicant']['a_email'] = $r['t_email'];
	  }
	  //$ROW['dh_applicant']['']
  }
  $referal = is_in_referal_list($ROW['dh_applicant']);
  $ROW['dh_applicant']['a_referral'] = $referal?$referal:"0";

  course_status_check( $center_id, $course_type, $ROW['dh_applicant']['a_gender'], $course_id );
  return $ROW;
}


function update_application ( $appid, $data )
{
//   print_r($data);
   print "Update App\n";
   $debug = 0;
//   $applicant_id = my_result("select a_id from dh_applicant where a_source_id='$appid'");
   exec_query('dh_applicant', $data['dh_applicant'], "a_id='$appid'", $debug);
   exec_query('dh_applicant_extra', $data['dh_applicant_extra'], "ae_applicant = '$appid'", $debug);
   if (isset($data['dh_applicant_course']) && is_array($data['dh_applicant_course']))
	  exec_query('dh_applicant_course', $data['dh_applicant_course'], "ac_applicant = '$appid'", $debug);
}

function insert_application( $data )
{
//   print_r($data);
   global $APP_ROOT;
   print "Insert App\n";
   $debug = 0;
   $applicant_id = exec_query('dh_applicant', $data['dh_applicant'], '', $debug);
   if ( isset($data['dh_applicant_extra']) )
   {
	  $data['dh_applicant_extra']['ae_applicant'] = $applicant_id;
	  exec_query('dh_applicant_extra', $data['dh_applicant_extra'], '', $debug);
   }
   if ( isset($data['dh_applicant_course']) )
   {
	  $data['dh_applicant_course']['ac_applicant'] = $applicant_id;
	  exec_query('dh_applicant_course', $data['dh_applicant_course'], '', $debug);
   }
   if ( isset($data['dh_applicant_lc']) )
   {
	  $data['dh_applicant_lc']['al_applicant'] = $applicant_id;
	  $data['dh_applicant_lc']['al_recommending_approved'] = 'Approved';
	  $data['dh_applicant_lc']['al_area_at_approved'] = 'Approved';
	  exec_query('dh_applicant_lc', $data['dh_applicant_lc'], '', $debug);
   }
   $old = getcwd();
   chdir($APP_ROOT);
   $cmd = "/usr/bin/php status-trigger.php $applicant_id 'Received'";
   exec($cmd);
   $update_waitlist = my_result("select cs_apps_waitlist from dh_center_setting where cs_center=".$data['dh_applicant']['a_center']);
   if ($update_waitlist)
   {
	 $old = $data['dh_applicant']['a_old']?"o":"n";
	 $course_status = my_result("select c_status_".$old.strtolower($data['dh_applicant']['a_gender'])." from dh_course where c_id=".$data['dh_applicant']['a_course']);
	 if ( in_array($course_status, array('Wait List', 'Course Full')) && ( strtolower($data['dh_applicant']['a_type']) <> 'sevak') )
	 {
	   $cmd = "/usr/bin/php status-trigger.php $applicant_id 'WaitList'";
	   exec($cmd);    
	 }    
   }

   chdir($old);
   return $applicant_id;
}


if ( !db_connect() )
  exit(1);

mysql_query("SET NAMES UTF8");
if ( $argc <= 1 )
   $q = "select * from dh_xml where x_processed=0 limit $BATCH_SIZE";
else
   $q = "select * from dh_xml where x_id='".$argv[1]."'";
$res = mysql_query( $q );
if ( !$res )
{
   logit("process-apps: Cannot read dh_xml: ".mysql_error()."\n");
   exit(1);
}

while ( $row = mysql_fetch_array($res) )
{
   echo "Processing ".$row['x_id']."\n";
   process_row( $row );
}

db_disconnect();

?>
