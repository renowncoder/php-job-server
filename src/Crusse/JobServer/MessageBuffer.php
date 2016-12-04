<?php

namespace Crusse\JobServer;

class MessageBuffer {

  public $message;
  public $hasMessage = false;
  public $headerBuffer = '';
  public $headerEnd = false;
  public $bodyLen = 0;

  function __construct() {
    
    $this->message = new Message();
  }
}

