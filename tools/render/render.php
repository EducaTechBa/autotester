<?php

// AUTOTESTER - automated compiling, execution, debugging, testing and profiling
// (c) Vedran Ljubovic and others 2014-2023.
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


// Renderer for test results

require("status_codes.php");

if (isset($_REQUEST['language'])) {
	$language_id = basename($_REQUEST['language']);
	if (!empty($language_id) && !preg_match("/\W/", $language_id))
		require("l10n/$language_id.php");
}

function tr($txt) { 
	global $language;
	if (is_array($language) && array_key_exists($txt, $language)) return $language[$txt];
	return $txt; 
}

?>
<html>
<head>
	<title><?=tr("Test results")?></title>
	<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
	<link rel="stylesheet" href="render.css">
	<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
	<script src="render.js"></script>
	<script src="jsdiff/diff.js"></script>
</head>


<body bgcolor="#ffffff">
<?php

if (isset($_REQUEST['title']))
	print "<h2>" . $_REQUEST['title'] . "</h2>\n";

// List of test status code labels
$statuses = array(
	array( "id" => "ok", "code" => 1, "label" => tr("OK"), "description" => tr("Test successful") ),
	array( "id" => "symbol", "code" => 2, "label" => tr("Not found"), "description" => tr("Required string/symbol not found in code") ),
	array( "id" => "error", "code" => 3, "label" => tr("Can't compile"), "description" => tr("Test code couldn't be compiled") ),
	array( "id" => "too_long", "code" => 4, "label" => tr("Timeout"), "description" => tr("Test took too long to finish") ),
	array( "id" => "crash", "code" => 5, "label" => tr("Crashed"), "description" => tr("The program crashed") ),
	array( "id" => "wrong", "code" => 6, "label" => tr("Wrong output"), "description" => tr("Program output doesn't match expected output") ),
	array( "id" => "profiler", "code" => 7, "label" => tr("Run-time error"), "description" => tr("A run-time error was reported by profiler") ),
	array( "id" => "find_fail", "code" => 8, "label" => tr("No output"), "description" => tr("Program output was not found") ),
	array( "id" => "exception", "code" => 9, "label" => tr("Unexpected exception"), "description" => tr("Program throws an exception") ),
	array( "id" => "internal", "code" => 10, "label" => tr("Internal error"), "description" => tr("Internal error with autotester system") ),
	array( "id" => "unzip", "code" => 11, "label" => tr("Not a ZIP file"), "description" => tr("Unzip command failed") ),
	array( "id" => "tool", "code" => 12, "label" => tr("Internal error"), "description" => tr("Internal error - a tool failed to run") ),
	array( "id" => "parser_ok", "code" => 201, "label" => tr("OK"), "description" => tr("Code successfully parsed") ),
	array( "id" => "starter_code_modified", "code" => 202, "label" => tr("Starter code modified"), "description" => tr("Starter code was defined for this task, but you modified it") ),
	array( "id" => "forbidden_substring", "code" => 203, "label" => tr("Forbidden"), "description" => tr("Something forbidden was found in your code") ),
	array( "id" => "forbidden_array", "code" => 204, "label" => tr("Arrays are forbidden"), "description" => tr("Using arrays is forbidden in this task") ),
	array( "id" => "forbidden_global", "code" => 205, "label" => tr("Globals are forbidden"), "description" => tr("Using global variables etc. is forbidden in this task") ),
	array( "id" => "missing_substring", "code" => 206, "label" => tr("Required code"), "description" => tr("Your code is missing something that is required in this task") ),
	array( "id" => "missing_symbol", "code" => 207, "label" => tr("Required code"), "description" => tr("Your code is missing something that is required in this task") ),
	array( "id" => "forbidden_symbol", "code" => 208, "label" => tr("Forbidden"), "description" => tr("Something forbidden was found in your code") ),
	array( "id" => "profiler_ok", "code" => 701, "label" => tr("OK"), "description" => tr("Profiler reported no known errors") ),
	array( "id" => "oob", "code" => 702, "label" => tr("Memory error"), "description" => tr("Memory error (exceeded array/vector size or illegal pointer operation)") ),
	array( "id" => "uninit", "code" => 703, "label" => tr("Uninitialized"), "description" => tr("Program is accessing a variable that wasn't initialized") ),
	array( "id" => "memleak", "code" => 704, "label" => tr("Memory leak"), "description" => tr("Allocated memory was not freed") ),
	array( "id" => "invalid_free", "code" => 705, "label" => tr("Bad deallocation"), "description" => tr("Attempting to free memory that wasn't allocated") ),
	array( "id" => "mismatched_free", "code" => 706, "label" => tr("Wrong deallocator"), "description" => tr("Wrong type of deallocation used (delete vs. delete[] ...)") ),
);

