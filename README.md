ManiaLive
=========

[![Latest Stable Version](https://poser.pugx.org/skorlok/manialive-lib/v/stable)](https://packagist.org/packages/skorlok/manialive-lib)
[![License](https://poser.pugx.org/skorlok/manialive-lib/license)](https://packagist.org/packages/skorlok/manialive-lib)

ManiaLive is the application core of the [eXpansion](https://github.com/Skorlok/eXpansion) server controller for ManiaPlanet: main loop, configuration, connection to the dedicated server, plugin handler, events, chat commands, database and logs.

This package is a fork of [maniaplanet/manialive-lib](https://github.com/maniaplanet/manialive-lib) by Nadeo, maintained **exclusively for eXpansion**. It loads eXpansion's core plugins directly, so it can't be used on its own. The namespace is unchanged (`ManiaLive\`), so it can't be installed together with `maniaplanet/manialive-lib`.

Differences with the original ManiaLive
---------------------------------------

The parts of the original ManiaLive that eXpansion replaces have been removed, mainly:
* `ManiaLive\Gui\*` (windows, controls, manialinks): use eXpansion's `ManiaLivePlugins\eXpansion\Gui` instead.
* `ManiaLive\Data\Storage`, `ManiaLive\Data\Player` and their events: use eXpansion's `ManiaLivePlugins\eXpansion\Helpers\Storage` instead.

Old ManiaLive plugins using these classes must be updated to work with eXpansion.

Installation
------------

You don't need to install it: it is installed by Composer as a dependency of eXpansion.

Requirements:
* PHP >= 5.4
* `ext-curl` (with SSL), `ext-json`, `ext-mbstring`, `ext-simplexml`, `ext-xml`
* `ext-mysqli` or `ext-sqlite3`
* `ext-com_dotnet` on Windows
* `ext-pcntl` is optional, on Linux: it allows a clean shutdown on `SIGTERM` and `SIGINT`.

License
-------

LGPL v3. Original work by Nadeo.
