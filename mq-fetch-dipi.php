<?php

require_once __DIR__ . '/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPSSLConnection;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

include_once('constants.inc');


function mq_connect()
{
   global $MQ_HOST, $MQ_PORT, $MQ_USER, $MQ_PASSWD, $MQ_QUEUE, $MQ_VHOST;
   $ssl_options = array(
  	'cafile' => '/etc/ssl/certs/ca-certificates.crt', // my downloaded cert file
	'verify_peer' => false,
	'verify_peer_name' => false,
   );
   $conn = new AMQPSSLConnection($MQ_HOST, $MQ_PORT, $MQ_USER, $MQ_PASSWD, $MQ_VHOST, $ssl_options); //$ssl_options

   return $conn;
}

$mq_callback = function($msg)
{
    logit( "Received ". $msg->delivery_info['delivery_tag']."\n" );

    $result = dump_xml( $msg );
    if ( $result['status'] )
    {
        $msg->delivery_info['channel']->basic_ack($msg->delivery_info['delivery_tag']);
	$cmd = "/usr/bin/php process-applications.php ".$result['id'];
	exec($cmd);
    }
    //process message here
    //send ack for the message back to the broker
};

function mq_get( $sock )
{
   global $MQ_QUEUE, $mq_callback;
   $channel = $sock->channel();
   $res = $channel->queue_declare($MQ_QUEUE, false, true, false, false);
   $channel->basic_consume($MQ_QUEUE, '', false, false, false, false, $mq_callback);

    while (count($channel->callbacks)) 
    {
        $channel->wait();
    }

   $count = 0;
}


function dump_xml( $msg )
{
   db_connect();
   $redeliver = $msg->delivery_info['redelivered'];
   $msg_id = $msg->get('message_id');
   mysql_query("SET NAMES UTF8");
   $q = "insert into dh_xml (x_tag, x_body, x_redelivery, x_msg_id) values ('".$msg->delivery_info['delivery_tag']."', '".mysql_real_escape_string($msg->body)."', '$redeliver' ,'$msg_id')";
   $success = mysql_query( $q ) or logit("Could not dump xml: ".mysql_error()."\n");
   $xml_id = mysql_insert_id();
   db_disconnect();
   return array('status' => $success, 'id' => $xml_id);
}

function mq_disconnect( $sock )
{
    if ( !$sock->disconnect() )
    {
        logit("Could not disconnect !\n");
    }
}


$sock = mq_connect();
mq_get( $sock );
mq_disconnect( $sock );

?>
