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

// Task.php - class representing a task on server - it actually ignores most of the task contents 


require_once("Program.php");

class Task {
	public $id, $desc;
	
	// Create a new task
	public static function create($taskDesc) {
		global $conf_basepath;
		$taskspath = $conf_basepath . "/tasks";
	
		if (!array_key_exists('id', $taskDesc) || intval($taskDesc['id']) == 0) {
			// Perhaps the same task already exists?
			$taskDesc['id'] = 0;
			foreach( scandir( $conf_basepath . "/tasks" ) as $entry ) {
				if ($entry == "." || $entry == "..") continue;
				try {
					$task = Task::fromId($entry);
				} catch(Exception $e) {
					continue;
				}
				
				$taskDesc['id'] = intval($entry);
				$taskDescJson = json_encode($taskDesc);
				$taskJson = json_encode($task->desc);
				if ($taskDescJson == $taskJson) {
					$task->message = "Found existing task $entry";
					return $task;
				}
			}
		
			do {
				$taskDesc['id'] = rand(1, 100000);
				$taskpath = $taskspath . "/" . $taskDesc['id'];
			} while(file_exists($taskpath));
		} else
			$taskpath = $taskspath . "/" . $taskDesc['id'];
		
		if (!file_exists($taskpath)) mkdir($taskpath);
		
		// We will replace the old task definition in case it changed
		$output = json_encode($taskDesc, JSON_PRETTY_PRINT);
		file_put_contents( $taskpath . "/description.json", $output );
		
		$task = new Task;
		$task->id = $taskDesc['id'];
		$task->desc = $taskDesc;
		$task->message = "Created new task " . $taskDesc['id'];
		return $task;
	}
	
	// Find existing task with given id
	public static function fromId($id) {
		global $conf_basepath;
		if (empty($id))
			throw new Exception();
		$taskpath = $conf_basepath . "/tasks/$id";
		if (!file_exists($taskpath) || !is_dir($taskpath))
			throw new Exception();
		
		$task = new Task;
		$task->id = $id;
		$task->desc = json_decode( file_get_contents($taskpath . "/description.json"), true );
		return $task;
	}

	// Add program to task 
	// This doesn't actually create a Program object. Use Program::create()
	public function addProgram($programId) {
		global $conf_basepath;
		$taskProgramsPath = $conf_basepath . "/tasks/" . $this->id . "/programs";
		if (file_exists($taskProgramsPath))
			foreach(file($taskProgramsPath) as $line)
				if (trim($line) === $programId)
					return;
		file_put_contents($taskProgramsPath, $programId . "\n", FILE_APPEND);
	}

	// Returns a list of all programs in task
	public function listPrograms() {
		global $conf_basepath;
		$taskProgramsPath = $conf_basepath . "/tasks/" . $this->id . "/programs";
		if (!file_exists($taskProgramsPath))
			return array();
		
		$programs = [];
		foreach(file($taskProgramsPath) as $line) {
			$program = Program::fromId(trim($line));
			$result = $program->getResult();
			if ($result['status'] == PROGRAM_AWAITING_TESTS)
				$status = "queued";
			else if ($result['status'] == PROGRAM_CURRENTLY_TESTING)
				$status = "assigned";
			else
				$status = "finished";
			$programs[] = array( "id" => $program->id, "name" => $program->desc['name'], "status" => $status );
		}
		return $programs;
	}
	
	// Static method that returns a list of all known tasks
	public static function listTasks() {
		global $conf_basepath;
		$tasks = [];
		foreach( scandir( $conf_basepath . "/tasks" ) as $entry ) {
			if ($entry == "." || $entry == "..") continue;
			try {
				$task = Task::fromId($entry);
			} catch(Exception $e) {
				continue;
			}
			$tasks[] = array( "id" => $entry, "name" => $task->desc['name'] );
		}
		return $tasks;
	}
	
	// Cleanup program lists and remove tasks older than $age that have no active programs
	public static function removeOldTasks($age) {
		global $conf_basepath;
		$now = time();
		$empty = [];
		$totalTasks = 0;
		$queue = new Queue;
		
		foreach( scandir( $conf_basepath . "/tasks" ) as $entry ) {
			if ($entry == "." || $entry == "..") continue;
			try {
				$task = Task::fromId($entry);
			} catch(Exception $e) {
				continue;
			}
			$totalTasks++;
			
			$taskProgramsPath = $conf_basepath . "/tasks/" . $task->id . "/programs";
			if (file_exists($taskProgramsPath)) {
				$taskPrograms = file($taskProgramsPath);
				foreach($taskPrograms as $key => $value) {
					// Remove empty lines
					if (intval($value) == 0) {
						unset($taskPrograms[$key]);
						continue;
					}
					
					// Remove nonexistant programs
					try {
						$program = Program::fromId(trim($value));
					} catch(Exception $e) {
						unset($taskPrograms[$key]);
					}
				}
				
				$taskPrograms = array_values($taskPrograms);
				
				file_put_contents($taskProgramsPath, join("", $taskPrograms));
				if (empty($taskPrograms))
					$empty[] = $task;
			} else 
				$empty[] = $task;
		}
		
		//foreach($empty as $task) {
			//$taskDescPath = $conf_basepath . "/tasks/" . $task->id . "/description.json";
		
		return array( "totalTasks" => $totalTasks, "empty" => count($empty));
	}
}


?>
