<?php

//
// TODO
//
// http://docs.libuv.org/en/v1.x/design.html
//
// "The event loop follows the rather usual single threaded asynchronous I/O approach: all (network) I/O is performed on non-blocking sockets. As part of a loop iteration **the loop will block waiting for I/O activity on sockets which have been added to the poller** and callbacks will be fired indicating socket conditions (readable, writable hangup) so handles can read, write or perform the desired I/O operation."
//
// - create a watchSocket() method for adding server and client sockets
// - emit events (run eventCallback with a 'writable' event) when a socket has sent a _full_ Request, and becomes writable
//   - pass the callback the Request, and maybe a SocketWriter (or make the callback return a Response|null)
// - implement BlockingEventLoop and NonBlockingEventLoop (with stream_select()) to test performance diff (if any)
//
// https://www.reddit.com/r/programming/comments/3vzepv/the_difference_between_asynchronous_and/
// 
// "An example I can think of is POSIX's select() interface. It should be relatively easy to write wrapper code around it such that reads and writes from and to a number of file descriptors are queued and then when those operations are completed events would be generated. What happens to those events can vary depending on the interface's design goals. An obvious choice would be to call any callback functions that the user of such an interface may have provided."
// 

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

