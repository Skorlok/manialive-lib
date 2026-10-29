<?php

namespace ManiaLive\Config;

class Loader extends \ManiaLive\Utilities\Singleton
{
	private $aliases = array(
		'config' => 'ManiaLive\\Config\\Config',
		'database' => 'ManiaLive\\Database\\Config',
		'manialive' => 'ManiaLive\\Application\\Config',
		'server' => 'ManiaLive\\DedicatedApi\\Config'
	);

	public function load($filename)
	{
		$values = parse_ini_file($filename, true);
		$values = $this->replaceAliases($values);
		$this->arrayToSingletons($values);
	}

	private function replaceAliases(array $values)
	{
		$newValues = array();
		foreach ($values as $key => $value) {
			$callback = explode('.', $key, 2);
			if (count($callback) == 2) {
				$className = reset($callback);
				$propertyName = end($callback);
				if (isset($this->aliases[$className])) {
					$className = $this->aliases[$className];
				}
				$newValues[$className . '.' . $propertyName] = $value;
			} else {
				$newValues[$key] = $value;
			}
		}
		return $newValues;
	}

	private function arrayToSingletons($values)
	{
		foreach ($values as $key => $value) {
			$callback = explode('.', $key, 2);
			if (count($callback) != 2) {
				continue;
			}
			$className = reset($callback);
			$propertyName = end($callback);
			if (class_exists($className)) {
				if (is_subclass_of($className, '\\ManiaLive\\Utilities\\Singleton')) {
					if (property_exists($className, $propertyName)) {
						$instance = call_user_func(array($className, 'getInstance'));
						$instance->$propertyName = $value;
					}
				}
			}
		}
	}
}

?>