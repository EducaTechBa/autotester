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

// CCpp.php - routines specific for C and C++ programming languages


class CCpp extends Language {
	private $symbols = array();
	
	// Find file with main function
	public function findPrimaryFile() {
		foreach($this->test->sourceFiles as $file) {
			if (!array_key_exists($file, $this->symbols))
				$this->symbols[$file] = $this->parse_c_cpp( file_get_contents($file), "C++", basename($file) );
			foreach($this->symbols[$file] as $data)
				if ($data['name'] == 'main') return $file;
		}
	}

	// findExecutable is meaningless for C/C++ since this is a parameter passed to compiler
	
	// Other functions for default implementation of Language::patch
	
	protected function printStdout($text) { 
		if (strtolower($this->language) == "c")
			return "printf(\"$text\");";
		return "std::cout << \"$text\";";
	}
	
	protected function tryCatch($code, $exceptionText) { 
		if (strtolower($this->language) == "c")
			return $code;
		return "try {\n$code\n} catch (...) {\n" . $this->printStdout($exceptionText) . "\n}\n"; 
	}
	
	protected function mainFunction($code) { return "int main() {\n$code\nreturn 0;\n}\n"; }
	
	// Version of strpos function that ignores whitespace
	private static function ws($char) {
		return ($char == " " || $char == "\n" || $char == "\t");
	}
	private static function strpos_ws($haystack, $needle, &$end) {
		$needle = trim($needle);
		for ($i=0; $i<strlen($haystack); $i++) {
			if ($haystack[$i] == $needle[0]) {
				$pos_n = $pos_h = 0;
				while ($haystack[$i+$pos_h] == $needle[$pos_n]) {
					$pos_h++;
					while ($i+$pos_h < strlen($haystack) && self::ws($haystack[$i+$pos_h]))
						$pos_h++;
					$pos_n++;
					while ($pos_n < strlen($needle) && self::ws($needle[$pos_n])) $pos_n++;
					if ($pos_n >= strlen($needle)) {
						$end = $i+$pos_h;
						while ($i > 0 && self::ws($haystack[$i-1]))
							$i--;
						return $i;
					}
					if ($i+$pos_h >= strlen($haystack))
						break;
				}
			}
		}
		return false;
	}
	
	// Helper function to remove starter code from user-submitted code
	private function findStarterCode($content, $starter_code) {
		$blocks = array();
		$oldPos = $end = -1;
		foreach(explode("===USER_CODE===", $starter_code) as $part) {
			$pos = self::strpos_ws($content, $part, $end);
			if ($oldPos == -1 && $pos != 0)
				// First chunk isn't at beginning
				return false;
			if ($pos === false)
				return false;
			if ($oldPos < $pos && $pos > 0) $blocks[] = array($oldPos, $pos);
			$oldPos = $end;
		}
		if ($oldPos < strlen($content))
			// Last chunk isn't at end
			return false;
		return $blocks;
	}
	
	// Helper function to check if value is in array of intervals
	private static function in_blocks($blocks, $val) {
		foreach($blocks as $block)
			if ($block[0] <= $val && $block[1] >= $val) 
				return true;
		return false;
	}
	
