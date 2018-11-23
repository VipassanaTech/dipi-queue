<?php

if ( $argc <= 1 )
{
   echo "Usage: php ".$argv[0]." < IN | NON-IN > [<out-filename>]\n\n";
   exit(1);
}

$country = $argv[1];
if ( !in_array( $country, array('IN', 'NON-IN') ) )
{
   echo "Parameter should be either 'IN' or 'NON-IN'\n\n";
   exit(1);
}
$out_zip = $argv[2];

if ( $country == 'NON-IN' )
  $operator = '<>';
else
  $operator = '=';


include_once("constants.inc");

$count = 10000;
$batch_count = 2000;

function save_labels( $lang, $batch, $content )
{
    $html = file_get_contents("header.html").$content.file_get_contents("footer.html");
    $pid = posix_getpid();
    $batch = sprintf("%02d", $batch);
    $out = "/tmp/patrika-$pid-$lang-$batch.html";
    file_put_contents($out, $html);
    return $out;
}

db_connect();

$q = "select p_id, CONCAT(p_f_name,' ',p_m_name, ' ', p_l_name) as 'name', p_address, p_subscription_type, date_format(p_end_date, '%d/%m/%Y') as 'exp_date', p_zip, 
   l.l_name, p_language, p_state, 
   co.c_name as 'country', ci.c_name as 'city', s.s_name as 'state', p_vray_id from dh_patrika left join dh_country co on p_country=co.c_code 
   left join dh_state s on (p_country=s_country and p_state=s_code)
   left join dh_city ci on p_city=ci.c_id left join dh_languages l on p_language=l.l_code where p_country $operator 'IN' and p_cancelled=0 and p_mode='PAPER' and p_end_date > CURDATE()
   order by l_name, s.s_name, ci.c_name, p_zip";

$hand = mysql_query($q);
if ( !$hand )
{
    logit("Unable to exec query - ".mysql_error());
    exit(1);
}

$template = file_get_contents("template.html");
$search = array('[patrika_id]', '[p_subscription_type]', '[exp_date]', '[name]', '[addr_1]', '[addr_2]', '[addr_3]', '[city]', '[state]', '[country]', '[pin]', '[counter]' );
$content = ''; $old_lang = ''; $old_state = ''; $count = 0; $batch = 1; $counter = 0; $report = array();
$files = array();
while( $row = mysql_fetch_array($hand))
{
    $replace = array();

    if ( ! isset($report[$row['l_name']][$row['state']][$row['city']]) )
       $report[$row['l_name']][$row['state']][$row['city']] = 0;
    $report[$row['l_name']][$row['state']][$row['city']]++ ;
    $cur_lang = $row['p_language'];
    $cur_state = $row['p_state'];
    if ( ($count > 0) &&  ($old_lang <> $cur_lang)  )
    {
	//echo "$old_lang - $old_state - $count - $old_lang-$batch\n";
	$out = save_labels($old_lang, $batch, $content);
	$files[] = $out;
	$content = '';
	echo "Batches ($old_lang) = $batch, count = $counter\n";
	$counter = 0;
        $count = 0; $batch = 1;
    }

    if ( $count >= $batch_count )
    {
	//echo "$old_lang - $old_state - $count - $old_lang-$batch\n";
	$out = save_labels($old_lang, $batch, $content);
	$files[] = $out;
	$content = '';
	$count = 0;
	$batch++;
    }
    $replace[] = str_pad($row['p_vray_id'], 6, '0', STR_PAD_LEFT); // p_id
    $replace[] = $row['p_subscription_type'];
    $replace[] = $row['exp_date'];
    $replace[] = $row['name'];
    $temp = explode(";", strtoupper($row['p_address']));
    if ( count($temp) > 3 )
    {
	logit("Patrika id ".$row['p_id'].", address more than 3 lines");
    }
    $addr_1 = $temp[0]; $addr_2 =  isset($temp[1])?$temp[1]:''; $addr_3 = isset($temp[2])?$temp[2]:'';
    if ( strlen($addr_1) > 42)	$addr_1 = strtolower($addr_1);
    if ( strlen($addr_2) > 42)	$addr_2 = strtolower($addr_2);
    if ( strlen($addr_2) > 42)	$addr_3 = strtolower($addr_3);

    $replace[] = $addr_1;
    $replace[] = $addr_2;
    $replace[] = $addr_3;

    $replace[] = $row['city'];
    $replace[] = $row['state'];
    $replace[] = $row['country'];
    $replace[] = $row['p_zip'];
    $counter++;
    $replace[] = $counter.'('.$row['p_language'].')';
    $content .= str_replace( $search, $replace, $template );
    $old_lang = $cur_lang;
    $old_state = $cur_state;
    $count++;
}

