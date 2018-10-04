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
  private $stop = false;
  private $readBuffer = array();
  private $writeBuffer = array();

  function __construct( $serverSocketAddr ) {

    $this->serverSocketAddr = $serverSocketAddr;
  }

  function __destruct() {

    foreach ( $this->sockets as $socket )
      $this->disconnect( $socket );

    // If we were listening on a socket, remove the socket file
    if ( $this->serverSocket ) {
      $this->disconnect( $this->serverSocket );
      unlink( $this->serverSocketAddr );
    }
  }

  function connect() {

    if ( ( $socket = socket_create( AF_UNIX, SOCK_STREAM, 0 ) ) === false )
      throw new \Exception( 'Could not create socket: '. socket_strerror( socket_last_error() ) );

    $this->addClientSocket( $socket );

    if ( socket_connect( $socket, $this->serverSocketAddr ) === false )
      throw new \Exception( 'Could not connect to socket. Peer is probably not listen()ing. '. socket_strerror( socket_last_error() ) );

    return $socket;
  }

  private function setSocketOptions( $socket ) {

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
      if ( empty( $this->sockets[ $i ] ) ) {
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

    // Uncomment only when debugging
    //file_put_contents( '/tmp/crusse-job-server.log', '' );

    @unlink( $this->serverSocketAddr );

    if ( ( $socket = socket_create( AF_UNIX, SOCK_STREAM, 0 ) ) === false )
      throw new \Exception( socket_strerror( socket_last_error() ) );

    if ( socket_bind( $socket, $this->serverSocketAddr ) === false )
      throw new \Exception( socket_strerror( socket_last_error() ) );

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

  function receive() {

    $this->log( 'Using select() timeout of '. $this->acceptTimeout .' s' );

    while ( true ) {

      // We have no more sockets to poll, all have disconnected
      if ( !$this->sockets && !$this->serverSocket ) {
        $this->log( 'No more sockets to poll, exiting receive() loop' );
        break;
      }

      $readables = $this->sockets;
      if ( $this->serverSocket )
        $readables[] = $this->serverSocket;

      // We're only interested in the sockets for which we have any buffered
      // data to send.
      // Note: array_filter() preserves keys.
      $writableSocketKeys = array_keys( array_filter( $this->writeBuffer, 'strlen' ) );

      if ( $writableSocketKeys ) {
        $writables = array();
        foreach ( $writableSocketKeys as $key )
          $writables[] = $this->sockets[ $key ];
      }
      else {
        $writables = null;
      }

      $except = null;
      $changedSockets = socket_select( $readables, $writables, $except, $this->acceptTimeout );

      if ( $changedSockets === 0 ) {
        $this->log( 'Error: select() timed out' );
        throw new \Exception( 'select() timed out' );
      }
      else if ( $changedSockets === false ) {
        $this->log( 'Error on select(): '. socket_strerror( socket_last_error() ) );
        throw new \Exception( socket_strerror( socket_last_error() ) );
      }

      if ( $readables )
        $this->handleReadableSockets( $readables );

      if ( $writables )
        $this->handleWritableSockets( $writables );

      if ( $this->stop ) {
        $this->log( 'stop() was called, exiting receive() loop' );
        break;
      }
    }

    foreach ( $this->sockets as $socket )
      $this->disconnect( $socket );

    $this->sockets = array();

    if ( $this->serverSocket ) {
      $this->disconnect( $this->serverSocket );
      $this->serverSocket = null;
    }
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

      if ( $this->stop ) {
        $this->log( 'stop() was called, so will skip reading from remaining readable sockets' );
        break;
      }
    }
  }

  private function handleWritableSockets( $sockets ) {

    foreach ( $sockets as $socket ) {

      $socketIndex = array_search( $socket, $this->sockets, true );
      $buffer = $this->writeBuffer[ $socketIndex ];
      $bufferLen = strlen( $buffer );

      // Nothing to write
      if ( !$bufferLen )
        continue;

      $sentBytes = socket_send( $socket, $buffer, $bufferLen, 0 );

      if ( $sentBytes === false ) {
        $this->log( 'Error: could not write to socket: "'. $buffer .'"' );
        throw new \Exception( 'Could not write to socket' );
      }

      $this->log( 'Sent '. $sentBytes .' bytes to '. $socketIndex );
      $this->writeBuffer[ $socketIndex ] = substr( $buffer, $sentBytes );
    }
  }

  private function acceptClient() {

    $socket = socket_accept( $this->serverSocket );

    if ( !$socket ) {
      $this->log( 'Error on accept(): '. socket_strerror( socket_last_error() ) );
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

    // --------------------------------------------------------------------
    // Populate the MessageBuffer from the socket
    // --------------------------------------------------------------------

    $data = '';

    // "socket_recv() returns the number of bytes received, or FALSE if there was an error"
    $dataLen = socket_recv( $socket, $data, 64 * 1024, MSG_DONTWAIT );

    // There was an error
    if ( $dataLen === false ) {
      $this->log( 'Error on recv(): '. socket_strerror( socket_last_error() ) );
      throw new \Exception( socket_strerror( socket_last_error() ) );
    }

    // Connection was dropped by peer. Calling code can decide if this is
    // an error or not by catching the exception (for the Server this is
    // probably an error, as Workers shouldn't disconnect before the Server,
    // but for Workers the Server disconnecting is a signal that there's no
    // more jobs; maybe do this more cleanly later, by sending a "close" message
    // to the Workers from the Server like HTTP does...).
    if ( $dataLen === 0 )
      throw new SocketDisconnectedException( 'Socket disconnected' );

    $this->log( 'Recvd '. $dataLen .' bytes from '. $socketIndex );
    $this->populateMessageBuffer( $data, $buffer );

    // --------------------------------------------------------------------
    // Get finished Message objects from the MessageBuffer
    // --------------------------------------------------------------------

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

  private function disconnect( $socket ) {

    $clientName = array_search( $socket, $this->sockets, true );

    if ( $clientName === false ) {
      if ( $socket === $this->serverSocket )
        $clientName = 'SERVER';
      else
        $clientName = '[unknown]';
    }

    // Close the connection until the worker client sends us a new result. We
    // silence any errors so that we don't have to test the connection status
    // before we try to close the socket.
    if ( @socket_shutdown( $socket, 2 ) )
      $this->log( 'Closed connection to socket '. $clientName );
  }

  private function log( $msg, $socketIndex = 0 ) {

    // Remove this only for debugging
    return;

    static $id = '';
    if ( !$id )
      $id = uniqid();

    $prefix = ( $this->serverSocket ) ? '[SERVER] ' : '[worker] ';
    file_put_contents( '/tmp/crusse-job-server.log', number_format( microtime( true ), 4, '.', '' ) .' '. $id .' '. $prefix . $msg . PHP_EOL, FILE_APPEND );
  }
}

