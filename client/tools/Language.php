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

// Language.php - abstract class for language specific routines inherited by 
// classes in language folder


class Language {
	protected $test, $tool, $language;
	
	// Map of inserted/removed lines
	// Format: line number in original source => number of lines added/removed
	public $lineNumbersMap = array();

	public function __construct($properties) {
		$this->test = $properties['test'];
		$this->tool = $properties['tool'];
		$this->language = $this->test->task->language; // shortcut
	}

	// Returns source file that contains the "main function" (code that will be
	// executed)
	// Default implementation returns the first source file
	public function findPrimaryFile() {
		if (empty($this->test->sourceFiles)) return false;
		return $this->test->sourceFiles[0];
	}

	// Returns file with executable code
	// Default implementation is good for non-compiled languages, it returns
	// primary file
	public function findExecutable() {
		return $this->findPrimaryFile();
	}
	
	// Default implementation of parse does nothing
	public function parse($options) {
		return array( "success" => true, "symbols" => array() );
	}
	
	
	// Default implementation of patch, works for C, C++ and Java
	
	// To implement new language, you need to implement some methods for your language: 
	// mainregex, mainclassregex, etc. (see below)
	// If your language is too different for such an approach, just override the patch method in your class
	
	public function patch($options) {
		// If code is not specified, do nothing
		if (!array_key_exists('code', $options))
			return array( "success" => true );
		
		$primaryFile = $this->findPrimaryFile();
		if (!$primaryFile)
			return array( "success" => false, "message" => "Couldn't find main function" );
		
		// Create backup, return file from backup (in case of folder reuse)
		// FIXME doesn't work with multiple patch calls
		$backupFile = $primaryFile . ".patch-backup";
		if (!file_exists($backupFile)) 
			copy($primaryFile, $backupFile);
		
		$main_source_code = file_get_contents($primaryFile);
		$mainFileName = basename($primaryFile);
		$parse_result = $this->parse( array() );
		$symbols = $parse_result['symbols'];
		
		// Some shorcuts
		$position    = $options['position'];
		$use_markers = $options['use_markers'];
		$try_catch   = $options['try_catch'];
		
		// For languages that have no main function, always use top_of_file
		if ($this->mainSymbol() == "" && ($position == "main" || $position == "above_main"))
			$position = "top_of_file";
		// For languages that above wouldn't work either (such as Matlab, Pascal etc.), reimplement patch function
		
		if ($position == "main") {
			// Rename main
			$newname = "main";
			$found = true;
			while ($found) {
				$found = false;
				$newname = "_$newname";
				
				// Look for newname in symbols
				if (!empty($symbols)) {
					foreach($symbols[$mainFileName] as $symbol)
						if ($symbol['name'] == $newname)
							$found = true;
				} else {
					// Some languages don't have a parser yet
					$no_whitespace = $this->replace_comments_with_whitespace($main_source_code);
					if (strstr($no_whitespace, $newname))
						$found = true;
				}
			}
			$main_source_code = preg_replace( $this->mainRegex(), "\${1}$newname\${2}", $main_source_code );
			
			// Default code if none is specified
			$test_code = $this->functionCall($newname);
			if (array_key_exists('code', $options)) $test_code = $options['code'];
			
			$markers = $this->tool->getMarkers();
			
			if ($use_markers)
				$test_code = $this->printStdout($markers[0]) . "\n" . $test_code . "\n" . $this->printStdout($markers[1]) . "\n";
			
			if ($try_catch)
				$test_code = $this->tryCatch($test_code, $markers[2]);
			
			$test_code = $this->mainFunction($test_code);
			
			// Prevent cheating
			$main_source_code = str_replace($markers[0], "====cheat_protection====", $main_source_code);
			$main_source_code = str_replace($markers[1], "====cheat_protection====", $main_source_code);
			$main_source_code = str_replace($markers[2], "====cheat_protection====", $main_source_code);
			
			$line = substr_count($main_source_code, "\n") + 1;
			$adjust = substr_count($test_code, "\n") + 2;
			if (array_key_exists($line, $this->lineNumbersMap)) 
				$this->lineNumbersMap[$line] += $adjust;
			else
				$this->lineNumbersMap[$line] = $adjust;
				
			// Construct whole file
			$main_source_code = $this->addFunction($main_source_code, $test_code);
		}
		
		else if ($position == "above_main") {
			$pos = 0;
			
			// Use main symbol if it exists
			foreach($symbols[$mainFileName] as $symbol)
				if ($symbol['name'] == $this->mainSymbol() && $symbol['type'] == "function")
					$pos = $symbol['pos'];
					
			if ($pos == 0) {
				// Try main regex on code stripped from comments
				$no_whitespace = $this->replace_comments_with_whitespace($main_source_code);
				if (preg_match($this->mainRegex(), $no_whitespace, $matches, PREG_OFFSET_CAPTURE))
					$pos = $matches[1][1];
			}
			
			if ($pos == 0)
				return array( "success" => false, "message" => "Couldn't find main function" );
				
			// Default code is nothing (meaningless)
			$test_code = "";
			if (array_key_exists('code', $options)) $test_code = $options['code'];
			
			// Find beginning of line
			while($pos > 0 && ord($main_source_code[$pos]) != 13 && ord($main_source_code[$pos]) != 10) $pos--;
			
			$main_source_code = substr($main_source_code, 0, $pos) . "\n" . $options['code'] . "\n" . substr($main_source_code, $pos);
			
			// Recalculate line number adjustments
			$line = substr_count($main_source_code, "\n", 0, $pos) + 1;
			$adjust = substr_count($options['code'], "\n") + 2;
			if (array_key_exists($line, $this->lineNumbersMap)) 
				$this->lineNumbersMap[$line] += $adjust;
			else
				$this->lineNumbersMap[$line] = $adjust;
		}
		
		else if ($position == "above_main_class") {
			$kk = $this->mainClassRegex();
			if (!empty($kk) && preg_match($kk, $main_source_code, $matches, PREG_OFFSET_CAPTURE) && array_key_exists('code', $options)) {
				$pos = $matches[1][1];
				// Find beginning of line
				while($pos > 0 && ord($main_source_code[$pos]) != 13 && ord($main_source_code[$pos]) != 10) $pos--;
				
				$main_source_code = substr($main_source_code, 0, $pos) . "\n" . $options['code'] . "\n" . substr($main_source_code, $pos);
				
				$line = substr_count($main_source_code, "\n", 0, $pos) + 1;
				$adjust = substr_count($options['code'], "\n") + 2;
				if (array_key_exists($line, $this->lineNumbersMap)) 
					$this->lineNumbersMap[$line] += $adjust;
				else
					$this->lineNumbersMap[$line] = $adjust;
			}
		}
		
		else if ($position == "top_of_file") {
			if (array_key_exists('code', $options)) {
				$main_source_code = $options['code'] . "\n" . $main_source_code;
				
				$adjust = substr_count($options['code'], "\n") + 1;
				if (array_key_exists(0, $this->lineNumbersMap)) 
					$this->lineNumbersMap[0] += $adjust;
				else
					$this->lineNumbersMap[0] = $adjust;
			}
		}
		
		file_put_contents($primaryFile, $main_source_code);
		
		return array( "success" => true );
	}
	
	
	// Implement these functions to get default patch implementation to work
	
