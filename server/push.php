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

// PUSH.PHP - autotester server web service


if (!file_exists("config.php")) {
	error("ERR099", "Autotester not configured");
}

require_once("config.php");
require_once("status_codes.php");
require_once("classes/Queue.php");

sanity_check();

if (php_sapi_name() == "cli") {
	$result = cli_parse_arguments($argc, $argv);
	if (is_array($result) && array_key_exists('success', $result) && $result['success'] == "false")
		print $result['code'] . ": " . $result['message'] . "\n";
	exit(0);
}

header("Content-Type: application/json");
$result = ws_parse_arguments();
if ($result === false)
	print "{ \"false\" }";
else
	print json_encode($result, JSON_PRETTY_PRINT);


// Print CLI HELP
function usage() {
	?>
Usage:	php push.php PARAMS

Available PARAMS are:
 next-task		ID of next task in queue
 list-tasks		List all tasks
 list-progs TASKID	List all programs in task TASKID available to current user
 list-queue		Dump current queue
 list-current		What is currently being build and where
 task-info TASKID	Some information about task TASKID
 prog-info PROGID	Some information about program PROGID
 add-task FILE		Adds JSON file to list of tasks
 add-program TASKID NAME FILE
			Adds program file with given name in given task
 retry TASKID PROGID	Retry program PROGID in task TASKID
 clean-programs AGE		Purge programs older than AGE
 clean-clients AGE		Purge clients that didn't connect for more than AGE
 help			This help page

<?php

}


// Parse command line arguments and invoke functions
function cli_parse_arguments($argc, $argv) {
	// Display help
	if ($argc == 1 || in_array("help", $argv) || in_array("--help", $argv) || in_array("-h", $argv)) {
		usage();
		return ok('');
	}

	// Commands that take no params
	if (in_array("list-tasks", $argv))
		return getTaskList();
	if (in_array("next-task", $argv)) {
		$msg = nextTask();
		if ($msg['data']['id'] == "false") {
			unset ($msg['data']);
			$msg['message'] = "No more tasks in queue";
		}
		return $msg;
	}
	if (in_array("list-queue", $argv))
		return listPrograms(0);
	if (in_array("list-current", $argv))
		return getCurrent();

	// Commands that take one param
	$pi = 0;
	if (($pi = array_search("list-progs", $argv)) || ($pi = array_search("prog-info", $argv)) || ($pi = array_search("task-info", $argv)) || ($pi = array_search("add-task", $argv))) {
		if ($pi == 1) $ii = 2; else $ii = 1;
		if ($argc < 3) {
			return error('ERR202', $argv[$pi]." takes exactly one parameter.");
		} else if ($argv[$pi] != "add-task" && !is_numeric($argv[$ii]))
			return error('ERR201', "ID should be an integer.");
		else {
			if ($argv[$pi] == "task-info") {
				$taskid = $argv[$ii];
				$taskmsg = getTaskData($taskid);
				$task = $taskmsg['data'];
				$result = ok("Task ID: $taskid\nName: ".$task['name']."\nLanguage: ".$task['language']);
				return $result;
			}
			if ($argv[$pi] == "list-progs")
				return listPrograms($argv[$ii]);
			if ($argv[$pi] == "prog-info") {
				return getProgramData($argv[$ii]);
			}
			if ($argv[$pi] == "add-task") {
				if (!file_exists($argv[$ii]))
					return error('ERR002', 'File not found');
				$task = json_decode( file_get_contents($argv[$ii]), true );
				if ($task === NULL)
					return error('ERR203', "File ".$argv[$ii]." doesn't seem to be a JSON file");
				$result = setTask($task);
				return $result;
			}
			// TODO
		}
		return;
	}

	// Cmds that have fixed param order
	if ($argv[1] == "add-program" && $argc==5) {
		if (!is_numeric($argv[2]))
			return error('ERR201', "ID should be an integer.");
		if (!file_exists($argv[4]))
			return error('ERR002', 'File not found');
		return addProgram($argv[2], $argv[3], $argv[4]);
	}
	if ($argv[1] == "retry" && $argc==4) {
		if (!is_numeric($argv[2]) || !is_numeric($argv[3]))
			return error('ERR201', "ID should be an integer.");
		return retryProgram($argv[2], $argv[3]);
	}
	if ($argv[1] == "clean-programs" && $argc==3) {
		if (!is_numeric($argv[2]))
			return error('ERR201', "AGE should be an integer (seconds from now)");
		$queue = new Queue;
		$result = $queue->removeOldPrograms( $argv[2] );
		print "Removed " . $result['removed']. " programs.\n";
		return true;
	}
	if ($argv[1] == "clean-clients" && $argc==3) {
		if (!is_numeric($argv[2]))
			return error('ERR201', "AGE should be an integer (seconds from now)");
		$result = Client::removeOldClients( $argv[2] );
		print "Removed " . $result['removed']. " clients.\n";
		return true;
	}
	
	usage();
	exit (0);
}


