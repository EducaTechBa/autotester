
// AUTOTESTERSERVICE.JS - library of functions for autotester service

const AutotesterService = (() => {
	const autotesterUrl = '/autotester/server/push.php';
	const testStatusDescription = [ "", "Ok", "Parser error", "Doesn't compile", "Timeout", "Crash", "Wrong output", "Profiler error", "Output not found", "Exception" ];
	const generalStatusDescription = [ "", "Waiting in queue", "Plagiarized", "Doesn't compile", "Ok", "Graded", "Sources not found", "Testing in progress", "Rejected" ];
	const checkEveryMs = 500;

	var testingState = "";
	var taskId = -1;
	var programId = -1;
	var c9Username = "";
	var c9Path = "";

	var autotestData = {}, resultData = {};
	var programFileContent;
	var autotestFormatVersion = 3;
	var requestName = "Autotester request";
	var requestLanguage = "C";

	var showMsgCallback = function(){};
	var testingCompleteCallback = function(){};

	const checkStatus = () => {
		var xmlhttp = new XMLHttpRequest();
		var url = autotesterUrl + "?action=getResult&id="+programId;
		xmlhttp.onreadystatechange = function() {
			if (xmlhttp.readyState == 4 && xmlhttp.status == 200) {
				let result = JSON.parse(xmlhttp.responseText);
				if (result.success == "true" || result.success == true) {
					resultData = result.data;
					console.log("Autotester: checkStatus " + programId + " - status "+resultData.status);

					if ('status' in resultData && resultData.status != 1 /* Waiting */ && resultData.status != 7 /* Testing */) {
						if (resultData.status == 4 /* Done */) {
							if (!('test_results' in resultData)) {
								showMsgCallback("Test successful, no results");
							} else {
								showMsgCallback("Result: " + getTestsPassed() + "/" + getTestsCount());
								console.log("Autotester: checkStatus passed " + getTestsPassed() + " of " + getTestsCount());
							}
						} else {
							showMsgCallback(generalStatusDescription[resultData.status]);
						}

						testingCompleteCallback();
						return true;
					}

					if (resultData.status == 7) {
						if ('test_results' in resultData)
							// Requires IE9+, FF4+, Safari 5+
							finishedTests = Object.keys(resultData.test_results).length+1;
						else
							finishedTests = 0;
						showMsgCallback("Testing, " + finishedTests + " tests done");
					} else
						showMsgCallback("Waiting (" + resultData.queue_items + " items in queue)");

					setTimeout(checkStatus, checkEveryMs);
				} else {
					console.log("Autotester: getResult success!=true");
					// The only possibility is that instance doesn't exist, that is the testing finished in the meanwhile
					setTimeout(checkStatus, checkEveryMs);
				}
				return false;
			}
			if (xmlhttp.readyState == 4 && xmlhttp.status == 500) {
				showMsgCallback("Test failed to run");
				console.log("Autotester: getResult readyState "+xmlhttp.readyState+" status "+xmlhttp.status);
			}
		}
		xmlhttp.open("GET", url, true);
		xmlhttp.send();
	}

	const setTask = (callback) => {
		let payload = "task=" + encodeURIComponent(JSON.stringify(autotestData));

		var xmlhttp = new XMLHttpRequest();
		var url = autotesterUrl + "?action=setTask";
		xmlhttp.onreadystatechange = function() {
			if (xmlhttp.readyState == 4 && xmlhttp.status == 200) {
				let result = JSON.parse(xmlhttp.responseText);
				if (result.success == "true" || result.success == true) {
					taskId = parseInt(result.data);
					console.log("Autotester: setTask " + taskId);
					callback();
				} else {
					console.log("Autotester: setTask success!=true");
					console.log(result);
				}
			}
			if (xmlhttp.readyState == 4 && xmlhttp.status == 500) {
				showMsgCallback("Test failed to run");
				console.log("Autotester: setTask readyState "+xmlhttp.readyState+" status "+xmlhttp.status);
			}
		}

		xmlhttp.open("POST", url, true)
		xmlhttp.setRequestHeader("Content-type", "application/x-www-form-urlencoded")
		xmlhttp.send(payload);
	}

	const setProgram = (callback) => {
		let payload = "program=" + encodeURIComponent(JSON.stringify({ name: requestName, language: requestLanguage, task: taskId }));

		var xmlhttp = new XMLHttpRequest();
		var url = autotesterUrl + "?action=setProgram";
		xmlhttp.onreadystatechange = function() {
			if (xmlhttp.readyState == 4 && xmlhttp.status == 200) {
				let result = JSON.parse(xmlhttp.responseText);
				if (result.success == "true" || result.success == true) {
					programId = parseInt(result.data);
					console.log("Autotester: setProgram " + programId);
					callback();
				} else {
					console.log("Autotester: setProgram success!=true");
					console.log(result);
				}
			}
			if (xmlhttp.readyState == 4 && xmlhttp.status == 500) {
				showMsgCallback("Test failed to run");
				console.log("Autotester: setProgram readyState "+xmlhttp.readyState+" status "+xmlhttp.status);
			}
		}
		xmlhttp.open("POST", url, true)
		xmlhttp.setRequestHeader("Content-type", "application/x-www-form-urlencoded")
		xmlhttp.send(payload);
	}

	const setProgramFile = async (callback) => {
		var url = autotesterUrl + "?action=setProgramFile&id="+programId;

		const formData = new FormData();
		formData.append('program', programFileContent, 'test.zip');
		let response = await fetch(url, {
			method: 'POST',
			body: formData
		});

		let result = await response.json();
		if (result.success == "true" || result.success == true) {
			console.log("Autotester: setProgramFile " + programId);
			callback();
		} else {
			console.log("Autotester: setProgramFile success!=true");
			console.log(result);
		}
	}

	const runTests = () => {
		setTask(function() {
			setProgram(function() {
				setProgramFile(function() {
					setTimeout(checkStatus, checkEveryMs);
				});
			});
		});
	}

	const getTestsCount = () => {
		if (autotestData.hasOwnProperty('tests')) {
			if (autotestData.hasOwnProperty('version'))
				autotestFormatVersion = parseInt(autotestData.version);
			if (autotestFormatVersion == 2)
				return autotestData.tests.length - 1;
			else
				return autotestData.tests.length;
		}
		if (autotestData.hasOwnProperty('test_specifications')) {
			autotestFormatVersion = 1;
			return autotestData.test_specifications;
		}
		return 0;
	}

	const getTestsPassed = () => {
		if (!resultData.hasOwnProperty('test_results')) return 0;
		let testResults = resultData.test_results;
		let testsPassed = 0;
		for(test_id in testResults) {
			test = testResults[test_id];
			if (parseInt(test.status) == 1)
				testsPassed++;
		}
		return testsPassed;
	}

	const getTestingStatus = () => {
		if (!resultData.hasOwnProperty('status')) return 0;
		return resultData.status;
	}

	const setAutotestData = (data) => {
		autotestData = data;
	}

	const getAutotestData = () => {
		return autotestData;
	}

	const hasAutotestData = () => {
		return Object.keys(autotestData).length > 0;
	}

	const setResultData = (data) => {
		resultData = data;
	}

	const getResultData = () => {
		return resultData;
	}

	const getTaskId = () => {
		return taskId;
	}

	const getProgramId = () => {
		return programId;
	}

	const setShowMsgCallback = (func) => {
		showMsgCallback = func;
	}

	const setTestingCompleteCallback = (func) => {
		testingCompleteCallback = func;
	}

	const setRequestName = (data) => {
		requestName = data;
	}

	const setRequestLanguage = (data) => {
		requestLanguage = data;
	}

	const setProgramFileContent = (data) => {
		programFileContent = data;
	}

	return {
		autotestData: autotestData,
		resultData: resultData,
		programFileContent: programFileContent,
		autotestFormatVersion: autotestFormatVersion,
		requestName: requestName,
		requestLanguage: requestLanguage,

		runTests: runTests,

		getTestsCount: getTestsCount,
		getTestsPassed: getTestsPassed,
		setAutotestData: setAutotestData,
		getAutotestData: getAutotestData,
		hasAutotestData: hasAutotestData,
		setResultData: setResultData,
		getResultData: getResultData,
		getTaskId: getTaskId,
		getProgramId: getProgramId,
		setShowMsgCallback: setShowMsgCallback,
		setTestingCompleteCallback: setTestingCompleteCallback,
		setRequestName: setRequestName,
		setRequestLanguage: setRequestLanguage,
		setProgramFileContent: setProgramFileContent
	}

})();

