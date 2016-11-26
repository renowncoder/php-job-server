<?php

function job_test( $message ) {
  
  usleep( 200000 );
  return strtoupper( $message );
}

