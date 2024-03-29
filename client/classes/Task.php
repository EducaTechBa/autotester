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

// Client classes

// Task.php - class representing a testing task to be executed on given program

require_once("classes/Test.php");
require_once("tools/ExternalTool.php");

class Task {
	public const CURRENT_VERSION = 3;

	public $version, $language = "", $zipFile = "", $taskDesc = array(), $tools = array(), $errMsg = "";
	private $result;
	public $afterEachTest = "", $lastTest = null;
	
	public function __construct($taskDesc, $program = []) {
		global $conf_verbosity;
		
		if (!array_key_exists('name', $taskDesc)) $taskDesc['name'] = "";
		$this->taskDesc = $taskDesc;
		Utils::debugLog( "Task (" .$taskDesc['id'] . "): " . trim($taskDesc['name']) , 1 );
		
		// File version
		if (array_key_exists('version', $taskDesc)) 
			$this->version = $taskDesc['version'];
		else
			$this->version = self::CURRENT_VERSION;
		
		// Find tools
		$found = false;
		if (array_key_exists('languages', $this->taskDesc))
		foreach($this->taskDesc['languages'] as $language) {
			Utils::debugLog( "Language: $language" , 1 );
			$language = strtolower($language);
			$this->tools[$language] = $this->findTools($language);
			if ($this->tools[$language]) $found = true;
			else Utils::debugLog( "Some tools were not found for $language" , 1 );
		}
		
		// Host can't build any of the languages and tools listed
		if (!$found)
			throw new Exception($this->errMsg);
			
		// Set program if passed
		if (!empty($program))
			if (!$this->setProgram($program))
				throw new Exception($this->errMsg);
				
		// Move prepare step to tests
		if (array_key_exists('prepare', $taskDesc)) {
			if (!array_key_exists('tests', $this->taskDesc))
				$this->taskDesc['tests'] = [];
			$prepareTest = [ 'id' => 'prepare', 'options' => [ 'silent', 'terminate' ], 'tools' => $taskDesc['prepare'] ];
			array_unshift($this->taskDesc['tests'], $prepareTest);
		}
		
		// No tests specified?
		if (!array_key_exists('tests', $this->taskDesc))
			throw new Exception("Task specifies no tests");
	}

	public function setProgram($programData) {
		$language = strtolower($programData['language']);
		Utils::debugLog( "Language: $language", 1 );
		if (!array_key_exists($language, $this->tools) || $this->tools[$language] === false)
			return false;
		$this->language = $language;
		$this->zipFile = $programData['zip'];
		return true;
	}
	
