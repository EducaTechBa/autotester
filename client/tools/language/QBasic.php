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

// CCpp.php - routines specific for C and C++ programming languages


class QBasic extends Language {
	// Patch for QBasic
	public function patch($options) {
		$primaryFile = $this->findPrimaryFile();
		if (!$primaryFile)
			return array( "success" => false, "message" => "Couldn't find main function" );
		
		// Create backup, return file from backup (in case of folder reuse)
		$backupFile = $primaryFile . ".patch-backup";
		if (file_exists($backupFile)) 
			copy($backupFile, $primaryFile);
		else
			copy($primaryFile, $backupFile);
		
		$main_source_code = file_get_contents($primaryFile);
		
		if (array_key_exists('code', $options)) {
			$instance = $this->test->instance;
			if ($position == "main") {
				// default code should be "GOSUB main";
				$main_source_code = "GOTO test$instance\nmain:\n$main_source_code\nRETURN\ntest$instance\n" . $options['code'] . "\n";
			}
			else if ($position == "above_main") {
				$main_source_code = "GOTO main$instance\n" . $options['code'] . "main:\n$main_source_code\n";
			}
		}

		// By default we patch QBasic programs so that output is redirected to console
		$main_source_code = "\$CONSOLE:ONLY\n_DEST _CONSOLE\n$main_source_code\nSYSTEM\n";
		if (strpos($main_source_code, "END\n"))
			$main_source_code = str_replace("END\n", "SYSTEM\n", $main_source_code);
		else if (strpos($main_source_code, "END\r"))
			$main_source_code = str_replace("END\r", "SYSTEM\r", $main_source_code);
		else if (strpos($main_source_code, "\nEND"))
			$main_source_code = str_replace("\nEND", "\nSYSTEM", $main_source_code);
		else if (strpos($main_source_code, "\rEND"))
			$main_source_code = str_replace("\rEND", "\rSYSTEM", $main_source_code);
		
	
		file_put_contents($primaryFile, $main_source_code);

		return array( "success" => true, "primaryFile" => $primaryFile );
	}

}

?>
