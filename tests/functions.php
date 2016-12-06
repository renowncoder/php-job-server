<?php

function job_test( $message ) {
  
  usleep( 100000 );
  return strtoupper( $message );
}

