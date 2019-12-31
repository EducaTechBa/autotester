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

// Execute.php - tool for program execution


class Execute extends ExternalTool {
	public function run() {
		parent::run();

		// Program does not fail the test if exit code is not zero
		$this->result['success'] = ( $this->result['status'] === EXECUTION_SUCCESS ||
			 $this->result['status'] === EXECUTION_CODE_NOT_ZERO );
	}
}