	// Parse for C and C++
	public function parse($options) {
		$found_subst = $all_symbols = array();
		foreach($this->test->sourceFiles as $file) {
			// Parse file
			$content = file_get_contents($file);
			$blocks = array ( array ( 0, strlen($content) ) );
			
			// Starter code
			if (array_key_exists("starter_code", $options)) {
				$blocks = $this->findStarterCode($content, $options['starter_code']);
				if ($blocks === false)
					return array( "success" => false, "message" => "Starter code is modified" );
			}
			$this->symbols[$file] = $this->parse_c_cpp( $content, $this->language, basename($file) );
			
			// Shortcut array
			foreach($this->symbols[$file] as $data)
				$all_symbols[] = $data['name'];
			
			// String level operations
			if (array_key_exists("require_substrings", $options))
				foreach($options['require_substrings'] as $substring)
					if (self::in_blocks($blocks, strpos($content, $substring)))
						$found_subst[$substring] = true;
			
			if (array_key_exists("ban_substrings", $options))
				foreach($options['ban_substrings'] as $substring)
					if (self::in_blocks($blocks, strpos($content, $substring)))
						return array( "success" => false, "message" => "Found forbidden substring $substring" );
			
			if (array_key_exists("ban_arrays", $options))
				foreach($this->symbols[$file] as $symbol)
					if (self::in_blocks($blocks, $symbol['pos']) && ($symbol['type'] == "array" || strstr($symbol['type'], "[]")))
						return array( "success" => false, "message" => "Found forbidden array " . $symbol['name'] );
			
			if (array_key_exists("ban_globals", $options))
				foreach($this->symbols[$file] as $symbol)
					if (self::in_blocks($blocks, $symbol['pos']) && ($symbol['type'] == "array" || $symbol['type'] == "identifier") && !array_key_exists('parent', $symbol))
						return array( "success" => false, "message" => "Found forbidden global variable " . $symbol['name'] );
			
			if (array_key_exists("replace_substrings", $options)) {
				foreach($options['replace_substrings'] as $search => $replace)
					$content = str_replace($search, $replace, $content);
				file_put_contents($file, $content);
				
				// The file has changed, so we need to parse again
				$this->symbols[$file] = $this->parse_c_cpp( $content, $this->language, basename($file) );
			}
			
			if (array_key_exists("rename_symbols", $options)) {
				$offset = 0;
				foreach($this->symbols[$file] as $data) {
					$data['pos'] += $offset;
					foreach($options['rename_symbols'] as $search => $replace) {
						if ($data['name'] == $search) {
							$content = substr($content, 0, $data['pos']) . $replace . substr($content, $data['pos'] + strlen($search) );
							$offset += strlen($replace) - strlen($search);
						}
					}
				}
				file_put_contents($file, $content);
				
				// The file has changed, so we need to parse again
				$this->symbols[$file] = $this->parse_c_cpp( $content, $this->language, basename($file) );
			}
		}
		
		if (array_key_exists("require_substrings", $options))
			foreach($options['require_substrings'] as $substring)
				if (!array_key_exists($substring, $found_subst))
					return array( "success" => false, "message" => "Couldn't find substring $substring", "symbols" => $all_symbols );
		
		if (array_key_exists("require_symbols", $options))
			foreach($options['require_symbols'] as $symbol)
				if (!in_array($symbol, $all_symbols))
					return array( "success" => false, "message" => "Couldn't find symbol $symbol", "symbols" => $all_symbols );
					
		if (array_key_exists("ban_symbols", $options))
			foreach($options['ban_symbols'] as $symbol)
				if (in_array($symbol, $all_symbols))
					return array( "success" => false, "message" => "Found forbidden symbol $symbol", "symbols" => $all_symbols );
		
		// Remove paths from symbols array
		$symbols_return = array();
		foreach($this->symbols as $key => $value)
			$symbols_return[basename($key)] = $value;
		
		return array( "success" => true, "symbols" => $symbols_return );
	}
	
	
	// ================================================
	
	// Helper functions for parser
	
	// This is currently only used to find which file contains main()
	// but in the future other tests may be implemented that don't require a compiler