// Parse web arguments and invoke functions
function ws_parse_arguments() {
	global $conf_allow_push, $conf_allow_pull, $conf_push_delete_done, $conf_protocol_version;
	
	$pull_actions = array("getTaskList", "getTaskData", "getProgramData", "listPrograms", "getCurrent", "nextTask", "assignProgram", 
	"setProgramStatus", "setCompileResult", "setExecuteResult", "setDebugResult", "setProfileResult", "setTestResult", "getFile");
	$push_actions = array();
	
	$action = "";
	if (isset($_REQUEST['action']))
		$action = $_REQUEST['action'];
	
	// Authenticate
	if (in_array($action, $pull_actions) && !authenticate($conf_allow_pull))
		return error('ERR001', 'Not allowed');
	if (in_array($action, $push_actions) && !authenticate($conf_allow_push))
		return error('ERR001', 'Not allowed');
	
	// New services
	if ($action == "setTask") {
		$taskDesc = json_decode($_REQUEST['task'], true);
		if ($taskDesc === NULL)
			return array( "success" => false, "code" => "ERR003", "message" => "JSON error " . json_last_error_msg() );
			
		// No failure modes
		$task = Task::create( $taskDesc );
		return array( "success" => true, "data" => $task->id, "message" => $task->message );
	}
	
	if ($action == "getTask") {
		try {
			$task = Task::fromId( $_REQUEST['id'] );
			return array( "success" => true, "data" => $task->desc );
		} catch(Exception $e) {
			return array( "success" => false, "code" => "ERR004", "message" => "Unknown task" );
		}
	}
	
	if ($action == "setProgram") {
		$progDesc = json_decode($_REQUEST['program'], true);
		if ($progDesc === NULL)
			return array( "success" => false, "code" => "ERR003", "message" => "JSON error " . json_last_error_msg() );
			
		try {
			$task = Task::fromId( $progDesc['task'] );
		} catch(Exception $e) {
			return array( "success" => false, "code" => "ERR004", "message" => "Unknown task ".$progDesc['task'] );
		}
		
		if (!array_key_exists("language", $progDesc)) {
			if (array_key_exists("language", $task->desc))
				$progDesc['language'] = $task->desc['language'];
			else if (array_key_exists("languages", $task->desc) && is_array($task->desc['languages']))
				$progDesc['language'] = $task->desc['languages'][0];
			else
				return array( "success" => false, "code" => "ERR008", "message" => "Language not specified" );
		}
		
		// No failure modes
		$program = Program::create( $progDesc );
		$task->addProgram( $program->id );
		
		return array( "success" => true, "data" => $program->id );
	}
	
	if ($action == "getProgram") {
		try {
			$program = Program::fromId( $_REQUEST['id'] );
			return array( "success" => true, "data" => $program->desc );
		} catch(Exception $e) {
			return array( "success" => false, "code" => "ERR005", "message" => "Unknown program" );
		}
	}
	
	if ($action == "setProgramFile") {
		try {
			$program = Program::fromId( $_REQUEST['id'] );
		} catch(Exception $e) {
			return array( "success" => false, "code" => "ERR005", "message" => "Unknown program" );
		}
		
		try {
			$same = $program->setFile( $_FILES['program']['tmp_name'] );
		} catch(Exception $e) {
			return array( "success" => false, "code" => "ERR006", "message" => "Upload failed" );
		}
		
		$queue = new Queue;
		$queue->add( $program->desc['task'], $program->id, $same );
		return array( "success" => true );
	}
	
	if ($action == "getProgramFile") {
		try {
			$program = Program::fromId( $_REQUEST['id'] );
		} catch(Exception $e) {
			try {
				$program = Program::fromId( $_REQUEST['program'] );
			} catch(Exception $e) {
				return array( "success" => false, "code" => "ERR005", "message" => "Unknown program" );
			}
		}
		
		// Suggested name for the downloaded file
		$filename = $program->id . ".zip";
		// Path on server where file should be
		$filepath = $program->getFilePath();
		if (!file_exists($filepath))
			return array( "success" => false, "code" => "ERR006", "message" => "File not found" );
		
		header("Content-Type: application/zip");
		header('Content-Disposition: attachment; filename="' . $filename.'"', false);
		header("Content-Length: ".(string)(filesize($filepath)));

		// workaround for http://support.microsoft.com/kb/316431
		header("Pragma: dummy=bogus"); 
		header("Cache-Control: private");

		$k = readfile($filepath,false);
		exit(0);
	}
	
	if ($action == "getResult") {
		try {
			$program = Program::fromId( $_REQUEST['id'] );
		} catch(Exception $e) {
			try {
				$program = Program::fromId( $_REQUEST['program'] );
			} catch(Exception $e) {
				return array( "success" => false, "code" => "ERR005", "message" => "Unknown program" );
			}
		}
		
		$result = $program->getResult();
		if (array_key_exists('status', $result) && $result['status'] == PROGRAM_AWAITING_TESTS) {
			$queue = new Queue;
			$stats = $queue->getStats();
			$result['queue_items'] = $stats['queued'];
		}
		
		return array( "success" => true, "data" => $result );
	}
	
	if ($action == "retest") {
		try {
			$program = Program::fromId( $_REQUEST['id'] );
		} catch(Exception $e) {
			try {
				$program = Program::fromId( $_REQUEST['program'] );
			} catch(Exception $e) {
				return array( "success" => false, "code" => "ERR005", "message" => "Unknown program" );
			}
		}
		$queue = new Queue;
		$queue->add( $program->desc['task'], $program->id );
		
		return array( "success" => true );
	}
	
	if ($action == "retestTask") {
		try {
			$task = Task::fromId( $_REQUEST['task'] );
		} catch(Exception $e) {
			return array( "success" => false, "code" => "ERR004", "message" => "Unknown task" );
		}
		
		$queue = new Queue;
		$queue->retestTask($task);
		return array( "success" => true );
	}
	
	if ($action == "cancelProgram") {
		try {
			$program = Program::fromId( $_REQUEST['id'] );
		} catch(Exception $e) {
			try {
				$program = Program::fromId( $_REQUEST['program'] );
			} catch(Exception $e) {
				return array( "success" => false, "code" => "ERR005", "message" => "Unknown program" );
			}
		}
		
		$queue = new Queue;
		$queue->remove( $program->id );
		return array( "success" => true );
	}
	
	
	// Client services
	
	
	if ($action == "registerClient") {
		$clientDesc = json_decode($_REQUEST['client'], true);
		if ($clientDesc === NULL)
			return array( "success" => false, "code" => "ERR003", "message" => "JSON error " . json_last_error_msg() );
			
		// No failure modes
		$client = Client::create( $clientDesc );
		return array( "success" => true, "data" => $client->id );
	}
	
	if ($action == "unregisterClient") {
		try {
			$client = Client::fromId($_REQUEST['client']);
		} catch(Exception $e) {
			return array( "success" => false, "code" => "ERR007", "message" => "Unknown client - please register first" );
		}
		
		$queue = new Queue;
		$queue->removeClient($client->id);
		$client->unregister();
		return array( "success" => true );
	}
	
	if ($action == "ping") {
		try {
			$client = Client::fromId($_REQUEST['client']);
		} catch(Exception $e) {
			return array( "success" => false, "code" => "ERR007", "message" => "Unknown client - please register first" );
		}
		
		$mode = "";
		if (isset($_REQUEST['mode'])) $mode = $_REQUEST['mode'];
		
		return array( "success" => true, "data" => $client->ping($mode), "mode" => $mode );
	}
	
	if ($action == "nextTask") {
		try {
			$client = Client::fromId($_REQUEST['client']);
		} catch(Exception $e) {
			return array( "success" => false, "code" => "ERR007", "message" => "Unknown client - please register first" );
		}
		$queue = new Queue;
		return array( "success" => true, "data" => $queue->nextTask($client) );
	}
	
	if ($action == "rejectTask") {
		try {
			$client = Client::fromId($_REQUEST['client']);
		} catch(Exception $e) {
			return array( "success" => false, "code" => "ERR007", "message" => "Unknown client - please register first" );
		}
		
		try {
			$task = Task::fromId( $_REQUEST['task'] );
		} catch(Exception $e) {
			return array( "success" => false, "code" => "ERR004", "message" => "Unknown task" );
		}
		
		$queue = new Queue;
		$queue->rejectTask($task->id, $client->id);
		$queue->writeQueue();
		return array( "success" => true );
	}
	
	if ($action == "assignProgram") {
		try {
			$client = Client::fromId($_REQUEST['client']);
		} catch(Exception $e) {
			return array( "success" => false, "code" => "ERR007", "message" => "Unknown client - please register first" );
		}
		
		try {
			$task = Task::fromId( $_REQUEST['task'] );
		} catch(Exception $e) {
			return array( "success" => false, "code" => "ERR004", "message" => "Unknown task" );
		}
		
		$queue = new Queue;
		return array( "success" => true, "data" => $queue->assign($task, $client) );
	}
	
	if ($action == "setResult") {
		try {
			$client = Client::fromId($_REQUEST['client']);
		} catch(Exception $e) {
			return array( "success" => false, "code" => "ERR007", "message" => "Unknown client - please register first" );
		}
		
		try {
			$program = Program::fromId( $_REQUEST['id'] );
		} catch(Exception $e) {
			return array( "success" => false, "code" => "ERR005", "message" => "Unknown program" );
		}
		
		$result = json_decode($_REQUEST['result'], true);
		if ($result === NULL)
			return array( "success" => false, "code" => "ERR003", "message" => "JSON error " . json_last_error_msg() );
		
		$queue = new Queue;
		if ( !$queue->setResult( $program, $client, $result ) )
			return array( "success" => true, "data" => "please_stop" );
		return array( "success" => true );
	}
	
	
	// Informative services
	
	if ($action == "listTasks") {
		return array( "success" => true, "data" => Task::listTasks() );
	}
	
	if ($action == "listPrograms") {
		try {
			$task = Task::fromId( $_REQUEST['task'] );
		} catch(Exception $e) {
			return array( "success" => false, "code" => "ERR004", "message" => "Unknown task" );
		}
		
		return array( "success" => true, "data" => $task->listPrograms() );
	}
	
	if ($action == "listClients") {
		return array( "success" => true, "data" => Client::listClients() );
	}
	
	if ($action == "getQueue") {
		$queue = new Queue;
		return array( "success" => true, "data" => $queue->getQueueInfo() );
	}
	
	if ($action == "getAssigned") {
		$queue = new Queue;
		return array( "success" => true, "data" => $queue->getAssignedInfo() );
	}
	
	if ($action == "getStats") {
		$queue = new Queue;
		return array( "success" => true, "data" => $queue->getStats() );
	}
	
	if ($action == "cleanupPrograms") {
		$queue = new Queue;
		return array( "success" => true, "data" => $queue->removeOldPrograms( 60*60*24 ) );
	}
	
	if ($action == "cleanupTasks") {
		return array( "success" => true, "data" => Task::removeOldTasks( 60*60*24 ) );
	}
	
	if ($action == "cleanupClients") {
		return array( "success" => true, "data" => Client::removeOldClients( 2*60*60*24 ) );
	}
	return array( "success" => true, "message" => "Autotester server v$conf_protocol_version is running. No action specified.", "data" => [] );
}


