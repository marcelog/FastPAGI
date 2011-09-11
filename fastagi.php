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

/**
 * This one is run by the OS whenever an interesting signal occurs.
 *
 * @param integer $signal The signal number
 *
 * @return void
 */
function signalHandler($signal)
{
    global $running;
    global $children;
    switch ($signal) {
        case SIGINT:
        case SIGQUIT:
        case SIGTERM:
            // Do not run more than once.
            if (!$running) {
                break;
            }
            cleanup();
           break;
       case SIGCHLD:
            // A child exited normally (maybe..) so remove it from the current active list.
            $pid = pcntl_waitpid(-1, $status);
            unset($children[$pid]);
       default:
            break;
    }
}

/**
 * Called by the signal handler when a termination signal occurs.
 *
 * @return void
 */
function cleanup()
{
    global $children;
    global $pidFile;
    global $socket;
    global $running;

    // Terminate main loop, do not accept any more calls.
    $running = false;

    // Wait for all ongoing calls to finish (actually kill them, then wait).
    foreach ($children as $pid => $child) {
        posix_kill($pid, SIGTERM);
        pcntl_waitpid($pid, $status);
        unset($children[$pid]);
    }
    // Remove pid file.
    @unlink($pidFile);
    if ($socket !== false) {
        fclose($socket);
    }
}

/**
 * Opens the socket server.
 * 
 * @param string address In the form: address:port
 *
 * @return stream
 */
function open($address)
{
    // Open socket stream server
    $socket = stream_socket_server($address, $errno, $errstr);
    if ($socket === false) {
        throw new \Exception('Error opening socket: ' . $errstr);
    }
    // Non blocking mode
    stream_set_blocking($socket, 0);
    return $socket;
}

/**
 * Launchs the application by fork()'ing.
 * 
 * @param stream   $client The client accept()ed.
 * @param string[] $applicationOptions The application options (bootstrap, log4php, etc)
 *
 * @return void
 */
function launch($client, $applicationOptions)
{
    switch(($pid = pcntl_fork())) {
        case 0:
            try
            {
                // Launch PAGI application.
                require_once $applicationOptions['bootstrap'];
                $options = array();
                $options['log4php.properties'] = $applicationOptions['log4php'];
                $options['stdin'] = $client;
                $options['stdout'] = $client;
                $app = new $applicationOptions['class']($options);
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
            $children[$pid] = $pid;
            //echo "Forked for: " . stream_socket_get_name($newSocket, true) . "\n";
            break;
    }
}

/**
 * Non-blocking accept for a new client.
 * 
 * @param stream $socket The server stream
 * 
 * @return stream|null The new client or null.
 */
function accept($socket)
{
    $read = array($socket);
    $write = null;
    $ex = null;
    $result = @stream_select($read, $write, $ex, 0, 1);
    if ($result !== false) {
        if ($result > 0) {
            if (in_array($socket, $read)) {
                return stream_socket_accept($socket);
            }
        }
    }
    return null;
}

/**
 * For each key/value, will do ini_set($key, $value)
 * 
 * @param string[] Valid php options.
 * 
 * @return void
 */
function setupPhp(array $options)
{
    foreach ($options as $key => $value) {
        ini_set($key, $value);
    }
}

/**
 * If pidfile already exists, will throw an exception. Otherwise, will
 * write the current pid to it (creating it).
 *
 * @param string $pidFile
 *
 * @return void
 */
function writePidfile($pidFile)
{
    if (file_exists($pidFile)) {
        $pid = @file_get_contents($pidFile);
        throw new \Exception("$pidFile already exists for pid: $pid");
    }

    // Save our pid.
    $pid = posix_getpid();
    if (file_put_contents($pidFile, $pid) === false) {
        throw new \Exception("Could not write $pidFile");
    }
}

/**
 * Tries to read and validate config file (for missing options).
 * 
 * @param string Absolute path to config file (php ini file).
 * 
 * @return string[] Options parsed
 */
function readConfigFile($configFile)
{
    $configFile = realpath($configFile);
    $config = parse_ini_file($configFile, true);
    if ($config === false) {
        throw new \Exception("Could not parse config file: $configFile");
    }
    if (!isset($config['server']['pid'])) {
        throw new \Exception('Missing server.pid configuration');
    }
    if (!isset($config['server']['listen'])) {
        throw new \Exception('Missing server.listen configuration');
    }
    return $config;
}

/**
 * Setups various interesting signals to be catched.
 * 
 * @return void
 */
function setupSignalHandlers()
{
    // Setup signal handlers.
    pcntl_signal(SIGINT, 'signalHandler');
    pcntl_signal(SIGTERM, 'signalHandler');
    pcntl_signal(SIGQUIT, 'signalHandler');
    pcntl_signal(SIGCHLD, 'signalHandler');
}

/**
 * Will register PAGI autoloader, check the SAPI for cli, setup the signal handlers,
 * and mute php.
 *
 * @return void
 */
function init()
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
    setupSignalHandlers();
}

/*************************
 * Main Entry Point.
 *************************/
declare(ticks=1); // Needed by the signal handler to be run properly.. this is deprecated..

// These are globals, so the try-catch can use them and be shared by to the signal handler.
$running = true;
$socket = false;
$retCode = 0;
$children = array();
$pidFile = '';

try
{
    init();

    // Check command line arguments.
    if ($argc !== 2) {
        throw new \Exception("Use: $argv[0] <config file>");
    }
    // Read config file.
    $config = readConfigFile($argv[1]);

    // Check if pidfile already exists.
    $pidFile = $config['server']['pid'];
    writePidfile($pidFile);

    // Setup php options, defined in the php section of the config file
    if (isset($config['php']) && is_array($config['php'])) {
        setupPhp($config['php']);
    }

    // Open socket stream server
    $socket = open($config['server']['listen']);

    // For-ever loop accepting clients
    do
    {
        $client = accept($socket);
        if ($client !== null) {
            launch($client, $config['application']);
        }
    } while($running);
} catch(\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    $retCode = 250;
}

// Done.
exit($retCode);

