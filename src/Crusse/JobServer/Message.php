<?php

namespace Crusse\JobServer;

class Message {

  public $headers = array();
  public $body = '';

  function __toString() {

    // Always set the body-len header after all headers have been added, so that
    // we override any body-len header set earlier
    $this->headers[ 'body-len' ] = strlen( $this->body );

    $ret = '';

    foreach ( $this->headers as $key => $val )
      $ret .= trim( $key ) .':'. $val ."\n";

    return rtrim( $ret ) ."\n\n". $this->body;
  }
}

