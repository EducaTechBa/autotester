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

// Test.php - class representing a single test

class Test {
	public $tools = array(), $task = array(), $options = array();
	public $result = array( 'success' => true, 'status' => TEST_SUCCESS, 'tools' => array() );
	public $sourceFiles = array();
	public $id=0, $instance=0, $name="", $executable="";

	private $testPath = "";
	private $notTools = array( "id", "name", "options" );

	public function __construct($task, $spec) {
		global $conf_extensions;
	
		$this->task =& $task;

		// Create directory if neccessary
		if (array_key_exists('options', $spec) && in_array("reuse", $spec['options']) && isset($task->lastTest)) {
			$this->testPath = $task->lastTest->path();
			$this->executable = $task->lastTest->executable;
			// Remove coredump
			foreach (glob($this->testPath . "/core*") as $filename)
				unlink($filename);
		}
		if (empty($this->testPath)) 
			$this->createTestPath();

		// Get a list of source files
		$extensions = array();
		foreach ($conf_extensions[$task->language] as $ext)
			$extensions[] = "*" . $ext;
		$this->sourceFiles = Utils::expandFilenameGlob( $this->testPath, $extensions );

		// Parse specification array
		foreach($spec as $key => $value) {
			if ($key === "id") $this->id = $value;
			if ($key === "name") $this->name = $value;
			if ($key === "options") $this->options = $value;
			// Ignore other keys in array
			if (in_array($key, $this->notTools)) continue;

			// We don't want test specifications to execute arbitrary commands on buildhost
			if (is_array($value) && array_key_exists("cmd", $value)) 
				unset($value['cmd']);

			// Find tool in task tools
			if (array_key_exists($key, $this->task->tools[$task->language])) {
				$tool = $this->task->tools[$task->language][$key];
				if (!$tool->merge($value)) {
					// Merge failed usually means that required features weren't properly declared in task header 
					$this->result['success'] = false;
					$this->result['status'] = TEST_INTERNAL_ERROR;
					$this->result['message'] = "Cannot instantiate tool $key with given options";
					break;
				}
				$this->tools[$key] = $tool;

			} else {
				// Tool doesn't exist in task... Let's try to create a new one, 
				// hoping it's internal tool such as execute
				$toolname = preg_replace("/\[.*?\]/", "", $key);
				$this->tools[$key] = Utils::findPlugin( $toolname, $task->language, "", $value );
				if (!$this->tools[$key]) {
					$this->result['success'] = false;
					$this->result['status'] = TEST_INTERNAL_ERROR;
					$this->result['message'] = "Tool $toolname not found";
					Utils::debugLog( "Test failed - tool $toolname not found", 1 );
					break;
				}
			}
			// Backlink
			$this->tools[$key]->test =& $this;
		}
		
		Utils::debugLog( "- Test " . $this->id . " (path " . $this->testPath . ")", 1 );
	}

	public function path() { return $this->testPath; }

