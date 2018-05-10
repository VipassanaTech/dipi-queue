<?php

$debug = 0;
if ( $argc <= 2)
{
   echo "Usage: php $argv[0] <dipi-centre-id> <vray-centre-id>\n";
   exit(1);
}

//$course_date = $argv[1];
$centre_id = $argv[1];
$VRAY_CENTRE_ID = $argv[2];

include_once("constants.inc");
include_once("constants-vray.inc");

$q_crs = "SELECT     CourseID, CourseName, CentreID, StartDate, EndDate
FROM         CourseMaster
WHERE     (CentreID = $VRAY_CENTRE_ID) and (StartDate > getdate())";

$confirmed = "select *, replace(convert(varchar, DOB, 111), '/', '-') as 'DOB1', replace(convert(varchar, StartDate, 111), '/', '-') as 'StartDate1', 
replace(convert(varchar, EndDate, 111), '/', '-') as 'EndDate1', replace(convert(varchar, FirstCourseDate, 111), '/', '-') as 'FirstCourseDate1',   replace(convert(varchar, LastCourseDate, 111), '/', '-') as 'LastCourseDate1'
from RegApplicantsMAster
join RegApplicantsCourseDetail on RegApplicantsMAster.RegApplicantID =  RegApplicantsCourseDetail.RegApplicantID left join coursemaster on RegApplicantsCourseDetail.CourseID = coursemaster.courseid 
where coursemaster.centreid =$VRAY_CENTRE_ID and coursemaster.CourseID=";
//and RegApplicantsCourseDetail.ApplicationStatus = 'Confirmed'

db_connect();
$link = mssql_connect($VRAY_SERVER, $VRAY_USER, $VRAY_PASSWD);

if (!$link)
    die('Unable to connect! - '. mssql_error($link));

if (!mssql_select_db($VRAY_DB, $link))
    die('Unable to select database! - '.mssql_error($link));



$q = "select c_code, c_name from dh_country";
$hand = mysql_query($q);
while( $r = mysql_fetch_array($hand))
  $COUNTRY[strtoupper($r['c_name'])] = $r['c_code'];

$q = "select s_code, s_name, s_country from dh_state";
$hand = mysql_query($q);
while( $r = mysql_fetch_array($hand))
  $STATE[strtoupper($r['s_country'].'-'.$r['s_name'])] = $r['s_code'];


$res_crs = mssql_query($q_crs) or die("Could not exec sql - ".mssql_get_last_message());

