<?php


// AUTOTESTER - automated compiling, execution, debugging, testing and profiling
// (c) Vedran Ljubovic and others 2014-2023.
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

// Queue.php - class representing queue of tasks on server

// TODO: convert into singleton? There is no need to have multiple queues

require_once("Client.php");
require_once("Task.php");
require_once("Program.php");


// Class Queue contains three queues: 
// $queue : unfinished tasks
// $assigned : tasks that are currently being processed by a client
// $finished : tasks that have been finished

class Queue {
	public $queue = [], $assigned = [], $finished = [], $rejected = [], $rejectedTasks = [];
	private $queueFile, $rejectedFile;
	
	/* Queue file format: three columns separated by slash (/) character
	First column: numerical id of task
	Second column: numerical id of program
	Third column: either numerical id of client currently processing the task, letter Q for queued or F for finished
	*/
	
	public function __construct() {
		global $conf_basepath, $conf_client_timeout, $conf_autowake;
		
		$this->queueFile = $conf_basepath . "/queue";
		if (!file_exists($this->queueFile)) return [];
		
		$this->rejectedFile = $conf_basepath . "/rejected";
		
		// Read queue, with locking
		$queueFp = fopen($this->queueFile, "r");
		if (flock($queueFp, LOCK_SH)) {
			while ($line = fgets($queueFp)) {
				list($task, $program, $status) = explode("/", trim($line));
				if (intval($task) == 0) continue;
				if ($status == "Q")
					$this->queue[] = array( "task" => $task, "program" => $program );
				else if ($status == "F")
					$this->finished[] = array( "task" => $task, "program" => $program );
				else
					$this->assigned[] = array( "task" => $task, "program" => $program, "client" => $status);
			}
			
			// Read list of programs that were rejected by clients and populate attribute arrays
			if (file_exists($this->rejectedFile)) {
				foreach(file($this->rejectedFile) as $line) {
					list($program, $client) = explode("/", trim($line));
					if (substr($program, 0, 1) == "T")
						$this->rejectTask(substr($program,1), $client);
					else
						$this->reject($program, $client);
				}
			}
			flock($queueFp, LOCK_UN);
		} else {
			throw new Exception("Failed to get file lock");
		}
		fclose($queueFp);
		
		// Look for clients that are not awake i.e. taking too long to respond
		$clients = Client::listClients( true );
		$hibernating = [];
		$awakeCount = 0;
		foreach($clients as $client) {
			$isAwake = false;
			if ($client->desc['hibernate'])
				$hibernating[] = $client;
			else if ($conf_client_timeout == 0 || time() - $client->getLastTime() <= $conf_client_timeout) {
				$isAwake = true;
				$awakeCount++;
			}
			
			// Are there tasks assigned to client who is not awake?
			foreach($this->assigned as $item)
				if ($item['client'] == $client->id && !$isAwake)
					$this->removeClient($client);
		}
		
		// autoawake
		if ($conf_autowake && !empty($this->queue) && $awakeCount == 0 && !empty($hibernating))
			$hibernating[0]->setRequestedMode("awake");
		
		return $this->queue;
	}
	
	// Add task and program to queue
	public function add($taskId, $programId, $same = false) {
		// Don't add again if already queued for testing
		foreach($this->queue as $item)
			if ($item['program'] == $programId)
				return;
				
		// Retest if assigned or finished (assumption is that the sourcecode is changed)
		foreach($this->assigned as $key => $item)
			if ($item['program'] == $programId)
				unset($this->assigned[$key]);
		foreach($this->finished as $key => $item)
			if ($item['program'] == $programId)
				if ($same) // If task is finished and same, just return old results
					return;
				else 
					unset($this->finished[$key]);
		
		$this->queue[] = array( "task" => $taskId, "program" => $programId );
		$this->writeQueue();
	}
	
	// Remove program from queue
	public function remove($programId) {
		// Don't add again if already queued for testing
		foreach($this->queue as $key => $item)
			if ($item['program'] == $programId)
				unset($this->queue[$key]);
		foreach($this->assigned as $key => $item)
			if ($item['program'] == $programId)
				unset($this->assigned[$key]);
		$this->writeQueue();
	}
	
	// Return task id for next unfinished program
	public function nextTask($client) {
		$client->updateLastTime();
		for ($i=0; $i < count($this->queue); $i++) {
			$taskId = $this->queue[$i]['task'];
			if (!$this->isRejectedTask($taskId, $client->id))
				return $taskId;
		}
		return false;
	}
	
