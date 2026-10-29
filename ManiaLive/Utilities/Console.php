<?php

namespace ManiaLive\Utilities;

abstract class Console
{
	public static function println($string)
	{
		echo $string . PHP_EOL;
		Logger::runtime($string);
	}

	public static function printlnFormatted($string)
	{
		self::println('[' . date("H:i:s") . '] ' . $string);
	}
}

?>
