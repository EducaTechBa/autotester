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

// PLUGINS.PHP - registry of plugins

$conf_pluginses = array(
	"Execute" => array( "tool" => "execute" ),
	"Debug" => array( "tool" => "debug" ),

	// Parsers for specific tools
	"output_parsers/Gcc" => array( "tool" => "compile", "name" => "gcc" ),
	"output_parsers/Gcc[C++]" => array( "tool" => "compile", "name" => "g++" ),
	"output_parsers/Gdb" => array( "tool" => "debug", "name" => "gdb" ),
	"output_parsers/Valgrind" => array( "tool" => "profile", "name" => "valgrind" ),
	"output_parsers/Octave" => array( "tool" => "execute", "name" => "octave" ), // Detect errors in Octave/Matlab

	// C & C++
	"language/CCpp" => array( "tool" => "language", "language" => "C" ),
	"language/CCpp[C++]" => array( "tool" => "language", "language" => "C++" ),
	"language/Java" => array( "tool" => "language", "language" => "Java" ),
	"language/QBasic" => array( "tool" => "language", "language" => "QBasic" ),
	"language/Matlab" => array( "tool" => "language", "language" => "Matlab" ),
	
	"Language" => array( "tool" => "language" ),
	
	// Shortcuts within language tool
	"Patch" => array( "tool" => "patch" ),
	"Parse" => array( "tool" => "parse" ),
	"Plagiarism" => array( "tool" => "plagiarism" ),
);





$conf_plugins = array(
	"patch" => array( "C" => "plugins/c_cpp/patch", "C++" => "plugins/c_cpp/patch", "QBasic" => "plugins/qbasic/patch" ),
	"parse" => array( "C" => "plugins/c_cpp/parse", "C++" => "plugins/c_cpp/parse" ),
	//"compile" => array( "gcc" => "plugins/gcc" ),
	//"debug" => array( "gdb" => "plugins/gdb" ),
	"profile" => array( "valgrind" => "plugins/valgrind" ),
	"find_executable" => array( "Java" => "plugins/java/find_executable", "Python" => "plugins/java/find_executable" ),
	"execute" => array( "" => "plugins/execute" ),
	"plagiarism" => array( "" => "plugins/plagiarism" )
);


