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

// ExternalTool.php - tool that calls a command from system

require_once("tools/AbstractTool.php");


class ExternalTool extends AbstractTool {
	// ExternalTool should inherit constructor from AbstractTool...
	
	
	// Default execution environment for all tools
	// Can be changed per tool or globally in Config.php ($conf_default_environment)
	private static $default_environment = array(
		"type" => "exec",
		"stdin" => "",
		"output_stream" => "both",
		"timeout" => 60,
		"timeout_method" => "timeout", // "timeout_method" => "ulimit",
		// "nice" => "10",
		"limit_output" => 10000,
		"limit_memory" => 0,
		"nice" => 0
	);
	
	
	// Tool::run function executes tool
	public function run() {
		$cmd = $this->properties['cmd'];

		// Cmd options (switches) shouldn't be able to run another command
		strtr( $this->properties['options'], "&><|;", "     " );

		// Replace properties into command line
		foreach($this->properties as $key => $value) {
			if (!is_string($value)) continue;
			$key = "{".$key."}";
			$cmd = str_replace($key, $value, $cmd);
		}
		
		if (strstr($cmd, "{test_path}"))
			$cmd = str_replace("{test_path}", $this->test->path(), $cmd);
		
		if (strstr($cmd, "{output_file}")) {
			$this->outputFile = tempnam($this->test->path(), "output");
			$cmd = str_replace("{output_file}", $this->outputFile, $cmd);
			
			// Remember that output file is the executable
			$toolKind = preg_replace("/\[.*?\]/", "", $this->tool);
			if ($toolKind == "compile") {
				$this->test->executable = $this->outputFile;
			}
		}
		
		if (array_key_exists('files', $this->properties))
			$files = $this->properties['files'];
		else
			$files = $this->test->sourceFiles;

		if (strstr($cmd, "{executable}")) {
			$executable = $this->findExecutable( $files );
			$cmd = str_replace("{executable}", $executable, $cmd);
		}
		
		$strfiles = join(" ", $files);
		$cmd = str_replace("{source_files}", $strfiles, $cmd);

		// Execute command
		$this->result = $this->executeCommand($cmd);

		// External tool fails if exit code is not zero
		if ($this->result['status'] === EXECUTION_SUCCESS && $this->result['exit_code'] != 0) {
			$this->result['status'] = EXECUTION_CODE_NOT_ZERO;
			$this->result['success'] = false;
		}
		else
			$this->result['success'] = ( $this->result['status'] === EXECUTION_SUCCESS );
	}


	// ExternalTool provides own merge function to reconstruct options based on features
	public function merge($spec) {
		// Don't allow spec to just override the options
		$options = "";
		if (array_key_exists("options", $this->properties))
			$options = $this->properties['options'];

		$success = parent::merge($spec);

		// Reconstruct options from features, if any
		if (array_key_exists("features", $spec)) {
			foreach($spec['features'] as $feature) {
				foreach($this->features as $key => $value) {
					if (strtolower($feature) === strtolower($key))
						$options = $value . " " . $options;
				}
			}
		}

		$this->properties['options'] = $options;
		
		return $success;
	}


	// Helper function to find executable files for current task
	protected function findExecutable($files) {
		// Test to see if output file was passed to compiler
		if (!empty($this->test->executable))
			return $this->test->executable;

		$plugin = Utils::findPlugin( "language", $this->test->task->language, "", array( "test" => $this->test, "tool" => $this ) );
		if ($plugin) 
			return $plugin->findExecutable( $files );

		// Nothing was found
		return "";
	}
	

	// Run external command with environment properties
	protected function executeCommand($cmd) {
		global $conf_nice, $conf_max_program_output;

		// Default environment
		$env = ExternalTool::$default_environment;
		
		if (isset($conf_default_environment))
			foreach($conf_default_environment as $key => $value)
				$env[$key] = $value;
		
		if (array_key_exists("environment", $this->properties))
			foreach($this->properties['environment'] as $key => $value)
				$env[$key] = $value;
				
		if (array_key_exists("stdin", $this->properties))
			$env['stdin'] = $this->properties['stdin'];
			
		// Remember merged environment
		$this->properties['environment'] = $env;
		
		// Parse various environment options
		if ($env['timeout'] > 0 && $env['timeout_method'] == "timeout")
			$cmd = "timeout " . $env['timeout'] . "s $cmd";
			
		if ($env['nice'] != 0) 
			$cmd = "nice -n " . $env['nice'] . " $cmd";
		
		if ($env['timeout'] > 0 && $env['timeout_method'] == "ulimit")
			$cmd = "ulimit -t " . $env['timeout'] . "; $cmd";
			
		if ($env['limit_memory'] > 0)
			$cmd = "ulimit -v " . $env['limit_memory'] . "; $cmd";
			
		// Always enable coredumps
		$cmd = "ulimit -c 1000000; $cmd";
		
		// Execute appropriate type of plugin
		if ($env['type'] == "exec")
			$result = $this->executeCommandExec($cmd, $env);
		else if ($env['type'] == "popen")
			$result = $this->executeCommandPopen($cmd, $env);
		
		// Process output
		if ($env['limit_output'] > 0 && strlen($result['output']) > $env['limit_output'])
			$result['output'] = substr($result['output'], 0, $env['limit_output']);
		$result['output'] = Utils::clearUnicode($result['output']);
		Utils::debugLog( $result['output'], 3 );
		
		// Did it fail to finish before $timeout ?
		if ($env['timeout'] > 0 && $result['duration'] >= $env['timeout']) { 
			Utils::debugLog( "- Duration was " . $result['duration'], 1 );
			$result['status'] = EXECUTION_TIMEOUT;
		}
		
		$cwd = $this->test->path();
		if ($filename = glob("$cwd/core*")) {
			Utils::debugLog( "- Crashed (".$filename[0].")", 2 );
			$result['status'] = EXECUTION_CRASH;
			$result['core'] = $filename[0];
			$result['output'] = str_replace("timeout: the monitored command dumped core\n", "", $result['output']);
		}
					
		$result['success'] = ($result['status'] === EXECUTION_SUCCESS);
		
		return $result;
	}


