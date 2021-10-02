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

// Utils.php - class with common utility function useful for all php programmers


class Utils {
	public static function getOsVersion()
	{
		STATIC $os = null;
		if ($os !== null) return $os;
		
		$os = `uname -srm`; // Works on Cygwin!
		if (trim(`uname -s`) === "Linux")
			// Redirect stderr to /dev/null if lsb_release doesn't exist
			$os .= `lsb_release -sd 2>/dev/null`;
		return $os;
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


	// Test if string starts or ends with some other string without invoking the overhead of regex
	public static function startsWith($string, $substring) 
	{
		if (strlen($string) >= strlen($substring))
			if (substr($string, 0, strlen($substring)) === $substring)
				return true;
		return false;
	}

	public static function endsWith($string, $substring) 
	{
		if (strlen($string) >= strlen($substring))
			if (substr($string, strlen($string)-strlen($substring)) === $substring)
				return true;
		return false;
	}


	// Debugging output
	public static function debugLog($message, $level) 
	{
		global $conf_verbosity;
		// TODO log into file
		if ($level > $conf_verbosity) return;
		if (php_sapi_name() == "cli") print $message . "\n";
	}


	// Remove illegal and harmful unicode characters from output
	public static function clearUnicode($text) 
	{
		// We can't use this due to bug in php: https://bugs.php.net/bug.php?id=48147
		//if (function_exists('iconv'))
		//	return iconv("UTF-8", "UTF-8//IGNORE", $text);
		ini_set('mbstring.substitute_character', "none"); 
		return mb_convert_encoding($text, 'UTF-8', 'UTF-8'); 
	}

	// Our custom glob function is used to also find filenames matching glob inside subdirectories
	// Required because some submitters might have own directory structure different from expected
	public static function expandFilenameGlob($path, $globs) 
	{
		$result = array();
		foreach ($globs as $glob)
			$result = array_merge($result, glob($path . "/" . $glob));
		foreach(scandir($path) as $subpath) {
			if ($subpath != "." && $subpath != ".." && is_dir($path . "/" . $subpath))
				$result = array_merge($result, self::expandFilenameGlob($path . "/" . $subpath, $globs));
		}
		$result = array_unique($result);
		return $result;
	}


	// Find plugin class matching given criteria and instantiate its class
	public static function findPlugin($tool, $language, $name, $properties) {
		global $conf_pluginses;

		$match1 = $match2 = false;
		foreach($conf_pluginses as $class => $options) {
			if ($options['tool'] != $tool) continue;
			if (!array_key_exists("language", $options) && !array_key_exists("name", $options))
				$match1 = $class;
			// Task description should never specify both name and language - it makes no sense?
			if (array_key_exists("language", $options) && strtolower($options['language']) == strtolower($language))
				$match2 = $class;
			if (array_key_exists("name", $options) && $options['name'] == $name)
				$match2 = $class; 
		}

		if (!$match1 && !$match2) return false;
		if ($match2) $match1 = $match2;
		$classname = preg_replace("/\[.*?\]/", "", $match1);
		Utils::debugLog( "Found plugin $classname", 2 );
		
		require_once("tools/$classname.php");
		if (strchr($classname, "/"))
			$classname = substr($classname, strrpos($classname, "/")+1);
		eval("\$result = new $classname(\$properties);");
		return $result;
	}
	
	public static function diff($a, $b) {
		$pa=0; $pb=0;
		while ($pa < strlen($a) || $pb < strlen($b)) {
			if ($pa >= strlen($a)) {
					print "b [$pb-END]: '" . substr($b, $pb) . "'\n";
					break;
			}
			if ($pb >= strlen($b)) {
					print "a [$pa-END]: '" . substr($a, $pa) . "'\n";
					break;
			}
			if ($a[$pa] != $b[$pb]) {
				$da = $db = 0;
				for ($i=$pa+1; $i<strlen($a); $i++)
					if ($a[$i] == $b[$pb]) { $da = $i - $pa; break; }
				for ($i=$pb+1; $i<strlen($b); $i++)
					if ($a[$pa] == $b[$i]) { $db = $i - $pb; break; }
				if ($da == 0 && $db == 0) {
					print "a [$pa-END]: '" . substr($a, $pa) . "'\n";
					print "b [$pb-END]: '" . substr($b, $pb) . "'\n";
					break;
				}
				else if ($da < $db || $db != 0) {
					print "a [$pa-" . ($pa+$da) . "]: '" . substr($a, $pa, $da) . "'\n";
					print "context: " . substr($a, $pa-20, $da+20) . "\n";
					$pa += $da;
				} else {
					print "b [$pb-" . ($pb+$db) . "]: '" . substr($b, $pb, $db) . "'\n";
					$pb += $db;
				}
			}
			$pa++; $pb++;
		}
	}
	
	public static function generateCallTrace()
	{
		$e = new Exception();
		$trace = explode("\n", $e->getTraceAsString());
		// reverse array to make steps line up chronologically
		$trace = array_reverse($trace);
		array_shift($trace); // remove {main}
		array_pop($trace); // remove call to this method
		$length = count($trace);
		$result = array();
	
		for ($i = 0; $i < $length; $i++) {
			$result[] = ($i + 1)  . ')' . substr($trace[$i], strpos($trace[$i], ' ')); // replace '#someNum' with '$i)', set the right ordering
		}
	
		return "\t" . implode("\n\t", $result);
	}
}

