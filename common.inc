<?php

function db_connect()
{
   global $DB_HOST, $DB_USER, $DB_PASSWD, $DB_NAME;
   $db_conn = mysql_connect($DB_HOST, $DB_USER, $DB_PASSWD) or logit("Unable to connect to db: ".mysql_error()."\n");
   if ( ! $db_conn )
      return false;
   $test = mysql_select_db($DB_NAME ) or logit("Unable to select db: ".mysql_error()."\n");
   if (! $test  )
      return false;
   return true;
}

function db_disconnect()
{
   mysql_close();
}

function get_language( $iso_code )
{
   $hand = mysql_query("select l_name from dh_languages where l_code='$iso_code'") or logit("Could not query language $iso_code\n");
   if (! $hand )
	return '';
   $name = '';
   if ( mysql_num_rows($hand) > 0 )
   {
      $r = mysql_fetch_array($hand);
      $name = $r['l_name'];
   }
   return $name;
}


function get_country( $iso_code )
{
   $hand = mysql_query("select c_id from dh_country where c_code='$iso_code'") or logit("Could not query country $iso_code\n");
   if (! $hand )
	return 0;
   $id = 0;
   if ( mysql_num_rows($hand) > 0 )
   {
      $r = mysql_fetch_array($hand);
      $id = $r['c_id'];
   }
   return $id;
}

function get_state( $iso_code )
{
   $hand = mysql_query("select s_id from dh_state where s_code='$iso_code'") or logit("Could not query state $iso_code\n");
   if (! $hand )
	return 0;
   $id = 0;
   if ( mysql_num_rows($hand) > 0 )
   {
      $r = mysql_fetch_array($hand);
      $id = $r['s_id'];
   }
   return $id;
}

function get_city( $name )
{
   $hand = mysql_query("select c_id from dh_city where c_name='$name'") or logit("Could not query city $name\n");
   if (! $hand )
	return 0;
   $id = 0;
   if ( mysql_num_rows($hand) > 0 )
   {
      $r = mysql_fetch_array($hand);
      $id = $r['c_id'];
   }
   return $id;
}

function my_result( $q )
{
   $hand = mysql_query( $q ) or logit("my_result: Could not exec query: ".mysql_error()."\n");
   if ( !$hand )
      return 0;
   if ( mysql_num_rows( $hand ) <= 0  )
      return 0;
   $r = mysql_fetch_array( $hand );
   return $r[0];
}


function exec_query($table, $data, $where = '', $debug = 0) 
{
   $count = 0;
   $fields = '';
   if ( !is_array($data) )
	return;
   foreach($data as $col => $val) 
   {
      if ($count++ != 0) $fields .= ', ';
      $col = mysql_real_escape_string($col);
      $val = mysql_real_escape_string($val);
      $fields .= "`$col` = '$val'";
   }
   if ( $where == '')
      $query = "INSERT INTO `$table` SET $fields";
   else
      $query = "Update `$table` SET $fields where $where";
   if ( !$debug )
       mysql_query( $query ) or logit("exec_query: ".mysql_error()." - $query\n");
   else
       print $query."\n";
   $last_id = 0;
   if ( $where == '' )
      $last_id = mysql_insert_id();
    return $last_id;
}

function logit($data)
{
   global $LOG;
   $fp = fopen( $LOG , "a+");
   fwrite($fp, "[".date("Y-m-d H:i:s")."] ".$data);
   fclose($fp);
}


?>
