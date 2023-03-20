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

// Valgrind.php - parse output of valgrind profiler


class Valgrind extends ExternalTool {
	public function run() {
		parent::run();
		
		$this->result['status'] = PROFILER_OK;
		$this->result['success'] = true;
		
		if (!array_key_exists('output', $this->result)) return; 
		
		$profiler_output = explode("\n", $this->result['output']);
		$alLocation = false;
		$current_message = $parsed_output = [];
	
		$message_strings = array( 
			array ( "msg" => "Invalid read of size", "status" => PROFILER_OOB), 
			array ( "msg" => "Use of uninitialised value", "status" => PROFILER_UNINIT), 
			array ( "msg" => "Conditional jump or move depends on uninitialised value", "status" => PROFILER_UNINIT), 
			array ( "msg" => "are definitely lost", "status" => PROFILER_MEMLEAK), 
			array ( "msg" => "Invalid free", "status" => PROFILER_INVALID_FREE), 
			array ( "msg" => "Mismatched free", "status" => PROFILER_MISMATCHED_FREE), 
			array ( "msg" => "Invalid write of size", "status" => PROFILER_OOB), 
			array ( "msg" => "Invalid read of size", "status" => PROFILER_OOB), 
			array ( "msg" => "Source and destination overlap", "status" => PROFILER_OOB), 
		);
		
		foreach ($profiler_output as $profiler_line) {
			// Remove PID
			$profiler_line = preg_replace("/^==\d+==/", "", $profiler_line);
			
			// Looking for start of next message
			if ($current_message === array()) {
				foreach ($message_strings as $msg)
					if (strstr($profiler_line, $msg['msg'])) {
						$current_message['type'] = $msg['status'];
						if ($this->result['status'] === PROFILER_OK)
							$this->result['status'] = $msg['status'];
						$current_message['output'] = $this->test->removeBasePath( $profiler_line ) . "\n";
					}
				/*
				if (strstr($profiler_line, "cannot throw exceptions and so is aborting")) 
					... what would you want to do with this?
				*/
				continue;
			}

			// We are parsing a valgrind message
			
			if (!preg_match("/\w/", $profiler_line)) { // Empty line means end of message
				// Remove duplicate messages
				$duplicate = false;
				if ($current_message !== array())
					foreach ($parsed_output as $msg)
						if ($msg['file'] === $current_message['file'] && $msg['line'] === $current_message['line'] && $msg['type'] === $current_message['type'])
							$duplicate = true;

				if (!$duplicate)
					array_push($parsed_output, $current_message);
				$current_message = array();
				$alLocation = false;
				continue;
			}
			
			if (preg_match("/^\s+Address .*? is .*? inside a block of size/", $profiler_line)) {
				// Line where memory was alloc'ed follows
				$alLocation = true;
			}
			
			// Ordinary message line
			else if (preg_match("/^\s+(at|by) 0x[\dA-F]+: .*? \((.*?\:.*?)\)$/", $profiler_line, $matches)) {
				list($profiler_file, $profiler_lineno) = explode(":", $matches[2]);
				// We're looking for first mention of file that is part of our project
				// All filenames produced by valgrind will be relative paths
				foreach ($this->test->sourceFiles as $file) {
					if (basename($file) == basename($profiler_file)) {
						if ($alLocation === false && !array_key_exists("file", $current_message)) {
							$current_message['file'] = $profiler_file;
							$current_message['line'] = $profiler_lineno;
						}
						if ($alLocation === true && !array_key_exists("file_allocated", $current_message)) {
							$current_message['file_allocated'] = $profiler_file;
							$current_message['line_allocated'] = $profiler_lineno;
						}
					}
				}
			}
			$current_message['output'] .= $this->test->removeBasePath( Utils::clearUnicode($profiler_line) ) . "\n";
		}
		
		$this->result['parsed_output'] = $parsed_output;
		
		if ($this->result['status'] == PROFILER_OK)
			$this->result['success'] = true;
		else
			$this->result['success'] = false;
	}
}
