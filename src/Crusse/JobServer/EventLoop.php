<?php

//
// TODO
//
// http://docs.libuv.org/en/v1.x/design.html
//
// "The event loop follows the rather usual single threaded asynchronous I/O approach: all (network) I/O is performed on non-blocking sockets. As part of a loop iteration **the loop will block waiting for I/O activity on sockets which have been added to the poller** and callbacks will be fired indicating socket conditions (readable, writable hangup) so handles can read, write or perform the desired I/O operation."
//
// - create a watchSocket() method for adding server and client sockets
// - emit events (run eventCallback with a 'writable' event) when a socket has sent a _full_ Message, and becomes writable
//   - pass the callback the Message, and maybe a SocketWriter (or make the callback return a Response|null)
// - implement BlockingEventLoop and NonBlockingEventLoop (with stream_select()) to test performance diff (if any)
//
// https://www.reddit.com/r/programming/comments/3vzepv/the_difference_between_asynchronous_and/
// 
// "An example I can think of is POSIX's select() interface. It should be relatively easy to write wrapper code around it such that reads and writes from and to a number of file descriptors are queued and then when those operations are completed events would be generated. What happens to those events can vary depending on the interface's design goals. An obvious choice would be to call any callback functions that the user of such an interface may have provided."
// 

namespace Crusse\JobServer;

class EventLoop {

  private $serverStream;
  private $acceptTimeout = 60;
  private $callbacks = array();
  private $streams = array();
  private $blocking = true;
  private $stop = false;

  function __construct( $blockingIo ) {
    
    $this->blocking = (bool) $blockingIo;
  }

  function addClientStream( $stream, $readTimeout = 2, $writeTimeout = 2 ) {
    
    if ( !is_resource( $stream ) )
      throw new \InvalidArgumentException( '$stream is not a resource' );
    
    stream_set_blocking( $stream, $this->blocking );
    $socket = socket_import_stream( $stream );
    socket_set_option( $socket, SOL_SOCKET, SO_RCVTIMEO, array( 'sec' => $readTimeout, 'usec' => 0 ) );
    socket_set_option( $socket, SOL_SOCKET, SO_SNDTIMEO, array( 'sec' => $writeTimeout, 'usec' => 0 ) );

    $foundSpot = false;
    $streamCount = count( $this->streams );

    // Reuse array keys, instead of always pushing to the array with an
    // incrementing key, so that we don't have large integer keys. We don't use
    // array_unshift when removing streams, as it's slow, so we use unset() 
    // instead.
    for ( $i = 0; $i < $streamCount; $i++ ) {
      if ( !isset( $this->streams[ $i ] ) ) {
        $foundSpot = true;
        $this->streams[ $i ] = $stream;
        break;
      }
    }

    if ( !$foundSpot )
      $this->streams[] = $stream;

    $this->readBuffer[ $i ] = new MessageBuffer();
    $this->writeBuffer[ $i ] = '';
  }

  function addServerStream( $stream, $acceptTimeout = 60 ) {
    
    if ( !is_resource( $stream ) )
      throw new \InvalidArgumentException( '$stream is not a resource' );

    stream_set_blocking( $stream, $this->blocking );

    $this->serverStream = $stream;
    $this->acceptTimeout = (int) $acceptTimeout;
  }

  function subscribe( $callable ) {
    
    if ( !is_callable( $callable ) )
      throw new \InvalidArgumentException( '$callable is not callable' );

    $this->callbacks[] = $callable;
  }

  function send( $stream, Message $message ) {

    if ( $this->stop )
      throw new \LogicException( 'Calling send() after stop() is redundant' );
    
    $streamIndex = array_search( $stream, $this->streams, true );
    
    if ( $streamIndex === false )
      throw new \InvalidArgumentException( 'No valid socket stream given' );

    $this->writeBuffer[ $streamIndex ] .= (string) $message;
  }

  function run() {

    while ( true ) {

      $readables = $this->streams;
      if ( $this->serverStream )
        $readables[] = $this->serverStream;
      $writables = $this->streams;
      $nullVar = null;

      $changedStreams = stream_select( $readables, $writables, $nullVar, $this->acceptTimeout );

      // TODO: can it happen that $changedStreams === 0 and $readables is not empty?
      if ( !$changedStreams )
        throw new \Exception( 'select() timed out' );

      // When we're stopping the loop, write all remaining buffer out, but
      // don't receive anything in anymore
      if ( $readables && !$this->stop )
        $this->handleReadableStreams( $readables );

      if ( $writables )
        $this->handleWritableStreams( $writables );

      // Exit the loop when all writes have been done
      if ( $this->stop && !array_filter( $this->writeBuffer ) )
        break;
    }

    foreach ( $this->streams as $stream )
      $this->closeConnection( $stream );
    $this->streams = array();

    if ( $this->serverStream )
      $this->closeConnection( $this->serverStream );
    unset( $this->serverStream );
  }

