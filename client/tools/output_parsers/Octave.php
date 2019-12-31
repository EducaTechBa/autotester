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

// Octave.php - parse error messages from GNU Octave

require_once("tools/Execute.php");


class Octave extends Execute {
	public function run() {
		parent::run();
		
		if (!array_key_exists('output', $this->result)) return; 
		
		$this->result['parsed_output'] = array();
		$error_started = false;
		foreach(explode("\n", $this->result['output']) as $line) {
			if (Utils::startsWith($line, "error: ") && !$error_started) {
				$this->result['success'] = false;
				$this->result['status'] = EXECUTION_RUNTIME_ERROR;
				$msg = array ( "type" => "error", "message" => substr($line, 7) );
				$error_started = true;
			}
			else if ($error_started && Utils::startsWith($line, "    ")) {
				$line = substr($line, 4);
				foreach($this->test->sourceFiles as $file) {
					if (Utils::startsWith($line, $file . " at line ")) {
						$msg['line'] = intval(substr($line, strlen($file . " at line ")));
						$pos = strpos($line, "column");
						if ($pos)
							$msg['col'] = intval(substr($line, $pos+7));
					}
					if (Utils::startsWith($line, $file)) {
						$msg['file'] = $this->test->removeBasePath( $file );
						$this->result['parsed_output'][] = $msg;
						$error_started = false;
					}
				}
			}
		}
	}
}
