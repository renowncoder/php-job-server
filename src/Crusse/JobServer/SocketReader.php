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

  private function populateFromSocket() {

    $headerBuffer = '';
    $headerEnd = false;
    $headers = array();
    $body = '';
    $bodyLen = 0;

    while ( true ) {

      $data = stream_socket_recvfrom( $this->socket, 1500 );
      $dataLen = strlen( $data );

      // Connection was dropped by the receiver, or the socket receive timed out
      if ( !$dataLen )
        break;

      $headerBuffer .= $data;

      if ( $headerEnd !== false ) {
        
        $body .= $data;
        $bodyLen += $dataLen;
        
        if ( $bodyLen >= $headers[ 'body-len' ] )
          break;
      }
      else {

        $headerEnd = strpos( $headerBuffer, "\n\n" );

        if ( $headerEnd !== false ) {

          $headerLines = array_filter( explode( "\n", substr( $headerBuffer, 0, $headerEnd ) ) );

          foreach ( $headerLines as $line ) {
            $colonPos = strpos( $line, ':' );
            $key = substr( $line, 0, $colonPos );
            $val = substr( $line, $colonPos + 1 );
            $headers[ $key ] = $val;
          }

          $bodyPart = substr( $headerBuffer, $headerEnd + 2 );
          $body .= $bodyPart;
          $bodyLen += strlen( $bodyPart );

          // Free memory
          $headerBuffer = '';

          if ( $bodyLen >= $headers[ 'body-len' ] )
            break;
        }
      }
    }

    // The connection was closed before we got the full response
    if ( !$headers || $bodyLen < $headers[ 'body-len' ] ) {
      $this->headers = null;
      $this->body = null;
    }
    else {
      $this->headers = $headers;
      $this->body = $body;
    }
  }
}
