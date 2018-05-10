<?php

if ( $argc < 2 )
{
   echo "Usage: php ${argv[0]} <course_id> <location_id>\n";
   exit(1);
}

function do_request($url, $headers, $post =array())
{
   $ch = curl_init();
   curl_setopt($ch, CURLOPT_URL,$url);
   if ( count($post) > 0 )
   {
      curl_setopt($ch, CURLOPT_POST, 1);
      curl_setopt($ch, CURLOPT_POSTFIELDS,$post);  //Post Fields
	echo http_build_query($post);
   }
   curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
   curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
   curl_setopt($ch, CURLOPT_HEADER, 1);
   //$out1 = curl_getinfo($ch, CURLINFO_HEADER_OUT);
//   curl_setopt($ch, CURLOPT_VERBOSE, 1);
   $out = trim(curl_exec ($ch));
   curl_close ($ch);
   print $out;
   return $out;
}

$course_id = $argv[1];
$location = $argv[2];

//$cookie_start = "Cookie: __utmc=155337710; __utmz=155337710.1519541173.1.1.utmcsr=(direct)|utmccn=(direct)|utmcmd=(none); cookie_test=".date("Y-m-d H:i:s O")."; _session_id=68783bc9947d2a272b59046120d847cc; __utma=155337710.1106563869.1519541173.1519541173.1519545028.2; __utmt=1; __utmb=155337710.";
//$cookie_start = "Cookie: __utmz=155337710.1519541173.1.1.utmcsr=(direct)|utmccn=(direct)|utmcmd=(none); __utma=155337710.1106563869.1519541173.1519617211.1519630204.6; __utmc=155337710; __utmt=1; cookie_test=2018-02-26+07%3A30%3A16+%2B0000; _session_id=586f8db374fd6421c0494c2a528508a9; __utmb=155337710.";
//$cookie_end = ".10.1519630204";

$headers = [
        'Connection: keep-alive',
        'Upgrade-Insecure-Requests: 1',
        'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_13_3) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/66.0.3354.0 Safari/537.36',
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8', /***/
	'Accept-Encoding: deflate, br',
	'Accept-Language: en-US,en;q=0.9',
];


//$first_url = "https://staging.dhamma.org/en/schedules/schgiri";
$first_url = "https://staging.dhamma.org/en/portal/student_apps/new_app?course_id=$course_id&location_id=$location";
$cookie = $cookie_start.'5'.$cookie_end;
$h_with_cookie = $headers;
//$h_with_cookie[] = $cookie;

$out = do_request($first_url, $h_with_cookie);
preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $out, $matches);
$cookies = array();
foreach($matches[1] as $item) {
    parse_str($item, $cookie);
    $cookies = array_merge($cookies, $cookie);
}
//var_dump($cookies);
$cookie = 'Cookie: ';
foreach( $cookies as $k => $v )
  $cookie .= "$k=".urlencode($v)."; "; 

$h_with_cookie[] = $cookie;
$out = do_request($first_url, $h_with_cookie);

preg_match("|student_apps/([0-9]+)/pages/new|", $out, $matches);

$id = $matches[1];

echo "Id is $id\nCookie is $cookie";

$url = "https://staging.dhamma.org/en/portal/student_apps/$id/pages/new";
//do_request($url, $h_with_cookie);
//exit(1);
//$common_header .= " -H 'Cache-Control: max-age=0' -H 'Origin: https://staging.dhamma.org'";
$headers[] = 'Cache-Control: max-age=0';
$headers[] = 'Origin: https://staging.dhamma.org';

$stages = array();
$stages[] = array('utmb' => 6,'ref' => 1, 'b' => "Content-Type: multipart/form-data; boundary=----WebKitFormBoundary4NzPiJdKJv889N6n");
/*$stages[] = array('utmb' => 5,'ref' => 3, 'b' => "Content-Type: multipart/form-data; boundary=----WebKitFormBoundaryBfXtLLGaLMsutje0");
$stages[] = array('utmb' => 6,'ref' => 4, 'b' => "Content-Type: multipart/form-data; boundary=----WebKitFormBoundaryRvMkCqY398YzU3Dn");
$stages[] = array('utmb' => 7,'ref' => 5, 'b' => "Content-Type: multipart/form-data; boundary=----WebKitFormBoundarytY4vcHUVR6LvnKjX");
$stages[] = array('utmb' => 8,'ref' => 6, 'b' => "Content-Type: multipart/form-data; boundary=----WebKitFormBoundaryFjMiaCaYGz9tq7P4");
$stages[] = array('utmb' => 9,'ref' => 7, 'b' => "Content-Type: multipart/form-data; boundary=----WebKitFormBoundary5WAyAPiIJRBW1gqB");
*/

