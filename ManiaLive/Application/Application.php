<?php
/**
 * ManiaLive - TrackMania dedicated server manager in PHP
 *
 * @copyright   Copyright (c) 2009-2011 NADEO (http://www.nadeo.com)
 * @license     http://www.gnu.org/licenses/lgpl.html LGPL License 3
 * @version     $Revision$:
 * @author      $Author$:
 * @date        $Date$:
 */

namespace ManiaLive\Application;

use Maniaplanet\DedicatedServer\Connection;
use ManiaLive\Config\Loader;
use ManiaLive\Event\Dispatcher;

const VERSION = '5.0';

class Application extends \ManiaLive\Utilities\Singleton
{
	const CYCLES_PER_SECOND = 60;

	static $startTime;
	/** @var bool */
	protected static $running = true;
	/** @var Connection */
	protected $connection;

	protected function __construct()
	{
		self::registerAutoload();
		self::checkInstallation();
		set_error_handler('\ManiaLive\Application\ErrorHandling::createExceptionFromError');
		if (extension_loaded('pcntl')) {
			pcntl_signal(SIGTERM, array($this, 'kill'));
			pcntl_signal(SIGINT, array($this, 'kill'));
			declare(ticks = 1);
		}

		// disable memory limit
		ini_set('memory_limit', -1);

		try {
			$configFile = CommandLineInterpreter::preConfigLoad();

			// load configuration file
			/** @var Loader $loader */
			$loader = Loader::getInstance();
			$loader->load(APP_ROOT.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.$configFile);
		} catch(\Exception $e) {
			// exception on startup ...
			ErrorHandling::processStartupException($e);
		}
	}

	protected function init()
	{
		\ManiaLive\Utilities\Console::println('Running ManiaLive '.VERSION.'...');

		new \ManiaLive\Features\Tick\Ticker();
		/** @var \ManiaLive\DedicatedApi\Config $config */
		$config = \ManiaLive\DedicatedApi\Config::getInstance();
		\ManiaLive\Utilities\Logger::log('Running ManiaLive ' . VERSION . ' on ' . $config->host . ':'. $config->port . ' on PHP ' . phpversion());
		$this->connection = Connection::factory($config->host, $config->port, $config->timeout, $config->user, $config->password);

		$serverStatus = $this->connection->getStatus();

		while ($serverStatus->code != 4) {
			\ManiaLive\Utilities\Console::printlnFormatted('Waiting for server to be in "Running" state, current state: ' . $serverStatus->name);
			sleep(5);
			$serverStatus = $this->connection->getStatus();
		}
		$this->connection->sendHideManialinkPage();

		\ManiaLive\Features\ChatCommand\Interpreter::getInstance();
		\ManiaLive\Features\EchoHandler::getInstance();
		\ManiaLive\PluginHandler\PluginHandler::getInstance();

        $this->connection->enableCallbacks(true);

		Dispatcher::dispatch(new Event(Event::ON_INIT));
	}

	final function run()
	{
		try {
			$this->init();

			Dispatcher::dispatch(new Event(Event::ON_RUN));
			self::$startTime = microtime(true);
			$nextCycleStart = self::$startTime;
			$cycleTime = 1 / static::CYCLES_PER_SECOND;
		} catch(\Exception $e) {
			ErrorHandling::processRuntimeException($e);
		}

		try {
			while(self::$running) {
				Dispatcher::dispatch(new Event(Event::ON_PRE_LOOP));

				$calls = $this->connection->executeCallbacks();

				if (!empty($calls)) {
					foreach($calls as $call) {
						$method = preg_replace('/^[[:alpha:]]+\./', '', $call[0]); // remove trailing "Whatever."
						$params = (array) $call[1];
						Dispatcher::dispatch(new \ManiaLive\DedicatedApi\Callback\Event($method, $params));
					}
				}

				$this->connection->executeMulticall();
				Dispatcher::dispatch(new Event(Event::ON_POST_LOOP));

				$endCycleTime = microtime(true) + $cycleTime / 10;
				do {
					$nextCycleStart += $cycleTime;
				} while($nextCycleStart < $endCycleTime);
				$delay = $nextCycleStart - microtime(true);

				if ($delay > 0) {
					usleep((int)($delay * 1000000));
				}
			}
		} catch(\Exception $e) {
			ErrorHandling::processRuntimeException($e);
		}

		Dispatcher::dispatch(new Event(Event::ON_TERMINATE));
		$this->connection->sendHideManialinkPage();
		\ManiaLive\Utilities\Console::println('ManiaLive closed successfully!');
	}

	function kill()
	{
		if($this->connection) $this->connection->manualFlowControlEnable(false);
		self::$running = false;
	}

	public static function isRunning()
	{
		return self::$running;
	}

