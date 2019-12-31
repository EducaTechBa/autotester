<?php


// AUTOTESTER - automated compiling, execution, debugging, testing and profiling
// (c) Vedran Ljubovic and others 2014-2019.
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

// PULL.PHP - evaluate programs returned by external webservice


if (!file_exists("classes/Config.php")) {
	echo "First you need to copy config.php.default to config.php and edit it.\n";
	exit(1);
}
require_once("classes/Config.php");
require_once("clientlib.php");
require_once("status_codes.php");
require_once("classes/Utils.php");
require_once("classes/Task.php");
require_once("plugins.php"); // fix pluginses into plugins as soon as old config obsolete

declare(ticks=1); // Handle Ctrl-C
pcntl_signal(SIGINT, 'signalHandler');
pcntl_signal(SIGTERM, 'signalHandler');
pcntl_signal(SIGHUP, 'signalHandler');


// Command line params 
echo "AUTOTESTER pull.php\nCopyright (c) 2014-2019 Vedran Ljubović\nElektrotehnički fakultet Sarajevo\nLicensed under GNU GPL v3\n\n";

$taskid = $progid = $wait_secs = 0;
$hibernate = false;

if ($argc > 1) 
	parse_arguments($argc, $argv);

authenticate();

// Register client
$data = array( "name" => $buildhost_id, "os" => Utils::getOsVersion(), "hibernate" => $hibernate );
$clientId = json_query("registerClient", array("client" => json_encode($data)) );
if ($clientId === false) {
	Utils::debugLog( "Failed to connect to server." , 0 );
	return 0;
}
$clientData = array( "client" => $clientId ); // Shortcut
Utils::debugLog( "OS: " . $data['os'] , 1 );


if ($taskid != 0) 
	process_task($taskid, $progid);

else {
	// Process tasks with pending programs until none are left
	do {
		$mode = json_query("ping", $clientData);
		Utils::debugLog( "ping - $mode" , 2 );
		if (!$hibernate && $mode == "hibernate") {
			Utils::debugLog("Hibernate...", 1);
			$hibernate = true;
		}
		if ($hibernate && $mode == "awake") {
			Utils::debugLog("Awake...", 1);
			$hibernate = false;
		}
			
		if ($mode == "go" && !$hibernate) {
			// Next task
			$taskid = json_query("nextTask", $clientData);
			if ($taskid > 0 && process_task($taskid))
				continue; // Ping again immediately
		}
		
		// If mode is clear or host is hibernating, sleep $wait_secs
		if ($wait_secs == 0) break;
		Utils::debugLog( "Waiting $wait_secs seconds." , 2 );
		sleep($wait_secs);
	} while(true);
}

json_query("unregisterClient", $clientData);
Utils::debugLog( "Finished." , 1 );
exit(0);


function signalHandler($signo)
{
	global $clientData, $conf_push_url, $session_id;
	$type = "Uknown signal $signo";
	if ($signo == SIGINT) $type = "Interrupt";
	if ($signo == SIGTERM) $type = "Shutdown";
	if ($signo == SIGHUP) $type = "Hang-up (shell is closed)";
	Utils::debugLog( "$type - unregistering..." , 1 );
	
	// Just request - don't retry
	$parameters = $clientData;
	$parameters['action'] = "unregisterClient";
	if ($session_id !== "")
		$parameters[session_name()] = $session_id;
	json_request( $conf_push_url, $parameters);
	exit(0);
}


// Process all pending programs in given task
// If $progid isn't zero, process just that program
// Return value: continue with nextTask
function process_task($taskid, $progid = 0) {
	global $conf_verbosity, $clientId;
	
	// Get task data
	$taskDesc = json_query("getTask", array("id" => $taskid));
	if ($taskDesc === false) return false;
	
	Utils::debugLog( date("\nd. m. Y H:i:s"), 1 );
	try {
		$task = new Task($taskDesc);
	} catch(Exception $e) {
		Utils::debugLog( "Task rejected" , 1 );
		json_query("rejectTask", array("task" => $taskid, "client" => $clientId));
		return true;
	}
	
	$loop = false;

	if ($progid != 0)
		process_program($task, $progid);

	else do {
		// Loop through available programs for this task
		$progid = json_query("assignProgram", array("task" => $taskid, "client" => $clientId));
		if ($progid) {
			Utils::debugLog( "" , 1 ); // Blank line looks prettier
			process_program($task, $progid);
			$loop = true;
		}
	} while($progid != false);
	Utils::debugLog( "\nNo more programs for task $taskid." , 1 );
	return $loop;
}


