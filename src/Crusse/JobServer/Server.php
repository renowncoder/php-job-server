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

-----------------------------------------------------------


Blocking i/o:
-------------

Server:

  results = []
  stream_socket_server
  while ( unfinished jobs )
    stream_socket_accept
    while ( got data )
      stream_socket_recvfrom
    results[] = data
    stream_socket_sendto
    fclose

Worker:

  stream_socket_client
  stream_socket_sendto [register worker with server]
  stream_socket_recvfrom [get first job from server]
  while ( got job )
    stream_socket_sendto [send result]
    stream_socket_recvfrom [get another job]


Non-blocking i/o:
-----------------

Server:

  clients = []
  writeBufferPerClient = []
  readBufferPerClient = []
  stream_socket_server
  stream_set_blocking( server socket not blocking )
  while ( unfinished jobs )
    stream_select( readable: clients + server, writable: clients )
    if ( server is readable )
      clients[] = stream_socket_accept
      stream_set_blocking( client socket not blocking )
    foreach ( clients[] )
      if ( is readable )
        while ( got data, which means client socket is open )
          stream_socket_recvfrom
          readBufferPerClient[] .= data
        if ( no received data )
          results[] = Request(readBuffer)->getBody/Headers()
      if ( is writable )
        while ( didn't write all bytes of writeBufferPerClient[current] yet )
          stream_socket_sendto
        if ( writeBufferPerClient[current] is empty )
          fclose
          unset( clients[current] )

Worker:

  initialized = false
  jobQueue = []
  resultsQueue = []
  readBuffer = ''
  writeBuffer = ''
  stream_socket_client
  stream_set_blocking( client socket not blocking )
  while ( true )
    stream_select( readable: server, writable: server )
    if ( server is writable )
      if ( !initialized )
        writeBuffer = I'm a new worker, register me
      else
        writeBuffer = here's a job result resultsQueue.pop(), gimme another job
      while ( didn't write all bytes of writeBuffer yet )
        stream_socket_sendto
      if ( writeBuffer is empty )
        fclose
    if ( server is readable )
      while ( got data, which means client socket is open )
        stream_socket_recvfrom
        readBuffer .= data
      if ( no received data )
        jobQueue[] = Request(readBuffer)->getBody/Headers()


*/

namespace Crusse\JobServer;

use Symfony\Component\Process\Process;

class Server {

  private $serverStreamPath;
  private $serverStream;
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

    if ( $this->serverStreamPath )
      unlink( $this->serverStreamPath );

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

      if ( !$this->serverStream )
        $this->createServerStream();
      if ( !$this->workerProcs )
        $this->createWorkerProcs( $this->workerCount );

      $loop = new EventLoop( false );
      $loop->addServerStream( $this->serverStream, $this->workerTimeout );
      $loop->subscribe( array( $this, '_messageCallback' ) );
      $loop->run();
    }
    catch ( \Exception $e ) {

      // Make sure the socket file is deleted
      if ( $this->serverStreamPath )
        unlink( $this->serverStreamPath );

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

  private function createServerStream() {

    $tmpDir = sys_get_temp_dir();
    if ( !$tmpDir )
      throw new \Exception( 'Could not find the system temporary files directory' );

    $this->serverStreamPath = $tmpDir .'/php_job_server_'. md5( uniqid( true ) ) .'.sock';
    @unlink( $this->serverStreamPath );
    $this->serverStream = stream_socket_server( 'unix://'. $this->serverStreamPath, $errNum, $errStr );

    if ( !$this->serverStream )
      throw new \Exception( 'Could not create a server: ('. $errNum .') '. $errStr );
  }

  private function createWorkerProcs( $count ) {

    $workers = array();

    for ( $i = 0; $i < $count; $i++ ) {
      // We use 'nice' to make the worker process slightly lower priority than
      // regular PHP processes that are run by the web server, so that the
      // worker's don't bring down the web server so easily
      $process = new Process( 'exec nice -n 5 php '. dirname( __FILE__ ) .
        '/worker_process.php \'unix://'. $this->serverStreamPath .'\'' );
      // We don't need stdout/stderr as we're communicating via sockets
      $process->disableOutput();
      $process->start();
      $workers[] = $process;
    }

    $this->workerProcs = $workers;
  }

  function _messageCallback( Message $message, EventLoop $loop, $stream ) {

    $headers = $message->headers;

    if ( !isset( $headers[ 'cmd' ] ) || !strlen( $headers[ 'cmd' ] ) )
      throw new \Exception( 'Missing header "cmd"' );

    if ( $headers[ 'cmd' ] === 'job-result' ) {
      $jobNumber = $headers[ 'job-num' ];
      $this->results[ $jobNumber ] = $message->body;
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

      $loop->send( $stream, $message );
    }
  }
}

