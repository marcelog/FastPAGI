<?php

try
{
    // Setup environment.
    error_reporting(0);
    ini_set('display_errors', 0);

    if (PHP_SAPI !== 'cli') {
        throw new \Exception('This script is intended to be run from the cli');
    }

    // Check command line arguments.
    if ($argc !== 2) {
        throw new \Exception("Use: $argv[0] <config file>");
    }
    // Read config file.
    $configFile = realpath($argv[1]);
    $config = parse_ini_file($configFile, true);
    if ($config === false) {
        throw new \Exception("Could not parse config file: $configFile");
    }

    // Setup php options, defined in the php section of the config file
    if (isset($config['php']) && is_array($config['php'])) {
        foreach ($config['php'] as $key => $value) {
            ini_set($key, $value);
        }
    }

    if (!isset($config['server']['listen'])) {
        throw new \Exception('Missing server.listen configuration');
    }
    $listen = $config['server']['listen'];

    // Open socket stream server
    $socket = stream_socket_server($listen, $errno, $errstr);
    if ($socket === false) {
        throw new \Exception('Error opening socket: ' . $errstr);
    }
    // Non blocking mode
    stream_set_blocking($socket, 0);

    // For-ever loop accepting clients
    while(true) {
        $read = array($socket);
        $write = null;
        $ex = null;
        $result = @stream_select($read, $write, $ex, 0, 1);
        if ($result === false) {
            throw new \Exception('Error selecting from socket: ' . socket_strerror(socket_last_error($this->_socket)));
        }
        if ($result > 0) {
            if (in_array($socket, $read)) {
                $newSocket = stream_socket_accept($socket);
                if ($newSocket !== false) {
                    $address = '';
                    $port = 0;
                    switch(($pid = pcntl_fork())) {
                        case 0:
                            try
                            {
                                require_once 'PAGI/Autoloader/Autoloader.php'; // Include PAGI autoloader.
                                \PAGI\Autoloader\Autoloader::register(); // Call autoloader register for PAGI autoloader.
                                require_once $config['application']['bootstrap'];
                                $options = array();
                                $options['log4php.properties'] = $config['application']['log4php'];
                                $options['stdin'] = $newSocket;
                                $options['stdout'] = $newSocket;
                                $app = new $config['application']['class']($options);
                                $app->init();
                                $app->run();
                            } catch (\Exception $exception) {
                            }
                            exit();
                            break;
                        case -1:
                            echo "Error forking for: " . stream_socket_get_name($newSocket, true) . "\n";
                            break;
                        default:
                            echo "Forked for: " . stream_socket_get_name($newSocket, true) . "\n";
                            break;
                    }
                }
            }
        }
        usleep(1000);
    }
} catch(\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

socket_close($socket);
