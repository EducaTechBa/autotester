// This file describes features that are known to be supported with current version of Autotester and AT editor

const KnownTemplates = {
	"templates/cpp_asan_template.json" : "C++ Template with Address Sanitizer",
	"templates/cpp_valgrind_template.json" : "C++ Template with Valgrind",
	"templates/c_asan_template.json" : "C Template with Address Sanitizer",
	"templates/c_valgrind_template.json" : "C Template with Valgrind",
	"templates/python3_template.json" : "Python3 Task Template",
	"templates/multilanguage_template.json" : "Multilanguage Task Template",
};

const SupportedLanguages = [ "C", "C++", "Java", "Python", "PHP", "HTML", "JavaScript", "Matlab" ];
const PatchToolSupportedLanguages = [ "C", "C++", "Java", "Matlab", "QBasic" ];
const ParseToolSupportedLanguages = [ "C", "C++" ];

const KnownToolsIcons = { "compile" : "build", "execute" : "settings", "debug" : "pest_control", "profile" : "search", "patch" : "healing", "parse" : "code", "http" : "public" };
