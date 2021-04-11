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

// Server classes

// Program.php - class representing a program on server


class Program {
	public $id, $desc;
	
	public static function create($progDesc) {
		global $conf_basepath;
		$programspath = $conf_basepath . "/programs";
	
		if (!array_key_exists('id', $progDesc) || intval($progDesc['id']) == 0) {
			do {
				$progDesc['id'] = rand(1, 100000);
				$progpath = $programspath . "/" . $progDesc['id'];
			} while(file_exists($progpath));
		} else
			$progpath = $programspath . "/" . $progDesc['id'];
		
		if (!file_exists($progpath)) mkdir($progpath);
		
		$output = json_encode($progDesc, JSON_PRETTY_PRINT);
		file_put_contents( $progpath . "/description.json", $output );
		
		$program = new Program;
		$program->id = $progDesc['id'];
		$program->desc = $progDesc;
		return $program;
	}
	

	public static function fromId($id) {
		global $conf_basepath;
		if (empty($id))
			throw new Exception();
		$progpath = $conf_basepath . "/programs/$id";
		if (!file_exists($progpath))
			throw new Exception();
		
		$progDesc = json_decode( file_get_contents($progpath . "/description.json"), true );
		
		$program = new Program;
		$program->id = $id;
		$program->desc = $progDesc;
		return $program;
	}
	
	// Sets ZIP file for program, returns true if file is the same as existing
	public function setFile($file) {
		global $conf_basepath;
		
		$zippath = $this->getFilePath();
		
		$same = false;
		if (file_exists($zippath)) {
			$oldmd5 = md5_file($zippath);
			$newmd5 = md5_file($file);
			if ($oldmd5 == $newmd5) $same = true;
		}
		
		$resultPath = $conf_basepath . "/programs/" . $this->id . "/result.json";
		if (!$same && file_exists($resultPath))
			unlink($resultPath);
		
		copy($file, $zippath);
		return $same;
	}
	
	public function getFilePath() {
		global $conf_basepath;
		$zippath = $conf_basepath . "/programs/" . $this->id . "/" . $this->id . ".zip";
		return $zippath;
	}
	
	public function setResult($result) {
		global $conf_basepath;
		$resultPath = $conf_basepath . "/programs/" . $this->id . "/result.json";
		
		$output = json_encode($result, JSON_PRETTY_PRINT);
		file_put_contents( $resultPath, $output );
		
	}
	
	public function getResult() {
		global $conf_basepath;
		$resultPath = $conf_basepath . "/programs/" . $this->id . "/result.json";
		if (!file_exists($resultPath))
			return array(
				"buildhost_description" => array(), 
				"status" => PROGRAM_AWAITING_TESTS,
			);
		return json_decode( file_get_contents($resultPath), true );

	}
	
	// Delete all data related to program
	public function remove() {
		global $conf_basepath;
		$programPath = $conf_basepath . "/programs/" . $this->id;
		self::rmMinusR($programPath);
		rmdir($programPath);
	}
	
	// Delete directory with subdirectories
	public static function rmMinusR($path) 
	{
		$files = glob($path."/*"); // There should be no hidden files...
		foreach ($files as $file) {
			if (is_dir($file)) {
				self::rmMinusR($file);
				rmdir($file);
			} else {
				unlink($file);
			}
		}
	}
}