// Universal function for authenticating user to web service
function authenticate($conf) {
	if (array_key_exists("disabled", $conf) && $conf["disabled"] === true)
		return false;
		
	if (array_key_exists("http_auth", $conf) && $conf["http_auth"] !== false) {
		if (!isset($_SERVER['PHP_AUTH_USER']))
			return false;
		if ($conf["http_auth"] !== true && !in_array($_SERVER['PHP_AUTH_USER'], $conf["http_auth"]))
			return false;
	}
	
	if (array_key_exists("php_session", $conf) && $conf["php_session"] !== false) {
		session_start();
		if (is_array($conf["php_session"])) foreach ($conf["php_session"] as $key => $value) {
			if (strlen($key)>7 && substr($key,0,7) == "exists_") {
				$key = substr($key,7);
				if (!isset($_SESSION[$key]))
					return false;
				
			} else {
				if (!isset($_SESSION[$key]))
					return false;
				
				if (is_array($value))
					if (!in_array($_SESSION[$key], $value))
						return false;					
				else
					if ($_SESSION[$key] !== $value)
						return false;
			}
		}
	}
	
	if (array_key_exists("hosts", $conf) && is_array($conf["hosts"])) {
		if (!in_array($_SERVER['REMOTE_ADDR'], $conf['hosts']) && !in_array($_SERVER['REMOTE_HOST'], $conf['hosts']))
			return false;
	}
	
	return true;
}


