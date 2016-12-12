<?php

if ( php_sapi_name() !== 'cli' )
  exit( 1 );

error_reporting( E_ALL );

require_once dirname( __FILE__ ) .'/Worker.php';

$worker = new \Crusse\JobServer\Worker( $argv[ 1 ] );
$worker->run();

