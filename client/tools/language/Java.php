<?php


// AUTOTESTER - automated compiling, execution, debugging, testing and profiling
// (c) Vedran Ljubovic and others 2014-2021.
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

// Java.php - routines specific for Java programming language


class Java extends Language {
	// Find file with main function
	public function findPrimaryFile() {
		// Primary file is the file with class set as executable class
		foreach ($this->test->tools as $toolname => $tool) {
			$toolKind = preg_replace("/\[.*?\]/", "", $toolname);
			if ($toolKind == "execute" && array_key_exists("executable", $tool->properties)) {
				$primaryClass = $tool->properties['executable'];
				
				// First assume that the file tree follows standard nomenclature
				$path = $this->test->path();
				if (is_dir($path . "/src")) $path = $path . "/src";
				$file = str_replace(".", "/", $primaryClass);
				if (file_exists($path . "/" . $file))
					return $path . "/" . $file;
				
				// Sadly, no. We will find the file with given class
				foreach($this->test->sourceFiles as $file) {
					$package = $class = "";
					foreach(file($file) as $line) {
						if (preg_match("/^\s*package\s+(\w*?)\s?;\s+$/", $line, $matches))
							$package = $matches[1] . ".";
						if (preg_match("/^\s*class\s+(\w*?)\W/", $line, $matches))
							$class = $matches[1];
					}
					if ($primaryClass == $class || $primaryClass == $package . $class)
						return $file;
				}
			}
		}
		
		// We were unable to find main class or it wasn't specified
		// Find first class containing a main function
		foreach($this->test->sourceFiles as $file) {
			$found = false;
			foreach(file($file) as $line)
				if (preg_match("/public\s+static\s+void\s+main/", $line)) return $file;
		}
		return false;
	}
	
	// Return fully-qualified class name for primary file
	// (Will only be called if executable wasn't specified in execute tool)
	public function findExecutable() {
		$file = $this->findPrimaryFile();
		$package = "";
		foreach(file($file) as $line) {
			if (preg_match("/^\s*package\s+(\w*?)\s?;\s+$/", $line, $matches))
				$package = $matches[1] . ".";
			// First class declaration in file is the main class
			if (preg_match("/^\s*(?:public\s+?)class\s+(\w*?)\W/", $line, $matches))
				return $package . $matches[1];
		}
	}
	
	// Functions needed for patch tool
	protected function mainRegex() { return "/(\spublic\s+static\s+void\s+)main(\W)/"; }
	
	protected function mainClassRegex() { return "/\sclass\s/"; } // TODO more elaborate regex?
	
	protected function printStdout($text) { return "System.out.print(\"$text\");"; }
	
	protected function tryCatch($code, $exceptionText) { 
		// Output exception class name as well
		return "try {\n$code\n } catch (Exception e) {\n" . $this->printStdout($exceptionText . "+ e") . "}\n"; 
	}
	
	protected function mainFunction($code) { return "public static void main(String[] args) {\n$code;\n}\n"; }
	
	protected function addFunction($file_source, $function) {
		// Add function before the last } (inside class)
		$pos = strrpos($file_source, "}");
		return substr($file_source, 0, $pos-1) . "\n$function\n" . substr($file_source, $pos-1);
	}
}


?>
