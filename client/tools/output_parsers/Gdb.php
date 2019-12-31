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

// Gdb.php - parse output of gdb debugger (very minimal currently - just locates 
// the file and line where crash happened)

require_once("tools/Debug.php");


class Gdb extends Debug { // We must inherit Debug for core file processing
	public function run() {
		parent::run();
		
		if (!array_key_exists('output', $this->result)) return; 
		
		$debugger_output = explode("\n", $this->result['output']);
		$line = -1;
		$msg = array();
		
		// Find first line in backtrace that belongs to our source
		foreach ($debugger_output as $output_line) {
			// Remove useless lines from output
			if (strstr($output_line, " in ?? ()")) {
				$this->result['output'] = str_replace( $output_line . "\n", "", $this->result['output'] );
				continue;
			}
		
			if (empty($msg)) {
				foreach ($this->test->sourceFiles as $source_file) {
					$substring = " at $source_file:";
					if ($match = strstr($output_line, $substring)) {
						$msg['file'] = $this->test->removeBasePath( $source_file );
						$msg['line'] = intval(substr($match, strlen($substring)));
						break;
					}
				}
			}
		}
		$this->result['parsed_output'] = [ $msg ];
	}
}
