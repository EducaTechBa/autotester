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

// AbstractTool.php - abstract class for tools


abstract class AbstractTool {
	public $properties = array(), $test = array(), $result = array(), $features = array();
	public $outputFile = false, $name="", $version, $tool="";

	public function __construct($properties) {
		$this->properties = $properties;
		// special cases
		if (array_key_exists('name', $properties)) $this->name = $properties['name']; 
		if (array_key_exists('features', $properties)) $this->features = $properties['features'];
	}
	
	public function exists() {
		// Test if tool exists on the system
		return true;
	}
	
	abstract public function run();

	public function getVersion() {
		if (!array_key_exists('version_line', $this->properties)) return false;
		if (isset($this->version)) return $this->version;
		$cmd = str_replace("{path}", $this->properties['path'], $this->properties['version_line']);
		$this->version = trim(strstr(`$cmd 2>&1`, "\n", true));
		return $this->version;
	}


	// Merge settings given in specification into current tool properties
	// Returns false if specification requires features not present in current tool
	public function merge($spec) {
		// If spec requires an exact tool, fail
		if (array_key_exists("require", $spec) && $this->name !== $spec['require']) {  
			return false; 
		}
		
		if (array_key_exists("features", $spec)) {
			foreach($spec['features'] as $feature) {
				$found = false;
				foreach($this->features as $key => $value) {
					if (strtolower($feature) === strtolower($key))
						$found = true;
					
					// Feature is supported without specific switches
					else if (is_numeric($key) && strtolower($feature) === strtolower($value)) 
						$found = true;
				}
				// Current tool doesn't support a required feature
				if (!$found) return false;
			}
		}
		
		// Add everything else in specification to properties, overriding any given options
		foreach($spec as $key => $value) {
			// environment is a special case because it's a subgroup
			if ($key == "environment" && array_key_exists("environment", $this->properties)) {
				foreach ($value as $k2 => $v2)
					$this->properties['environment'][$k2] = $v2;
			} else if ($key !== "require" && $key !== "features")
				$this->properties[$key] = $value;
		}

		// Merge is successful
		return true;
	}
}



?>
