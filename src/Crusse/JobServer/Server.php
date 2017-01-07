<?php

namespace Crusse\JobServer;

use Symfony\Component\Process\Process;

class Server {

  private $serverSocketAddr;
  private $workerCount;
  private $workerProcs = array();
  private $workerIncludes = array();
  private $jobQueue = array();
  private $results = array();
  private $workerTimeout = 60;
  private $sentJobCount = 0;
  private $jobCallback;

  function __construct( $workerCount ) {

    if ( $workerCount < 1 )
      throw new \InvalidArgumentException( '$workerCount must be >= 1' );
    
    $this->workerCount = $workerCount;

    $tmpDir = sys_get_temp_dir();
    if ( !$tmpDir )
      throw new \Exception( 'Could not find the system temporary files directory' );
    $this->serverSocketAddr = $tmpDir .'/php_job_server_'. md5( uniqid( true ) ) .'.sock';
  }

  function __destruct() {

    foreach ( $this->workerProcs as $proc ) {
      // Kill any stuck processes. They should already have all finished after
      // their sockets were closed by EventLoop->stop(), so normally this
      // should not do anything.
      // 
      // On some platforms (OSX) the SIGTERM constant is not defined.
      $proc->stop( 0, ( defined( 'SIGTERM' ) ? SIGTERM : 15 ) );
    }
  }

  function addJob( $function, $message ) {

    $job = new \SplFixedArray( 2 );
    $job[ 0 ] = $function;
    $job[ 1 ] = $message;

    $this->jobQueue[] = $job;
  }

  function addWorkerInclude( $phpFilePath ) {

    if ( $phpFilePath )
      $this->workerIncludes[] = $phpFilePath;
  }

  function getOrderedResults() {

    $this->jobCallback = null;

    $loop = new EventLoop( $this->serverSocketAddr );
    $loop->listen( $this->workerTimeout );
    $loop->subscribe( array( $this, '_messageCallback' ) );

    if ( !$this->workerProcs )
      $this->createWorkerProcs( $this->workerCount );

    $loop->run();

    $results = $this->results;
    ksort( $results );
    $this->results = array();

    return $results;
  }

  function getResults( $jobCallback ) {

    if ( !is_callable( $jobCallback ) )
      throw new \InvalidArgumentException( '$jobCallback is not callable' );

    $this->jobCallback = $jobCallback;

    $loop = new EventLoop( $this->serverSocketAddr );
    $loop->listen( $this->workerTimeout );
    $loop->subscribe( array( $this, '_messageCallback' ) );

    if ( !$this->workerProcs )
      $this->createWorkerProcs( $this->workerCount );

    $loop->run();
  }

  function setWorkerTimeout( $timeout ) {
    $this->workerTimeout = (int) $timeout;
  }

  private function createWorkerProcs( $count ) {

    $workers = array();

    for ( $i = 0; $i < $count; $i++ ) {
      // We use 'nice' to make the worker process slightly lower priority than
      // regular PHP processes that are run by the web server, so that the
      // worker's don't bring down the web server so easily
      $process = new Process( 'exec nice -n 5 php '. dirname( __FILE__ ) .
        '/worker_process.php \''. $this->serverSocketAddr .'\'' );
      // We don't need stdout/stderr as we're communicating via sockets
      $process->disableOutput();
      $process->start();
      $workers[] = $process;
    }

    $this->workerProcs = $workers;
  }

  function _messageCallback( Message $message, EventLoop $loop, $socket ) {

    $headers = $message->headers;

    if ( !isset( $headers[ 'cmd' ] ) || !strlen( $headers[ 'cmd' ] ) )
      throw new \Exception( 'Missing header "cmd"' );

    if ( $headers[ 'cmd' ] === 'job-result' ) {

      $jobNumber = $headers[ 'job-num' ];

      if ( strtolower( $headers[ 'job-status' ] ) != 'ok' )
        throw new \RuntimeException( 'Worker error: ['. $headers[ 'job-status' ] .'] '. $message->body );

      // Only store the result if the client called getOrderedResults()
      $this->results[ $jobNumber ] = ( $this->jobCallback )
        ? true
        : $message->body;
      // ...otherwise return the result immediately in the callback
      if ( $this->jobCallback ) {
        call_user_func( $this->jobCallback, $message->body, count( $this->results ),
          count( $this->jobQueue ) );
      }
    }

    // We have all the results; stop the event loop
    if ( count( $this->results ) >= count( $this->jobQueue ) ) {
      
      $loop->stop();
    }
    // Send a job to the worker
    else if ( $this->sentJobCount < count( $this->jobQueue ) ) {

      $message = new Message();

      if ( $headers[ 'cmd' ] === 'new-worker' )
        $message->headers[ 'includes' ] = implode( ',', $this->workerIncludes );

      $message->headers[ 'job-num' ] = $this->sentJobCount;
      $job = $this->jobQueue[ $this->sentJobCount ];
      $message->headers[ 'function' ] = $job[ 0 ];
      $message->body = $job[ 1 ];

      // Job was sent to worker, free memory
      $this->jobQueue[ $this->sentJobCount ] = '';
      $this->sentJobCount++;

      $loop->send( $socket, $message );
    }
  }
}