	// Finds matching closed brace for open brace at $pos
	// In case there is no matching brace, function will return strlen($string)
	private static function find_matching($string, $pos) 
	{
		global $conf_verbosity;

		$open_chr = $string[$pos];
		if ($open_chr === "{") $closed_chr = "}";
		else if ($open_chr === "(") $closed_chr = ")";
		else if ($open_chr === "[") $closed_chr = "]";
		else if ($open_chr === "<") $closed_chr = ">";
		else $closed_chr = ")"; // This is surely an error, but at least avoid infinite loop
		$level=0;
		
		for ($i=$pos; $i<strlen($string); $i++) {
			if ($string[$i] === $open_chr) $level++;
			if ($string[$i] === $closed_chr) $level--;
			if ($level==0) break;
			// Skip various non-code blocks
			if (substr($string, $i, 2) == "//") $i = $this->skip_to_newline($string, $i);
			if (substr($string, $i, 2) == "/*") {
				$eoc = strpos($string, "*/", $i);
				if ($eoc === false) {
					if ($conf_verbosity>1) self::parser_error("C-style comment doesn't end", "", $string, $i);
					break;
				}
				$i = $eoc+1;
			}
			if ($string[$i] == "'") {
				$start = $i;
				$end = $i+1;
				$count = 0;
				while ($end < strlen($string) && $string[$end] != "'") {
					if ($string[$end] == "\\") $end++;
					$end++;
					$count++;
				}
				if ($end >= strlen($string)) {
					if ($conf_verbosity>1) self::parser_error("unclosed char literal", "", $string, $i);
					break;
				}
				if ($count > 1) self::parser_error("too long char literal", "", $string, $i);
				if ($count == 0) self::parser_error("empty char literal", "", $string, $i);
				$i = $end;
			}
			if ($string[$i] == '"') {
				$end = $i+1;
				while ($end < strlen($string) && $string[$end] != '"') {
					if ($string[$end] == "\\") $end++;
					$end++;
				}
				if ($end >= strlen($string)) {
					if ($conf_verbosity>1) self::parser_error("unclosed string literal", "", $string, $i);
					break;
				}
				$i = $end;
			}
		}
		return $i;
	}

	// Display error message with some context
	private static function parser_error($msg, $file, $code, $pos)
	{
		$context_before = $context_after = 20;

		print "C/C++ parser error: $msg\n";

		print "   ";
		if (!empty($file)) print "File: $file, ";

		// Get line number
		$line = 1;
		for ($i=0; $i<$pos; $i++) if ($code[$i] == "\n") $line++;
		print "Line: $line, ";

		$start = $pos - $context_before;
		$end   = $pos + $context_after;
		if ($start < 0) $start=0;
		if ($start > strlen($code)) $start=strlen($code);
		if ($end > strlen($code)) $end=strlen($code);
		print "Context: ".substr($code, $start, $end-$start)."\n";
	}


	private static function skip_whitespace($string, $i) 
	{
		while ( $i<strlen($string) && ctype_space($string[$i]) ) $i++;
		return $i;
	}

	// Valid identifier characters in C and C++
	private static function ident_char($c) { return (ctype_alnum($c) || $c == "_"); }

	// Skip ident chars
	private static function skip_ident_chars($string, $i) 
	{
		while ( $i<strlen($string) && self::ident_char($string[$i]) ) $i++;
		return $i;
	}
	private function skip_to_newline($string, $i) 
	{
		$i = strpos($string, "\n", $i);
		if ($i===false) return strlen($string);
		return $i;
	}

	private function skip_template($string, $i)
	{
		global $conf_verbosity;

		if ($i>=strlen($string) || $string[$i] !== "<") return false;
		$i = self::find_matching($string, $i);
		if ($i === false) {
			if ($conf_verbosity>1) self::parser_error("template never ends", "", $string, $i);
			return false;
		}
		return $i;
	}