while( $r_course = mssql_fetch_array( $res_crs ) )
{
   $result = mssql_query($confirmed.$r_course['CourseID']) or die("COuld not exec sql - ".mssql_get_last_message());
   echo $r_course['CourseName']."\n";
//   continue;
   $count = 0;
   $CONF['NF'] = $CONF['OF'] = $CONF['NM'] = $CONF['OM'] = 0;
//   unset($ins); unset($ins_ae); unset($ins_c);
   while($row = mssql_fetch_array($result))
   {
      unset($ins); unset($ins_ae); unset($ins_c);
      $course = my_result("select c_id from dh_course where c_center='$centre_id' and  c_start='".$row['StartDate1']."' and c_end='".$row['EndDate1']."' and c_deleted=0");

      if ( $course == 0 )
      {
	  $i_c['c_course_type'] = $MAP_COURSE[$row['CourseTypeID']];
  	  $i_c['c_center'] = $centre_id; // Dhamma Vipula
	  $i_c['c_start'] = $row['StartDate1'];
	  $i_c['c_end'] = $row['EndDate1'];
	  $i_c['c_status'] = 'Open';
	  $i_c['c_processed'] = 1;
	  $course = exec_query('dh_course', $i_c, '', $debug);
       }

   //print_r($row);
      foreach( $MAP as $key => $val )
      {
  	  $ins[$val] = $row[$key];
	  if ( $key == 'DOB1')
 	  {
	     $temp = explode("-", $row[$key]);
	     $dob = $temp[0]."-".$temp[2]."-".$temp[1];
	     $ins[$val] = $dob;
	  }
      }
      $ins['a_center'] = $centre_id; // Dhamma Vipula
      $ins['a_type'] = 'Student';
      $ins['a_course'] = $course;
      $ins['a_city'] = get_city($row['CityName']);
      $ins['a_country'] = $COUNTRY[strtoupper($row['CountryName'])];
      $ins['a_state'] = $STATE[$ins['a_country'].'-'.$row['StateName']];
      $ins['a_langs'] = $row['MotherTongue'].', '.$row['OtherLanguagesKnown'];
      if ( $row['ConfirmationNumber'] > 0 )
         $ins['a_conf_no'] = $row['ConfirmationNumberPrefix'].$row['ConfirmationNumber'];
      else
         $ins['a_conf_no'] = '';
      $ins['a_problem_physical'] = $ins['a_problem_mental'] = $ins['a_addiction_current'] = $ins['a_other_technique'] = $ins['a_teach_others'] = $ins['a_medication'] = 0;

      if ( $row['ConfirmationNumber'] > $CONF[$row['ConfirmationNumberPrefix']])
         $CONF[$row['ConfirmationNumberPrefix']] = $row['ConfirmationNumber'];

      if ( trim($row['HealthProblemPhysical']) <> '' )
  	$ins['a_problem_physical'] = 1;
      if ( trim($row['HealthProblemMental']) <> '' )
	$ins['a_problem_mental'] = 1;
      if ( trim($row['Intoxicants']) <> '' )
	$ins['a_addiction_current'] = 1;
      if ( trim($row['OtherKnownTechniques']) <> '' )
	$ins['a_other_technique'] = 1;
      if ( trim($row['TeachOrPracticeOthTechniques']) <> '' )
	$ins['a_teach_others'] = 1;
      if ( trim($row['Medication']) <> '' )
	$ins['a_medication'] = 1;

      $ins['a_status'] = $row['ApplicationStatus'];  //'Confirmed';
      $ins['a_created_by'] = 15;
      $ins['a_updated_by'] = 15;
      $ins['a_updated'] = date('Y-m-d H:i:s');

      $app_id = exec_query('dh_applicant', $ins, '', $debug) ;
      if ( $ins['a_problem_physical'] || $ins['a_problem_mental'] || $ins['a_addiction_current'] || $ins['a_other_technique'] || $ins['a_teach_others'] || $ins['a_medication'])
      {
	  $ins_ae['ae_applicant'] = $app_id;
  	  $ins_ae['ae_desc_physical'] = $row['HealthProblemPhysical'];
	  $ins_ae['ae_desc_mental'] = $row['HealthProblemMental'];
	  $ins_ae['ae_desc_addiction_current'] = $row['Intoxicants'];
	  $ins_ae['ae_desc_other_technique'] = $row['OtherKnownTechniques'];
	  $ins_ae['ae_desc_medication'] = $row['Medication'];
	  exec_query('dh_applicant_extra', $ins_ae, '', $debug);
      }

     if ( $ins['a_old'] )
     {
 	  $first = explode("-", $row['FirstCourseDate1']);
 	  $last = explode("-", $row['LastCourseDate1']);
	  $ins_c['ac_applicant'] = $app_id;
	  $ins_c['ac_practice_details'] = $row['MaintainedVipPracticeDetails'];
	  $ins_c['ac_first_year'] = $first[0];
  	  $ins_c['ac_first_month'] = $first[1];
	  $ins_c['ac_first_day'] = $first[2];
	  $ins_c['ac_first_location_str'] = $row['FirstCourseLocation'];
	  $ins_c['ac_first_teacher_str'] = $row['FirstCourseTeacherName'];
	  $ins_c['ac_last_year'] = $last[0];
	  $ins_c['ac_last_month'] = $last[1];
	  $ins_c['ac_last_day'] = $last[2];
	  $ins_c['ac_last_location_str'] = $row['LastCourseLocation'];
	  $ins_c['ac_last_teacher_str'] = $row['LastCourseTeacherName'];
	  exec_query('dh_applicant_course', $ins_c, '', $debug);
     }
     $count++;
  }

  foreach( $CONF as $key => $val )
  {
     $q = "insert into dh_sequence (s_key, s_val ) values ('$course-$key', '".($val + 1)."')";
     mysql_query($q);
   }

   echo "TOtal - $count\n";

}



mssql_free_result( $result );
db_disconnect();


?>