$common_data = array("utf8" => "\u2713", "_method" => "put", "authenticity_token" => "mX8PfbfLBN0pyjFjcy5wG1fHPt0DeSawIojMsv+IS5U=");

$data[] = array("items[item_5]" => "Yes", "items[item_8]" => "Sit", "items[item_1]" => "M", "items[item_60]" => "IN", "next" => "Next");
$data[] = array("next" => "Next" );
$data[] = array("items[item_2]" => "John", "items[item_3]" => "Doe", "items[item_106]" => "20", "items[item_107][day]" => "2", "items[item_107][month]" => "5", 
	"items[item_107][year]" => "1998", "items[item_10]" => "JJKKM", "items[item_11]" => "KKKAM", "items[item_12]" => "IN.MH", "items[item_13]" => "400029", 
	"items[item_60]" => "IN", "items[item_14]" => "IN", "items[item_15]" => "ta", "items[item_16]" => "ENG,HIN", "items[item_247]" => "lala@vinay.in", 
	"items[item_61]" => "022 61122344", "items[item_63]" => "9928888888" ,"items[item_62]" => "NA", "items[item_64]" => "NA", "items[item_269]" => "BE", 
	"items[item_286]" => "Business", "items[item_270]" => "Infosys", "items[item_271]" => "IT", "items[item_272]" => "Manager", "next" => "Next" );
$data[] = array("items[item_75]" => "2008/12", "items[item_76]" => "IGATPURI", "items[item_77]" => "TEACHER1", "items[item_79]" => "2017/03",
	"items[item_80]" => "PATTANA", "items[item_81]" => "TEACHER2", "items[item_82]" => "8", "items[item_83]" => "2", "items[item_84]" => "1 STP", 
	"items[item_111]" => "2 1 days", "items[item_87]" => "Yes", "items[item_88]" => "REIKI", "items[item_238]" => "Yes", "items[item_113]" => "TEACH MANY",
	"items[item_85]" => "Yes", "items[item_239]" => "HARDLY 5 mins", "next" => "Next");
$data[] = array("items[item_140]" => "", "items[item_94]" => "Yes", "items[item_95]" => "BIG PROBLEM", "items[item_96]" => "Yes", "items[item_97]" => "DEPRESSION",
	"items[item_98]" => "Yes", "items[item_99]" => "MANY DRUGS", "items[item_100]" => "Yes", "items[item_101]" => "HUGE MEDICATION", "items[item_243]" => "false",
	"items[item_241]" => "false", "items[item_241]" => "true", "items[item_67]" => "Yes", "items[item_250]" => "SOME RELATION", "items[item_4]" => "", 
	"items[item_93]" => "8 Days", "items[item_102]" => "NOTHING", "items[item_114]" => "true", "items[item_104]" => "John Doe", 
	"items[item_105][day]" => date("j"), "items[item_105][month]" => date("n"), "items[item_105][year]" => date("Y"), "accept" => "I Agree");
$data[] = array("items[item_2]" => "John", "items[item_3]" => "Doe", "items[item_10]" => "JJKKM", "items[item_11]" => "KKKAM", "items[item_12]" => "IN.MH", "items[item_13]" => "400029",
	 "items[item_60]" => "IN", "items[item_247]" => "lala@vinay.in", "items[item_248]" => "lala@vinay.in", "items[item_61]" => "022 61122344",
	 "items[item_63]" => "9928888888", "confirm" => "Confirm and Submit");


foreach( $stages as $k => $v )
{
   $h = $headers;
//   $cookie = $cookie_start.$v['utmb'].$cookie_end;
   $h[] = $cookie;
   $h[] = "Referer: https://staging.dhamma.org/en/portal/student_apps/$id/pages/".$v['ref']."/edit";
   $h[] = 'Content-Type: multipart/form-data';
//   $h[] = 'Content-Type: application/x-www-form-urlencoded';
//   $h[] = $v['b'];
   $url = "https://staging.dhamma.org/en/portal/student_apps/$id/pages/".$v['ref'];
   $out = do_request($url, $h, array_merge($common_data, $data[$k]) );
   print $out."\n";
}

?>