$newlines = array( "\r\n", "\\n", "\n" );



function fatal_error() {
	?>
	<p style="color: red; font-weight: bold"><?=tr("Illegal request")?></p>
	<p><?=tr("If this problem persists, please contact your system administrator.")?></p>
	<?php
	exit(0);
}


function show_table($task, $result) {
	global $statuses, $language_id;
	
	$task_enc = htmlspecialchars(json_encode($task));
	$result_enc = htmlspecialchars(json_encode($result));
	
	?>
	<form action="render.php" method="POST" id="details_form">
	<input type="hidden" name="language" value="<?=$language_id?>">
	<input type="hidden" name="task" value="<?=$task_enc?>">
	<input type="hidden" name="result" value="<?=$result_enc?>">
	<input type="hidden" name="test" id="form_test_id" value="0">
	</form>
	
	<script>
	function showDetail(id) {
		document.getElementById('form_test_id').value = id;
		document.getElementById('details_form').submit();
		return false;
	}
	</script>
	
	<table border="1" cellspacing="0" cellpadding="2">
		<thead><tr>
			<th><?=tr("Test")?></th>
			<th><?=tr("Result")?></th>
			<th><?=tr("Time of testing")?></th>
			<th>&nbsp;</th>
		</tr></thead>
	<?php
	
	$no = 0;
	if (array_key_exists('prepare', $task)) {
		$prepareTest = [ 'id' => 'prepare', 'options' => [ 'silent', 'terminate' ], 'tools' => $task['prepare'] ];
		array_unshift($task['tests'], $prepareTest);
	}
	foreach($task['tests'] as $test) {
		if (!array_key_exists($test['id'], $result['test_results'])) continue;
		$tr = $result['test_results'][$test['id']];
		if (array_key_exists('options', $test) && in_array("silent", $test['options']) && $tr['status'] == TEST_SUCCESS) continue;
		if ($tr['status'] == TEST_SUCCESS) 
			$icon = "<i class=\"fa fa-check\" style=\"color: green\"></i>"; 
		else 
			$icon = "<i class=\"fa fa-times\" style=\"color: red\"></i>"; 
			
		// Get detailed status text for parser errors
		if ($tr['status'] == TEST_SYMBOL_NOT_FOUND) {
			foreach($tr['tools'] as $key => $value)
				if (substr($key, 0, 5) == "parse" && $value['status'] != PARSER_OK)
					$tr['status'] = 200 + $value['status'];
		}
			
		// Get detailed status text for profiler errors
		if ($tr['status'] == TEST_PROFILER_ERROR) {
			foreach($tr['tools'] as $key => $value)
				if (substr($key, 0, 7) == "profile" && $value['status'] != PROFILER_OK)
					$tr['status'] = 700 + $value['status'];
		}

		// Get status text
		$status_text = tr("Ok");
		if (array_key_exists('options', $test) && in_array("nodetail", $test['options']) && $tr['status'] != TEST_SUCCESS) 
			$status_text = tr("Not ok");
		else foreach($statuses as $st)
			if ($tr['status'] == $st['code'])
				$status_text = $st['label'];
		
		// Gray color for hidden tests
		if (array_key_exists('options', $test) && in_array("nodetail", $test['options']))
			$class = "gray";
		else
			$class = "";
		$no++;
		
		$nicetime = date(tr("F j, Y, g:i a"), $result['time']);
		if ($test['id'] == 'prepare')
			$noOut = tr("Pre-Test");
		else 
			$noOut = $no;
		
		?>
		<tr>
			<td class="<?=$class?>"><?=$noOut?></td>
			<td class="<?=$class?>"><?=$icon?> <?=$status_text?></td>
			<td class="<?=$class?>"><?=$nicetime?></td>
			<td>
				<a href="#" onclick="return showDetail('<?=$test['id']?>');"><?=tr("Details")?></a>
			</td>
		</tr>
		<?php
	}
}

