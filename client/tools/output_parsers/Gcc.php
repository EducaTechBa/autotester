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

// Gcc.php - parse output of gcc compiler (both C and C++)


class Gcc extends ExternalTool {
	public function run() {
		parent::run();
		
		if (!array_key_exists('output', $this->result)) return; 
		
		// Parse gcc output
		$ignore_messages = array( "this will be reported", "ISO C90 forbids mixed declarations and code", "C++ style comments are not allowed" );
		
		$compiler_output = explode("\n", $this->result['output']);
		$current_message = array();
		$state = "";
		$this->result['parsed_output'] = array();

		foreach($compiler_output as $line) {
			$matches = array();
			if (preg_match("/^([^\:\s]*?): In function ‘(.*?)’:$/", $line, $matches)) {
				if (!empty($current_message)) $this->result['parsed_output'][] = $current_message;
				$current_message = array();
				
				$state = "infunction";
				$file = $this->test->removeBasePath( $matches[1] );
				$function = $matches[2];
				// Do nothing
			}
			else if (preg_match("/^([^\:\s]*?):(\d+):(\d+): error: (.*?)$/", $line, $matches)
				|| preg_match("/^([^\:\s]*?):(\d+): error: (.*?)$/", $line, $matches)
				|| preg_match("/^([^\:\s]*?):(\d+):(\d+): fatal error: (.*?)$/", $line, $matches)) {
				if (count($matches) == 5) $message = $matches[4]; else $message = $matches[3];

				$ignore = false;
				foreach($ignored_messages as $msg)
					if (strstr($message, $msg)) $ignore = true;
				if ($ignore) continue;
				
				if (!empty($current_message)) $this->result['parsed_output'][] = $current_message;
				$current_message = array();
				
				$state = "error";
				$current_message = array();
				$current_message['type'] = "error";
				$current_message['file'] = $this->test->removeBasePath( $matches[1] );
				$current_message['line'] = $matches[2];
				if (count($matches) == 5)
					$current_message['col'] = $matches[3];
				$current_message['message'] = $this->gcc_cleanup($message);
				//$current_message['output'] = $line;
			}
			else if (preg_match("/^([^\:\s]*?):(\d+):(\d+): warning: (.*?)$/", $line, $matches) 
				|| preg_match("/^([^\:\s]*?):(\d+): warning: (.*?)$/", $line, $matches)) {
				if (count($matches) == 5) $message = $matches[4]; else $message = $matches[3];
				
				$ignore = false;
				foreach($ignored_messages as $msg)
					if (strstr($message, $msg)) $ignore = true;
				if ($ignore) continue;
				
				if (!empty($current_message)) $this->result['parsed_output'][] = $current_message;
				$current_message = array();
				
				$state = "warning";
				$current_message = array();
				$current_message['type'] = "warning";
				$current_message['file'] = $this->test->removeBasePath( $matches[1] );
				$current_message['line'] = $matches[2];
				if (count($matches) == 5)
					$current_message['col'] = $matches[3];
				$current_message['message'] = $this->gcc_cleanup($message);
				//$current_message['output'] = $line;
			} 
			else if (preg_match("/^([^\:\s]*?):(\d+):(\d+): note: (.*?)$/", $line, $matches)) {
				$ignore = false;
				foreach($ignored_messages as $msg)
					if (strstr($message, $msg)) $ignore = true;
				if ($ignore) continue;
				
				if (!empty($current_message)) $this->result['parsed_output'][] = $current_message;
				$current_message = array();
				
				$state = "note";
				$file = $this->test->removeBasePath( $matches[1] );
				$line = $matches[2];
				$col = $matches[3];
				$msg = $matches[4];
				// Do nothing
			}
			else if (preg_match("/^([^\:\s]*?): (undefined reference to `.*?')$/", $line, $matches)) {
				if (!empty($current_message)) $this->result['parsed_output'][] = $current_message;
				$current_message = array();
				
				$state = "error";
				$current_message = array();
				$current_message['type'] = "error";
				$current_message['message'] = $this->gcc_cleanup($matches[2]);
				//$current_message['output'] = $line;
				
				$this->result['parsed_output'][] = $current_message;
				$current_message = array();
			}
			else {
				if (!empty($current_message)) {
					//$current_message['output'] .= $line;
					if (empty($current_message['code'])) {
						$line = trim($line);
						$line = preg_replace("/^\d+ \|\s+/", "", $line);
						$current_message['code'] = trim($line);
					}
				}
			}
		}
		if (!empty($current_message)) $this->result['parsed_output'][] = $current_message;
	}
	
	// Clean up unicode and other unwanted output from gcc
	private function gcc_cleanup($msg) {
		$msg = str_replace("‘", "'", $msg);
		$msg = str_replace("’", "'", $msg);
		$msg = str_replace("`", "'", $msg);
		$msg = str_replace(",", ",", $msg);
		$msg = preg_replace("/\[-W.*?\]/", "", $msg);
		$msg = str_replace("(first use in this function)", "", $msg);
		return trim($msg);
	}
}
