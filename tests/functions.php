<?php

function job_test( $message ) {

  usleep( rand( 50000, 500000 ) );

  return strtoupper( $message );
}