function show_form() {
	global $language_id;
	
	?>
	<form action="render.php" method="POST">
	<input type="hidden" name="language" value="<?=$language_id?>">
	<textarea name="task" rows="10" cols="50"></textarea><br>
	<textarea name="result" rows="10" cols="50"></textarea><br>
	
	<input type="text" name="test" value="0"><br>
	<input type="submit" value=" Go ">
	</form>
	
	<?php
	
}

function escape_output($s) {
	global $newlines;
	$s = htmlspecialchars($s);
	$s = str_replace($newlines, "<br>", $s);
	$s = str_replace(" ", "&nbsp;", $s);
	return $s;
}

function escape_javascript($s) {
	global $newlines;
	$s = str_replace($newlines, "\\n", trim($s));
	$s = str_replace("'", "\'", $s);
	$s = preg_replace("/\\\\([^n])/", "\\\\\\\\\\\\$1", $s);
	return $s;
}


function message_position($msg) {
	if (array_key_exists('line', $msg)) $result = $msg['line'];
	else $result = "??";
	if (array_key_exists('file', $msg))
		if ($msg['file'] == "TEST_CODE")
			$result .= ", " . tr("test code");
		else
			$result .= ", " . tr("file") . " ".$msg['file'];
	return $result;
}


function generate_report($test, $test_result) {
	global $newlines, $statuses;
	
	if (!is_array($test_result) || !array_key_exists("status", $test_result)) {
		$tmpfname = tempnam("/tmp/render-debug-data", "FOO");
		file_put_contents($tmpfname, "INVALID OR CORRUPT TEST DATA:\n" . json_encode($test, JSON_PRETTY_PRINT) . "\n\n" . json_encode($test_result, JSON_PRETTY_PRINT) . "\n", FILE_APPEND);
		return "INVALID OR CORRUPT TEST DATA";
	}
	
	$status_text = "unknown status " . $test_result['status'];
	foreach($statuses as $st)
		if ($st['code'] == $test_result['status']) 
			$status_text = $st['description'];
				
	$report = tr("TEST STATUS: ") . $status_text . "\n\n";
	$raw = "";
	
	if (!array_key_exists("tools", $test_result)) {
		$tmpfname = tempnam("/tmp/render-debug-data", "FOO");
		file_put_contents($tmpfname, "NO TOOLS:\n" . json_encode($test, JSON_PRETTY_PRINT) . "\n\n" . json_encode($test_result, JSON_PRETTY_PRINT) . "\n", FILE_APPEND);
		return $report;
	}
	
	// Parser output
	if ($test_result['status'] >= 200 && $test_result['status'] < 300) {
		$pr = $test_result['tools']['parse'];
		
		if ($pr['status'] == STARTER_CODE_MODIFIED) {
			$starterCode = "";
			foreach($test['tools'] as $tool) {
				if (is_array($tool) && array_key_exists('parse', $tool) && array_key_exists('starter_code', $tool['parse']))
					$starterCode = $tool['parse']['starter_code'];
			}
			$report .= "Starter code:\n\n<pre>" . str_replace("\\n", "reallybackslashen", htmlentities($starterCode)) . "</pre>";
		}
		
		else {
			$report .= $pr['message'] . "\n\n";
			if ($pr['status'] == FORBIDDEN_SUBSTRING || $pr['status'] == FORBIDDEN_ARRAY || $pr['status'] == FORBIDDEN_GLOBAL || $pr['status'] == FORBIDDEN_SYMBOL) {
				$forbidden = substr($pr['message'], strrpos($pr['message'], " ") + 1);
				// TODO show context
			}
		}
	}
	
	// Debug
	if (array_key_exists('debug', $test_result['tools'])) {
		$dr = $test_result['tools']['debug'];
		if (array_key_exists('parsed_output', $dr)) {
			$report .= tr("DEBUGGER MESSAGES:") . "\n";
			foreach($dr['parsed_output'] as $msg)
				$report .= tr("Program crashes in line ") . message_position($msg) . "\n\n";
		}
		if (array_key_exists('output', $dr) && !empty($dr['output']))
			$raw .= tr("DEBUGGER OUTPUT:") . "\n". $dr['output'] . "\n\n";
	}

	// Compile
	$cr = [];
	if (array_key_exists('compile', $test_result['tools']))
		$cr = $test_result['tools']['compile'];
	else if (array_key_exists('compile[debug]', $test_result['tools']))
		$cr = $test_result['tools']['compile[debug]'];
	if (array_key_exists('parsed_output', $cr) && !empty($cr['parsed_output'])) {
		$report .= tr("COMPILER MESSAGES:") . "\n";
		foreach($cr['parsed_output'] as $msg) {
			if ($msg['type'] == "warning")
				$report .= tr("Warning in line ");
			else if ($msg['type'] == "error")
				$report .= tr("Error in line ");
			else
				$report .= tr("Message in line ");
			$report .= message_position($msg) . ":\n" . $msg['message'] . "\n\n";
		}
	}
	if (array_key_exists('output', $cr) && !empty($cr['output']))
		$raw .= tr("COMPILER OUTPUT:") . "\n" . $cr['output'] . "\n\n";

	if (array_key_exists('profile[asan]', $test_result['tools']) && $test_result['tools']['profile[asan]']['status'] != PROFILER_OK)
		$pr = $test_result['tools']['profile[asan]'];
	else if (array_key_exists('profile[memcheck]', $test_result['tools']) && $test_result['tools']['profile[memcheck]']['status'] != PROFILER_OK)
		$pr = $test_result['tools']['profile[memcheck]'];
	else if (array_key_exists('profile[sgcheck]', $test_result['tools']))
		$pr = $test_result['tools']['profile[sgcheck]'];
	else
		$pr = [];

	if (array_key_exists('parsed_output', $pr) && !empty($pr['parsed_output'])) {
		$report .= tr("PROFILER MESSAGES:")."\n";
		foreach($pr['parsed_output'] as $msg) {
			$report .= tr("Error in line ") . message_position($msg);
			foreach($statuses as $st)
				if ($st['code'] == 700 + $msg['type'])
					$report .= ":\n" . $st['description'] . "\n";
			if (array_key_exists('file_allocated', $msg)) {
				$msg['line'] = $msg['line_allocated'];
				$msg['file'] = $msg['file_allocated'];
				$report .= tr("Allocated in line ") . message_position($msg) . "\n";
			}
			if (array_key_exists('file_freed', $msg)) {
				$msg['line'] = $msg['line_freed'];
				$msg['file'] = $msg['file_freed'];
				$report .= tr("Freed in line ") . message_position($msg) . "\n";
			}
		}
	}
	if (array_key_exists('output', $pr) && !empty($pr['output']) && $pr['status'] != PROFILER_OK)
		$raw .= tr("PROFILER OUTPUT:") . "\n". $pr['output'] . "\n\n";

	$report .= "\n\n$raw";
	$report = str_replace($newlines, "<br>", $report);
	$report = str_replace("reallybackslashen", "\\n", $report);
	
	return $report;
}