  function stop() {
    
    $this->stop = true;
  }

  private function handleReadableStreams( $streams ) {

    if ( in_array( $this->serverStream, $streams ) )
      $this->acceptClient();

    foreach ( $streams as $stream ) {

      if ( $stream === $this->serverStream )
        continue;

      $messages = $this->getMessagesFromStream( $stream );

      if ( !$messages )
        continue;

      foreach ( $messages as $message ) {
        foreach ( $this->callbacks as $callback ) {
          call_user_func( $callback, $message, $this, $stream );
        }
      }
    }
  }

  private function handleWritableStreams( $streams ) {

    foreach ( $streams as $stream ) {

      $streamIndex = array_search( $stream, $this->streams, true );
      $buffer = $this->writeBuffer[ $streamIndex ];
      $bufferLen = strlen( $buffer );
      $sentBytes = stream_socket_sendto( $stream, $buffer );

      if ( $sentBytes < 0 )
        throw new \Exception( 'Could not write to socket' );
      
      $this->writeBuffer[ $streamIndex ] = substr( $buffer, $sentBytes );
    }
  }

  private function acceptClient() {

    $stream = stream_socket_accept( $this->serverStream, $this->acceptTimeout );

    if ( !$stream )
      throw new \Exception( 'Reached listen timeout ('. $this->acceptTimeout .')' );

    $this->addClientStream( $stream );

    return $stream;
  }

  /**
   * Returns one or more Messages from the stream. Reading from the stream
   * might return multiple messages, and in that case this function will
   * conserve message boundaries and return each message as a Message.
   *
   * @return array Array of Message objects. Can be empty.
   */
  private function getMessagesFromStream( $stream ) {

    $streamIndex = array_search( $stream, $this->streams, true );
    $buffer = $this->readBuffer[ $streamIndex ];

    // Populate the MessageBuffer from the stream

    if ( $this->blocking ) {
      do {
        $data = stream_socket_recvfrom( $stream, 1500 );
        $this->populateMessageBuffer( $data, $buffer );
      }
      while ( !$buffer->hasMessage );
    }
    else {
      $data = stream_socket_recvfrom( $stream, 1500 );
      $this->populateMessageBuffer( $data, $buffer );
    }

    // Get finished Message objects from the MessageBuffer

    $messages = array();

    while ( $buffer->hasMessage ) {

      $messages[] = $buffer->message;
      // Check if we received multiple messages' data from the stream
      $overflowBytes = $buffer->bodyLen - $buffer->message->headers[ 'body-len' ];
      
      // We got more bytes than the message consists of, so we got (possibly
      // partially) other messages' data
      if ( $overflowBytes > 0 ) {

        $overflow = substr( $buffer->message->body, -$overflowBytes );
        $buffer->message->body .= substr( $buffer->message->body, 0, -$overflowBytes );
        $messages[] = $buffer->message;

        $buffer = new MessageBuffer();
        $this->readBuffer[ $streamIndex ] = $buffer;

        $this->populateMessageBuffer( $overflow, $buffer );
      }
      // We got the whole message, and nothing more (no overflow to the next message)
      else {

        $buffer = new MessageBuffer();
        $this->readBuffer[ $streamIndex ] = $buffer;
      }
    }

    return $messages;
  }

  private function populateMessageBuffer( $data, MessageBuffer &$buffer ) {

    // We already have the header. Add further data to body.
    if ( $buffer->headerEnd !== false ) {
      
      $dataLen = strlen( $data );
      $buffer->bodyLen += $dataLen;
      $buffer->message->body .= $data;
      
      if ( $buffer->bodyLen >= $buffer->message->headers[ 'body-len' ] )
        $buffer->hasMessage = true;
    }
    // We're reading the header of the message
    else {

      $buffer->headerBuffer .= $data;
      $buffer->headerEnd = strpos( $buffer->headerBuffer, "\n\n" );

      if ( $buffer->headerEnd !== false ) {

        $headerLines = array_filter( explode( "\n", substr( $buffer->headerBuffer, 0, $buffer->headerEnd ) ) );

        foreach ( $headerLines as $line ) {
          $colonPos = strpos( $line, ':' );
          $key = substr( $line, 0, $colonPos );
          $val = substr( $line, $colonPos + 1 );
          $buffer->message->headers[ $key ] = $val;
        }

        $bodyPart = substr( $buffer->headerBuffer, $buffer->headerEnd + 2 );
        $buffer->message->body .= $bodyPart;
        $buffer->bodyLen += strlen( $bodyPart );

        if ( $buffer->bodyLen >= $buffer->message->headers[ 'body-len' ] )
          $buffer->hasMessage = true;
      }
    }
  }

  private function closeConnection( $stream ) {

    // Close the connection until the worker client sends us a new result
    stream_socket_shutdown( $stream, STREAM_SHUT_RDWR );
    fclose( $stream );
  }
}

