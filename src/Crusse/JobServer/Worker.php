<?php

namespace Crusse\JobServer;

// Including these directly, and not via autoloading, as we might not have an
// autoloader in the current context
require_once dirname( __FILE__ ) .'/SocketWriter.php';
require_once dirname( __FILE__ ) .'/SocketReader.php';

class Worker {

  // Timeouts for sending/receiving to/from the job server socket
  const CONNECT_TIMEOUT = 5;
  const SEND_TIMEOUT = 3;
  const RECV_TIMEOUT = 3;

  private $serverSocketPath;

  function __construct( $serverSocketPath ) {

    $this->serverSocketPath = $serverSocketPath;
  }

  function run() {

    $response = $this->sendMessage( 'new-worker' );

    while ( $response ) {
      $response = $this->handleServerResponse( $response );
    }
  }

  private function handleServerResponse( SocketReader $response ) {

    $headers = $response->getHeaders();

    // No response from server
    if ( !$headers )
      return null;

    if ( isset( $headers[ 'includes' ] ) )
      $this->includePhpSources( $headers[ 'includes' ] );

    if ( !isset( $headers[ 'function' ] ) )
      throw new \Exception( 'Request has no \'function\' header' );
    if ( !is_callable( $headers[ 'function' ] ) )
      throw new \Exception( '\''. $headers[ 'function' ] .'\' is not callable' );

    $result = call_user_func( $headers[ 'function' ], $response->getBody() );
    $response = $this->sendMessage( 'job-result', $headers[ 'job-num' ], $result );

    return $response;
  }

  private function includePhpSources( $paths ) {

    foreach ( explode( ',', $paths ) as $path )
      require_once $path;
  }

  private function sendMessage( $cmd, $jobNumber = null, $body = '' ) {

    $server = $this->getSocketClient();

    $request = new SocketWriter( $server );
    $request->setTimeout( self::SEND_TIMEOUT );
    $request->addHeader( 'cmd', $cmd );
    if ( $jobNumber !== null )
      $request->addHeader( 'job-num', $jobNumber );
    $request->setBody( $body );
    $request->write();

    $responseReader = new SocketReader( $server );
    $responseReader->setTimeout( self::RECV_TIMEOUT );

    return $responseReader;
  }

  private function getSocketClient() {

    $server = stream_socket_client( 'unix://'. $this->serverSocketPath, $errNum,
      $errStr, self::CONNECT_TIMEOUT );

    if ( !$server || $errNum != 0 )
      throw new \Exception( 'Could not create socket client: ('. $errNum .') '. $errStr );

    return $server;
  }
}
