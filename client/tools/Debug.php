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

// Debug.php - tool for processing coredump


class Debug extends ExternalTool {
	// Debug should inherit constructor from AbstractTool...

	public function run() {
		$this->result = array( "success" => true ); // This tool always succeeds
		
		// If program crashed, execute tool should have a "core" in its result
		$this->properties['coredump'] = "";
		foreach ($this->test->tools as $toolname => $tool) {
			if ($toolname == "execute" && !empty($tool->result['core']))
				$this->properties['coredump'] = $tool->result['core'];
		}

		if (empty($this->properties['coredump'])) return;

		parent::run();
		
		// Remove coredump file so it doesn't interfere with other tools
		unlink($this->properties['coredump']);
		unset($this->result['core']);
		$this->result['success'] = true; // success will become false because of presence of coredump file
	}
}
