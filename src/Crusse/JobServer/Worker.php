<?php

namespace Crusse\JobServer;

// Including these directly, and not via autoloading, as we might not have an
// autoloader in the current context
require_once dirname( __FILE__ ) .'/EventLoop.php';
require_once dirname( __FILE__ ) .'/Message.php';
require_once dirname( __FILE__ ) .'/MessageBuffer.php';

class Worker {

  private $serverSocketAddr;
  // FIXME: see below
  //private $writeBuffer = array();

  function __construct( $serverSocketAddr ) {

    $this->serverSocketAddr = $serverSocketAddr;
  }

  function run() {

    // Try/catch in case the server exits before we have a chance to connect or 
    // write to it
    try {
      $loop = new EventLoop( $this->serverSocketAddr );
      $loop->subscribe( array( $this, '_messageCallback' ) );
      $socket = $loop->connect();
      $this->sendMessage( $loop, $socket, 'new-worker' );
      $loop->run();
    }
    catch ( \Exception $e ) {
      trigger_error( $e->getMessage() );
    }
  }

  function _messageCallback( Message $message, EventLoop $loop, $socket ) {

    $headers = $message->headers;

    if ( isset( $headers[ 'includes' ] ) ) {
      foreach ( array_filter( explode( ',', $headers[ 'includes' ] ) ) as $path )
        require_once $path;
    }

    if ( !isset( $headers[ 'function' ] ) )
      throw new \Exception( 'Request has no \'function\' header' );
    if ( !is_callable( $headers[ 'function' ] ) )
      throw new \Exception( '\''. $headers[ 'function' ] .'\' is not callable' );

    $result = call_user_func( $headers[ 'function' ], $message->body );
    $this->sendMessage( $loop, $socket, 'job-result', $headers[ 'job-num' ], $result );
  }

  private function sendMessage( EventLoop $loop, $socket, $cmd, $jobNumber = null, $body = '' ) {

    $message = new Message();
    $message->headers[ 'cmd' ] = $cmd;
    if ( $jobNumber !== null )
      $message->headers[ 'job-num' ] = $jobNumber;
    $message->body = $body;

    //$this->writeBuffer[] = $message;

    //// FIXME:
    //// Just testing what happens at the server when we send multiple messages
    //// at once. Maybe we could buffer multiple results before sending them back
    //// to the server kinda like this? How do we know when we've got the last
    //// job?
    //if ( count( $this->writeBuffer ) >= 2 ) {
    //  foreach ( $this->writeBuffer as $msg )
    //    $loop->send( $socket, $msg );
    //  $this->writeBuffer = array();
    //}
    //else {
    //  $message = new Message();
    //  $message->headers[ 'cmd' ] = 'new-worker';
      $loop->send( $socket, $message );
    //}
  }
}