	// Find all the tools in task specification preamble
	// Returns false if tools aren't found (build host should reject the task)
	public function findTools($language) {
		global $conf_tools, $conf_plugins;
		
		// TODO no sources found?

		if (!array_key_exists("tools", $this->taskDesc)) {
			$this->errMsg = "Task specifies no tools";
			Utils::debugLog($this->errMsg, 1);
			return false;
		}
		
		$tools = array();
		
		foreach($this->taskDesc['tools'] as $tool => $tool_options) {
			// If JSON file contains something like: "tools" : { "compile", "debug" }, json_decode will return an array like this:
			//    array( 0 => "compile", 1 => "debug" )
			// In this case, numeric key shall be ignored, and $tool shall equal value ($tool_options)
			if (is_numeric($tool) && !is_array($tool_options)) $tool = $tool_options;
			if (!is_array($tool_options)) $tool_options = array();
			
			// $tool is in form "kind[id]" where [id] part is used to differentiate multiple tools of same kind
			$toolname = preg_replace("/\[.*?\]/", "", $tool);
			
			// 'cmd' is used in config file to specify shell commands for tool
			// We don't want test specifications to execute arbitrary commands on buildhost
			if (array_key_exists("cmd", $tool_options)) unset($tool_options['cmd']);
			
			$tools[$tool] = array();
			$closest_match = array();
			
			// Iterate through all configured external tools
			foreach($conf_tools as $tool_kind => $available_tools) {
				if ($tool_kind === $toolname) {
					// Iterate through available tools of a given kind
					foreach ($available_tools as $available_tool) {
						// If language is specified in tool description, skip if no match
						if (array_key_exists("language", $available_tool) && strtolower($available_tool['language']) !== $language) continue;
						if (array_key_exists("languages", $available_tool)) {
							$tool_languages = array_map('strtolower', $available_tool['languages']);
							if (!in_array($language, $tool_languages)) continue;
						}

						// Lets create class for available tool
						if (array_key_exists('name', $available_tool)) $name = $available_tool['name']; else $name = "";
						$merged_tool = Utils::findPlugin($toolname, $language, $name, $available_tool);
						
						// There is no matching plugin, just instantiate ExternalTool with given properties
						// Config file should include sufficient data
						if (!$merged_tool)
							$merged_tool = new ExternalTool($available_tool);

						// Attempt to merge tool to find if it has all required features
						if (!$merged_tool->merge($tool_options)) continue;
						
						// Is there a version requirement?
						if (array_key_exists("version", $tool_options)) {
							if (!$merged_tool->testVersion($tool_options['version'])) {
								Utils::debugLog( "Wrong version " . $merged_tool->getVersion() . ", required " . $tool_options['version'], 1 );
								continue;
							}
						}
						
						// Special processing for "prefer" keyword
						if (array_key_exists("prefer", $tool_options) && $tool_options['prefer'] !== $available_tool['name']) {
							if (empty($closest_match)) $closest_match = $merged_tool;
						} else {
							$tools[$tool] = $merged_tool;
							// We found a match, no need to look further
							break;
						}
					}
					
					// We didn't find the exact tool, use the closest match
					if (empty($tools[$tool]) && !empty($closest_match)) {
						$tools[$tool] = $closest_match;
					}
				}
			}
			
			// Tool not found in configuration, try to find a plugin of this type (unless it "requires" certain tool)
			if (empty($tools[$tool]) && !array_key_exists('require', $tool_options))
				$tools[$tool] = Utils::findPlugin( $toolname, $language, "", $tool_options );
			
			// Still empty... sorry, we failed
			if (empty($tools[$tool])) {
				$this->errMsg = "No suitable tool of type '$toolname' found";
				return false;
			}
			
			// Test for invalid config, sadly
			if (!$tools[$tool]->exists()) {
				$this->errMsg = "Tool '$toolname' specified in config but not found. Please check your Config.php";
				return false;
			}
			
			if ($tools[$tool]->getVersion())
				Utils::debugLog( "Found $tool: " . $tools[$tool]->getVersion(), 1 );
			// Let the tool know what kind of tool it is :D
			$tools[$tool]->tool = $tool;
		}
		
		return $tools;
	}
	
	// Run all tests in task specification
	public function runAllTests() {
		$this->testResults = array();
		foreach($this->taskDesc['tests'] as $testDesc) {
			if (array_key_exists('id', $testDesc))
				$id = $testDesc['id'];
			else if (count($this->testResults) == 0)
				$id = 1;
			else
				$id = max( array_keys($this->testResults) ) + 1;
			
			$test = new Test($this, $testDesc);
			// Sometimes construtor can fail the test (if tool isn't found - but that is poorly written spec)
			if ($test->result['success']) $test->run();
			$this->testResults[$id] = $test->result;
			
			if (!in_array("reuse", $test->options) && !empty($this->lastTest))
				$this->lastTest->purge();
			if (in_array("nodetail", $test->options))
				$this->testResults[$id] = array ( "success" => $test->result['success'] );
			if (in_array("silent", $test->options) && $test->result['success'])
				unset( $this->testResults[$id] );
				
			// Some tests reference previous test in some way, 
			// Task class will provide this service to the Test class
			$this->lastTest = $test;
			
			$this->result['test_results'] = $this->testResults;
			$this->result['time'] = time();
			
			$result = $this->result;
			global $stop_testing;
			eval($this->afterEachTest);
			if ($stop_testing) break;;
			
			if (in_array("terminate", $test->options) && !$test->result['success'])
				break;
		}
		if ($this->lastTest) $this->lastTest->purge();
		$this->lastTest = null;
	}

	// Run task with all tests, return task result object, or false if task is rejected
	public function run() {
		global $buildhost_id; // defined in Config
		
		$this->result = array( "buildhost_description" => array(
				"id" => $buildhost_id, 
				"os" => Utils::getOsVersion()
			),
			"tools" => [],
			"status" => PROGRAM_CURRENTLY_TESTING
		);
		
		// Add tool details to results
		foreach ($this->tools[$this->language] as $name => $tool)
			if ($tool->getVersion())
				$this->result['tools'][$name] = $tool->getVersion();
			
		$this->runAllTests();
		if (array_key_exists('prepare', $this->testResults)) {
			if ($this->testResults['prepare']['status'] == TEST_COMPILE_FAILED)
				$this->result['status'] = PROGRAM_COMPILE_ERROR;
		} else {
			$this->result['test_results'] = $this->testResults;
			$this->result['status'] = PROGRAM_FINISHED_TESTING;
		}
		return $this->result;
	}
}
