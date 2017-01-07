<?php

namespace Crusse\JobServer;

// Including these directly, and not via autoloading, as we might not have an
// autoloader in the current context
require_once dirname( __FILE__ ) .'/EventLoop.php';
require_once dirname( __FILE__ ) .'/Message.php';
require_once dirname( __FILE__ ) .'/MessageBuffer.php';

class Worker {

  private $serverSocketAddr;

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
      trigger_error( $e->getMessage() .' in '. $e->getFile() .':'. $e->getLine(), E_USER_WARNING );
    }
  }

  function _messageCallback( Message $message, EventLoop $loop, $socket ) {

    $headers = $message->headers;

    try {

      if ( isset( $headers[ 'includes' ] ) ) {
        foreach ( array_filter( explode( ',', $headers[ 'includes' ] ) ) as $path )
          require_once $path;
      }

      if ( !isset( $headers[ 'function' ] ) ) {
        $result = 'Request has no \'function\' header';
        $status = 'invalid_request';
      }
      else if ( !is_callable( $headers[ 'function' ] ) ) {
        $result = '\''. $headers[ 'function' ] .'\' is not callable';
        $status = 'invalid_request';
      }
      else {
        $result = call_user_func( $headers[ 'function' ], $message->body );
        $status = 'ok';
      }
    }
    catch ( \Exception $e ) {

      $result = $e->getMessage() .' in '. $e->getFile() .':'. $e->getLine();
      $status = 'exception';
    }

    $this->sendMessage( $loop, $socket, 'job-result', $headers[ 'job-num' ], $status, $result );
  }

  private function sendMessage( EventLoop $loop, $socket, $cmd, $jobNumber = null, $status = 'ok', $body = '' ) {

    if ( !is_string( $body ) )
      throw new \InvalidArgumentException( 'Worker result must be a string' );

    $message = new Message();
    $message->headers[ 'cmd' ] = $cmd;

    if ( $jobNumber !== null ) {
      $message->headers[ 'job-status' ] = $status;
      $message->headers[ 'job-num' ] = $jobNumber;
    }

    $message->body = $body;

    $loop->send( $socket, $message );
  }
}