	private function skip_constructor($string, $pos)
	{
		global $conf_verbosity;

		$open_brace_pos = strpos($string, "(", $pos);
		if ($open_brace_pos) $close_brace_pos = self::find_matching($string, $open_brace_pos);
		if (!$open_brace_pos || $close_brace_pos == strlen($string)) {
			if ($conf_verbosity>1) self::parser_error("ctor invalid parameter list", "", $string, $pos);
			return false;
		}

		$colon_pos = strpos($string, ":", $close_brace_pos);
		$sc_pos    = strpos($string, ";", $close_brace_pos);
		$curly_pos = strpos($string, "{", $close_brace_pos);
		if ($colon_pos !== false && ($sc_pos === false || $colon_pos < $sc_pos) && ($curly_pos === false || $colon_pos < $curly_pos)) {
			for ($i=$colon_pos+1; $i<strlen($string); $i++) {
				$i = self::skip_whitespace($string, $i);
				if ($string[$i] == ';' || $string[$i] == '{') return $i;

				$i = self::skip_ident_chars($string, $i);
				$i = self::skip_whitespace($string, $i);
				if ($string[$i] == '<') {
					$i = self::find_matching($string, $i)+1;
					$i = self::skip_whitespace($string, $i);
				}
				if ($string[$i] == '(' || $string[$i] == '{') $i = self::find_matching($string, $i)+1;
				else {
					if ($conf_verbosity>1) self::parser_error("invalid init list format (no brace)", "", $string, $i);
					return false;
				}
				$i = self::skip_whitespace($string, $i);
				if ($string[$i] != ',' && $string[$i] != ';' && $string[$i] != '{') {
					if ($conf_verbosity>1) self::parser_error("invalid init list format (no comma)", "", $string, $i);
					return false;
				} else if ($string[$i] == ';' || $string[$i] == '{') $i--;
			}
		}
		return $pos;
	}
	
	
	// Find symbols within a function
	private static function parse_function($parent, $sourcecode, $start) {
		global $conf_verbosity;
		
		$end = self::find_matching($sourcecode, $start);
		if ($end === strlen($sourcecode)) {
			if ($conf_verbosity>2) self::parser_error("Function $parent never closed", "main.c", $sourcecode, $start);
			return array();
		}
		
		$cpp_types = array( "int", "short", "char", "long", "long long", "float", "double", "bool", "unsigned", "signed" );
		$symbols = array();
		$inside_dowhile = false;
		
		for ($i=$start+1; $i<$end; $i++) {
			$i = self::skip_whitespace($sourcecode, $i);
			if ($sourcecode[$i] == "{" || $sourcecode[$i] == "}" || $sourcecode[$i] == ";")
				continue;
			
			// Identifier declaration
			$type = "";
			foreach($cpp_types as $cpp_type) {
				if (substr($sourcecode, $i, strlen($cpp_type)) == $cpp_type) {
					$type = $cpp_type;
					break;
				}
			}
			if ($type != "") { // Found type
				$i += strlen($type);
				$i = self::skip_whitespace($sourcecode, $i);
				
				// Further type declaration?
				foreach($cpp_types as $cpp_type) {
					if (substr($sourcecode, $i, strlen($cpp_type)) == $cpp_type) {
						$i += strlen($cpp_type);
						$i = self::skip_whitespace($sourcecode, $i);
						$type .= " " . $cpp_type;
						break;
					}
				}
				// Pointers / references
				while ($i < $end && ($sourcecode[$i] == "*" || $sourcecode[$i] == "&")) {
					$type .= $sourcecode[$i];
					$i = self::skip_whitespace($sourcecode, $i+1);
				}
				
				// Identifier 
				do {
					$i = self::skip_whitespace($sourcecode, $i);
					$end_ident = self::skip_ident_chars($sourcecode, $i);
					$ident_name = substr($sourcecode, $i, $end_ident - $i);
					$symbol = array( 'name' => $ident_name, 'pos' => $i, 'type' => $type, 'parent' => $parent );
					
					// Is it an array?
					$i = self::skip_whitespace($sourcecode, $end_ident);
					if ($sourcecode[$i] == '[')
						$symbol['type'] .= "[]";
					$symbols[] = $symbol;
					
					// Are there other variables declared in this statement?
					$comma_pos     = strpos($sourcecode, ",", $i);
					$semicolon_pos = strpos($sourcecode, ";", $i);
					$i = (($comma_pos != false && $comma_pos < $semicolon_pos) ? $comma_pos : $semicolon_pos) + 1;
				} while ($comma_pos != false && $comma_pos < $semicolon_pos);
				continue;
			}
			
			// Now comes identifier or language construct
			$end_ident = self::skip_ident_chars($sourcecode, $i);
			$ident_name = substr($sourcecode, $i, $end_ident - $i);
			
			// Skip labels && namespace
			$j = self::skip_whitespace($sourcecode, $end_ident);
			if ($sourcecode[$j] == ":") {
				$i = $j;
				if ($sourcecode[$i+1] == ":")
					$i++;
				else if ($conf_verbosity>1) print "Label $ident_name\n";
				continue;
			}
			
			$ident_name = trim($ident_name);
			if (empty($ident_name) && $conf_verbosity>1) self::parser_error("Empty ident", "main.c", $sourcecode, $i);
			
			// Loops
			if ($ident_name == "for" || $ident_name == "while" || $ident_name == "do") {
				if ($ident_name == "do") {
					$ident_name = "do-while";
					$inside_dowhile = true;
				}
				
				if ($ident_name == "while" && $inside_dowhile)
					$inside_dowhile = false;
				else
					$symbols[] = array( 'name' => $ident_name, 'pos' => $i, 'type' => 'loop', 'parent' => $parent );
			}
			
			// Control statements
			if ($ident_name == "switch")
				$symbols[] = array( 'name' => $ident_name, 'pos' => $i, 'type' => 'switch', 'parent' => $parent );
			if ($ident_name == "goto")
				$symbols[] = array( 'name' => $ident_name, 'pos' => $i, 'type' => 'goto', 'parent' => $parent );
				
			if ($ident_name == "for" || $ident_name == "while" || $ident_name == "switch" || $ident_name == "if" || $ident_name == "catch") {
				$i = strpos($sourcecode, "(", $i);
				$i = self::find_matching($sourcecode, $i);
			}
			else if ($ident_name == "do-while" || $ident_name == "try")
				$i += strlen($ident_name);
			else
				$i = strpos($sourcecode, ";", $i);
		}
		
		return $symbols;
	}
	

