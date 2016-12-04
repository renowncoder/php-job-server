<?php

namespace Crusse\JobServer;

// Including these directly, and not via autoloading, as we might not have an
// autoloader in the current context
require_once dirname( __FILE__ ) .'/EventLoop.php';
require_once dirname( __FILE__ ) .'/Message.php';
require_once dirname( __FILE__ ) .'/MessageBuffer.php';

class Worker {

  const CONNECT_TIMEOUT = 3;

  private $serverSocketAddr;

  function __construct( $serverSocketAddr ) {

    $this->serverSocketAddr = $serverSocketAddr;
  }

  function run() {

    $stream = stream_socket_client( $this->serverSocketAddr, $errNum,
      $errStr, self::CONNECT_TIMEOUT );

    if ( !$stream || $errNum != 0 )
      throw new \Exception( 'Could not create socket client: ('. $errNum .') '. $errStr );

    $loop = new EventLoop( false );
    $loop->subscribe( array( $this, '_messageCallback' ) );
    $loop->addClientStream( $stream );
    $this->sendMessage( $loop, $stream, 'new-worker' );
    $loop->run();
  }

  function _messageCallback( Message $message, EventLoop $loop, $stream ) {

    $headers = $message->headers;

    if ( isset( $headers[ 'includes' ] ) ) {
      foreach ( explode( ',', $headers[ 'includes' ] ) as $path )
        require_once $path;
    }

    if ( !isset( $headers[ 'function' ] ) )
      throw new \Exception( 'Request has no \'function\' header' );
    if ( !is_callable( $headers[ 'function' ] ) )
      throw new \Exception( '\''. $headers[ 'function' ] .'\' is not callable' );

    $result = call_user_func( $headers[ 'function' ], $message->body );
    $this->sendMessage( $loop, $stream, 'job-result', $headers[ 'job-num' ], $result );
  }

  private function sendMessage( EventLoop $loop, $stream, $cmd, $jobNumber = null, $body = '' ) {

    $message = new Message();
    $message->headers[ 'cmd' ] = $cmd;
    if ( $jobNumber !== null )
      $message->headers[ 'job-num' ] = $jobNumber;
    $message->body = $body;

    $loop->send( $stream, $message );
  }
}

