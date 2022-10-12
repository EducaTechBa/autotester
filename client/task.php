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

// TASK.PHP - perform a single task



require("classes/Config.php");
require("classes/Utils.php");
require("classes/Task.php");
require("plugins.php"); // fix pluginses into plugins as soon as old config obsolete
require("status_codes.php");



// CLI version
if (php_sapi_name() == "cli") {
	echo "AUTOTESTER task.php\nCopyright (c) 2014-2019 Vedran Ljubović\nElektrotehnički fakultet Sarajevo\nLicensed under GNU GPL v3\n\n";

	exec("command -v zip", $output, $exitCode);
	if ($exitCode != 0) {
		print "Error: task.php requires 'zip' command to be present\n";
		exit(1);
	}

	if ($argc != 3 && $argc != 4 && $argc != 5) {
		print "Usage:	php task.php task_specification.json program.zip [task_result.json] [LANGUAGE]\n";
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
	
	if ($argc == 5)
		$language = $argv[4];
		// We don't check if language is listed in task specification
		// We assume CLI user knows what they are doing :D
		
	else if (array_key_exists('languages', $taskDesc) && count($taskDesc['languages']) > 0)
		$language = $taskDesc['languages'][0];
		
	else {
		print "Programming Language not specified.\n";
		exit(2);
	}
	
	$program = array( "name" => $argv[2], "zip" => $zip_file, "language" => $language );
	
	try {
		$task = new Task($taskDesc, $program);
		if ($result = $task->run()) {
			$result_json = json_encode( $result, JSON_PRETTY_PRINT );
			if ($argc >= 4)
				file_put_contents( $argv[3], $result_json );
			else
				print $result_json . "\n";
			print "\nDone.\n";
		}
		else
			print "\nTask rejected.\n";
	} catch(exception $e) {
		print "\n" . $e->getMessage() . "\nTask rejected.\n";
	}
	
	if ($nz) { unlink ($nz); unlink($zip_file); }
}

?>