	// Update queue file
	public function writeQueue() {
		$output = "";
		foreach($this->queue as $item)
			$output .= $item['task'] . "/" . $item['program'] . "/Q\n";
		foreach($this->assigned as $item)
			$output .= $item['task'] . "/" . $item['program'] . "/" . $item['client'] . "\n";
		foreach($this->finished as $item)
			$output .= $item['task'] . "/" . $item['program'] . "/F\n";
			
		$rejectOutput = "";
		foreach($this->rejected as $program => $ar)
			foreach($ar as $client)
				$rejectOutput .= "$program/$client\n";
		foreach($this->rejectedTasks as $task => $ar)
			foreach($ar as $client)
				$rejectOutput .= "T$task/$client\n";
				
		$queueFp = fopen($this->queueFile, "c");
		if (flock($queueFp, LOCK_EX)) {
			ftruncate($queueFp, 0);
			fwrite($queueFp, $output);
			flock($queueFp, LOCK_UN);
		
			// Write reject file
			file_put_contents($this->rejectedFile, $rejectOutput);
		} else {
			throw new Exception("Failed to get exclusive lock for writing.");
		}
		fclose($queueFp);
	}
	
	// Assign the next unfinished program in queue for given task id to 
	// given client. Returns program id or false if there are no more
	// unfinished programs
	public function assign($task, $client) {
		if ($this->isRejectedTask($task->id, $client->id))
			return false;
	
		foreach($this->queue as $key => $item) {
			//print "it ".$item['task']." tid ".$task->id." program ".$item['program']."\n";
			if ($item['task'] == $task->id && !$this->isRejected($item['program'], $client->id)) {
				$this->assigned[] = array ( "task" => $task->id, "program" => $item['program'], "client" => $client->id );
				unset($this->queue[$key]);
				$this->writeQueue();
				$client->updateLastTime();
				return $item['program'];
			}
		}
		return false;
	}
	
	// Client rejected the program
	public function reject($programId, $clientId) {
		//print "Reject $programId $clientId\n";
		if (array_key_exists($programId, $this->rejected))
			$this->rejected[$programId][] = $clientId;
		else
			$this->rejected[$programId] = array($clientId);
	}
	
	// Client rejected the task
	public function rejectTask($taskId, $clientId) {
		//print "Reject $taskId $clientId\n";
		if (array_key_exists($taskId, $this->rejectedTasks))
			$this->rejectedTasks[$taskId][] = $clientId;
		else
			$this->rejectedTasks[$taskId] = array($clientId);
	}
	
	// Did the client reject the program?
	public function isRejected($programId, $clientId) {
		if (!array_key_exists($programId, $this->rejected))
			return false;
		return (in_array($clientId, $this->rejected[$programId]));
	}
	
	// Did the client reject the program?
	public function isRejectedTask($taskId, $clientId) {
		if (!array_key_exists($taskId, $this->rejectedTasks))
			return false;
		return (in_array($clientId, $this->rejectedTasks[$taskId]));
	}
	
	// Update program result from client
	// Returns false if client should stop testing
	public function setResult($program, $client, $result) {
		$client->updateLastTime();
		
		// If client rejects the program, move it the end of unfinished queue 
		if ($result['status'] == PROGRAM_REJECTED) {
			foreach($this->assigned as $key => $item) {
				if ($item['program'] == $program->id) {
					$this->reject($program->id, $client->id);
					$this->queue[] = array ( "task" => $item['task'], "program" => $item['program'] );
					unset($this->assigned[$key]);
					$this->writeQueue();
					break;
				}
			}
			
			// No need to call Program::setResult because rest of array will 
			// be empty
			return true;
		}
		
		// If program is returned to waiting queue, stop testing
		if ($result['status'] == PROGRAM_CURRENTLY_TESTING) {
			$found = false;
			foreach($this->assigned as $key => $item) {
				if ($item['program'] == $program->id) {
					$found = true;
				}
			}
			if (!$found) return false;
		}
		
		// Call Program::setResult to write the file etc.
		$program->setResult($result);
		
		// Move to the finished queue if finished
		// Status PROGRAM_CURRENTLY_TESTING is used to incrementally update result
		// Other statuses indicate that testing is finished
		if ($result['status'] != PROGRAM_CURRENTLY_TESTING) {
			foreach($this->assigned as $key => $item) {
				if ($item['program'] == $program->id) {
					$this->finished[] = array ( "task" => $item['task'], "program" => $item['program'] );
					unset($this->assigned[$key]);
					$this->writeQueue();
					break;
				}
			}
		}
		return true;
	}
	
