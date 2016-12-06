<?php

namespace Crusse\JobServer;

// Bug: this constant is missing in PHP 5.*
if ( !defined( 'MSG_DONTWAIT' ) )
  define( 'MSG_DONTWAIT', 0x40 );

class EventLoop {

  const MAX_LISTEN_CONNECTIONS = 50;

  private $serverSocket;
  private $serverSocketAddr;
  private $acceptTimeout = 60;
  private $callbacks = array();
  private $sockets = array();
  private $blocking = true;
  private $stop = false;

  function __construct( $serverSocketAddr, $blockingIo ) {

    $this->serverSocketAddr = $serverSocketAddr;
    $this->blocking = (bool) $blockingIo;
  }

  function __destruct() {

    // If we were listening on a socket, remove the socket file
    if ( $this->serverSocket )
      unlink( $this->serverSocketAddr );
  }

  function connect() {

    if ( ( $socket = socket_create( AF_UNIX, SOCK_STREAM, 0 ) ) === false )
      throw new \Exception( socket_strerror( socket_last_error() ) );

    $this->addClientSocket( $socket );

    if ( socket_connect( $socket, $this->serverSocketAddr ) === false )
      throw new \Exception( socket_strerror( socket_last_error() ) );

    return $socket;
  }

  private function setSocketOptions( $socket ) {

    if ( $this->blocking )
      socket_set_block( $socket );
    else
      socket_set_nonblock( $socket );

    socket_set_option( $socket, SOL_SOCKET, SO_RCVTIMEO, array( 'sec' => 2, 'usec' => 0 ) );
    socket_set_option( $socket, SOL_SOCKET, SO_SNDTIMEO, array( 'sec' => 2, 'usec' => 0 ) );
  }

  private function addClientSocket( $socket ) {

    $this->setSocketOptions( $socket );

    $foundSpot = false;
    $socketCount = count( $this->sockets );

    // Reuse array keys, instead of always pushing to the array with an
    // incrementing key, so that we don't have large integer keys. We don't use
    // array_unshift when removing sockets, as it's slow, so we use unset() 
    // instead.
    for ( $i = 0; $i < $socketCount; $i++ ) {
      if ( !isset( $this->sockets[ $i ] ) ) {
        $foundSpot = true;
        $this->sockets[ $i ] = $socket;
        break;
      }
    }

    if ( !$foundSpot )
      $this->sockets[] = $socket;

    $this->readBuffer[ $i ] = new MessageBuffer();
    $this->writeBuffer[ $i ] = '';
  }

  function listen( $acceptTimeout = 60 ) {

    file_put_contents( '/tmp/crusse-job-server.log', '' );

    @unlink( $this->serverSocketAddr );

    if ( ( $socket = socket_create( AF_UNIX, SOCK_STREAM, 0 ) ) === false )
      throw new \Exception( socket_strerror( socket_last_error() ) );

    if ( socket_bind( $socket, $this->serverSocketAddr ) === false )
      throw new \Exception( socket_strerror( socket_last_error() ) );

    if ( $this->blocking )
      socket_set_block( $socket );
    else
      socket_set_nonblock( $socket );

    if ( socket_listen( $socket, self::MAX_LISTEN_CONNECTIONS ) === false )
      throw new \Exception( socket_strerror( socket_last_error() ) );

    $this->serverSocket = $socket;
    $this->acceptTimeout = (int) $acceptTimeout;
  }

  function subscribe( $callable ) {
    
    if ( !is_callable( $callable ) )
      throw new \InvalidArgumentException( '$callable is not callable' );

    $this->callbacks[] = $callable;
  }

  function send( $socket, Message $message ) {

    if ( $this->stop ) {
      $this->log( 'Error: called send() after stop()' );
      throw new \LogicException( 'Calling send() after stop() is redundant' );
    }
    
    $socketIndex = array_search( $socket, $this->sockets, true );
    
    if ( $socketIndex === false ) {
      $this->log( 'Error: $socket was not found in list of clients' );
      throw new \InvalidArgumentException( 'No valid socket given' );
    }

    $this->writeBuffer[ $socketIndex ] .= (string) $message;
  }

  function run() {

    $this->log( 'Using select() timeout of '. $this->acceptTimeout .' s' );

    while ( true ) {

      $readables = $this->sockets;
      if ( $this->serverSocket )
        $readables[] = $this->serverSocket;
      $writables = $this->sockets;
      $nullVar = null;

      $changedSockets = @socket_select( $readables, $writables, $nullVar, $this->acceptTimeout );

      if ( $changedSockets === 0 ) {
        $this->log( 'Error: select() timed out' );
        throw new \Exception( 'select() timed out' );
      }
      else if ( $changedSockets === false ) {
        $this->log( 'Error: '. socket_strerror( socket_last_error() ) );
        throw new \Exception( socket_strerror( socket_last_error() ) );
      }

      // When we're stopping the loop, write all remaining buffer out, but
      // don't receive anything in anymore
      if ( $readables && !$this->stop )
        $this->handleReadableSockets( $readables );

      if ( $writables )
        $this->handleWritableSockets( $writables );

      // Exit the loop when all writes have been done
      if ( $this->stop && !array_filter( $this->writeBuffer ) )
        break;
    }

    foreach ( $this->sockets as $socket )
      $this->closeConnection( $socket );
    $this->sockets = array();

    if ( $this->serverSocket )
      $this->closeConnection( $this->serverSocket );
  }

