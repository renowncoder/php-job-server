<?php

namespace Crusse\JobServer;

if ( php_sapi_name() !== 'cli' )
  exit( 1 );

// TODO: read --include and --function from cli args

$worker = new Worker( $argv[ 1 ] );
$worker->run();