	// Find symbols in global scope to know which files need to be included
	private function parse_c_cpp($sourcecode, $language, $file /* Only used for error messages... */) 
	{
		global $conf_verbosity;
		
		// It's simpler to remove all comments from sourcecode in advance
		$sourcecode = $this->replace_comments_with_whitespace($sourcecode);

		$symbols = array();

		$lineno=1;
		for ($i=0; $i<strlen($sourcecode); $i++) {
			$i = self::skip_whitespace($sourcecode, $i);
			if ($i==strlen($sourcecode)) break;
			
			// Find #define'd constants
			if (substr($sourcecode, $i, 7) == "#define") {
				$i = self::skip_whitespace($sourcecode, $i+7);
				
				// If valid identifier doesn't follow, syntax error
				if (!self::ident_char($sourcecode[$i])) {
					if ($conf_verbosity>1) self::parser_error("invalid symbol after #define: ".$sourcecode[$i], $file, $sourcecode, $i);
					break;
				}
				
				$define_begin = $i;
				$i = self::skip_ident_chars($sourcecode, $i);
				$define_name = substr($sourcecode, $define_begin, $i-$define_begin);
				if ($conf_verbosity>2) print "Define $define_name\n";
				$symbols[] = array( 'name' => $define_name, 'pos' => $define_begin, 'type' => "define" );
				
				while (1) {
					$i = $this->skip_to_newline($sourcecode, $i);
					if ($i < strlen($sourcecode) - 2 && $sourcecode[$i-1] == "\\") $i+=2; // multiline defines
					else break;
				}
				continue;
			}
			
			// Find classes and structs
			if (substr($sourcecode, $i, 5) == "class" || substr($sourcecode, $i, 6) == "struct") {
				if (substr($sourcecode, $i, 5) == "class") $symbol_type = "class"; else { $symbol_type = "struct"; $i++; }
				
				$i = self::skip_whitespace($sourcecode, $i+5); 
				
				// If a valid identifier doesn't follow the keyword, syntax error
				if (!self::ident_char($sourcecode[$i])) {
					if ($conf_verbosity>1) self::parser_error("invalid symbol after class/struct: ".$sourcecode[$i], $file, $sourcecode, $i);
					break;
				}
				
				$class_begin = $i;
				$i = self::skip_ident_chars($sourcecode, $i);
				$class_name = substr($sourcecode, $class_begin, $i-$class_begin);
				
				// If semicolon is closer than curly brace, this is just forward definition so we skip it
				$sc_pos    = strpos($sourcecode, ";", $i);
				$curly_pos = strpos($sourcecode, "{", $i);
				
				// there is neither curly nor semicolon, syntax error
				if ($curly_pos === false && $sc_pos === false) {
					if ($conf_verbosity>1) self::parser_error("neither ; nor { after class/struct", $file, $sourcecode, $i);
					break;
				}

				if ($curly_pos === false || ($sc_pos !== false && $sc_pos < $curly_pos)) {
					$i = $sc_pos;
					continue;
				}
				
				if ($conf_verbosity>2) print "$symbol_type $class_name\n";
				$symbols[] = array( 'name' => $class_name, 'pos' => $class_begin, 'type' => $symbol_type );
				
				// Skip to end of block
				$i = self::find_matching($sourcecode, $curly_pos);
				if ($i==strlen($sourcecode)) {
					if ($conf_verbosity>1) self::parser_error("missing closed curly", $file, $sourcecode, $curly_pos);
					break;
				}
			}
			
			// Skip other preprocessor directives
			if ($sourcecode[$i] == "#") {
				// Skip to newline
				$i = $this->skip_to_newline($sourcecode, $i);
				continue;
			}
			
			// Skip using
			if (substr($sourcecode, $i, 5) == "using") {
				// Skip to semicolon
				$sc_pos = strpos($sourcecode, ";", $i);
				if ($sc_pos === false) {
					if ($conf_verbosity>1) self::parser_error("missing semicolon after 'using'", $file, $sourcecode, $i);
					break;
				}
				$i = $sc_pos+1;
			}
			
			// Skip template definitions
			if (substr($sourcecode, $i, 8) == "template") {
				$i = self::skip_whitespace($sourcecode, $i+8);
				if ($i<strlen($sourcecode) && $sourcecode[$i] == "<") {
					$i = $this->skip_template($sourcecode, $i);
					if ($i === false) break;
				} else {
					// No template after "template" keyword? syntax error
					if ($conf_verbosity>1) self::parser_error("no template after 'template' keyword: ".$sourcecode[$i], $file, $sourcecode, $i);
					break;
				}
			}
			
			// The rest is likely an identifier of some kind - we want that!
			if (self::ident_char($sourcecode[$i])) {
				// Skip keyword const
				if (substr($sourcecode, $i, 5) == "const")
					$i = self::skip_whitespace($sourcecode, $i+5); 

				// skip type
				$multiword = array("long double", "unsigned int", "unsigned long", "short int", "unsigned short", "long long int", "long long", "unsigned char", "signed char", "enum class"); // TODO add others
				$found = false;
				foreach($multiword as $type) 
					if (strlen($sourcecode)>$i+strlen($type) && substr($sourcecode, $i, strlen($type)) == $type) {
						$found = true;
						$start_type = $i;
						$i += strlen($type);
						$end_type = $i;
						break;
					}
				if (!$found) {
					$start_ns = $end_ns = -1;
					$start_type = $i;
					$i = self::skip_ident_chars($sourcecode, $i);
					$end_type = $i;
				}
				$i = self::skip_whitespace($sourcecode, $i); 
				
				// handle stream ops as special case
				if (substr($sourcecode, $i, 2) == "<<" || substr($sourcecode, $i, 2) == ">>") {
					$i = strpos($sourcecode, ";", $i);
					if (!$i) $i = strlen($sourcecode);
					continue;
				}
				
				// skip template as part of type
				if ($sourcecode[$i] == "<") {
					$i = $this->skip_template($sourcecode, $i);
					if ($i === false || $i === strlen($sourcecode)-1) break;
					$i = self::skip_whitespace($sourcecode, $i+1); 
				}
				
				// skip namespace as part of type
				if (substr($sourcecode, $i, 2) == "::") {
					// We already skipped namespace so now we are skipping actual type
					$i = self::skip_whitespace($sourcecode, $i+2); 
					$start_ns = $start_type;
					$end_ns = $end_type;
					$start_type = $i;
					$i = self::skip_ident_chars($sourcecode, $i);
					$end_type = $i;
					$i = self::skip_whitespace($sourcecode, $i); 
					
					// handle stream ops as special case
					if (substr($sourcecode, $i, 2) == "<<" || substr($sourcecode, $i, 2) == ">>") {
						$i = strpos($sourcecode, ";", $i);
						if (!$i) $i = strlen($sourcecode);
						continue;
					}

					// skip template as part of type
					if ($sourcecode[$i] == "<") {
						$i = $this->skip_template($sourcecode, $i);
						if ($i === false || $i === strlen($sourcecode)-1) break;
						$i = self::skip_whitespace($sourcecode, $i+1); 
					}
				}

				// there could be characters: * & [] ^
				$typechars = array("*", "&", "[", "]", "^");
				while (in_array($sourcecode[$i], $typechars) && $i<strlen($sourcecode)) $i++;
				$i = self::skip_whitespace($sourcecode, $i); 
				
				// here comes identifier
				$ident_begin = $i;
				$i = self::skip_ident_chars($sourcecode, $i);
				if ($ident_begin != $i) {
					$ident_name = substr($sourcecode, $ident_begin, $i-$ident_begin);
					$i = self::skip_whitespace($sourcecode, $i); 
				
					if ($sourcecode[$i] == "<" && $ident_name !== "operator" || $sourcecode[$i] == ":") {
						// This is a class method
						$class_name = $ident_name;

						// Find method name (used just for debugging msgs)
						if ($sourcecode[$i] == "<") $i = self::find_matching($sourcecode, $i)+1;
						if ($i !== false && $i < strlen($sourcecode)-1) {
							if ($sourcecode[$i] == ":") $i += 2;
							$ident_begin = $i;
							if ($sourcecode[$i] == "~") $i++;
							$i = self::skip_ident_chars($sourcecode, $i);
							$ident_name = substr($sourcecode, $ident_begin, $i-$ident_begin);
						}

						if ($conf_verbosity>2) print "Class method $class_name::$ident_name\n";
						$symbol = array( 'name' => $ident_name, 'pos' => $ident_begin, 'type' => "identifier", "parent" => $class_name );
					} else {
						if ($conf_verbosity>2) print "Ident $ident_name\n";
						$symbol = array( 'name' => $ident_name, 'pos' => $ident_begin, 'type' => "identifier" );
						$type = substr($sourcecode, $start_type, $end_type-$start_type);
						if ($type == "enum" || $type == "enum class") $symbol['type'] = "enum";
					}
				} else {
					// This catches two cases not handled with above code
					// where ident would be detected as "type" and type as "namespace"

					if ($sourcecode[$start_type] == "~") // Destructor
						$end_type = self::skip_ident_chars($sourcecode, $start_type+1);
					$ident_name = substr($sourcecode, $start_type, $end_type-$start_type);
					
					// Typeless idents (possible...)
					if ($start_ns == -1) {
						if ($conf_verbosity>2) print "Typeless ident $ident_name\n";
						$symbol = array( 'name' => $ident_name, 'pos' => $start_type, 'type' => 'typeless identifier' );

					// Ctor, dtor and such
					} else {
						$class_name = substr($sourcecode, $start_ns, $end_ns-$start_ns);
						if ($conf_verbosity>2) print "Ctor-like ident $class_name::$ident_name\n";
						$symbol = array( 'name' => $ident_name, 'pos' => $ident_begin, 'type' => "ctor", "parent" => $class_name );

						// In case of constructor, we need to skip the initialization list
						// This wouldn't be neccessary if not for C++11 style initializers using curly braces e.g.
						// MyClass::MyClass() : attribute{value}, attribute{value} { /* Actual ctor code */ }
						$i = $this->skip_constructor($sourcecode, $end_type);
						if ($i === false) break;
					}
				}
				
				if ($ident_name == "operator") {
					$i = self::skip_whitespace($sourcecode, $i);
					$symbol['name'] .= $sourcecode[$i];
					if ($sourcecode[$i] == '(' || $sourcecode[$i] == '[') {
						$i = self::find_matching($sourcecode, $i);
						$symbol['name'] .= $sourcecode[$i];
					}
					$i++;
				}

				
				// skip to semicolon or end of block, whichever comes first
				do {
					$repeat = false;
					$sc_pos    = strpos($sourcecode, ";", $i);
					$curly_pos = strpos($sourcecode, "{", $i);
					// BUT if curly is inside braces, skip that too
					$open_brace_pos = strpos($sourcecode, "(", $i);
					
					if ($open_brace_pos && $open_brace_pos < $sc_pos && $open_brace_pos < $curly_pos) {
						if ($sc_pos !== false && $sc_pos < $curly_pos) 
							$symbol['type'] = "function prototype"; 
						else {
							$symbol['type'] = "function";
 							$symbols = array_merge($symbols, self::parse_function( $symbol['name'], $sourcecode, $curly_pos));
						}
						$i = self::find_matching($sourcecode, $open_brace_pos);
						$repeat = true;
					} 
					
					// Detect arrays and multiple declarations separated with comma
					else if ($sc_pos !== false && $sc_pos < $curly_pos) {
						$bracket = strpos($sourcecode, "[", $i);
						$comma_pos = strpos($sourcecode, ",", $i);
						
						if ($bracket !== false && $bracket < $sc_pos && $bracket < $comma_pos)
							$symbol['type'] = "array";
							
						while ($comma_pos !== false && $comma_pos < $sc_pos) {
							$symbols[] = $symbol;
							
							$ident_begin = self::skip_whitespace($sourcecode, $comma_pos+1);
							$ident_end = self::skip_ident_chars($sourcecode, $ident_begin);
							$ident_name = substr($sourcecode, $ident_begin, $ident_end-$ident_begin);
							$bracket = strpos($sourcecode, "[", $ident_begin);
							
							$symbol['name'] = $ident_name;
							$symbol['pos'] = $ident_begin;
							$comma_pos = strpos($sourcecode, ",", $ident_begin);
							if ($bracket !== false && $bracket < $sc_pos && $bracket < $comma_pos)
								$symbol['type'] = "array";
							else
								$symbol['type'] = "identifier";
						}
					}
					
				} while ($repeat);
				
				$symbols[] = $symbol;
				
				// there is neither curly nor semicolon, syntax error
				if ($curly_pos === false && $sc_pos === false) {
					if ($conf_verbosity>1) self::parser_error("neither ; nor { after identifier", $file, $sourcecode, $i);
					break;
				}

				else if ($curly_pos === false || ($sc_pos !== false && $sc_pos < $curly_pos))
					$i = $sc_pos;
				else {
					$i = self::find_matching($sourcecode, $curly_pos);
					if ($i==strlen($sourcecode)) {
						if ($conf_verbosity>1) self::parser_error("missing closed curly", $file, $sourcecode, $curly_pos);
						break;
					}
				}
			}
		}

		return $symbols;
	}

}

?>
