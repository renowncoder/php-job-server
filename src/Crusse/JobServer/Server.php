<?php

/*


Blocking i/o (perfectly synchronized):

worker1: 2ms to finish
worker2: 2ms to finish
worker3: 2ms to finish

server reads result: 0.5ms (r)
server writes new job: 0.5ms (w)

time:     0     1     2     3     4     5     6     7
worker1:  rrrwww            rrrwww
worker2:        rrrwww            rrrwww
worker3:              rrrwww            rrrwww

-----------------------------------------------------------

Blocking i/o (not synchronized):

worker1: 3ms to finish
worker2: 2ms to finish
worker3: 1ms to finish

server reads result: 0.5ms (r)
server writes new job: 0.5ms (w)
cpu time available for extra non-i/o work: (+)
waiting for i/o: (.)

time:     0     1     2     3     4     5     6     7
worker1:  rrrwww                  rrrwww
worker2:  ......rrrwww            ......rrrwww
worker3:  ............rrrwww      ............rrrwww
server:                     ......

-----------------------------------------------------------

Non-blocking i/o (perfectly synchronized):

worker1: 2ms to finish
worker2: 2ms to finish
worker3: 2ms to finish

server reads result: 0.5ms (r)
server writes new job: 0.5ms (w)
cpu time available for extra non-i/o work: (+)
waiting for i/o: (.)

time:     0     1     2     3     4     5     6     7
worker1:  r++r++r++w++w++w            r++r++r++w++w++w
worker2:   r++r++r++w++w++w            r++r++r++w++w++w
worker3:    r++r++r++w++w++w            r++r++r++w++w++w
server:                     ++++++++++

-----------------------------------------------------------

Non-blocking i/o (not synchronized):

worker1: 3ms to finish
worker2: 2ms to finish
worker3: 1ms to finish

server reads result: 0.5ms (r)
server writes new job: 0.5ms (w)
cpu time available for extra non-i/o work: (+)
waiting for i/o: (.)

time:     0     1     2     3     4     5     6     7
worker1:  r++r++r++w++w++w                  r+r+rwww
worker2:   r++r++r++w++w++w            r+rrw+w+w
worker3:    r++r++r++w++w++w      rrrww+w
server:                     ++++++


*/

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
  private $workerIncludes = array();
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

    $this->closeConnection( $this->serverSocket );

    if ( $this->serverSocketPath )
      unlink( $this->serverSocketPath );

    foreach ( $this->workerProcs as $proc ) {
      // Kill any stuck processes. They should already have all finished after
      // the socket was closed, so normally this should not do anything.
      $proc->stop( 0, SIGTERM );
    }
  }

  function addJob( $function, $message ) {

    $job = new \SplFixedArray( 2 );
    $job[ 0 ] = $function;
    $job[ 1 ] = $message;

    $this->jobQueue[] = $job;
  }

  function addWorkerInclude( $phpFilePath ) {
    $this->workerIncludes[] = $phpFilePath;
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
      // We use 'nice' to make the worker process slightly lower priority than
      // regular PHP processes that are run by the web server, so that the
      // worker's don't bring down the web server so easily
      $process = new Process( 'exec nice -n 5 php '. dirname( __FILE__ ) .'/worker_process.php \''.
        $this->serverSocketPath .'\'' );
      // We don't need stdout/stderr as we're communicating via sockets
      $process->disableOutput();
      $process->start();
      $workers[] = $process;
    }

    $this->workerProcs = $workers;
  }

  private function handleWorkerRequests() {

  }

  private function eventLoopTickCallback( $event ) {
    
    if ( count( $this->results ) >= count( $this->jobQueue ) )
      return null;

    return true;
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

    if ( $headers[ 'cmd' ] === 'new-worker' || $headers[ 'cmd' ] === 'job-result' ) {
      $includes = ( $headers[ 'cmd' ] === 'new-worker' )
        ? $this->workerIncludes
        : null;
      $this->sendJobToWorker( $client, $includes );
    }

    $this->closeConnection( $client );
  }

  private function sendJobToWorker( $client, array $includes = null ) {

    if ( $this->sentJobCount >= count( $this->jobQueue ) )
      return;

    $request = new SocketWriter( $client );
    $request->setTimeout( self::SOCKET_TIMEOUT_SEND );
    $request->addHeader( 'job-num', $this->sentJobCount );
    if ( $includes )
      $request->addHeader( 'includes', implode( ',', $includes ) );
    $job = $this->jobQueue[ $this->sentJobCount ];
    $request->addHeader( 'function', $job[ 0 ] );
    $request->setBody( $job[ 1 ] );
    $request->write();

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