	public function run() {
		$excessive_output = false;
		$patch_tool = false;
		foreach($this->tools as $toolname => $tool) {
			$toolKind = preg_replace("/\[.*?\]/", "", $toolname);
			
			// Speedups: profiler tool will be skipped if execute took too long, or produced too much output
			if ($toolKind == "profile" && ($this->result['status'] == TEST_EXECUTION_TIMEOUT || $excessive_output))
				continue;
				
			if ($toolKind == "patch") $patch_tool = $tool;
				
			Utils::debugLog( "Tool $toolname", 2 );
			$tool->run();

			// Analyse tool results to provide special test status codes
			if (!$tool->result['success'] && $this->result['success']) {
				$this->result['success'] = false;

				if ($toolKind === "execute") {
					if ($tool->result['status'] == EXECUTION_TIMEOUT) {
						$this->result['status'] = TEST_EXECUTION_TIMEOUT;
						Utils::debugLog( "Test failed - duration too long (" . $tool->result['duration'] . " s)", 1 );
					}
					else if ($tool->result['status'] == EXECUTION_RUNTIME_ERROR) {
						$this->result['status'] = TEST_COMPILE_FAILED;
						Utils::debugLog( "Test failed - runtime error", 1 );
					}
					else if ($tool->result['status'] == EXECUTION_CRASH) {
						$expect_crash = false;
						if (array_key_exists("expect_crash", $tool->properties) && ($tool->properties['expect_crash'] === true || $tool->properties['expect_crash'] == "true"))
							$expect_crash = true;
							
						// Exceptions might be detected as crashes in C++ ...
						else if (array_key_exists("expect_exception", $tool->properties) && $this->task->language == "c++")
							$expect_crash = true;
							
						if ($expect_crash) {
							$this->result['success'] = $tool->result['success'] = true;
							Utils::debugLog( "Crash ok", 1 );
							
						} else {
							$this->result['status'] = TEST_EXECUTION_CRASH;
							Utils::debugLog( "Test failed - crash", 1 );
						}
					}
					else { // Shouldn't happen!
						$this->result['status'] = TEST_INTERNAL_ERROR;
						Utils::debugLog( "Test failed - internal error, unknown tool status (execute)", 1 );
					}
				}
				else if ($toolKind == "parse") {
					$this->result['status'] = TEST_SYMBOL_NOT_FOUND;
					Utils::debugLog( "Test failed - symbol not found", 1 );
				} else if ($toolKind == "compile") {
					$this->result['status'] = TEST_COMPILE_FAILED;
					Utils::debugLog( "Test failed - can't compile", 1 );
				} else if ($toolKind == "profile") {
					$this->result['status'] = TEST_PROFILER_ERROR;
					Utils::debugLog( "Test failed - profiler error", 1 );
				} else {
					$this->result['status'] = TEST_TOOL_FAILED;
					$this->result['message'] = "Tool $toolname failed";
					Utils::debugLog( "Test failed - tool $toolname failed", 1 );
				}
			}

			// Check tool output
			if ($tool->result['success'] && (array_key_exists("expect", $tool->properties) || array_key_exists("fail", $tool->properties) || array_key_exists("expect_exception", $tool->properties)))
				$this->checkOutput($tool);

			// Remove test base path from tool outputs
			if (array_key_exists('output', $tool->result))
				$tool->result['output'] = $this->removeBasePath( $tool->result['output'] );
				
			// Fix line numbers
			if ($patch_tool && array_key_exists('parsed_output', $tool->result)) {
				foreach($tool->result['parsed_output'] as &$parsed_result)
					$parsed_result = $patch_tool->fixLineNumber( $parsed_result );
			}
			
			$this->result['tools'][$toolname] = $tool->result;
			
			// Don't continue, except for execute tool where we can analyse why this result happened
			if (!$tool->result['success'] && $toolKind != "execute") break;
			
			// Detect excessive output
			if (!$tool->result['success'] && $toolKind == "execute" && strlen($tool->result['output']) > $tool->properties['environment']['limit_output'] - 10)
				$excessive_output = true;
		}
	}