	// Name for "main" function in this particular language
	// The only language that I know of where it isn't "main" is C# with "Main"
	// If language has no main function (i.e. PHP, Perl...) return empty string
	protected function mainSymbol() { return "main"; }
	
	// Regex for locating main function properly (also use this if parser is not implemented)
	// If language has no main function (i.e. PHP, Perl...) return empty string
	protected function mainRegex() { return "/(\s)main(\W)/"; }
	
	// Match begginning of class declaration (i.e. "class" keyword)
	// If language doesn't keep main function in class (i.e. C++), return empty string
	protected function mainClassRegex() { return ""; }
	
	// Remove all comments from code, replacing them with whitespace characters
	// Default implementation removes C-style comments /* comment */
	// and C++ style comments (such as this one), since they exist in a number of 
	// programming languages and their intersections can be tricky
	protected function replace_comments_with_whitespace($sourcecode)
	{
		global $conf_verbosity;
		
		$i=0;
		do {
			$c_comment = strpos($sourcecode, "/*", $i);
			$cpp_comment = strpos($sourcecode, "//", $i);
			if ($c_comment !== false && ($c_comment < $cpp_comment || $cpp_comment === false)) {
				$end = strpos($sourcecode, "*/", $c_comment);
				if ($end === false) {
					if ($conf_verbosity>1) $this->parser_error("C-style comment doesn't end", "", $string, $i);
					$end = strlen($sourcecode) - 2;
				}
				for ($i=$c_comment; $i<=$end+1; $i++)
					$sourcecode[$i] = " ";
			}
			if ($cpp_comment !== false && ($cpp_comment < $c_comment || $c_comment === false)) {
				$end = strpos($sourcecode, "\n", $cpp_comment);
				if ($end === false) {
					$end = strlen($sourcecode);
				}
				for ($i=$cpp_comment; $i<$end; $i++)
					$sourcecode[$i] = " ";
			}
		} while ($c_comment !== false || $cpp_comment !== false);
		return $sourcecode;
	}
	
	// Code that invokes named function
	protected function functionCall($name) { return "$name();"; }
	
	// Print text to standard output
	protected function printStdout($text) { return "print $text;"; }
	
	// Envelope code into try-catch block (just return $code if exceptions are not supported)
	protected function tryCatch($code, $exceptionText) { return $code; }
	
	// Envelope code into main function
	protected function mainFunction($code) { return "main() {\n$code\n}\n"; }
	
	// Add a new function to file
	protected function addFunction($file_source, $function) { return $file_source . "\n" . $function . "\n"; }

}