	private static function registerAutoload()
	{
		if (defined('APP_ROOT')) {
			return;
		}

		$classLoader = new \ReflectionClass('Composer\Autoload\ClassLoader');
		define('APP_ROOT', dirname(dirname(dirname($classLoader->getFileName()))).DIRECTORY_SEPARATOR);

		spl_autoload_register(function ($className) {
			$path = APP_ROOT.'libraries'.DIRECTORY_SEPARATOR.str_replace('\\', DIRECTORY_SEPARATOR, $className).'.php';
			if (file_exists($path)) {
				require_once $path;
			}
		});
	}

	private static function checkInstallation()
	{
		$required = array('spl' => 'Standard PHP Library', 'json' => 'JSON', 'mbstring' => 'mbString', 'curl' => 'cURL with SSL', 'xml' => 'xml', 'simplexml' => 'simplexml', 'pcre' => 'pcre');
		$optional = array('gd' => 'gd', 'phar' => 'phar', 'openssl' => 'openssl', 'zlib' => 'zlib', 'sockets' => 'sockets', 'xmlrpc' => 'xmlrpc');
		$atLastOne = array('sqlite3' => 'SQLite3', 'mysqli' => 'MySQLI');

		if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
			$required['com_dotnet'] = 'com_dotnet';
		} else {
			$optional['pcntl'] = 'pcntl';
		}

		$phpOk = (function_exists('version_compare') && version_compare(phpversion(), '5.4.0', '>='));

		$requiredPass = true;
		$optionalPass = true;
		$atLastOnePass = false;

		echo '
_|      _|                      _|            _|        _|
_|_|  _|_|    _|_|_|  _|_|_|          _|_|_|  _|            _|    _|    _|_|
_|  _|  _|  _|    _|  _|    _|  _|  _|    _|  _|        _|  _|    _|  _|_|_|_|
_|      _|  _|    _|  _|    _|  _|  _|    _|  _|        _|  _|  _|    _|
_|      _|    _|_|_|  _|    _|  _|    _|_|_|  _|_|_|_|  _|    _|        _|_|_|
';
		echo '-----------------------------------------------------' . PHP_EOL;
		echo 'PHP Environment Compatibility Test' . PHP_EOL;
		echo '-----------------------------------------------------' . PHP_EOL;
		echo 'PHP 5.4.0 or newer    -> required  -> '.($phpOk ? ('[ Yes ] ' . phpversion()) : '[ No  ]') . PHP_EOL;
		foreach ($required as $ext => $name) {
			if ($ext == 'curl') {
				
				$curlVersion = null;
				$curlSslOk = false;
				if(extension_loaded('curl') && function_exists('curl_version')) {
					$curlVersion = curl_version();
					$curlSslOk = (function_exists('curl_exec') && in_array('https', $curlVersion['protocols'], true));
				}
				
				echo 'cURL with SSL         -> required  -> '.($curlVersion ? ($curlSslOk ? '[ Yes ] '.$curlVersion['version'].' (with '.$curlVersion['ssl_version'].')' : '[ No  ] '.$curlVersion['version'].' (without SSL)') : '[ No  ]') . PHP_EOL;
				
				if (!extension_loaded($ext) || !$curlSslOk) {
					$requiredPass = false;
				}
				
			} else {
				echo str_pad($name, 22) . '-> required  ->' . (extension_loaded($ext) ? ' [ Yes ]' : ' [ No  ]') . PHP_EOL;
				if (!extension_loaded($ext)) {
					$requiredPass = false;
				}
			}
		}
		foreach ($optional as $ext => $name) {
			echo str_pad($name, 22) . '-> optional  ->' . (extension_loaded($ext) ? ' [ Yes ]' : ' [ No  ]') . PHP_EOL;
			if (!extension_loaded($ext)) {
				$optionalPass = false;
			}
		}
		foreach ($atLastOne as $ext => $name) {
			echo str_pad($name, 22) . '-> optional  ->' . (extension_loaded($ext) ? ' [ Yes ]' : ' [ No  ]') . PHP_EOL;
			if (extension_loaded($ext)) {
				$atLastOnePass = true;
			}
		}
		echo '-----------------------------------------------------' . PHP_EOL;


		if (!$requiredPass || !$phpOk) {
			echo 'Your system is not compatible, check your php configuration.' . PHP_EOL;
			exit;
		}

		if (!$atLastOnePass) {
			echo 'Your system is not compatible, you must have a database extension.' . PHP_EOL;
			exit;
		}

		if (!$optionalPass) {
			echo 'Some optional extensions are disabled. eXpansion may encounter some performance trouble, and some features may not work.' . PHP_EOL;
		}

		// better checking if timezone is set
		if (!ini_get('date.timezone')) {
			$timezone = @date_default_timezone_get();
			echo 'Timezone is not set in php.ini. Please edit it and change/set "date.timezone" appropriately. Setting to default: \''.$timezone.'\'' . PHP_EOL;
			date_default_timezone_set($timezone);
		}

		// enable error reporting
		ini_set('display_errors', 1);
		error_reporting(E_ALL);
		gc_enable();
	}
}

?>