	// Run command using PHP proc_open mechanism
	private function executeCommandPopen($cmd, $env)
	{
		global $conf_max_program_output;

		$stderr_file   = $this->test->path() . "/buildservice_stderr.txt";
		$descriptorspec = array(
			0 => array("pipe", "r"),  // stdin is a pipe that the child will read from
			1 => array("pipe", "w"),  // stdout is a pipe that the child will write to
			2 => array("pipe", "w")   // stderr ditto
		);
		
		$cwd = $this->test->path();
		$cmd_env = array();
		$result = array();
		
		$output_limit = $env['limit_output'] + 10;
		if ($output_limit == 10) $output_limit = -1; // Don't limit output
		
		$process = proc_open($cmd, $descriptorspec, $pipes, $cwd, $cmd_env);
		if ($env['output_stream'] == "both")
			stream_set_blocking($pipes[2], 0);
		
		if (is_resource($process)) {
			$statusar = proc_get_status($process);
			$pid = $statusar['pid'] + 1; // first one is ulimit...
			Utils::debugLog( "PID: $pid", 2 );
			
			fwrite($pipes[0], $env['stdin']);
			fclose($pipes[0]);
			
			// stream_get_contents will wait until program ends
			$start_time = time();
			
			if ($env['output_stream'] == "stdout")
				$result['output'] = stream_get_contents( $pipes[1], $output_limit );
			else if ($env['output_stream'] == "stderr")
				$result['output'] = stream_get_contents( $pipes[2], $output_limit);
			else if ($env['output_stream'] == "both") {
				// FIXME? stderr will be appended to stdout
				$result['output'] = stream_get_contents( $pipes[1], $output_limit );
				$result['output'] .= stream_get_contents( $pipes[2], $output_limit );
			}
			
			$result['duration'] = time() - $start_time;
			$result['status'] = EXECUTION_SUCCESS;
			fclose($pipes[1]);
			
			$statusar = proc_get_status($process);
			$result['exit_code'] = $statusar['exitcode'];
		} else {
			Utils::debugLog( "Not a resource", 1 );
			$result['status'] = EXECUTION_FAIL;
			return $result;
		}

		return $result;
	}


	// Obsolete, esentially the same thing as executeCommandExec
	// except it's impossible to get the exit code
	function executeCommandBacktick($cmd, $env)
	{
		$stdin_file    = $this->test->path() . "/buildservice_stdin.txt";
		$stderr_file   = $this->test->path() . "/buildservice_stderr.txt";
		$stdout_file   = $this->test->path() . "/buildservice_stdout.txt";
		
		$run_result = array( 'status' => EXECUTION_SUCCESS );
		
		$cmd = "cd " . $this->test->path() . "; $cmd";
		
		file_put_contents($stdin_file, $env['stdin'] . "\n");
		$start_time = time();
		$run_result['output'] = `$cmd < $stdin_file`;
		$run_result['duration'] = time() - $start_time;
		$run_result['exit_code'] = 0; // FIXME

		return $run_result;
	}


	// Run command using PHP exec mechanism
	function executeCommandExec($cmd, $env)
	{
		$stdin_file    = $this->test->path() . "/buildservice_stdin.txt";
		$stdout_file   = $this->test->path() . "/buildservice_stdout.txt";
		
		$run_result = array( 'status' => EXECUTION_SUCCESS );
		
		$cmd = "cd " . $this->test->path() . "; $cmd";
		$output = array();
		Utils::debugLog ( "CMD: $cmd", 2 );
		
		if ($env['output_stream'] == "stdin")
			$output_selection = "2>/dev/null";
		else if ($env['output_stream'] == "stderr")
			$output_selection = "1>/dev/null 2>&1";
		else if ($env['output_stream'] == "both")
			$output_selection = "2>&1";
			
		if (array_key_exists("stdin", $env)) {
			file_put_contents($stdin_file, $env['stdin'] . "\n");
			$start_time = time();
			exec( "$cmd < $stdin_file $output_selection", $output, $return );
		} else {
			$start_time = time();
			exec( "$cmd $output_selection", $output, $return );
		}
		$run_result['duration'] = time() - $start_time;
		$run_result['output'] = join("\n", $output);
		$run_result['exit_code'] = $return;

		return $run_result;
	}

}



?>
