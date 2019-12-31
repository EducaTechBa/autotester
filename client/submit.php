<?php


// BUILDSERVICE - automated compiling, execution, debugging, testing and profiling
// (c) Vedran Ljubovic and others 2014.
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

// SUBMIT.PHP - submit tasks to server


if (!file_exists("classes/Config.php")) {
	echo "First you need to copy config.php.default to config.php and edit it.\n";
	exit(1);
}
require_once("classes/Config.php");
require_once("clientlib.php");
require_once("status_codes.php");
require_once("classes/Utils.php");


// Command line params 
if (php_sapi_name() == "cli") {
	echo "AUTOTESTER submit.php\nCopyright (c) 2014-2019 Vedran Ljubović\nElektrotehnički fakultet Sarajevo\nLicensed under GNU GPL v3\n\n";

	if ($argc != 3 && $argc != 4 && $argc != 5) {
		print "Usage:	php submit.php task_specification.json program.zip [LANGUAGE]\n";
		exit(1);
	}
	
	$taskDesc = json_decode(file_get_contents($argv[1]), true);
	if ($taskDesc===NULL) {
		print "Failed to decode task specification as JSON\n";
		exit(3);
	}
	
	// Normally id and name will be set by server, if not provided in task specification
	if (!array_key_exists('id', $taskDesc)) $taskDesc['id'] = "";
	if (!array_key_exists('name', $taskDesc)) $taskDesc['name'] = "";
	
	
	// If filename is not .zip - then zip it!
	$zip_file = $argv[2]; $nz = false;
	if (!file_exists($argv[2])) {
		print "File $zip_file doesn't exist\n";
		exit(4);
	}
	if (!Utils::endsWith($zip_file, ".zip")) {
		$nz = tempnam("/tmp", "bs");
		`zip $nz.zip $zip_file`;
		$zip_file = "$nz.zip";
	}
	
	if ($argc == 4)
		$language = $argv[3];
		// We don't check if language is listed in task specification
		// We assume CLI user knows what they are doing :D
		
	else if (array_key_exists('languages', $taskDesc) && count($taskDesc['languages']) > 0)
		$language = $taskDesc['languages'][0];
		
	else {
		print "Programming Language not specified.\n";
		exit(2);
	}

	authenticate();

	$taskId = json_query("setTask", array("task" => json_encode($taskDesc)), "POST");
	if ($conf_verbosity > 0) print "taskId $taskId\n";
	
	$program = array( "name" => $argv[2], "language" => $language, "task" => $taskId );
	$programId = json_query("setProgram", array("program" => json_encode($program)), "POST");
	if ($conf_verbosity > 0) print "programId $programId\n";
	
	json_put_binary_file("setProgramFile", array("id" => $programId), "program", $zip_file);
	print "Program submitted to server.\n";
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

?>
