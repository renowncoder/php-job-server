<?php

require_once __DIR__ .'/../vendor/autoload.php';

function generateRandomString($length = 10) {
	$characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
	$charactersLength = strlen($characters);
	$randomString = '';
	for ($i = 0; $i < $length; $i++) {
		$randomString .= $characters[rand(0, $charactersLength - 1)];
	}
	return $randomString;
}

$timeTotal = microtime( true );

$server = new Crusse\JobServer\Server( 3 );
$server->addWorkerInclude( __DIR__ .'/functions.php' );
$server->setWorkerTimeout( 3 );

for ( $i = 0; $i < 1000; $i++ )
  $server->addJob( 'job_test', 'Job '. $i .': '. generateRandomString( 10000 ) );

$time = microtime( true );
$res = $server->getResults();
$elapsed = ( microtime( true ) - $time ) * 1000;
$elapsedTotal = ( microtime( true ) - $timeTotal ) * 1000;

//var_dump( $res );
echo 'Finished in '. $elapsed .' ms'. PHP_EOL;
echo 'Total '. $elapsedTotal .' ms'. PHP_EOL;

