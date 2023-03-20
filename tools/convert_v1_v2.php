<?php 

// Convert from file format V1 into V2

$data = json_decode(file_get_contents($argv[1]), true);

$data2 = array();
$data2['id'] = $data['id'];
$data2['name'] = $data['name'];
$data2['languages'] = array ( $data['language'] );

$data2['tools'] = array ( 
	"compile" => array( "require" => $data['required_compiler'], "features" => array( "optimize", "warn", "pedantic" ) ),
	"compile[debug]" => array( "require" => $data['required_compiler'], "features" => array( "debug" ) ),
	"execute" => array( "environment" => array( "timeout" => 10, "memory" => 1000000 ) ),
	"debug" => array(),
	"profile[memcheck]" => array( "require" => "valgrind", "features" => array( "memcheck" ), "environment" => array( "timeout" => 10) ),
	"profile[sgcheck]" => array( "require" => "valgrind", "features" => array( "sgcheck" ), "environment" => array( "timeout" => 10) ),
);
if ($data['language'] == "C++") {
	$data2['tools']['compile']['features'][] = "C++14";
	$data2['tools']['compile[debug]']['features'][] = "C++14";
}

$data2['tests'] = array( 
	array( "compile" => array(), "options" => array( "silent", "terminate" ) )
);

$first = true;
foreach($data['test_specifications'] as $test) {
	$test2 = array(
		"id" => $test['id'],
	);
	$patch = array();
	if ($test['code'] != "_main();" || !$first && $oldtest['code'] != "_main();")
		$patch[] = array( "position" => "main", "code" => $test['code'], "use_markers" => true );
	if ($test['global_top'] != "")
		$patch[] = array( "position" => "top_of_file", "code" => $test['global_top'], "use_markers" => true );
	if ($test['global_above_main'] != "")
		$patch[] = array( "position" => "above_main", "code" => $test['global_above_main'], "use_markers" => true );
	
	if (!empty($patch)) {
		$test2['patch'] = $patch;
		$test2['compile[debug]'] = array();
	}
	else if ($first)
		$test2['compile[debug]'] = array();
		
	$test2['execute'] = array();
	if (array_key_exists("stdin", $test['running_params']) && !empty($test['running_params']['stdin']) && $test['running_params']['stdin'] !== "0") {
		$test2['execute']['environment'] = array( "stdin" => $test['running_params']['stdin'] );
	}
	$test2['execute']['expect'] = [];
	foreach($test['expected'] as $expect)
		$test2['execute']['expect'][] = str_replace("\\n", "\n", $expect);
	
	if ($test['ignore_whitespace'] == "true")
		$test2['execute']['matching'] = "whitespace";
	else if ($test['substring'] == "true")
		$test2['execute']['matching'] = "substring";
	else if ($test['regex'] == "true")
		$test2['execute']['matching'] = "regex";
		
	$test2['debug'] = array();
	$test2['profile[memcheck]'] = $test2['execute'];
	$test2['profile[sgcheck]'] = $test2['execute'];
	unset ($test2['profile[memcheck]']['expect']);
	unset ($test2['profile[sgcheck]']['expect']);
	
	if ($test['code'] == "_main();" && !$first && $oldtest['code'] == "_main();") 
		$test2['options'] = array( "reuse" );
	
	$data2['tests'][] = $test2;
	$oldtest2 = $test2;
	$oldtest = $test;
	
	$first = false;
}

print json_encode($data2, JSON_PRETTY_PRINT);

?>