// Set lock
function lock() {
	global $conf_basepath;
	$qlock = "$conf_basepath/queue.lock";
	lockWait();
	touch($qlock);
}

// Clear lock
function unlock() {
	global $conf_basepath;
	$qlock = "$conf_basepath/queue.lock";
	unlink($qlock);
}


// HELPER functions

// Construct ok/error messages
function error($code, $msg) {
	$result = array();
	$result['success'] = "false";
	$result['code'] = $code;
	$result['message'] = $msg;
	return $result;
}

function ok($msg) {
	$result = array();
	$result['success'] = "true";
	$result['message'] = $msg;
	$result['data'] = array();
	return $result;
}

function okData($data) {
	$result = array();
	$result['success'] = "true";
	$result['data'] = $data;
	return $result;
}



// New functions

function sanity_check() {
	global $conf_basepath;
	$success = true;
	if (!file_exists($conf_basepath))
		$success = mkdir($conf_basepath);
	if ($success && !file_exists($conf_basepath . "/tasks"))
		$success = mkdir($conf_basepath . "/tasks");
	if ($success && !file_exists($conf_basepath . "/programs"))
		$success = mkdir($conf_basepath . "/programs");
	if ($success && !file_exists($conf_basepath . "/clients"))
		$success = mkdir($conf_basepath . "/clients");
	if (!$success) {
		$result = array( "success" => false, "code" => "ERR099", "message" => "Base doesn't exist or not writeable by server" );
		print json_encode($result, JSON_PRETTY_PRINT);
		exit(0);
	}
}

?>