// Run all tests for given program
function process_program($task, $program_id) {
	global $conf_tmp_path, $conf_verbosity, $clientId, $stop_testing;

	// Display program data
	$program = json_query("getProgram", array("id" => $program_id) );
	if ($program === false) {
		exit(0);
	}
	Utils::debugLog( "Program ($program_id): " . $program['name'], 1 );

	// Get files (format is ZIP)
	$program['zip'] = $conf_tmp_path."/bs_download_$program_id.zip";
	if (!json_get_binary_file($program['zip'], "getProgramFile", array("id" => $program_id))) {
		Utils::debugLog( "Downloading file failed.", 0 );
		// We want program to remain assigned because this is a server error
		return;
	}

	// Create directory structure that will be used for everything related to this program
	if (!$task->setProgram($program)) {
		Utils::debugLog( "Program $program_id rejected.", 1 );
		$result = array( "status" => PROGRAM_REJECTED );

	} else {
		$stop_testing = false;
		$task->afterEachTest = "\$ans = json_query(\"setResult\", array(\"id\" => $program_id, \"client\" => $clientId, \"result\" => json_encode(\$result)), \"POST\" ); if (\$ans === \"please_stop\") \$stop_testing = true;";
		$result = $task->run();
 	}

 	if (!$stop_testing)
		$k = json_query("setResult", array("id" => $program_id, "client" => $clientId, "result" => json_encode($result)), "POST" );
//		file_put_contents("kk/result.json", json_encode($result, JSON_PRETTY_PRINT));
	else
		Utils::debugLog( "Stop testing per request from server", 1 );

		print_r($k);
		
} // End process_program



// ------------------------------------
// COMMAND LINE PARAMETERS PROCESSING
// ------------------------------------


function usage() {
	?>
Usage:	php pull.php PARAMS

Available PARAMS are:
 (none)			Process all unfinished programs in all available tasks
 wait			Don't end when there are no more tasks
 hibernate		Launch client in hibernate mode (awaken by server)
 TASKID			Process all unfinished programs in task TASKID
 TASKID PROGID		Process program PROGID in task TASKID
 list-tasks		List all tasks available to current user
 list-progs TASKID	List all programs in task TASKID available to current user
 task-info TASKID	Some information about task TASKID
 prog-info PROGID	Some information about program PROGID
 fetch-task TASKID FILENAME	Download task description to a file
 fetch-progs TASKID PATH	Download all programs in task description to a directory PATH
 set-status TASKID STATUS	Set all programs in task to STATUS
 help			This help page

/<?php
}


function parse_arguments($argc, $argv) {
	global $taskid, $progid, $wait_secs, $conf_wait_secs, $hibernate;

	// Display help
	if ($argc == 1 || in_array("help", $argv) || in_array("--help", $argv) || in_array("-h", $argv)) {
		usage();
		exit (1);
	}

	// Commands that take no params
	if (in_array("list-tasks", $argv)) {
		authenticate();
		list_tasks();
		exit (0);
	}
	if (in_array("wait", $argv) || in_array("hibernate", $argv)) { 
		$wait_secs = $conf_wait_secs; 
		if (in_array("hibernate", $argv)) $hibernate = true;
		return; 
	}

	// Commands that take one param
	$pi = 0;
	if (($pi = array_search("list-progs", $argv)) || ($pi = array_search("prog-info", $argv)) || ($pi = array_search("task-info", $argv)) || ($pi = array_search("wait", $argv))) {
		if ($pi == 1) $ii = 2; else $ii = 1;
		if ($argc < 3) {
			print "Error: ".$argv[$pi]." takes exactly one parameter.\n\n";
			usage();
		} else if (!is_numeric($argv[$ii]))
			print "Error: ID is an integer.\n\n";
		else {
			authenticate();
			if ($argv[$pi] == "list-progs") list_progs($argv[$ii]);
			if ($argv[$pi] == "prog-info") prog_info($argv[$ii]);
			if ($argv[$pi] == "task-info") task_info($argv[$ii]);
		}
		exit (0);
	}

	// Commands that take two params
	if (($pi = array_search("fetch-task", $argv)) || ($pi = array_search("fetch-progs", $argv)) || ($pi = array_search("set-status", $argv))) {
		if ($pi == 1) { $ii1 = 2; $ii2 = 3; }
		else if ($pi == 2) { $ii1 = 1; $ii2 = 3; }
		else { $ii1 = 1; $ii2 = 2; }

		if ($argc < 4) {
			print "Error: ".$argv[$pi]." takes exactly two parameters.\n\n";
			usage();
		}
		else if (!is_numeric($argv[$ii1]))
			print "Error: TASKID is an integer.\n\n";
		else {
			authenticate();
			if ($argv[$pi] == "fetch-task") fetch_task($argv[$ii1], $argv[$ii2]);
			if ($argv[$pi] == "fetch-progs") fetch_progs($argv[$ii1], $argv[$ii2]);
			if ($argv[$pi] == "set-status") set_status($argv[$ii1], $argv[$ii2]);
		}
		exit (0);
	}

	// Unrecognized command
	if (!is_numeric($argv[1]) || ($argc==3 && !is_numeric($argv[2])))
		print "Error: TASKID is an integer.\n\n";
	else {
		$taskid = $argv[1];
		if ($argc == 3) $progid = $argv[2];
		return;
	}
	usage();
	exit (0);
}



