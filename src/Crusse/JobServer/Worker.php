<?php

namespace Crusse\JobServer;

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

      $headers = $response->getHeaders();
      // FIXME: pass in a 'function' argument in the response, and run that php function
      $dummyResult = str_replace( 'Job', 'Result', $response->getBody() );
      $response = $this->sendMessage( 'job-result', $headers[ 'job-num' ], $dummyResult );
    }
  }

  private function sendMessage( $cmd, $jobNumber = null, $body = '' ) {

    $server = $this->getSocketClient();

    $request = new Request( $server );
    $request->setTimeout( self::SEND_TIMEOUT );
    $request->addHeader( 'cmd', $cmd );
    if ( $jobNumber !== null )
      $request->addHeader( 'job-num', $jobNumber );
    $request->setBody( $body );
    $request->send();

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
