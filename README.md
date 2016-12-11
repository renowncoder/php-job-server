# php-job-server

Brainstorming:

    Blocking i/o (perfectly synchronized):

    worker1: 2ms to finish
    worker2: 2ms to finish
    worker3: 2ms to finish

    server reads result: 0.5ms (r)
    server writes new job: 0.5ms (w)

    time:     0     1     2     3     4     5     6     7
    worker1:  rrrwww            rrrwww
    worker2:        rrrwww            rrrwww
    worker3:              rrrwww            rrrwww

    -----------------------------------------------------------

    Blocking i/o (not synchronized):

    worker1: 3ms to finish
    worker2: 2ms to finish
    worker3: 1ms to finish

    server reads result: 0.5ms (r)
    server writes new job: 0.5ms (w)
    cpu time available for extra non-i/o work: (+)
    waiting for i/o: (.)

    time:     0     1     2     3     4     5     6     7
    worker1:  rrrwww                  rrrwww
    worker2:  ......rrrwww            ......rrrwww
    worker3:  ............rrrwww      ............rrrwww
    server:                     ......

    -----------------------------------------------------------

    Non-blocking i/o (perfectly synchronized):

    worker1: 2ms to finish
    worker2: 2ms to finish
    worker3: 2ms to finish

    server reads result: 0.5ms (r)
    server writes new job: 0.5ms (w)
    cpu time available for extra non-i/o work: (+)
    waiting for i/o: (.)

    time:     0     1     2     3     4     5     6     7
    worker1:  r++r++r++w++w++w            r++r++r++w++w++w
    worker2:   r++r++r++w++w++w            r++r++r++w++w++w
    worker3:    r++r++r++w++w++w            r++r++r++w++w++w
    server:                     ++++++++++

    -----------------------------------------------------------

    Non-blocking i/o (not synchronized):

    worker1: 3ms to finish
    worker2: 2ms to finish
    worker3: 1ms to finish

    server reads result: 0.5ms (r)
    server writes new job: 0.5ms (w)
    cpu time available for extra non-i/o work: (+)
    waiting for i/o: (.)

    time:     0     1     2     3     4     5     6     7
    worker1:  r++r++r++w++w++w                  r+r+rwww
    worker2:   r++r++r++w++w++w            r+rrw+w+w
    worker3:    r++r++r++w++w++w      rrrww+w
    server:                     ++++++

    -----------------------------------------------------------


    Blocking i/o:
    -------------

    Server:

      results = []
      stream_socket_server
      while ( unfinished jobs )
        stream_socket_accept
        while ( got data )
          stream_socket_recvfrom
        results[] = data
        stream_socket_sendto
        fclose

    Worker:

      stream_socket_client
      stream_socket_sendto [register worker with server]
      stream_socket_recvfrom [get first job from server]
      while ( got job )
        stream_socket_sendto [send result]
        stream_socket_recvfrom [get another job]


    Non-blocking i/o:
    -----------------

    Server:

      clients = []
      writeBufferPerClient = []
      readBufferPerClient = []
      stream_socket_server
      stream_set_blocking( server socket not blocking )
      while ( unfinished jobs )
        stream_select( readable: clients + server, writable: clients )
        if ( server is readable )
          clients[] = stream_socket_accept
          stream_set_blocking( client socket not blocking )
        foreach ( clients[] )
          if ( is readable )
            while ( got data, which means client socket is open )
              stream_socket_recvfrom
              readBufferPerClient[] .= data
            if ( no received data )
              results[] = Request(readBuffer)->getBody/Headers()
          if ( is writable )
            while ( didn't write all bytes of writeBufferPerClient[current] yet )
              stream_socket_sendto
            if ( writeBufferPerClient[current] is empty )
              fclose
              unset( clients[current] )

    Worker:

      initialized = false
      jobQueue = []
      resultsQueue = []
      readBuffer = ''
      writeBuffer = ''
      stream_socket_client
      stream_set_blocking( client socket not blocking )
      while ( true )
        stream_select( readable: server, writable: server )
        if ( server is writable )
          if ( !initialized )
            writeBuffer = I'm a new worker, register me
          else
            writeBuffer = here's a job result resultsQueue.pop(), gimme another job
          while ( didn't write all bytes of writeBuffer yet )
            stream_socket_sendto
          if ( writeBuffer is empty )
            fclose
        if ( server is readable )
          while ( got data, which means client socket is open )
            stream_socket_recvfrom
            readBuffer .= data
          if ( no received data )
            jobQueue[] = Request(readBuffer)->getBody/Headers()


