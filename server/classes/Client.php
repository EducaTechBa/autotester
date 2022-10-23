<?php


// AUTOTESTER - automated compiling, execution, debugging, testing and profiling
// (c) Vedran Ljubovic and others 2014-2021.
//
//     This program is free software: you can redistribute it and/or modify
//     it under the terms of the GNU General Public License as published by
//     the Free Software Foundation, either version 3 of the License, or
//     (at your option) any later version.
// 
//     This program is distributed in the hope that it will be useful,
//     but WITHOUT ANY WARRANTY; without even the implied warranty of
//     MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
//     GNU General Public License for more details.
// 
//     You should have received a copy of the GNU General Public License
//     along with this program.  If not, see <http://www.gnu.org/licenses/>.

// Server classes

// Client.php - class representing a client connecting to server

require_once("Queue.php");


class Client {
	public $id, $desc;
	private $path;
	
	// Register a new client with given description on the server
	public static function create($clientDesc) {
		global $conf_basepath;
		$clientsPath = $conf_basepath . "/clients";
	
		if (!array_key_exists('id', $clientDesc) || intval($clientDesc['id']) == 0) {
			do {
				$clientDesc['id'] = rand(1, 100000);
				$clientPath = $clientsPath . "/" . $clientDesc['id'];
			} while(file_exists($clientPath));
		} else
			$clientPath = $clientsPath . "/" . $clientDesc['id'];
		
		if (!file_exists($clientPath)) mkdir($clientPath);
		
		$output = json_encode($clientDesc, JSON_PRETTY_PRINT);
		file_put_contents( $clientPath . "/description.json", $output );
		
		$client = new Client;
		$client->id = $clientDesc['id'];
		$client->desc = $clientDesc;
		$client->path = $clientPath;
		return $client;
	}
	

	// Find existing client with given id
	public static function fromId($id) {
		global $conf_basepath;
		if (empty($id))
			throw new Exception();
		$clientPath = $conf_basepath . "/clients/$id";
		if (!file_exists($clientPath))
			throw new Exception();
		
		$clientDesc = json_decode( file_get_contents($clientPath . "/description.json"), true );
		
		$client = new Client;
		$client->id = $id;
		$client->desc = $clientDesc;
		$client->path = $clientPath;
		return $client;
	}
	
	// Update timestamp of last contact with this client
	public function updateLastTime() {
		file_put_contents( $this->path . "/last_time.txt", time() );
	}
	
	// Return timestamp of last contact with this client
	public function getLastTime() {
		if (!file_exists($this->path . "/last_time.txt"))
			return 0;
		return intval(file_get_contents( $this->path . "/last_time.txt" ));
	}
	
	// Return operation mode that server kindly requests the client to 
	// switch to
	public function getRequestedMode() {
		$file = $this->path . "/requested_mode";
		if (file_exists($file))
			return trim(file_get_contents( $file ));
		return false;
	}
	
	// Change operation mode
	public function setRequestedMode($mode) {
		$file = $this->path . "/requested_mode";
		file_put_contents($file, $mode);
		
		// Update "hibernate" option in client description
		if ($mode == "hibernate") $this->desc['hibernate'] = true; else $this->desc['hibernate'] = false;
		$output = json_encode($this->desc, JSON_PRETTY_PRINT);
		file_put_contents( $this->path . "/description.json", $output );
	}
	
	// Perform ping operation for client: call updateLastTime and return
	// next operation mode for client
	public function ping($mode = "") {
		$this->updateLastTime();
	
		$clientRequestedMode = $this->getRequestedMode(); // hibernate, awake...
		if ($clientRequestedMode == "awake")
			// Client is now awoken
			unlink($this->path . "/requested_mode");
		if ($clientRequestedMode)
			return $clientRequestedMode;
		
		// Client thinks he's hibernating
		if ($mode == "hibernate") return "awake";
		
		$queue = new Queue;
		if (empty($queue->queue))
			return "clear";
		else
			return "go";
	}
	
	// Remove all client data
	public function unregister() {
		unlink( $this->path . "/last_time.txt" );
		if (file_exists($this->path . "/requested_mode"))
			unlink( $this->path . "/requested_mode" );
		unlink( $this->path . "/description.json" );
		rmdir( $this->path );
	}
	
	// Static method that returns a list of all known clients
	// If parameter is true, method returns Client objects
	// Otherwise it returns a 
	public static function listClients( $objects = false ) {
		global $conf_basepath, $conf_client_timeout;
		$clients = [];
		foreach( scandir( $conf_basepath . "/clients" ) as $entry ) {
			if ($entry == "." || $entry == "..") continue;
			try {
				$client = Client::fromId($entry);
			} catch(Exception $e) {
				continue;
			}
			if ($objects)
				$clients[] = $client;
			else {
				$time = $client->getLastTime();
				if ($time == 0) continue; // unregistered client
			
				$clientData = array( "id" => $entry, "name" => $client->desc['name'], "time" => $time );
				if ($client->desc['hibernate']) $clientData['mode'] = "hibernate";
				else if ($client->getRequestedMode()) $clientData['mode'] = $client->getRequestedMode();
				$clients[] = $clientData;
			}
		}
		return $clients;
	}
	
	// Remove clients that are not responding to pings for more than $age seconds
	public static function removeOldClients($age) {
		$clients = self::listClients(true);
		$removed = 0;
		$queue = new Queue;
		$now = time();
		
		foreach($clients as &$client) {
			if ($now - $client->getLastTime() > $age) {
				$queue->removeClient($client);
				$client->unregister();
				$removed++;
			}
		}
		$queue->writeQueue();
		
		return array( "removed" => $removed );
	}
}
