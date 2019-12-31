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

// Patch.php - shortcut for calling Language::patch


require_once("tools/Language.php");

class Patch extends AbstractTool {
	private $lineNumbersMap;

	public function __construct($properties) {
		// Set defaults
		foreach ($properties as &$options) {
			if (!array_key_exists("position", $options))
				$options['position'] = "main";
			
			// Sometimes setting boolean in JSON is not supported by framework, so we allow strings as well
			if (!array_key_exists('use_markers', $options) || ($options['use_markers'] !== true && $options['use_markers'] != "true"))
				$options['use_markers'] = false;
			if (!array_key_exists('try_catch', $options) || ($options['try_catch'] !== false && $options['try_catch'] != "false"))
				$options['try_catch'] = true;
		}
		
		parent::__construct($properties);
	}

	public function run() {
		// Use Lanaguage::patch (or subclass, if available)
		$this->result = array( "success" => "false", "message" => "Nothing to do" );
		$plugin = Utils::findPlugin( "language", $this->test->task->language, "", array( "test" => $this->test, "tool" => $this ) );
		foreach($this->properties as $options) {
			$this->result = $plugin->patch( $options );
			if (!$this->result['success']) break;
		}
		$this->lineNumbersMap = $plugin->lineNumbersMap;
		ksort($this->lineNumbersMap);
	}
	
	// Patching markers, used to prevent cheating attempts (e.g. by faking expected output from global code)
	public function getMarkers() {
		$instance = $this->test->instance;
		
		return array(
			"====START_TEST_$instance====",
			"====END_TEST_$instance====",
			"====EXCEPTION_TEST_$instance===="
		);
	}
	
	// After patching, line numbers in tool output will no longer be correct
	// This method can be used to fix tool "parsed output" so it reflects
	// line numbers in originally submitted file
	public function fixLineNumber($parsed_result) {
		if (!array_key_exists('line', $parsed_result)) return $parsed_result;
		$lineno = $parsed_result['line'];
		$total_adjust = 0;
		foreach($this->lineNumbersMap as $line => $adjust) {
			if ($line + $total_adjust <= $lineno) {
				$total_adjust += $adjust;
				if ($line + $total_adjust > $lineno) {
					// Message actually belongs to code inserted by patch tool
					$parsed_result['file'] = "TEST_CODE";
					$parsed_result['line'] = $lineno - $line - $total_adjust + $adjust;
					return $parsed_result;
				}
			}
		}
		$parsed_result['line'] = $lineno - $total_adjust;
		return $parsed_result;
	}
}

?>
