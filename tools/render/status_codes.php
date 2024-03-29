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


// Status for entire program task
define("PROGRAM_AWAITING_TESTS",       1);
// define("PROGRAM_PLAGIARIZED",          2); // Not actually used
define("PROGRAM_COMPILE_ERROR",        3);
define("PROGRAM_FINISHED_TESTING",     4);
// define("PROGRAM_GRADED",               5); // Not actually used
define("PROGRAM_NO_SOURCES_FOUND",     6);
define("PROGRAM_CURRENTLY_TESTING",    7);
define("PROGRAM_REJECTED",             8);


// Compile status
define("COMPILE_SUCCESS",  1);
define("COMPILE_FAIL",     2);


// Execution status
define("EXECUTION_SUCCESS",    1);
define("EXECUTION_TIMEOUT",    2);
define("EXECUTION_CRASH",      3);
define("EXECUTION_FAIL",       4);
define("EXECUTION_CODE_NOT_ZERO", 5);
define("EXECUTION_RUNTIME_ERROR", 6);

// Profiling status
define("PROFILER_OK",           1);
define("PROFILER_OOB",          2);
define("PROFILER_UNINIT",       3);
define("PROFILER_MEMLEAK",      4);
define("PROFILER_INVALID_FREE", 5);
define("PROFILER_MISMATCHED_FREE", 6);
define("PROFILER_FPE",          7);
define("PROFILER_MEMORY_EXCEEDED", 8);

// Test status
define("TEST_SUCCESS",           1);
define("TEST_SYMBOL_NOT_FOUND",  2);
define("TEST_COMPILE_FAILED",    3);
define("TEST_EXECUTION_TIMEOUT", 4);
define("TEST_EXECUTION_CRASH",   5);
define("TEST_WRONG_OUTPUT",      6);
define("TEST_PROFILER_ERROR",    7);
define("TEST_OUTPUT_NOT_FOUND",  8);
define("TEST_UNEXPECTED_EXCEPTION", 9);
define("TEST_INTERNAL_ERROR",    10);
define("TEST_UNZIP_FAILED",      11);
define("TEST_TOOL_FAILED",       12);

// Parse tool status
define("PARSER_OK",              1);
define("STARTER_CODE_MODIFIED",  2);
define("FORBIDDEN_SUBSTRING",    3);
define("FORBIDDEN_ARRAY",        4);
define("FORBIDDEN_GLOBAL",       5);
define("MISSING_SUBSTRING",      6);
define("MISSING_SYMBOL",         7);
define("FORBIDDEN_SYMBOL",       8);


?>
