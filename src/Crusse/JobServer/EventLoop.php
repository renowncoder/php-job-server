<?php

namespace Crusse\JobServer;

class EventLoop {

  private $listenStream;
  private $eventCallback;
  private $timeout = 60;
  
  function __construct( $listenStream ) {
    
    $this->listenStream = $listenStream;
  }

  private function tick() {

    $client = stream_socket_accept( $this->listenStream, $this->timeout );

    if ( !$client )
      throw new \Exception( 'Reached idle timeout ('. $this->timeout .')' );

    $request = $this->getRequestFromClientStream( $client );

    return call_user_func( $this->eventCallback, $request );
  }

  function run( $eventCallback ) {

    $this->eventCallback = $eventCallback;
    
    while ( true ) {
      if ( !$this->tick() )
        break;
    }
  }

  private function getRequestFromClientStream( $stream ) {

    $request = new Request();
    $request->stream = $stream;

    while ( $this->populateRequestFromStream( $stream, $request ) ) {}

    // Free memory
    unset( $request->headerBuffer );
    unset( $request->headerEnd );
    unset( $request->bodyLen );

    // The connection was closed before we got the full response
    if ( !$request->valid )
      throw new \Exception( 'Stream was closed unexpectedly' );

    return $request;
  }

  private function populateRequestFromStream( $stream, &$request ) {

    $data = stream_socket_recvfrom( $stream, 1500 );
    $dataLen = strlen( $data );

    // Connection was dropped by the receiver, or the socket receive timed out
    if ( !$dataLen )
      return false;

    $request->headerBuffer .= $data;

    // We already have the header. Add further data to body.
    if ( $request->headerEnd !== false ) {
      
      $request->body .= $data;
      $request->bodyLen += $dataLen;
      
      if ( $request->bodyLen >= $request->headers[ 'body-len' ] ) {
        $request->valid = true;
        return false;
      }
    }
    // We're reading the header of the request
    else {

      $request->headerEnd = strpos( $request->headerBuffer, "\n\n" );

      if ( $request->headerEnd !== false ) {

        $headerLines = array_filter( explode( "\n", substr( $request->headerBuffer, 0, $request->headerEnd ) ) );

        foreach ( $headerLines as $line ) {
          $colonPos = strpos( $line, ':' );
          $key = substr( $line, 0, $colonPos );
          $val = substr( $line, $colonPos + 1 );
          $request->headers[ $key ] = $val;
        }

        $bodyPart = substr( $headerBuffer, $headerEnd + 2 );
        $request->body .= $bodyPart;
        $bodyLen += strlen( $bodyPart );

        if ( $request->bodyLen >= $request->headers[ 'body-len' ] ) {
          $request->valid = true;
          return false;
        }
      }
    }

    return true;
  }
}

class Request {

  public $stream;
  public $headers = array();
  public $body = '';
  public $valid = false;

  public $headerBuffer = '';
  public $headerEnd = false;
  public $bodyLen = 0;
}

