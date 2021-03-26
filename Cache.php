<?php
#####################################################################################################################
if(!class_exists("CACHE")){
	class CACHE{
#####################################################################################################################
		const VERSION = "1.8";

		public static $config = [
			"path" 				=> __DIR__."/cache/storage/",
			"use.memcached" 	=> true,
			"memcached.host" 	=> "localhost",
			"memcached.port" 	=> 11211,
			"prefix.key" 		=> "cache/",
			"prefix.file" 		=> "cache.",
			"default.time" 		=> 60,
			"db"				=> null,
		];

		public static $memcached = null;
		public static $db = null;

		static $methods = [];

#####################################################################################################################
		public static function __callStatic($name, $args){
			if(is_callable(self::$methods[$name])){
				return call_user_func_array(self::$methods[$name], $args);
			}
			else{
				throw new Exception("Fatal error: Call to undefined method ".__CLASS__."::".$name);
			}
		}
#####################################################################################################################
		public static function addMethod($name, $function){
			if(is_callable(self::$methods[$name])){
				throw new Exception("Fatal error: Method ".__CLASS__."::".$name." already exists.");
			}
			else{
				self::$methods[$name] = $function;
			}
		}
#####################################################################################################################
		public function __construct($options=null){
			if(is_array($options)){
				foreach($options as $k => $v){
					if(isset($options[$k])){
						self::$config[$k] = $options[$k];
					}
				}
			}


			if(self::$config["use.memcached"] === true){
				if(class_exists("memcached")){
					$m = new \Memcached();
					if($m->addServer(self::$config["memcached.host"], self::$config["memcached.port"]) === true){
						self::$memcached = $m;
					}
					else{
						self::$config["use.memcached"] = false;
					}
				}
				else{
					self::$config["use.memcached"] = false;
				}
			}
			else{
				self::$config["use.memcached"] = false;
			}


			if(self::$config["db"] !== null){
				if(class_exists("Doctrine\\DBAL\\DriverManager")){
					self::$db = self::$config["db"];
				}
			}

/*
			if(!is_writable(self::$config["path"])){
				if(file_exists(self::$config["path"])){
					if(@chmod(self::$config["path"], 0777) !== true){
						throw new Exception("Path is not writable: ".self::$config["path"]);
					}
				}
				else{
					if(@mkdir(self::$config["path"], 0777, true) !== true){
						throw new Exception("Can't create path: ".self::$config["path"]);
					}
				}
			}

			if(substr(self::$config["path"], -1) !== "/"){
				self::$config["path"] = self::$config["path"]."/";
			}
*/
		}
#####################################################################################################################
		public static function getConfig(){
			return self::$config;
		}
#####################################################################################################################
		public static function getVersion(){
			return self::VERSION;
		}
#####################################################################################################################
		public static function getMemcachedVersion(){
			if(self::$config["use.memcached"] !== false){
				return preg_replace("/[^0-9\.]/", "", implode("", self::$memcached->getVersion()));
			}

			return false;
		}
#####################################################################################################################
		public static function validateKey($key){
			$key = str_replace(	["ä",  "Ä",  "ö",  "Ö",  "ü",  "Ü",  "ß",  "é", "è", "É", "È"],
								["ae", "AE", "oe", "OE", "ue", "UE", "ss", "e", "e", "E", "E"], $key);
			$key = preg_replace("/[^a-zA-Z0-9 \.\,\-\_\/\:]/", " ", $key);
			$key = str_replace([" ", "\t", "\n", "\r"], "", $key);
			$key = trim($key);

			if(substr($key, 0, strlen(self::$config["prefix.key"])) !== self::$config["prefix.key"]){
				$key = self::$config["prefix.key"].$key;
			}

			return $key;
		}
#####################################################################################################################
		public static function validateTime($t){
			if(is_numeric($t) and $t > time()){
				return $t - time();
			}

			if(is_numeric($t) and $t <= (60*60*24*365)){
				return $t;
			}

			if(($result = strtotime($t)) !== false){
				return $result;
			}

			return self::$config["default.time"];
		}
#####################################################################################################################
		public static function encode($value, $options=null){
			if(isset($options["tags"])){
				$tags = is_array($options["tags"]) ? $options["tags"] : array($options["tags"]);
			}

			return serialize([	"data" 				=> $value,
								"creation_date" 	=> time(),
								"expiration_date"	=> isset($options["expiration_date"]) ? $options["expiration_date"] : null,
								"tags" 				=> isset($tags) ? $tags : null,
								"version" 			=> self::VERSION,
							]);
		}
#####################################################################################################################
		public static function decode($value, $data_only=false){
			$value = unserialize($value);

			if(!isset($value["expiration_date"]) or $value["expiration_date"] === false or $value["expiration_date"] === null){
				$value["is_expired"] = false;
			}
			else{
				if($value["expiration_date"] >= time()){
					$value["is_expired"] = false;
				}
				else{
					$value["is_expired"] = true;
				}
			}

			return ($data_only === true) ? $value["data"] : $value;
		}
#####################################################################################################################
		public static function has($key){
			$key = self::validateKey($key);

			if(self::$config["use.memcached"] !== false){
				$result = self::$memcached->get($key);
				return self::$memcached->getResultCode() !== \Memcached::RES_NOTFOUND; // (boolean) true or false
			}

/*
			if(file_exists(self::filename($key))){
				$result = self::decode(@file_get_contents(self::filename($key)));
				if($result["is_valid"] === true){
					return true;
				}
			}
*/

			return false;
		}
#####################################################################################################################
		public static function set($key, $value=null, $time=false, $tags=[]){
			$key 	= self::validateKey($key);
			$time 	= self::validateTime($time);
			$value 	= self::encode($value, ["expiration_date" => time()+$time, "tags" => $tags]);


/*
			if(@file_put_contents(self::filename($key), $value) === false){
				throw new Exception("Can't write: ".self::filename($key));
			}
			else{
				@touch(self::filename($key), time()+$time);
			}
*/


			if(self::$config["use.memcached"] !== false){
				$allKeys = self::$memcached->get(self::validateKey("/:::KEYS"));
				$allKeys = is_array($allKeys) ? $allKeys : [];
				$allKeys[$key] = null;
				self::$memcached->set(self::validateKey("/:::KEYS"), $allKeys, time()+60*60*24*365);

				return self::$memcached->set($key, $value, $time);
			}

			return false;
		}
#####################################################################################################################
		public static function add($key, $value=null, $time=false, $tags=[]){
			$key 	= self::validateKey($key);
			$time 	= self::validateTime($time);
			$value 	= self::encode($value, ["expiration_date" => time()+$time, "tags" => $tags]);


/*
			if(!file_exists(self::filename($key))){
				if(@file_put_contents(self::filename($key), $value) === false){
					throw new Exception("Can't write: ".self::filename($key));
				}
				else{
					@touch(self::filename($key), time()+$time);
				}
			}
			else{
				$status_file = false;
			}
*/


			if(self::$config["use.memcached"] !== false){
				$allKeys = self::$memcached->get(self::validateKey("/:::KEYS"));
				$allKeys = is_array($allKeys) ? $allKeys : [];
				$allKeys[$key] = null;
				self::$memcached->set(self::validateKey("/:::KEYS"), $allKeys, time()+60*60*24*365);

				return self::$memcached->add($key, $value, $time);
			}

			return false;
		}
#####################################################################################################################
		public static function pull($key){
			$key 	= self::validateKey($key);

			if(self::$config["use.memcached"] !== false){
				$result = self::$memcached->get($key);
				if(self::$memcached->getResultCode() !== \Memcached::RES_NOTFOUND){
					$response = self::decode($result, true);
					self::delete($key);

					return $response;
				}
			}

			return false;
		}
#####################################################################################################################
		public static function get($key){
			$key 	= self::validateKey($key);

			if(self::$config["use.memcached"] !== false){
				$result = self::$memcached->get($key);
				if(self::$memcached->getResultCode() !== \Memcached::RES_NOTFOUND){
					return self::decode($result, true);
				}
			}

/*
			if(file_exists(self::filename($key))){
				$result = self::decode(@file_get_contents(self::filename($key)));
				if($result["is_expired"] === false){
					return $result["data"];
				}
				else{
					@unlink(self::filename($key));
				}
			}
*/

			return false;
		}
#####################################################################################################################
		public static function getAllKeys(){
			$key 	= self::validateKey("");

			if(self::$config["use.memcached"] !== false){
				foreach(self::$memcached->getAllKeys() as $entry){
					if(substr($entry, 0, strlen($key)) === $key){
						$entries[] = $entry;
					}
				}


				$req = self::$memcached->get(self::validateKey("/:::KEYS"));
				foreach($req as $entry => $value){
					if(substr($entry, 0, strlen($key)) === $key){
						if(self::has($entry) === true){
							$entries[] = $entry;
						} else{
							$allKeys = self::$memcached->get(self::validateKey("/:::KEYS"));
							$allKeys = is_array($allKeys) ? $allKeys : [];
							unset($allKeys[$entry]);
							self::$memcached->set(self::validateKey("/:::KEYS"), $allKeys, time()+60*60*24*365);
						}
					}
				}


				if(is_array($entries)){
					$entries = array_unique($entries);
					natsort($entries);
				}


				if(isset($_GET["cache-debug"])){
					var_dump(self::$memcached->get(self::validateKey("/:::KEYS")));
					die;
				}


				return is_array($entries) ? $entries : [];
			}

			return false;
		}
#####################################################################################################################
		public static function findKeys($key){
			$key 	= self::validateKey($key);

			if(self::$config["use.memcached"] !== false){
				foreach(self::getAllKeys() as $entry){
					if(substr($entry, 0, strlen($key)) === $key){
						$entries[] = $entry;
					}
				}

				return is_array($entries) ? $entries : [];
			}

			return false;
		}
#####################################################################################################################
		public static function touch($key, $time=false){
			$key 	= self::validateKey($key);
			$time 	= self::validateTime($time);

			if(self::$config["use.memcached"] !== false){
				return self::$memcached->touch($key, $time);
			}

			return false;
		}
#####################################################################################################################
		public static function delete($key){
/*
			if(file_exists(self::filename($key))){
				if(@unlink(self::filename($key)) !== true){
					throw new Exception("Can't delete: ".self::filename($key));
					return false;
				}
			}
*/

			if(self::$config["use.memcached"] !== false){
				if(substr($key, -1) === "*"){
					foreach(self::findKeys($key) as $entry){
						$entries[] = $entry;
						self::$memcached->delete($entry);

						$allKeys = self::$memcached->get(self::validateKey("/:::KEYS"));
						$allKeys = is_array($allKeys) ? $allKeys : [];
						unset($allKeys[$entry]);
						self::$memcached->set(self::validateKey("/:::KEYS"), $allKeys, time()+60*60*24*365);
					}

					return is_array($entries) ? $entries : [];
				}
				else{
					$key = self::validateKey($key);

					$allKeys = self::$memcached->get(self::validateKey("/:::KEYS"));
					$allKeys = is_array($allKeys) ? $allKeys : [];
					unset($allKeys[$key]);
					self::$memcached->set(self::validateKey("/:::KEYS"), $allKeys, time()+60*60*24*365);

					return self::$memcached->delete($key);
				}
			}

			return false;
		}
#####################################################################################################################
		public static function remove($key){ // alias
			return self::delete($key);
		}
#####################################################################################################################
		public static function quit(){
			if(self::$config["use.memcached"] !== false){
				return self::$memcached->quit();
			}
			return false;
		}
#####################################################################################################################
		public static function close(){ // alias
			return self::quit();
		}
#####################################################################################################################
		public static function glob($dir, $time=false, $key=false){
			$time 	= self::validateTime($time);

			if($key === false or empty($key)){
				$key = "glob/".sha1($dir);
			}
			$key = self::validateKey($key);


			if(self::has($key) and ($get = self::get($key)) !== false){
				return $get;
			}
			else{
				$value = glob($dir);
				if($value !== false){
					self::set($key, $value, $time);
				}
				return $value;
			}
		}
#####################################################################################################################
		public static function file_get_contents($file, $time=false, $key=false){
			$time 	= self::validateTime($time);

			if($key === false or empty($key)){
				$key = "file_get_contents/".sha1($dir);
			}
			$key = self::validateKey($key);


			if(self::has($key) and ($get = self::get($key)) !== false){
				return $get;
			}
			else{
				$value = @file_get_contents($file);
				if($value !== false){
					self::set($key, $value, $time);
				}
				return $value;
			}
		}
#####################################################################################################################
		public static function fetchColumn($statement, $bind=false, $column=0, $time=false, $key=false){
			if(self::$db === null){
				throw new Exception("Database instance required.");
			}

			$time 	= self::validateTime($time);
			$bind 	= is_array($bind) ? $bind : [];

			if($key === false or empty($key)){
				$key = "fetchcolumn/".sha1($statement.http_build_query($bind).$column);
			}
			$key = self::validateKey($key);


			if(self::has($key) and ($get = self::get($key)) !== false){
				return $get;
			}
			else{
				$value = self::$db->fetchColumn($statement, $bind, $column);
				if($value !== false){
					self::set($key, $value, $time);
				}
				return $value;
			}
		}
#####################################################################################################################
		public static function fetchAssoc($statement, $bind=false, $time=false, $key=false){
			if(self::$db === null){
				throw new Exception("Database instance required.");
			}

			$time 	= self::validateTime($time);
			$bind 	= is_array($bind) ? $bind : [];

			if($key === false or empty($key)){
				$key = "fetchassoc/".sha1($statement.http_build_query($bind));
			}
			$key = self::validateKey($key);


			if(self::has($key) and ($get = self::get($key)) !== false){
				return $get;
			}
			else{
				$value = self::$db->fetchAssoc($statement, $bind);
				if($value !== false){
					self::set($key, $value, $time);
				}
				return $value;
			}
		}
#####################################################################################################################
		public static function fetchAll($statement, $bind=false, $time=false, $key=false){
			if(self::$db === null){
				throw new Exception("Database instance required.");
			}

			$time 	= self::validateTime($time);
			$bind 	= is_array($bind) ? $bind : [];

			if($key === false or empty($key)){
				$key = "fetchall/".sha1($statement.http_build_query($bind));
			}
			$key = self::validateKey($key);


			if(self::has($key) and ($get = self::get($key)) !== false){
				return $get;
			}
			else{
				$value = self::$db->fetchAll($statement, $bind);
				if($value !== false){
					self::set($key, $value, $time);
				}
				return $value;
			}
		}
#####################################################################################################################
		public static function fetchTableExists($statement, $time=false, $key=false){
			if(self::$db === null){
				throw new Exception("Database instance required.");
			}

			$time 	= self::validateTime($time);

			if($key === false or empty($key)){
				$key = "fetchtableexists/".sha1($statement);
			}
			$key = self::validateKey($key);


			if(self::has($key) and ($get = self::get($key)) !== false){
				return $get;
			}
			else{
				$value = (self::$db->executeQuery($statement)->rowCount() == 1) ? true : false;
				self::set($key, $value, $time);
				return $value;
			}
		}
#####################################################################################################################
	}
}
