<?php

/*
 * libasynql_v3
 *
 * Copyright (C) 2018 SOFe
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
 */

declare(strict_types=1);

namespace poggit\libasynql;

use pocketmine\plugin\Plugin;
use pocketmine\utils\Terminal;
use pocketmine\utils\Utils;
use poggit\libasynql\base\DataConnectorImpl;
use poggit\libasynql\base\QueryRecvQueue;
use poggit\libasynql\base\QuerySendQueue;
use poggit\libasynql\base\SqlThreadPool;
use poggit\libasynql\mysqli\MysqlCredentials;
use poggit\libasynql\mysqli\MysqliThread;
use poggit\libasynql\sqlite3\Sqlite3Thread;
use function extension_loaded;
use function is_array;
use function is_string;
use function strtolower;
use function usleep;

/**
 * An utility class providing convenient access to the API
 */
final class libasynql{
	/** @var bool */
	private static $packaged;

	public static function isPackaged() : bool{
		return self::$packaged;
	}

	public static function detectPackaged() : void{
		self::$packaged = __CLASS__ !== 'poggit\libasynql\libasynql';

		if(!self::$packaged){
			echo Terminal::$COLOR_YELLOW . "Warning: Use of unshaded libasynql detected. This may lead to performance drop. Do not do so in production.\n";
		}
	}

	private function __construct(){
	}

	/**
	 * Create a {@link DatabaseConnector} from a plugin and a config entry, and initializes it with the relevant SQL files according to the selected dialect
	 *
	 * @param Plugin              $plugin     the plugin using libasynql
	 * @param mixed               $configData the config entry for database settings
	 * @param string[]|string[][] $sqlMap     an associative array with key as the SQL dialect ("mysql", "sqlite") and value as a string or string array indicating the relevant SQL files in the plugin's resources directory
	 * @return DataConnector
	 * @throws ConfigException if the config is invalid such that it is impossible to create a proper database connection
	 * @throws SqlError if the connection could not be created
	 */
	public static function create(Plugin $plugin, $configData, array $sqlMap) : DataConnector{
		if(!is_array($configData)){
			throw new ConfigException("Database settings are missing or incorrect");
		}

		$type = (string) $configData["type"];
		if($type === ""){
			throw new ConfigException("Database type is missing");
		}

		$pdo = ($configData["prefer-pdo"] ?? false) && extension_loaded("pdo");

		$dialect = null;
		$placeHolder = null;
		switch(strtolower($type)){
			case "sqlite":
			case "sqlite3":
			case "sq3":
				if(!$pdo && !extension_loaded("sqlite3")){
					throw new ExtensionMissingException("sqlite3");
				}

				$fileName = self::resolvePath($plugin->getDataFolder(), $configData["sqlite"]["file"] ?? "data.sqlite");
				if($pdo){
					// TODO add PDO support
				}else{
					$factory = function(QuerySendQueue $send, QueryRecvQueue $recv) use ($fileName){
						return new Sqlite3Thread($fileName, $send, $recv);
					};
				}
				$dialect = "sqlite";
				break;
			case "mysql":
			case "mysqli":
				if(!$pdo && !extension_loaded("mysqli")){
					throw new ExtensionMissingException("mysqli");
				}

				if(!isset($configData["mysql"])){
					throw new ConfigException("Missing MySQL settings");
				}

				$cred = MysqlCredentials::fromArray($configData["mysql"], strtolower($plugin->getName()));

				if($pdo){
					// TODO add PDO support
				}else{
					$factory = function(QuerySendQueue $send, QueryRecvQueue $recv) use ($cred){
						return new MysqliThread($cred, $send, $recv);
					};
					$placeHolder = "?";
				}
				$dialect = "mysql";

				break;
		}

		if(!isset($dialect, $factory, $sqlMap[$dialect])){
			throw new ConfigException("Unsupported database type \"$type\". Try \"sqlite\" or \"mysql\".");
		}

		$pool = new SqlThreadPool($factory, $configData["worker-limit"] ?? 1);
		while(!$pool->connCreated()){
			usleep(1000);
		}
		if($pool->hasConnError()){
			throw new SqlError(SqlError::STAGE_CONNECT, $pool->getConnError());
		}

		$connector = new DataConnectorImpl($plugin, $pool, $placeHolder);
		foreach(is_string($sqlMap[$dialect]) ? [$sqlMap[$dialect]] : $sqlMap[$dialect] as $file){
			$connector->loadQueryFile($plugin->getResource($file));
		}
		return $connector;
	}

	private static function resolvePath(string $folder, string $path) : string{
		if($path{0} === "/"){
			return $path;
		}
		if(Utils::getOS() === "win"){
			if($path{0} === "\\" || $path{1} === ":"){
				return $path;
			}
		}
		return $folder . $path;
	}
}

libasynql::detectPackaged();