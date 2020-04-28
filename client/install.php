AUTOTESTER install.php
Copyright (c) 2014-2020 Vedran Ljubović
Elektrotehnički fakultet Sarajevo
Licensed under GNU GPL v3

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

// INSTALL.PHP - generate Config.php

if (file_exists("classes/Config.php")) {
	if (!yesno("Config.php found. Do you want to overwrite it?"))
		exit(0);
	print "\n";
}

require_once("classes/Config.php.default");
require_once("clientlib.php");

print "Testing directories... ";
while_not_ok($conf_tmp_path, "Please enter path for temporary files: ", function($k) {
	if (!is_dir($k))
		print "$k doesn't exist.\n";
	else if (!is_writeable($k))
		print "$k is not writeable.\n";
	else return true;
	return false;
});

print "Looking for unzip... ";
while_not_ok($conf_unzip_command, "Enter name/path for unzip command: ", function($k) {
	if (program_exists($k)) return true;
	print "$k not found. Unpacking zip files is required for autotester.\n";
	return false;
});

$buildhost_id = readline("Enter a unique name for this client (e.g. client15): ");

enter_url($conf_push_url, "Enter autotester server URL: ");
$conf_base_url = dirname($conf_push_url);

$conf_json_login_required = yesno("Does server require a login?");
if ($conf_json_login_required) {
	do {
		enter_url($conf_auth_url, "Enter server authentication URL: ");
		$conf_json_user = readline("Enter username: ");
		$conf_json_pass = readline("Enter password: ");
		print "Trying to authenticate... ";
		$ok = json_login();
		if ($ok) print "ok.\n";
		else print "authentication failed. Username and/or password is wrong.\n";
	} while (!$ok);
}


// Generate header of Config.php

$str_login = $conf_json_login_required ? "true" : "false";

$configuration_output = <<<CONFIG
<?php

\$conf_tmp_path = "$conf_tmp_path";
\$conf_basepath = \$conf_tmp_path . "/buildservice";
\$conf_unzip_command = "$conf_unzip_command";

\$buildhost_id = "$buildhost_id";
\$conf_wait_secs = $conf_wait_secs;
\$conf_verbosity = $conf_verbosity;
\$conf_max_tasks = $conf_max_tasks;

\$conf_base_url = "$conf_base_url";
\$conf_push_url = "$conf_push_url";
\$conf_auth_url = "$conf_auth_url";

\$conf_json_login_required = $str_login;
\$conf_json_user = "$conf_json_user";
\$conf_json_pass = "$conf_json_pass";

\$conf_json_max_retries = $conf_json_max_retries;
\$conf_default_wait = $conf_default_wait;

\$conf_tools = array(
CONFIG;


$all_ok = true;
foreach($conf_tools as $type => $tools) {
	print "\nChecking $type tools...\n";
	$configuration_output .= "\n\t\"$type\" => array(\n";
	foreach ($tools as $key => $tool) {
		if (array_key_exists('name', $tool))
			print $tool['name'] . "... ";
		else if (array_key_exists('language', $tool))
			print "(default tool for " . $tool['language'] . ")... ";
		
		$ok = true;
		if (array_key_exists('path', $tool) && !program_exists($tool['path'])) {
			print "not found.\nTool is not installed at " . $tool['path'] . " - removing.\n";
			$ok = false;
		} else if (array_key_exists('cmd', $tool)) {
			$parts = explode(" ", $tool['cmd']);
			if ($parts[0][0] != "{" && !program_exists($parts[0])) {
				print "not found.\nTool is not installed at " . $parts[0] . " - removing.\n";
				$ok = false;
			}
		}
		if ($ok) {
			print "ok.\n"; 
			$configuration_output .= var_export($tool, true) . ",\n";
		} else {
			$all_ok = false;
		}
	}
	$configuration_output .= "\t),\n";
}

if (!$all_ok) {
	?>
	

Some tools were not found on your system and were removed from configuration. Please review messages above. 
If you install these tools later, you can re-run install.php to add them to configuration.
	<?php
}

$configuration_output .= ");\n\n\$conf_extensions = " . var_export($conf_extensions, true) . ";\n\n";
file_put_contents("classes/Config.php", $configuration_output);

print "\n\nDone. Autotester client is now ready.\n";


function yesno($message) {
	$input = trim(strtolower(readline($message . " (Y/N) ")));
	if ($input == "y") return true;
	if ($input == "n") return false;
	print "Invalid input! Y or N only.\n";
	return yesno($message);
}

function program_exists($program) {
	exec("command -v $program", $output, $exitCode);
	return $exitCode == 0;
}

function while_not_ok(&$variable, $prompt, $lambda) {
	while (!$lambda($variable)) $variable = readline($prompt);
	print "ok.\n";
}

function enter_url(&$variable, $prompt) {
	while_not_ok($variable, $prompt, function($k) {
		print "Contacting server at $k... ";
		$http_result = @file_get_contents("$k");
		if ($http_result === false) {
			print "request failed.\n";
			return false;
		}
		
		$http_code = explode(" ", $http_response_header[0]);
		$http_code = $http_code[1];
		if ($http_code != "200") {
			print "received code $http_code, expected 200\nThe server is misconfigured or not installed.\n";
			return false;
		}
		$json_result = json_decode($http_result, true); // Retrieve json as associative array
		if ($json_result===NULL) {
			print "failed to decode response as JSON\nThe server is misconfigured or not installed.\n";
			return false;
		}
		if (!array_key_exists('success', $json_result) || $json_result['success'] !== true) {
			print "success is not true\nThe server is misconfigured or not installed.\n";
			return false;
		}
		return true;
	});
}

?>