  function stop() {
    
    $this->stop = true;
  }

  private function handleReadableSockets( $sockets ) {

    if ( in_array( $this->serverSocket, $sockets ) )
      $this->acceptClient();

    foreach ( $sockets as $socket ) {

      if ( $socket === $this->serverSocket )
        continue;

      $messages = $this->getMessagesFromSocket( $socket );

      if ( !$messages )
        continue;

      $this->log( 'Buffer had '. count( $messages ) .' messages' );

      foreach ( $messages as $message ) {
        foreach ( $this->callbacks as $callback ) {
          call_user_func( $callback, $message, $this, $socket );
        }
      }
    }
  }

  private function handleWritableSockets( $sockets ) {

    foreach ( $sockets as $socket ) {

      $socketIndex = array_search( $socket, $this->sockets, true );
      $buffer = $this->writeBuffer[ $socketIndex ];
      $bufferLen = strlen( $buffer );

      if ( !$bufferLen )
        continue;

      $sentBytes = socket_send( $socket, $buffer, $bufferLen, 0 );

      if ( $sentBytes === false ) {
        $this->log( 'Error: could not write to socket: "'. $buffer .'"' );
        throw new \Exception( 'Could not write to socket' );
      }

      $this->log( 'Sent '. $sentBytes .' b to '. $socketIndex );
      $this->writeBuffer[ $socketIndex ] = substr( $buffer, $sentBytes );
    }
  }

  private function acceptClient() {

    $socket = socket_accept( $this->serverSocket );

    if ( !$socket ) {
      $this->log( 'Error: '. socket_strerror( socket_last_error() ) );
      throw new \Exception( socket_strerror( socket_last_error() ) );
    }

    $this->addClientSocket( $socket );
    $this->log( 'Accepted client '. ( count( $this->sockets ) - 1 ) );

    return $socket;
  }

  /**
   * Returns one or more Messages from the socket. Reading from the socket
   * might return multiple messages, and in that case this function will
   * conserve message boundaries and return each message as a Message.
   *
   * @return array Array of Message objects. Can be empty.
   */
  private function getMessagesFromSocket( $socket ) {

    $socketIndex = array_search( $socket, $this->sockets, true );
    $buffer = $this->readBuffer[ $socketIndex ];

    // Populate the MessageBuffer from the socket

    $data = '';
    $dataLen = socket_recv( $socket, $data, 1500, MSG_DONTWAIT );

    // There was an error
    if ( $dataLen === false ) {
      $this->log( 'Error: '. socket_strerror( socket_last_error() ) );
      throw new \Exception( socket_strerror( socket_last_error() ) );
    }

    // Connection was dropped by peer
    if ( $dataLen === 0 ) {
      // Don't read/write from/to this socket in the future
      unset( $this->sockets[ $socketIndex ] );
      return array();
    }

    $this->log( 'Recvd '. $dataLen .' b from '. $socketIndex );
    $this->populateMessageBuffer( $data, $buffer );

    // Get finished Message objects from the MessageBuffer

    $messages = array();

    while ( $buffer->hasMessage ) {

      $messages[] = $buffer->message;
      // Check if we received multiple messages' data from the socket
      $overflowBytes = $buffer->bodyLen - $buffer->message->headers[ 'body-len' ];
      
      // We got more bytes than the message consists of, so we got (possibly
      // partially) other messages' data
      if ( $overflowBytes > 0 ) {

        $this->log( 'Recvd multiple messages from socket (overflow: '. $overflowBytes .' b)' );

        $overflow = substr( $buffer->message->body, -$overflowBytes );
        $buffer->message->body .= substr( $buffer->message->body, 0, -$overflowBytes );
        $messages[] = $buffer->message;

        $buffer = new MessageBuffer();
        $this->readBuffer[ $socketIndex ] = $buffer;

        $this->populateMessageBuffer( $overflow, $buffer );
      }
      // We got the whole message, and nothing more (no overflow to the next message)
      else {

        $buffer = new MessageBuffer();
        $this->readBuffer[ $socketIndex ] = $buffer;
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

  private function closeConnection( $socket ) {

    // Close the connection until the worker client sends us a new result
    socket_shutdown( $socket, 2 );
  }

  private function log( $msg, $socketIndex = 0 ) {

    static $id = '';
    if ( !$id )
      $id = uniqid();

    $prefix = ( $this->serverSocket ) ? '[SERVER] ' : '[worker] ';
    file_put_contents( '/tmp/crusse-job-server.log',
      $id .' '. $prefix . $msg . PHP_EOL, FILE_APPEND );
  }
}