	// Helper funcion to test if output matches the expected
	// Since tool->result['status'] can be changed, we pass by reference
	protected function checkOutput(&$tool) {
		$output = $tool->result['output'];
		$expecteds = $fails = array();
		if (array_key_exists("expect", $tool->properties))
			$expecteds = $tool->properties['expect'];
		if (array_key_exists("fail", $tool->properties))
			$fails = $tool->properties['fail'];

		// See if patch tool was used and added tool markers
		foreach ($this->tools as $toolname => $prev_tool) {
			if ($toolname == "patch") {
				$markers = $prev_tool->getMarkers();
				$start = strpos($output, $markers[0]);
				$end = strpos($output, $markers[1]);
				$except = strpos($output, $markers[2]);
				$testok = true;

				// Markers not found, but required
				// FIXME
				if ($prev_tool->properties[0]['use_markers'] && ($start === false || ($end === false && $except === false))) {
					$this->result['success'] = $tool->result['success'] = false;
					$this->result['status'] = $tool->result['status'] = TEST_OUTPUT_NOT_FOUND;
					
					Utils::debugLog( "Test failed - not found", 1 );
					return;
				}
				
				// Exception happened
				if ($prev_tool->properties[0]['try_catch'] && $except) {
					if (array_key_exists("expect_exception", $tool->properties)) {
						$exp_exc = $tool->properties['expect_exception'];
						$recv_exc = substr($output, $except + strlen($markers[2]));
						print "Recv $recv_exc\n";
						if (!$exp_exc || $exp_exc == $recv_exc) {
							Utils::debugLog( "Exception '$exp_exc' ok...", 1 );
						} else {
							$this->result['success'] = $tool->result['success'] = false;
							$this->result['status'] = $tool->result['status'] = TEST_UNEXPECTED_EXCEPTION;
							Utils::debugLog( "Test failed - wrong kind of exception (expected $exp_exc received $recv_exc)", 1 );
							$testok = false;
						}
					} else {
						$this->result['success'] = false;
						$this->result['status'] = TEST_UNEXPECTED_EXCEPTION;
						Utils::debugLog( "Test failed - unexpected exception", 1 );
						$testok = false;
					}
				}

				// Remove markers from output
				if ($start !== false) {
					$start += strlen($markers[0]);
					// We need to trim because there can be a newline before end marker that is otherwise ignored
					if ($end !== false)
						$output = trim(substr($output, $start, $end-$start));
					else
						$output = trim(substr($output, $start, $except-$start));
					$tool->result['output'] = $output;
				}
				
				if (!$testok) return;
			}
		} // foreach previous tools

		// Nothing left to do?
		if (empty($expecteds) && empty($fails)) return;
		
		// Test expected outputs
		$found_expecteds = $found_fails = false;
		
		$matching = "invisible";
		if (array_key_exists('matching', $tool->properties))
			$matching = $tool->properties['matching'];
		
		if ($matching == "invisible")
			$output = trim(preg_replace("/\s+/", " ", $output));
		if ($matching == "whitespace")
			$output = preg_replace("/\s/", "", $output);

		foreach($expecteds as $expect) {
			if ($matching == "regex") {
				if (preg_match("/$expect/", $output))
					$found_expecteds = true;
			} else if ($matching == "substring") {
				if (strstr($output, $expect))
					$found_expecteds = true;
			} else if ($matching == "whitespace") {
				$expect = preg_replace("/\s/", "", $expect);
				if ($output === $expect)
					$found_expecteds = true;
			} else if ($matching == "invisible") {
				$expect = trim(preg_replace("/\s+/", " ", $expect));
				if ($output === $expect)
					$found_expecteds = true;
			} else {
				// Exact match
				if ($output === $expect) 
					$found_expecteds = true;
				//else
				//	Utils::diff($output, $expect);
			}
		}

		foreach($fails as $fail) {
			if ($matching == "regex") {
				if (preg_match("/$fail/", $output))
					$found_fails = true;
			} else if ($matching == "substring") {
				if (strstr($output, $fail))
					$found_fails = true;
			} else if ($matching == "whitespace") {
				$fail = preg_replace("/\s/g", "", $fail);
				if ($output === $fail)
					$found_fails = true;
			} else {
				// Exact match
				if ($output === $fail) 
					$found_fails = true;
			}
		}

		if ($found_fails || !$found_expecteds) {
			$this->result['status'] = $tool->result['success'] = TEST_WRONG_OUTPUT;
			$this->result['success'] = $tool->result['status'] = false;
			Utils::debugLog( "Test failed - wrong output", 1 );
		} else {
			Utils::debugLog( "Output ok...", 1 );
		}
	}

	// Create directory structure that will be used for everything related to this program
	private function createTestPath()
	{
		global $conf_max_tasks, $conf_unzip_command, $conf_verbosity, $conf_basepath;

		$zip_file = $this->task->zipFile;

		// Find unused path
		do {
			$this->instance = rand(0, $conf_max_tasks);
			$path = $conf_basepath . "/bs_" . $this->instance;
		} while (file_exists($path));

		if (!mkdir($path, 0777, true)) {
			// ?
			print "Fatal error: Can't create directory for test! Check permissions.\n";
			exit(0);
		}

		$this->testPath = $path;

		Utils::debugLog( "Unzipping file...", 2 );
		exec("$conf_unzip_command \"$zip_file\" -d $path", $output, $return_value);
		Utils::debugLog( join("\n", $output), 2 );
		if ($return_value != 0) {
			$this->success = false;
			$this->status = TEST_INTERNAL_ERROR;
			$this->message = "Failed to unzip program. ZIP file is corrupt or unzip command is missing.";
		}
	}

	public function purge()
	{
		Utils::rmMinusR($this->testPath);
		rmdir($this->testPath);
	}
	
	// Helper function to remove test base path from string
	public function removeBasePath($str) {
		return str_replace( $this->path() . "/", "", $str );
	}

}
