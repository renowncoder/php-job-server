<?php

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
    file_put_contents( '/tmp/crusse-job-server.log', '' );
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

    if ( $this->stop ) {
      $this->log( 'Error: called send() after stop()' );
      throw new \LogicException( 'Calling send() after stop() is redundant' );
    }
    
    $streamIndex = array_search( $stream, $this->streams, true );
    
    if ( $streamIndex === false ) {
      $this->log( 'Error: $stream was not found in list of clients' );
      throw new \InvalidArgumentException( 'No valid socket stream given' );
    }

    $this->writeBuffer[ $streamIndex ] .= (string) $message;
  }

  function run() {

    $this->log( 'Using select() timeout of '. $this->acceptTimeout .' s' );

    while ( true ) {

      $readables = $this->streams;
      if ( $this->serverStream )
        $readables[] = $this->serverStream;
      $writables = $this->streams;
      $nullVar = null;

      $changedStreams = stream_select( $readables, $writables, $nullVar, $this->acceptTimeout );

      // TODO: can it happen that $changedStreams === 0 and $readables is not empty?
      if ( !$changedStreams ) {
        $this->log( 'Error: select() timed out' );
        throw new \Exception( 'select() timed out' );
      }

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

      if ( !$bufferLen )
        continue;

      $sentBytes = stream_socket_sendto( $stream, $buffer );

      if ( $sentBytes < 0 ) {
        $this->log( 'Error: could not write to socket: "'. $buffer .'"' );
        throw new \Exception( 'Could not write to socket' );
      }

      $this->log( 'Sent '. $sentBytes .' b to '. $streamIndex );
      $this->writeBuffer[ $streamIndex ] = substr( $buffer, $sentBytes );
    }
  }

  private function acceptClient() {

    $stream = stream_socket_accept( $this->serverStream, $this->acceptTimeout );

    if ( !$stream ) {
      $this->log( 'Error: reached accept timeout' );
      throw new \Exception( 'Reached accept timeout ('. $this->acceptTimeout .')' );
    }

    $this->addClientStream( $stream );
    $this->log( 'Accepted client' );

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

    $data = stream_socket_recvfrom( $stream, 1500 );
    $this->log( 'Recvd '. strlen( $data ) .' b from '. $streamIndex );
    $this->populateMessageBuffer( $data, $buffer );

    // Get finished Message objects from the MessageBuffer

    $messages = array();

    while ( $buffer->hasMessage ) {

      $messages[] = $buffer->message;
      // Check if we received multiple messages' data from the stream
      $overflowBytes = $buffer->bodyLen - $buffer->message->headers[ 'body-len' ];
      
      // We got more bytes than the message consists of, so we got (possibly
      // partially) other messages' data
      if ( $overflowBytes > 0 ) {

        $this->log( 'Recvd multiple messages from stream (overflow: '. $overflowBytes .' b)' );

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

  private function log( $msg ) {
    $prefix = ( $this->serverStream ) ? '[SERVER] ' : '[worker] ';
    file_put_contents( '/tmp/crusse-job-server.log', $prefix . $msg . PHP_EOL, FILE_APPEND );
  }
}

