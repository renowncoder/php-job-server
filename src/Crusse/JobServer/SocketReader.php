<?php

namespace Crusse\JobServer;

class SocketReader {

  private $socket;
  private $headers = null;
  private $body = null;

  function __construct( $socket ) {

    if ( !is_resource( $socket ) )
      throw new \InvalidArgumentException( '$socket is not a resource' );

    $this->socket = $socket;
  }

  function getHeaders() {

    if ( $this->headers === null || $this->body === null )
      $this->populateFromSocket();

    return $this->headers;
  }

  function getBody() {

    if ( $this->headers === null || $this->body === null )
      $this->populateFromSocket();

    return $this->body;
  }

  function setTimeout( $timeout ) {

    $socket = socket_import_stream( $this->socket );
    socket_set_option( $socket, SOL_SOCKET, SO_RCVTIMEO, array( 'sec' => $timeout, 'usec' => 0 ) );
  }
}
