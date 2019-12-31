<?php

// BUILDSERVICE - automated compiling, execution, debugging, testing and profiling
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

// buildservice configuration



// ----------------------------
// COMMON settings
// ----------------------------

// Commands and paths

$conf_tmp_path = "/tmp";
// Base directory for all files related to buildservice
$conf_basepath = "/tmp/autotester";
$conf_unzip_command = "unzip";


// ----------------------------
// PULL settings
// ----------------------------

// Give some name to this buildhost
$buildhost_id = "lab002-c01";

// How long to wait (in seconds) before pinging the server again (when in wait mode)
$conf_wait_secs = 5;

// Verbosity level for messages on stdout
// 0 = no output
// 1 = information about what is being done currently
// 2 = some debugging output
// 3 = forward output from all child processes to stdout
$conf_verbosity = 1;


// Maximum number of simultainously executed tasks... 10000 should be enough to choke the server :)
// However sometimes instances are incorrectly purged so this number can be increased as a stopgap measure
$conf_max_tasks = 10000;


// JSON options

//$conf_base_url = "http://localhost/bsnew/server";
$conf_base_url = "https://c9.etf.unsa.ba/autotester";
$conf_push_url = $conf_base_url . "/push.php";
$conf_auth_url = $conf_base_url . "/auth.php";
//$conf_base_url = "https://zamger.etf.unsa.ba/buildservice";
//$conf_push_url = "https://zamger.etf.unsa.ba/buildservice/autotester.php";
//$conf_auth_url = "https://zamger.etf.unsa.ba/auth.php";

$conf_json_login_required = false;
$conf_json_user = "autotester";
$conf_json_pass = "";

$conf_json_max_retries = 10;
$conf_default_wait = 60;



// ----------------------------------------------
// TOOLS
// ----------------------------------------------

// 

$conf_tools = array(
	"compile" => array(
		// GCC
		array(
			"name" => "gcc",
			"language" => "C",
			"path" => "/usr/bin/gcc",
			"cmd" => "{path} -o {output_file} {source_files} {options}",
			"version_line" => "{path} --version | grep ^gcc",
			"options" => "-lm -pass-exit-codes", // CLI switches that will always be passed regardless
			"features" => array( // add features supported by this compiler
				"optimize" => "-O1",
				"debug" => "-g",
				"warn" => "-Wall -Wuninitialized -Winit-self -Wfloat-equal -Wno-sign-compare",
				"pedantic" => "-Werror=implicit-function-declaration -Werror=vla -pedantic"
			)
		),

		// G++
		array(
			"name" => "g++",
			"language" => "C++",
			//"path" => "/opt/gcc-4.8.2/bin/g++",
			"path" => "/usr/bin/g++",
			//"cmd_line" => "COMPILER_PATH -o {output_file} {source_files} -Wl,-rpath /opt/gcc-4.8.2/lib64 {options}",
			"cmd" => "{path} -o {output_file} {source_files} {options}",
			"version_line" => "{path} --version | grep ^g++",
			"options" => "-lm -pass-exit-codes", // CLI switches that will always be passed regardless
			"features" => array(
				"c++11" => "-std=c++11",
				"optimize" => "-O1",
				"debug" => "-ggdb",
				"warn" => "-Wall -Wuninitialized -Winit-self -Wfloat-equal -Wno-sign-compare",
				"pedantic" => "-Werror=implicit-function-declaration -Werror=vla -pedantic"
			)
		),

		// JDK
		array(
			"name" => "jdk",
			"language" => "Java",
			"path" => "javac",
			"executor_path" => "java",
			"cmd" => "{path} -d {test_path} {options} {source_files}",
			"version_line" => "{path} --version",
			"features" => array(),
		),

		// PYTHON
		array(
			"name" => "python3", // "Compiling" python here performs a syntax check (FIXME)
			"language" => "Python",
			"path" => "/usr/bin/python3",
			"cmd" => "{path} -m py_compile {options} {source_files}",
			"version_line" => "{path} --version",
			"features" => array( "python3" ), // Python version 3 is used
		),

		// QB64
		array(
			"name" => "QB64",
			"language" => "QBasic",
			"path" => "/home/vedran/Programs/qb64/qb64",
			"cmd" => "{path} -x -o {output_file} {source_files}",
			"version_line" => "{path} -x /tmp/nonexistant.bas | grep QB64", // hack
			"features" => array( ),
		),
	),
	"execute" => array(
		// JDK
		array(
			"name" => "jdk",
			"language" => "Java",
			"path" => "java",
			"cmd" => "{path} {executable}",
			"version_line" => "{path} --version",
			"features" => array(),
		),

		// PYTHON
		array(
			"name" => "python3",
			"language" => "Python",
			"path" => "/usr/bin/python3",
			"cmd" => "{path} {options} {executable}",
			"version_line" => "{path} --version",
			"features" => array( "python3" ), // Python version 3 is used
		),

		// Octave
		array(
			"name" => "octave",
			"language" => "Matlab",
			"path" => "/usr/bin/octave",
			"cmd" => "{path} -W {options} {executable}",
			"version_line" => "{path} --version | grep version",
			"features" => array(),
		),
		
		// QB64 compiled binaries must use "exec"
		array(
			"language" => "QBasic",
			"cmd" => "{executable}",
			// Use popen call for compiled binaries
			"environment" => array(
				"type" => "exec"
			),
		),
		
		// Default - execute compiled binary
		array(
			"cmd" => "{executable}",
			// Use popen call for compiled binaries
			"environment" => array(
				"type" => "popen"
			),
		),
	),
	"debug" => array(
		// GDB
		array(
			"name" => "gdb",
			"path" => "gdb",
			"features" => array(),
			// options needed to process core dump (COREFILE will be replaced with filename)
			"cmd" => "{path} --batch -ex \"bt 100\" --core={coredump} {executable}",
			"version_line" => "{path} --version | grep ^GNU",
		),
	),
	"profile" => array(
		array(
			"name" => "valgrind",
			"path" => "valgrind",
			"cmd" => "{path} {executable}",
			"options" => "", // add options that need to be passed every time
			"inherit" => "execute",
			"features" => array(
				"memcheck" => "--leak-check=full",
				"sgcheck" => "--tool=exp-sgcheck",
				"log" => "--log-file=LOGFILE", 
				//"log" => "--log-file-exact=LOGFILE",  // old valgrind
			),
			"version_line" => "{path} --version",
		),
	),
	"http" => array(
		array(
			"name" => "wget",
			"path" => "wget",
			"cmd" => "{path} {options} {url}",
			"options" => "", // add options that need to be passed every time
		),
	),
);


// Lists of source filename extensions per language
$conf_extensions = array(
	"c"    => array( ".c" ),
	"c++"  => array( ".cpp", ".cxx" ),
	"java" => array( ".java" ),
	"python" => array( ".py" ),
	"qbasic" => array( ".bas" ),
	"matlab" => array( ".m" ),
);






