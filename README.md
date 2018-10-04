# php-job-server

Spawns multiple PHP processes that act as independent worker processes. This
allows you to parallelize work easily.

Inter-process communication between the workers and the server is done via
Unix domain sockets.


## Installation

Put this in your project's composer.json file:

```
"repositories": [
  {
    "url": "https://github.com/Crusse/php-job-server.git",
    "type": "vcs"
  }
],
"require": {
  "crusse/job-server": "dev-master"
}
```


## Usage

Create the jobs:

```
$server = new Crusse\JobServer\Server( 4 );
$server->setWorkerTimeout( 2 );

for ( $i = 0; $i < 20; $i++ )
  $server->addJob( 'strtoupper', 'foo'. $i );
```

Get results:

```
print_r( $server->getOrderedResults() );
```

Alternative way to get each result immediately after it's been computed:

```
$server->getResults( function( $result, $jobNumber, $total ) {
  echo 'Job '. $jobNumber .'/'. $total .': '. $result . PHP_EOL;
} );
```


## Errors

Workers' PHP errors are logged to syslog (i.e. /var/log/syslog).
