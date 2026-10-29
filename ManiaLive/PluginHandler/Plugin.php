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

namespace ManiaLive\PluginHandler;

use ManiaLive\Event\Dispatcher;
use ManiaLive\Application\Listener as AppListener;
use ManiaLive\Application\Event as AppEvent;
use ManiaLive\DedicatedApi\Callback\Adapter as ServerAdapter;
use ManiaLive\DedicatedApi\Callback\Event as ServerEvent;
use ManiaLive\Features\Tick\Listener as TickListener;
use ManiaLive\Features\Tick\Event as TickEvent;
use ManiaLive\PluginHandler\Listener as PluginListener;
use ManiaLive\PluginHandler\Event as PluginEvent;
use ManiaLive\Database\Connection as DbConnection;
use Maniaplanet\DedicatedServer\Connection;
use ManiaLive\Features\ChatCommand\Interpreter;
use ManiaLive\Features\ChatCommand\Command;

/**
 * Extend this class to create a Plugin that can be used with the PluginHandler.
 * This will also provide function shortcuts for registering chat commands,
 * dependency handling and the possibility of Plugin communication.
 */
abstract class Plugin extends ServerAdapter implements TickListener, AppListener, PluginListener
{
	/** @var string */
	private $id;
	/** @var mixed */
	private $version;
	/** @var Dependency[] */
	private $dependencies;
	/**
	 * Event subscriber swichtes
	 */
	private $eventsApplication = 0;
	private $eventsServer = 0;
	private $eventsPlugins = 0;
	/** @var \ManiaLive\PluginHandler\PluginHandler */
	private $pluginHandler;
	/** @var \ReflectionMethod[] */
	private $methods;
	/** @var \ManiaLive\Features\ChatCommand\Command[] */
	private $chatCommands;
	/** @var Connection */
	protected $connection;
	/** @var DbConnection */
	protected $db;

	final function __construct()
	{
		$this->dependencies = array();
		$this->methods = array();

		$this->id = "\\".get_class($this);
		$this->setVersion(1);

		/** @var \ManiaLive\DedicatedApi\Config $config */
		$config = \ManiaLive\DedicatedApi\Config::getInstance();
		$this->connection = Connection::factory($config->host, $config->port, $config->timeout, $config->user, $config->password);
		$this->pluginHandler = PluginHandler::getInstance();
		$this->chatCommands = array();
	}

	/**
	 * Sets the current version number for this Plugin.
	 * Can only be used during initialization!
	 * @param mixed $version
	 * @throws \InvalidArgumentException
	 */
	final protected function setVersion($version)
	{
		$this->version = $version;
	}

	/**
	 * Returns the version number of the Plugin.
	 * @return mixed
	 */
	final public function getVersion()
	{
		return $this->version;
	}

	/**
	 * Returns full qualified class of plugin
	 * @return string
	 */
	final public function getId()
	{
		return $this->id;
	}

	/**
	 * Adds a Dependency to the Plugin.
	 * Can only be used during initialisation!
	 * @param ManiaLive\PluginHandler\Dependency $dependency
	 */
	final public function addDependency(Dependency $dependency)
	{
		$this->dependencies[] = $dependency;
	}

	/**
	 * Returns an array of all known dependencies of this Plugin.
	 * @return array[ManiaLive\PluginHandler\Dependency]
	 */
	final public function getDependencies()
	{
		return $this->dependencies;
	}

	/**
	 * Declare this method as public.
	 * It then can be called by other Plugins.
	 * @param string $name The name of the method you want to expose.
	 * @throws Exception
	 */
	final protected function setPublicMethod($name)
	{
		try
		{
			$method = new \ReflectionMethod($this, $name);
			if(!$method->isPublic())
				throw new Exception('The method "'.$name.'" must be declared as public!');
			$this->methods[$name] = $method;
		}
		catch(\ReflectionException $ex)
		{
			throw new Exception('The method "'.$name.'" does not exist and therefore can not be exposed!');
		}
	}

	/**
	 * Calls a public method of the specified plugin.
	 * The method has been marked as public by the owner.
	 * The plugin has to be registered at the plugin handler.
	 * @param string $plugin_name
	 * @param string $method
	 */
	final protected function callPublicMethod($pluginId, $method)
	{
		$this->restrictIfUnloaded();
		return $this->pluginHandler->callPublicMethod($this, $pluginId, $method, array_slice(func_get_args(), 2));
	}

	/**
	 * Gets a method, that has been marked as public, from this Plugin.
	 * This method will be invoked by the Plugin Handler.
	 * If you want to call a method from another Plugin, then use the internal callPublicMethod function.
	 * @param string $method
	 * @return \ReflectionMethod
	 * @throws Exception
	 */
	final public function getPublicMethod($method)
	{
		if(isset($this->methods[$method]))
			return $this->methods[$method];
		else
			throw new Exception('The method "'.$method.'" does not exist or has not been set public in plugin "'.$this->id.'"!');
	}

	/**
	 * This method can be used to restrict a call to a specific method
	 * until the plugin has been loaded successfully!
	 * @throws \Exception
	 */
	private function restrictIfUnloaded()
	{
		if(!$this->isLoaded())
		{
			$trace = debug_backtrace();
			throw new \Exception("The method '{$trace[1]['function']}' can not be called before the Plugin '".$this->getId()."' has been loaded!");
		}
	}

	/**
	 * Checks whether the current plugin has been loaded.
	 * @return bool
	 */
	final public function isLoaded()
	{
		return $this->isPluginLoaded($this->getId());
	}

