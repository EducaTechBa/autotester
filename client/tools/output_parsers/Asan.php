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

// Asan.php - parse output of AddressSanitizer (-fsanitize=address)


class Asan extends ExternalTool {
	public function run() {
		if (array_key_exists("fast", $this->properties) && $this->properties['fast']) {
			$input_file = "stderr.txt";
			if (array_key_exists('input_file', $this->properties)) $input_file = $this->properties['input_file'];
			$input_file = $this->test->path() . "/" . $input_file;
			if (file_exists($input_file)) $this->result['output'] = file_get_contents($input_file);
		} else {
			parent::run();
		}
		
		$this->result['status'] = PROFILER_OK;
		$this->result['success'] = true;
		
		if (!array_key_exists('output', $this->result)) return; 
		
		$profiler_output = explode("\n", $this->result['output']);
		$context = "";
		$current_message = $parsed_output = [];
	
		$message_strings = array( 
			array ( "msg" => "heap-use-after-free", "status" => PROFILER_OOB),
			array ( "msg" => "heap-buffer-overflow", "status" => PROFILER_OOB),
			array ( "msg" => "stack-buffer-overflow", "status" => PROFILER_OOB),
			array ( "msg" => "stack-buffer-underflow", "status" => PROFILER_OOB),
			array ( "msg" => "dynamic-stack-buffer-overflow", "status" => PROFILER_OOB),
			array ( "msg" => "global-buffer-overflow", "status" => PROFILER_OOB),
			array ( "msg" => "alloc-dealloc-mismatch", "status" => PROFILER_MISMATCHED_FREE),
			array ( "msg" => "double-free", "status" => PROFILER_INVALID_FREE),
			array ( "msg" => "new-delete-type-mismatch", "status" => PROFILER_MISMATCHED_FREE),
			array ( "msg" => "stack-use-after-return", "status" => PROFILER_UNINIT),
			array ( "msg" => "stack-use-after-scope", "status" => PROFILER_UNINIT),
			array ( "msg" => "use-after-poison", "status" => PROFILER_UNINIT),
			array ( "msg" => "Direct leak of", "status" => PROFILER_MEMLEAK),
			array ( "msg" => "Indirect leak of", "status" => PROFILER_MEMLEAK),
			array ( "msg" => "ERROR: AddressSanitizer: FPE", "status" => PROFILER_FPE),
			array ( "msg" => "requested allocation size", "status" => PROFILER_MEMORY_EXCEEDED),
		);
		
		$crashed = false;
		
		foreach ($profiler_output as $profiler_line) {
			// Remove PID
			$profiler_line = preg_replace("/^==\d+==/", "", $profiler_line);
			if (empty(trim($profiler_line)) || trim($profiler_line) == "=================================================================" ||
				$profiler_line == "timeout: the monitored command dumped core"
			) continue;
			
			if ($profiler_line == "AddressSanitizer:DEADLYSIGNAL" || $profiler_line == "Segmentation fault") {
				Utils::debugLog( "AddressSanitizer: The program crashed!", 2 );
				$crashed = true;
				continue;
			}
			
			// New message
			if (strstr($profiler_line, "ERROR: AddressSanitizer: ") || strstr($profiler_line, "ERROR: LeakSanitizer: ") || trim($profiler_line) == "ABORTING" || strstr($profiler_line, "irect leak")) {
				// Store current message
				$this->addMessage($current_message, $parsed_output);
				$current_message = [ 'output' => "" ];
				$context = "";
				
				// Find error message
				foreach ($message_strings as $msg) {
					if (strstr($profiler_line, $msg['msg'])) {
						$current_message['type'] = $msg['status'];
						if ($this->result['status'] === PROFILER_OK)
							$this->result['status'] = $msg['status'];
						$current_message['output'] = $this->test->removeBasePath( $profiler_line ) . "\n";
					}
				}
				
				// Nothing more to do here
				continue;
			}

			// Parsing sourcecode line
			else if (strstr($profiler_line, "previously allocated by thread")) {
				$context = "allocated";
			}
			
			else if (strstr($profiler_line, "freed by thread")) {
				$context = "freed";
			}
			
			else if (preg_match("/^\s+\#\d+ 0x[\dA-Fa-f]+ in .*? (\S*?\:\d+)$/", $profiler_line, $matches)) {
				list($profiler_file, $profiler_lineno) = explode(":", $matches[1]);
				if (!Utils::startsWith($profiler_file, $this->test->path()))
					continue;
				// We're looking for first mention of file that is part of our project
				// All filenames produced by valgrind will be relative paths
				foreach ($this->test->sourceFiles as $file) {
					if ($profiler_file == $file) {
						if ($context == "" && !array_key_exists("file", $current_message)) {
							$current_message['file'] = basename($profiler_file);
							$current_message['line'] = $profiler_lineno;
						}
						if ($context == "freed" && !array_key_exists("file_freed", $current_message)) {
							$current_message['file_freed'] = basename($profiler_file);
							$current_message['line_freed'] = $profiler_lineno;
						}
						if ($context == "allocated" && !array_key_exists("file_allocated", $current_message)) {
							$current_message['file_allocated'] = basename($profiler_file);
							$current_message['line_allocated'] = $profiler_lineno;
						}
					}
				}
			}

			if (empty($current_message['output'])) continue;
			$current_message['output'] .= $this->test->removeBasePath( Utils::clearUnicode($profiler_line) ) . "\n";
		}
		
		$this->addMessage($current_message, $parsed_output);
		$this->result['parsed_output'] = $parsed_output;
		
		if ($crashed) {
			Utils::debugLog( "Test failed - crash", 1 );
			$this->result['success'] = false;
			$this->test->result['status'] = TEST_EXECUTION_CRASH;
		}
		else if ($this->result['status'] == PROFILER_OK) {
			$this->result['success'] = true;
		} 
		else {
			$this->result['success'] = false;
			// Program didn't output anything because Asan terminated it
			if ($this->test->result['status'] == TEST_OUTPUT_NOT_FOUND) {
				Utils::debugLog( "Test failed - crash", 1 );
				$this->test->result['status'] = TEST_EXECUTION_CRASH;
			}
		}
	}
	
	private function addMessage($current_message, &$parsed_output) {
		if (empty($current_message) || $current_message === ["output" => ""]) return;
		
		// Remove duplicate messages
		$duplicate = false;
		foreach ($parsed_output as $msg)
			if (array_key_exists('file', $current_message) && $msg['file'] === $current_message['file'] && $msg['line'] === $current_message['line'] && $msg['type'] === $current_message['type'])
				$duplicate = true;

		if (!$duplicate)
			array_push($parsed_output, $current_message);
	}
}