function show_test($task, $result, $testId) {
	global $statuses;

	// Colors
	$input_color  = "#fcc";
	$output_color = "#cfc";
	$version = 3;
	if (array_key_exists('version', $task)) $version = $task['version'];

	
	$test = null;
	$test_no = 0;
	foreach ($task['tests'] as $t) {
		if (!array_key_exists('id', $t) || !array_key_exists($t['id'], $result['test_results'])) continue;
		$test_no++;
		if ($t['id'] == $testId) { $test = $t; break; }
	}
	if ($testId == 'prepare' && array_key_exists('prepare', $task)) {
		$test = [ 'id' => 'prepare', 'options' => [ 'silent', 'terminate' ], 'tools' => $task['prepare'] ];
		$test_no = tr("Pre-Test");
	}
	
	if ($test === null) {
		?>
		<p style="color: red; font-weight: bold"><?=tr("Illegal request")?></p>
		<p><?php printf(tr("Test with id %d not found."), $testId); ?></p>
		<?php
		return;
	}
	
	$test_result = $result['test_results'][$testId];
	
	// Remap tools
	$test_tools = [];
	foreach ($test['tools'] as $tool) {
		if (is_array($tool)) {
			foreach($tool as $toolKey => $toolValue)
				$test_tools[$toolKey] = $toolValue;
		} else {
			$test_tools[$tool] = [];
		}
	}

	// Get detailed status text for parser and profiler errors
	if ($test_result['status'] == TEST_SYMBOL_NOT_FOUND) {
		foreach($test_result['tools'] as $key => $value)
			if (substr($key, 0, 5) == "parse" && $value['status'] != PARSER_OK)
				$test_result['status'] = 200 + $value['status'];
	}
	if ($test_result['status'] == TEST_PROFILER_ERROR) {
		foreach($test_result['tools'] as $key => $value)
			if (substr($key, 0, 7) == "profile" && $value['status'] != PROFILER_OK)
				$test_result['status'] = 700 + $value['status'];
	}

	$status_text = "";
	foreach($statuses as $st)
		if ($st['code'] == $test_result['status']) 
			$status_text = $st['label'];
	
	// Status background
	if ($test_result['status'] == 1) $style = "success"; else $style = "fail";


?>
	<script>
	var expected = [];
	var diffLabel = '<?=tr('Diff')?>';
	var hideDiffLabel = '<?=tr('Hide diff')?>';
	</script>
	
	<h2><?=tr("Detailed information")?> - Test <?=$test_no?></h2>
	<p><a href="#" onclick="return showhide('buildhost_data');"><?=tr("Show information on test platform")?></a></p>
	<div id="buildhost_data" style="display:none">
		<b><?=tr("Testing system:")?></b><br><?=$result['buildhost_description']['id']?><br><br>
		<b>OS:</b><br><?=str_replace("\n", "<br>", $result['buildhost_description']['os'])?><br><br>
		<b><?=tr("Compiler version:")?></b><br><?=$result['tools']['compile']?><br><br>
		<b><?=tr("Debugger version:")?></b><br><?=$result['tools']['debug']?><br><br>
		<?php 
		if (array_key_exists("profile[memcheck]", $result['tools'])) {
			print "<b>" . tr("Profiler version:") . "</b><br>" . $result['tools']["profile[memcheck]"];
		} 
		?>
	</div>
	
	<h3><?=tr("Result")?>: <span class="<?=$style?>"><?=$status_text?></span></h3>
	<?php
	
	// Patch tool
	if (array_key_exists('patch', $test_tools))
	foreach($test_tools['patch'] as $patch) {
		if (!array_key_exists("position", $patch) || $patch['position'] == "main") {
			?>
			<h3><?=tr("Test code:")?></h3>
			<pre><?=htmlspecialchars($patch['code']);?></pre>
			<?php
		}
		else if ($patch['position'] == "top_of_file") {
			?>
			<h3><?=tr("Global scope (top of file):")?></h3>
			<pre><?=htmlspecialchars($patch['code']);?></pre>
			<?php
		}
		else if ($patch['position'] == "above_main") {
			?>
			<h3><?=tr("Global scope (above main):")?></h3>
			<pre><?=htmlspecialchars($patch['code']);?></pre>
			<?php
		}
	}
	
	
	if (array_key_exists('execute', $test_tools)) {
		$execute = $test_tools['execute'];
		
		?>
		<hr>
		<h3><?=tr("Program input/output")?></h3>
		<table border="0" cellspacing="5">
		<?php
		
		if (array_key_exists('environment', $execute) && array_key_exists('stdin', $execute['environment'])) {
			?>
			<tr><td><?=tr("Standard input:")?></td>
			<td><code><?=escape_output($execute['environment']['stdin'])?></code></td></tr>
			<?php
		}
		
		if (array_key_exists('expect', $execute)) {
			$label_printed = false;
			$exno = 0;
			foreach ($execute['expect'] as $expect) {
				if (!$label_printed) {
					?>
					<tr><td><?=tr("Expected output(s):")?></td>
					<?php
					$label_printed = true;
				} else {
					?>
					<tr><td>&nbsp;</td>
					<?php
				}
				
				?>
				<script>
					expected[<?=$exno?>] = '<?=escape_javascript($expect)?>';
				</script>
				<td><span class="fail"><code><?=escape_output($expect)?></code></span></td></tr>
				<tr><td>&nbsp;</td>
				<td><a href="#" onclick="return showDiff(<?=$exno?>)" id="showDiffLink"><?=tr("Diff")?></a></td></tr>
				<?php
				
				$exno++;
			}
		}

		if (array_key_exists('fail', $execute)) {
			$label_printed = false;
			foreach ($execute['fail'] as $expect) {
				if (!$label_printed) {
					?>
					<tr><td><?=tr("Fail on output(s):")?></td>
					<td><?=escape_output($expect)?></td></tr>
					<?php
				} else {
					?>
					<tr><td>&nbsp;</td>
					<td><?=escape_output($expect)?></td></tr>
					<?php
				}
			}
		}
		
		if (array_key_exists('matching', $execute)) {
			?>
			<tr><td><?=tr("Matching type")?></td>
			<td><?=$execute['matching']?></td></tr>
			<?php
		}
		
		if (array_key_exists('execute', $test_result['tools'])) {
			$execute_result = $test_result['tools']['execute'];
			
			if (array_key_exists('output', $execute_result)) {
				?>
				<script>
				var programOutput = '<?=escape_javascript($execute_result['output'])?>';
				</script>
				<tr><td><?=tr("Your program output:")?></td>
				<td id="programOutput"><span class="success"><code><?=escape_output($execute_result['output'])?></code></span></td></tr>
				<?php 
			}
			
			if(array_key_exists('duration', $execute_result)) {
				?>
				<tr><td><?=tr("Execution time (rounded):")?></td>
				<td><?=$execute_result['duration']?> <?=tr("seconds")?></td></tr>
				<?php
			}
		}
		
		
		?>
		</table>
		<?php

	}
	
	?>
	<hr>
	<h3><?=tr("Test report:")?></h3>
	<code><?=generate_report($test, $test_result)?></code>
	<?php
}



if(!isset($_REQUEST['task'])) {
	show_form();
	fatal_error();
}

$task = json_decode($_REQUEST['task'], true);
$result = json_decode($_REQUEST['result'], true);

if (array_key_exists('version', $task) && $task['version'] == 2) {
	$task_enc = htmlspecialchars(json_encode($task));
	$result_enc = htmlspecialchars(json_encode($result));
	$test = isset($_REQUEST['test']) ? $_REQUEST['test'] : "";
	
	?>
	<form action="render_v2.php" method="POST" id="v2form">
	<input type="hidden" name="language" value="<?=$language_id?>">
	<input type="hidden" name="task" value="<?=$task_enc?>">
	<input type="hidden" name="result" value="<?=$result_enc?>">
	<input type="hidden" name="test" value="<?=$test?>">
	<input type="submit" value=" Redirecting... ">
	</form>
	
	<script>
		setTimeout(function() { document.getElementById("v2form").submit(); }, 1000);
	</script>

	<?php
	return;
}

if (!isset($_REQUEST['test']) || (intval($_REQUEST['test']) == 0 && $_REQUEST['test'] != 'prepare'))
	show_table($task, $result);
else
	show_test($task, $result, $_REQUEST['test']);
exit(0);
	

