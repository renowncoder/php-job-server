<?php

namespace Crusse\JobServer;

use Symfony\Component\Process\Process;

class Server {

  // Timeouts for sending/receiving to/from the worker client sockets
  const SOCKET_TIMEOUT_RECV = 3;
  const SOCKET_TIMEOUT_SEND = 3;

  private $serverSocketPath;
  private $serverSocket;
  private $workerCount;
  private $workerProcs = array();
  private $jobQueue = array();
  private $results = array();
  private $workerTimeout = 60;
  private $sentJobCount = 0;

  function __construct( $workerCount ) {

    if ( $workerCount < 1 )
      throw new \InvalidArgumentException( '$workerCount must be >= 1' );
    
    $this->workerCount = $workerCount;
  }

  function __destruct() {

    foreach ( $this->workerProcs as $proc ) {
      // Kill any stuck processes. They should already have all finished, so
      // normally this should not do anything.
      $proc->stop( 0, SIGTERM );
    }

    $this->closeConnection( $this->serverSocket );

    if ( $this->serverSocketPath )
      unlink( $this->serverSocketPath );
  }

  function addJob( $message ) {
    $this->jobQueue[] = $message;
  }

  function getResults() {

    try {

      if ( !$this->serverSocket )
        $this->createServerSocket();
      if ( !$this->workerProcs )
        $this->createWorkerProcs( $this->workerCount );

      $this->handleWorkerRequests();
    }
    catch ( \Exception $e ) {

      // Make sure the socket file is deleted
      if ( $this->serverSocketPath )
        unlink( $this->serverSocketPath );

      throw $e;
    }

    $results = $this->results;
    $this->results = array();
    ksort( $results );

    return $results;
  }

  function setWorkerTimeout( $timeout ) {
    $this->workerTimeout = (int) $timeout;
  }

  private function createServerSocket() {

    $tmpDir = sys_get_temp_dir();
    if ( !$tmpDir )
      throw new \Exception( 'Could not find the system temporary files directory' );

    $this->serverSocketPath = $tmpDir .'/php_job_server_'. md5( uniqid( true ) ) .'.sock';
    @unlink( $this->serverSocketPath );
    $this->serverSocket = stream_socket_server( 'unix://'. $this->serverSocketPath, $errNum, $errStr );

    if ( !$this->serverSocket )
      throw new \Exception( 'Could not create a server: ('. $errNum .') '. $errStr );

  }

  private function createWorkerProcs( $count ) {

    $workers = array();

    for ( $i = 0; $i < $count; $i++ ) {
      $process = new Process( 'exec php '. dirname( __FILE__ ) .'/forked_worker.php \''.
        $this->serverSocketPath .'\'' );
      // We don't need stdout/stderr as we're communicating via sockets
      $process->disableOutput();
      $process->start();
      $workers[] = $process;
    }


    $this->workerProcs = $workers;
  }

  private function handleRequest( $client ) {

    $reader = new SocketReader( $client );
    $reader->setTimeout( self::SOCKET_TIMEOUT_RECV );
    $headers = $reader->getHeaders();

    if ( !$headers )
      throw new \Exception( 'Worker unexpectedly closed connection' );

    if ( !isset( $headers[ 'cmd' ] ) || !strlen( $headers[ 'cmd' ] ) )
      throw new \Exception( 'Missing header "cmd"' );

    if ( $headers[ 'cmd' ] === 'job-result' ) {
      $jobNumber = $headers[ 'job-num' ];
      $this->results[ $jobNumber ] = $reader->getBody();
    }

    if ( $headers[ 'cmd' ] === 'new-worker' || $headers[ 'cmd' ] === 'job-result' )
      $this->sendJobToWorker( $client );

    $this->closeConnection( $client );
  }

  private function handleWorkerRequests() {

    while ( true ) {

      $client = stream_socket_accept( $this->serverSocket, $this->workerTimeout, $peerName );

      if ( !$client )
        throw new \Exception( 'Reached worker timeout ('. $this->workerTimeout .')' );

      $this->handleRequest( $client );

      if ( count( $this->results ) >= count( $this->jobQueue ) )
        break;
    }
  }

  private function sendJobToWorker( $client ) {

    if ( $this->sentJobCount >= count( $this->jobQueue ) )
      return;

    $request = new Request( $client );
    $request->setTimeout( self::SOCKET_TIMEOUT_SEND );
    $request->addHeader( 'job-num', $this->sentJobCount );
    $request->setbody( $this->jobQueue[ $this->sentJobCount ] );
    $request->send();

    // Job was sent to worker, free memory
    $this->jobQueue[ $this->sentJobCount ] = '';
    $this->sentJobCount++;
  }

  private function closeConnection( $client ) {

    // Close the connection until the worker client sends us a new result
    stream_socket_shutdown( $client, STREAM_SHUT_RDWR );
    fclose( $client );
  }
}