$out = save_labels( $old_lang, $batch, $content );
$files[] = $out;
$out_files = array();
foreach( $files as $f )
{
   $out_pdf = str_replace(".html", ".pdf", $f);
//   $cmd = "wkhtmltopdf -s A4 --disable-smart-shrinking -B 0 -L 0 -R 0 -T 0 $f $out_pdf";
   $cmd = "wkhtmltopdf --page-width 206mm --page-height 283mm --disable-smart-shrinking -B 0 -L 0 -R 0 -T 0 $f $out_pdf";
   exec($cmd);
   //echo "$out_pdf\n";
   $out_files[] = $out_pdf;
   unlink($f);
}

//$out_pdf = "/tmp/lala.pdf";
//$cmd = "wkhtmltopdf -s A4 --disable-smart-shrinking -B 0 -L 0 -R 0 -T 0 $out $out_pdf";
//exec($cmd);

echo "Batches ($old_lang) = $batch, count = $counter\n";

/* Create Report */
$report_html = '
<html>
<head>
<style type="text/css">
	.container { text-align: center; font-family: "Courier New"; font-size: 13px; width: 28cm; }
	h1 { font-size: 28px; }
	h2 { font-size: 24px; background: #cccccc; padding: 5px 0; }
	h3 { font-size: 20px; background: #ececec; padding: 3px 0; }
	table.center { margin: 0 auto; width: 14cm; padding: 0; }
	tr { padding: 0; margin: 0;}
	td { border: 1px solid #ececec; padding: 0; margin: 0; }
	th { background: #ececec;  padding: 5px; margin: 0; }
	.page-break { page-break-after: always; }
</style>
</head>
<body>
<div class="container">
<h1>Summary Report </h1>'."\n";
$page_start = 1; $page_end = 0;
foreach( $report as $lang => $lang_data )
{
   $report_html .= "<h2> Language - $lang</h2>"."\n\n";
   $page_start = 1;
   foreach( $lang_data as $state => $state_data)
   {
	$report_html .= "<h3>State - $state ($lang)</h3><table class=\"center\"><tr><th>City</th><th>Count</th><th>Page No</th></tr>"."\n\n";
	$state_total = 0; 
	foreach( $state_data as $city => $count )
	{
	   $state_total += $count;
	   $page_end = $page_start + $count -1 ;
	   $report_html .= '<tr><td>'.$city.'</td><td>'.$count.'</td><td>'.$page_start.' - '.$page_end.'</td></tr>'."\n";
	   $page_start = $page_end + 1;
	}
	$report_html .= '<tr><td><b>Total</b></td><td><b>'.$state_total.'</b></td><td>&nbsp;</td></tr>'."\n";
	$report_html .= '</table>'."\n";
   }
   $report_html .= '<div class="page-break"></div>';
}
$report_html .= "</div></body></html>";
$out = "/tmp/report.html";
file_put_contents($out, $report_html);

$cmd = "wkhtmltopdf -s A4 $out /tmp/report.pdf";
exec($cmd);
//print_r($report);
db_disconnect();

$out_files[] = "/tmp/report.html";
$out_files[] = "/tmp/report.pdf";
if ( $out_zip <> '' )
   $zip = $out_zip;
else
   $zip = "/tmp/patrika-".date("Y-m-d-H-i").".zip";
$cmd = "zip $zip ".implode(" ",$out_files);
exec($cmd);

foreach( $out_files as $f ) unlink( $f );
echo "Output : $zip\n";

?>
