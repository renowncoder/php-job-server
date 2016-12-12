<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
ini_set('error_log', dirname(__FILE__) .'/php_errors.log');
error_reporting(E_ALL);

require_once __DIR__ .'/../vendor/autoload.php';

function generateString($length) {

	static $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
	static $charactersLength = 0;

  if ( !$charactersLength )
    $charactersLength = strlen($characters);

	$ret = '';
	for ($i = 0; $i < $length; $i++) {
		$ret .= $characters[$i % $charactersLength];
	}
	return $ret;
}

$timeTotal = microtime( true );

$server = new Crusse\JobServer\Server( 6 );
$server->addWorkerInclude( __DIR__ .'/functions.php' );
$server->setWorkerTimeout( 2 );
for ( $i = 0; $i < 1000; $i++ )
  $server->addJob( 'job_test', 'Job '. $i .': '. generateString( 100 * 250 ) );

echo 'Results with callback:'. PHP_EOL . PHP_EOL;

$server->getResults( function( $result, $jobNumber, $total ) {
  echo 'Job '. $jobNumber .'/'. $total . PHP_EOL;
} );

echo PHP_EOL .'Ordered results:'. PHP_EOL . PHP_EOL;

$server = new Crusse\JobServer\Server( 6 );
$server->addWorkerInclude( __DIR__ .'/functions.php' );
$server->setWorkerTimeout( 2 );
for ( $i = 0; $i < 1000; $i++ )
  $server->addJob( 'job_test', 'Job '. $i .': '. generateString( 100 * 250 ) );

$time = microtime( true );

$res = $server->getOrderedResults();

$elapsed = ( microtime( true ) - $time ) * 1000;
$elapsedTotal = ( microtime( true ) - $timeTotal ) * 1000;

echo implode( PHP_EOL, array_keys( $res ) ) . PHP_EOL . PHP_EOL;
echo 'Finished in '. $elapsed .' ms'. PHP_EOL;
echo 'Total '. $elapsedTotal .' ms'. PHP_EOL;