function authenticate()
{
	global $session_id, $conf_json_login_required, $conf_verbosity;
	$session_id = "";
	if ($conf_json_login_required) {
		if ($conf_verbosity>0) print "Authenticating...\n";
		$session_id = json_login();
		if ($conf_verbosity>0) print "Login successful!\n\n";
	}
}

function list_tasks() {
	$tasks = json_query("listTasks");
	print "\nAvailable tasks:\n";
	foreach ($tasks as $task)
		print "  ".$task['id']."\t".$task['name']."\n";
}

function progs_sort_by_name($p1, $p2) { return strcmp($p1['name'], $p2['name']); }
function list_progs($taskid) {
	$progs = json_query("listPrograms", array("task" => $taskid));
	print "\nAvailable programs in task:\n";
	usort($progs, "progs_sort_by_name");
	foreach ($progs as $prog)
		print "  ".$prog['id']."\t".$prog['name']."\n";
}

function prog_info($progid) {
	global $global_status_codes;

	$proginfo = json_query("getProgram", array("program" => $progid));
	print "\nProgram ID: $progid\nName: ".$proginfo['name']."\nStatus: ".$global_status_codes[$proginfo['status']]." (".$proginfo['status'].")\n\nTask info:";
	task_info($proginfo['task']);
}

function task_info($taskid) {
	$task = json_query("getTask", array("task" => $taskid));
	print "\nTask ID: $taskid\nName: ".$task['name']."\nLanguage: ".$task['language']."\n";
}

function fetch_task($taskid, $filename) {
	$task = json_query("getTask", array("task" => $taskid));
	if (!$task)
		print "\nError: Unkown task $taskid\n";
	else {
		file_put_contents($filename, json_encode($task));
		print "\nTask '".$task['name']."' written to file '".$filename."'\n\n";
	}
}

function fetch_progs($taskid, $path) {
	global $conf_verbosity, $conf_json_max_retries;

	if (!is_dir($path)) {
		print "\nError: Path doesn't exist or isn't a directory: $path\n";
		return;
	}
	
	$progs = json_query("listPrograms", array("task" => $taskid));
	usort($progs, "progs_sort_by_name");
	foreach ($progs as $prog) {
		$zip_file = $path . "/" . $prog['id'] . ".zip";
		if (!json_get_binary_file($zip_file, "getFile", array("program" => $prog['id']))) {
			// Retry on failure
			$try = 1;
			do {
				print "... try $try ...\n";
				$result = json_get_binary_file($zip_file, "getFile", array("program" => $prog['id']));
				$try++;
			} while ($result === FALSE && $try < $conf_json_max_retries);
			if ($conf_verbosity>0) 
				print "\nError: Failed to download ".$prog['id']."\n";
		}
		else if ($conf_verbosity > 0)
			print "Download program ".$prog['id']."\t".$prog['name']."\n";
	}
}

function set_status($taskid, $status) {
	print "Obsolete\n";
}

?>

