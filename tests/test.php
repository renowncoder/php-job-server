<?php

require_once __DIR__ .'/../vendor/autoload.php';

$timeTotal = microtime( true );

$server = new Crusse\JobServer\Server( 3 );
$server->addWorkerInclude( __DIR__ .'/functions.php' );

for ( $i = 0; $i < 30; $i++ )
  $server->addJob( 'job_test', 'Job '. $i );

$time = microtime( true );
$res = $server->getResults();
$elapsed = ( microtime( true ) - $time ) * 1000;
$elapsedTotal = ( microtime( true ) - $timeTotal ) * 1000;

var_dump( $res );
echo 'Finished in '. $elapsed .' ms'. PHP_EOL;
echo 'Total '. $elapsedTotal .' ms'. PHP_EOL;