	// Move all programs assigned to this client to queue
	public function removeClient($client) {
		foreach($this->assigned as $key => $item) {
			if ($item['client'] == $client->id) {
				$this->queue[] = array( "task" => $item['task'], "program" => $item['program'] );
				unset($this->assigned[$key]);
			}
		}
		$this->writeQueue();
	}
	
	// Move all programs in this task to queue
	public function retestTask($task) {
		foreach($this->assigned as $key => $item) {
			if ($item['task'] == $task->id) {
				$this->queue[] = array( "task" => $item['task'], "program" => $item['program'] );
				unset($this->assigned[$key]);
			}
		}
		foreach($this->finished as $key => $item) {
			if ($item['task'] == $task->id) {
				$this->queue[] = array( "task" => $item['task'], "program" => $item['program'] );
				unset($this->finished[$key]);
			}
		}
		$this->writeQueue();
	}
	
	// Return an array with information about items in queue
	public function getQueueInfo() {
		$info = [];
		foreach($this->queue as $item) {
			$task = Task::fromId($item['task']);
			$program = Program::fromId($item['program']);
			$info[] = array( "id" => $program->id, "name" => $program->desc['name'], "task" => $task->id, "task_name" => $task->desc['name'] );
		}
		return $info;
	}
	
	// Return an array with information about assigned items
	public function getAssignedInfo() {
		$info = [];
		foreach($this->assigned as $item) {
			$task = Task::fromId($item['task']);
			$program = Program::fromId($item['program']);
			
			try {
				$client = Client::fromId($item['client']);
			} catch(Exception $e) {
				$client = new stdClass(); 
				$client->id = 0;
				$client->desc = ["name" => "unknown (client has gone away)"] ;
			}
			
			$info[] = array( "id" => $program->id, "name" => $program->desc['name'], "task" => $task->id, "task_name" => $task->desc['name'], "client" => $client->id, "clientname" => $client->desc['name'] );
		}
		return $info;
	}
	
	// Lightweight stats
	public function getStats() {
		return array( "queued" => count($this->queue), "assigned" => count($this->assigned), "finished" => count($this->finished), "rejected" => count($this->rejected) );
	}
	
	// Lightweight stats
	public function getProgramPosition($programId) {
		$i = 1;
		foreach($this->queue as $item) {
			if ($item['program'] == $programId)
				return $i;
			$i++;
		}
		return $i;
	}
	
	// Remove old programs from queue (and filesystem) ($age is seconds from now)
	public function removeOldPrograms($age) {
		$now = time();
		$removed = 0;
		$tasksRemovePrograms = []; // We will do it later to minimize impact on queue
		
		// Avoid code duplication
		$removeProgramFunc = function($key, $item, &$tasksRemovePrograms, &$removed, &$queue, $now, $age) {
			try {
				$program = Program::fromId($item['program']);
				$programFile = $program->getFilePath();
				if ($now - filemtime($programFile) > $age) {
					if (!array_key_exists($item['task'], $tasksRemovePrograms))
						$tasksRemovePrograms[$item['task']] = [ $item['program'] ];
					else
						$tasksRemovePrograms[$item['task']][] = $item['program'];
					$program->remove();
					unset($queue[$key]);
					$removed++;
				}
			} catch(Exception $e) {
				// Program doesn't actually exist
				unset($queue[$key]);
				$removed++;
			}
		};
		
		// Remove old programs
		foreach($this->finished as $key => $item)
			$removeProgramFunc($key, $item, $tasksRemovePrograms, $removed, $this->finished, $now, $age);
		foreach($this->assigned as $key => $item)
			$removeProgramFunc($key, $item, $tasksRemovePrograms, $removed, $this->assigned, $now, $age);
		$this->writeQueue();
		
		// Remove programs from tasks
		foreach($tasksRemovePrograms as $taskId => $programs) {
			$task = Task::fromId($taskId);
			foreach($programs as $programId)
				$task->removeProgram($programId);
		}
		
		return array( "removed" => $removed );
	}
}

?>
