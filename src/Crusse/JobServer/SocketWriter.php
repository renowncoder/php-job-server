<?php

namespace Crusse\JobServer;

class SocketWriter {

  private $socket;
  private $headers = '';
  private $body = '';

  function __construct( $socket ) {

    if ( !is_resource( $socket ) )
      throw new \InvalidArgumentException( '$socket is not a resource' );

    $this->socket = $socket;
  }

  function setTimeout( $timeout ) {

    $socket = socket_import_stream( $this->socket );
    socket_set_option( $socket, SOL_SOCKET, SO_SNDTIMEO, array( 'sec' => $timeout, 'usec' => 0 ) );
  }

  function addHeader( $key, $value ) {
    $this->headers .= trim( $key ) .':'. trim( $value ) ."\n";
  }

  function setBody( $body ) {
    $this->body = $body;
  }

  function write() {

    // Always set the body-len header after all headers have been added, so that
    // we override any body-len header set earlier
    $this->addHeader( 'body-len', strlen( $this->body ) );

    $message = $this->headers ."\n". $this->body;
    $bytesSent = stream_socket_sendto( $this->socket, $message );

    if ( $bytesSent < 1 )
      throw new \Exception( 'Could not write message to the socket' );

    return true;
  }
}