	/**
	 * Is the plugin currently loaded or not?
	 * @param string $name
	 * @return bool
	 */
	final public function isPluginLoaded($pluginId, $min = Dependency::NO_LIMIT, $max = Dependency::NO_LIMIT)
	{
		return $this->pluginHandler->isLoaded($pluginId, $min, $max);
	}

	// Helpers
	final protected function enableDatabase()
	{
		/** @var \ManiaLive\Database\Config $config */
		$config = \ManiaLive\Database\Config::getInstance();
		$this->db = DbConnection::getConnection(
				$config->host,
				$config->username,
				$config->password,
				$config->database,
				$config->type,
				$config->port
		);
	}

	/**
	 * Start invoking methods for application intern events which are
	 * onInit, onRun, onPreLoop, onPostLoop, onTerminate
	 */
	final protected function enableApplicationEvents($events = AppEvent::ALL)
	{
		$this->restrictIfUnloaded();

		Dispatcher::register(AppEvent::getClass(), $this, $events & ~$this->eventsApplication);
		$this->eventsApplication |= $events;
	}

	/**
	 * Stop listening for application events.
	 */
	final protected function disableApplicationEvents($events = AppEvent::ALL)
	{
		$this->restrictIfUnloaded();

		Dispatcher::unregister(AppEvent::getClass(), $this, $events & $this->eventsApplication);
		$this->eventsApplication &= ~$events;
	}

	/**
	 * Start invoking the ticker method (onTick) every second.
	 */
	final protected function enableTickerEvent()
	{
		$this->restrictIfUnloaded();

		Dispatcher::register(TickEvent::getClass(), $this);
	}

	/**
	 * Stop listening for the ticker event.
	 */
	final protected function disableTickerEvent()
	{
		$this->restrictIfUnloaded();

		Dispatcher::unregister(TickEvent::getClass(), $this);
	}

	/**
	 * Start invoking methods for extern dedicated server events which are
	 * the callbacks described in the ListCallbacks.html which you have retrieved with your
	 * dedicated server.
	 * Otherwise you can find an online copy here http://server.xaseco.org/callbacks.php
	 */
	final protected function enableDedicatedEvents($events = ServerEvent::ALL)
	{
		$this->restrictIfUnloaded();

		Dispatcher::register(ServerEvent::getClass(), $this, $events & ~$this->eventsServer);
		$this->eventsServer |= $events;
	}

	/**
	 * Stop listening for dedicated server events.
	 */
	final protected function disableDedicatedEvents($events = ServerEvent::ALL)
	{
		$this->restrictIfUnloaded();

		Dispatcher::unregister(ServerEvent::getClass(), $this, $events & $this->eventsServer);
		$this->eventsServer &= ~$events;
	}

	/**
	 * Start listen for plugin events like
	 * onPluginLoaded and onPluginUnloaded
	 */
	final protected function enablePluginEvents($events = PluginEvent::ALL)
	{
		$this->restrictIfUnloaded();

		Dispatcher::register(PluginEvent::getClass(), $this, $events & ~$this->eventsPlugins);
		$this->eventsPlugins |= $events;
	}

	/**
	 * stop to listen for plugin events.
	 */
	final protected function disablePluginEvents($events = PluginEvent::ALL)
	{
		$this->restrictIfUnloaded();

		Dispatcher::unregister(PluginEvent::getClass(), $this, $events & $this->eventsPlugins);
		$this->eventsPlugins &= ~$events;
	}

	/**
	 * Registers a chatcommand at the Interpreter.
	 * @param string $name
	 * @param integer $parameterCount
	 * @param string $method
	 * @param bool $addLogin
	 * @param array[string] $authorizedLogin
	 * @return \ManiaLive\Features\ChatCommand\Command
	 */
	final function registerChatCommand($name, $method, $parameterCount = 0, $addLogin = false, $authorizedLogin = array())
	{
		$this->restrictIfUnloaded();
		$cmd = new Command($name, $parameterCount, $authorizedLogin);
		$cmd->callback = array($this, $method);
		$cmd->addLoginAsFirstParameter = $addLogin;
		$cmd->isPublic = true;
		Interpreter::getInstance()->register($cmd);
		$this->chatCommands[] = $cmd;

		return $cmd;
	}

	/**
	 * This will unregister all chat commands that have been
	 * created using the plugins method registerChatCommand.
	 */
	final public function unregisterAllChatCommands()
	{
		while($command = array_pop($this->chatCommands))
			Interpreter::getInstance()->unregister($command);
	}

        final public function unregisterChatCommand($name){
            $toRemove = -1;
            foreach($this->chatCommands as $key => $command){
                if($command->name == $name){
                    Interpreter::getInstance()->unregister($command);
                    $toRemove = $key;
                }
            }

            if($toRemove != -1){
                unset($this->chatCommands[$toRemove]);
            }
        }

	// LISTENERS
	// plugin events
	function onInit() {}
	function onLoad() {}
	function onReady() {}

	/**
	 * If you override this method you might want to
	 * call the parent's onUnload as well, as it does some
	 * useful stuff!
	 * Use this method to remove any windows that are
	 * currently displayed by the plugin, you might also need to
	 * destroy some objects that have been created without using the
	 * plugin intern methods.
	 */
	function onUnload()
	{
		$this->disableApplicationEvents();
		$this->disableDedicatedEvents();
		$this->disableTickerEvent();
		$this->disablePluginEvents();

		$this->unregisterAllChatCommands();

		$this->methods = array();
	}

	// application events
	function onRun() {}
	function onPreLoop() {}
	function onPostLoop() {}
	function onTerminate() {}

	// ticker event
	function onTick() {}

	// plugin events
	function onPluginLoaded($pluginId) {}
	function onPluginUnloaded($pluginId) {}
}

?>
