<?php

if ( php_sapi_name() !== 'cli' )
  exit( 1 );

// TODO: read --include from cli args, so that clients can include_once the functions to run

$worker = new \Crusse\JobServer\Worker( $argv[ 1 ] );
$worker->run();
