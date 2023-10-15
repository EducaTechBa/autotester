<?php 

// Convert from file format V1 into V2

$taskDesc = json_decode(file_get_contents($argv[1]), true);
if ($argc > 2) $target = $argv[2];

if (array_key_exists('version', $taskDesc) && $taskDesc['version'] != "2") {
	print "ERROR: Can't convert from version " . $taskDesc['version'] . " to version 3\n";
	exit(1);
}

$taskDesc['version'] = "3";
foreach($taskDesc['tests'] as $i => &$test) {
	$newTest = [];
	$notTools = [ 'id', 'name', 'options' ];
	foreach($notTools as $s) {
		if (array_key_exists($s, $test)) { 
			$newTest[$s] = $test[$s];
			unset($test[$s]);
		}
	}
	
	$newTest['tools'] = [];
	foreach($test as $key => $value) {
		if (empty($value))
			$newTest['tools'][] = $key;
		else
			$newTest['tools'][] = [ $key => $value ];
	}
	
	if (is_prepare($newTest) && !array_key_exists('prepare', $taskDesc)) {
		$taskDesc['prepare'] = $newTest['tools'];
		print "Unsetting $i\n";
		unset($taskDesc['tests'][$i]);
	} else
		$test = $newTest;
}

$taskDesc['tests'] = array_values($taskDesc['tests']);

if ($argc > 2)
	file_put_contents($target, json_encode($taskDesc, JSON_PRETTY_PRINT));
else
	print json_encode($taskDesc, JSON_PRETTY_PRINT);



function is_prepare($test) {
	return array_key_exists('options', $test) && in_array('silent', $test['options']) && in_array('terminate', $test['options']);
}


?>
