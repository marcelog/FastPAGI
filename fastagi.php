<?php
/**
 * A very simple FastAGI server to be used for PAGI applications.
 *
 * PHP Version 5
 *
 * @category FastAGI
 * @author   Marcelo Gornstein <marcelog@gmail.com>
 * @license  http://marcelog.github.com/ Apache License 2.0
 * @version  SVN: $Id$
 * @link     http://marcelog.github.com/
 *
 * Copyright 2011 Marcelo Gornstein <marcelog@gmail.com>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 */

// Signal Handler.
function signalHandler($signal)
{
    global $running;
    switch ($signal) {
        case SIGINT:
        case SIGQUIT:
        case SIGTERM:
            $running = false;
            break;
       case SIGCHLD:
            pcntl_waitpid(-1, $status, WNOHANG);
       default:
            break;
    }
}

declare(ticks=1); // Needed by the signal handler to be run properly.. this is deprecated..

// These are globals, so the try-catch can use them and be shared by to the signal handler.
$running = true;
$socket = false;
$retCode = 0;

try
{
    // Setup environment.
    error_reporting(0);
    ini_set('display_errors', 0);
    require_once 'PAGI/Autoloader/Autoloader.php'; // Include PAGI autoloader.
    \PAGI\Autoloader\Autoloader::register(); // Call autoloader register for PAGI autoloader.

    // Check cli environment.
    if (PHP_SAPI !== 'cli') {
        throw new \Exception('This script is intended to be run from the cli');
    }

    // Setup signal handlers.
    pcntl_signal(SIGINT, 'signalHandler');
    pcntl_signal(SIGTERM, 'signalHandler');
    pcntl_signal(SIGQUIT, 'signalHandler');
    pcntl_signal(SIGCHLD, 'signalHandler');

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
    while($running) {
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
                            //echo "Error forking for: " . stream_socket_get_name($newSocket, true) . "\n";
                            break;
                        default:
                            //echo "Forked for: " . stream_socket_get_name($newSocket, true) . "\n";
                            break;
                    }
                }
            }
        }
        usleep(1000);
    }
} catch(\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    $retCode = 250;
}

// Done.
if ($socket !== false) {
    fclose($socket);
}
exit($retCode);
