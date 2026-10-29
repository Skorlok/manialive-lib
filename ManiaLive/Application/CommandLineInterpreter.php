<?php

namespace ManiaLive\Application;

abstract class CommandLineInterpreter
{
	public static function preConfigLoad()
	{
		$options = getopt("", array('help::', 'manialive_cfg::'));

		$help = 'ManiaLive' . PHP_EOL
		. 'Usage: php bootstrapper.php [args]' . PHP_EOL
		. 'Arguments:' . PHP_EOL
		. '  --help              - displays the present help' . PHP_EOL
		. '  --manialive_cfg=xxx - xxx represents the config file for manialive in config/ folder' . PHP_EOL;

		if (isset($options['help'])) {
			echo $help;
			exit;
		}

		if (isset($options['manialive_cfg'])) {
			return $options['manialive_cfg'];
		} else {
			return 'config.ini';
		}
	}
}
