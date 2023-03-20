<?php 

// Convert from Valgrind profiler to ASan

$taskDesc = json_decode(file_get_contents($argv[1]), true);

if (!array_key_exists('tools', $taskDesc)) $taskDesc['tools'] = [];
if (array_key_exists('compile[debug]', $taskDesc['tools'])) {
	if (!array_key_exists('features', $taskDesc['tools']['compile[debug]']))
		$taskDesc['tools']['compile[debug]']['features'] = [];
	$taskDesc['tools']['compile[debug]']['features'][] = "asan";
}
if (array_key_exists('execute', $taskDesc['tools'])) {
	$taskDesc['tools']['execute'] = [ "require" => "asan" ];
}
$taskDesc['tools']['profile[asan]'] = [ "require" => "asan", "input_file" => "stderr.txt" ];
unset($taskDesc['tools']['profile[memcheck]']);
unset($taskDesc['tools']['profile[sgcheck]']);

foreach($taskDesc['tests'] as &$test) {
	if (array_key_exists('compile', $test) && array_key_exists('compile[debug]', $test))
		unset($test['compile']);
	/*if (array_key_exists('patch', $test)) // With this we can't detect UNEXCEPTED_EXPECTION
		foreach($test['patch'] as &$patch)
			if (array_key_exists('use_markers', $patch))
				unset($patch['use_markers']);*/
	unset($test['profile[memcheck]']);
	unset($test['profile[sgcheck]']);
	$test['profile[asan]'] = [ "require" => "asan", "input_file" => "stderr.txt", "fast" => true];
}


print json_encode($taskDesc, JSON_PRETTY_PRINT);







?>
