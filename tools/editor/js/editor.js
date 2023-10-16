const TaskEditor = (() => {

	//Variables required for operations
	//config is .autotest content
	var task;
	var emptyTestTemplate;
	var currentTestId;
	var currentTest;
	var dirty;
	var jsonEditor;
	var taskLanguage;
	var saveCallback;
	
	const initialize = () => {
		let autotestTemplate = null, autotestContent = null;
		
		// Try URL from localStorage
		if (window.localStorage.getItem('.autotest-template-url')) {
			let autotestTemplateUrl = window.localStorage.getItem('.autotest-template-url');
			$.ajax({
				url: autotestTemplateUrl,
				dataType: 'text',
				success: function(result) {
					autotestTemplate = result
				}
			});
		}
		if (window.localStorage.getItem('.autotest-content-url')) {
			let autotestContentUrl = window.localStorage.getItem('.autotest-content-url');
			$.ajax({
				url: autotestContentUrl,
				dataType: 'text',
				success: function(result) {
					autotestContent = result
				}
			});
		}
		if (autotestTemplate == null && window.localStorage.getItem('.autotest-template')) {
			autotestTemplate = window.localStorage.getItem('.autotest-template');
		}
		if (autotestContent == null && window.localStorage.getItem('.autotest-content')) {
			autotestContent = window.localStorage.getItem('.autotest-content');
		}
		
		if (autotestTemplate != null) {
			setEmptyTestTemplate(autotestTemplate);
			if (autotestContent == null) autotestContent = autotestTemplate;
		}
		if (autotestContent != null) {
			load(autotestContent);
		}
		if (autotestTemplate == null && autotestContent == null) {
			let templateChooser = document.getElementById('knownTemplates');
			for (let filename in KnownTemplates) {
				let newOption = new Option(KnownTemplates[filename], filename);
				templateChooser.add(newOption, undefined);
			}
			openTab('templateChooser', 'general')
		}
		
		saveCallback = null;
		if (window.localStorage.getItem('.autotest-save-callback')) {
			saveCallback = window.localStorage.getItem('.autotest-save-callback');
		}
	}
	
	const loadTemplate = () => {
		let url = document.getElementById('knownTemplates').value;
		$.ajax({
			url: url,
			dataType: 'text',
			success: function(result) {
				load(result);
				openTab('simpleTab', 'general')
			}
		});
	}
	
	const setEmptyTestTemplate = (data) => {
		emptyTestTemplate = JSON.parse(data);
		if (emptyTestTemplate.hasOwnProperty('testTemplate'))
			emptyTestTemplate = emptyTestTemplate.testTemplate;
		if (!emptyTestTemplate.hasOwnProperty('tools') || emptyTestTemplate.tools.length == 0) {
			alert("Empty test template is invalid - it has no tools");
			emptyTestTemplate = null;
		}
	}

	const load = (data) => {
		task = JSON.parse(data);
		if (task.hasOwnProperty('testTemplate')) {
			emptyTestTemplate = task.testTemplate;
			delete task.testTemplate;
		}
		
		if (!sanityCheck()) {
			alert("Task specification is invalid - switch to advanced mode to fix");
			return;
		}
		
		currentTestId = 0;
		currentTest = null;
		dirty = false;
		render();
		populateFeatures();
	}
	
	const sanityCheck = () => {
		// Check if task specification has bugs preventing simple mode from running
		if (!task.hasOwnProperty('languages') || task.languages.length == 0 || !task.hasOwnProperty('tools') || Object.keys(task.tools).length == 0)
			return false;
		if (!task.hasOwnProperty('tests'))
			task.tests = [];
		if (!task.hasOwnProperty('id'))
			task.id = 0;
		
		let maxId = 0;
		for (let i=0; i<task.tests.length; i++) {
			let test = task.tests.at(i);
			if (test.hasOwnProperty('id') && test.id > maxId)
				maxId = test.id;
		}
		
		for (let i=0; i<task.tests.length; i++) {
			let test = task.tests.at(i);
			
			if (!test.hasOwnProperty('id')) {
				maxId = maxId + 1;
				console.log("AUTOTEST SANITY CHECK: Test has no id - setting to " + maxId);
				test.id = maxId;
			} else {
				for (let j=i+1; j<task.tests.length; j++) {
					let test2 = task.tests.at(j);
					if (test.id == test2.id) {
						maxId = maxId + 1;
						console.log("AUTOTEST SANITY CHECK: Two tests have the same id " + test.id + " setting latter to " + maxId);
						test2.id = maxId;
					}
				}
			}
			
			for (let j=0; j<test.tools.length; j++) {
				let tool = test.tools.at(j);
				
				let toolName = '';
				if (typeof tool === 'string' || tool instanceof String) {
					toolName = tool;
				} else {
					for (const key in tool) {
						if (tool.hasOwnProperty(key)) {
							toolName = key;
							break;
						}
					}
				}
				if (toolName != "patch" && toolName != "parse" && !task.tools.hasOwnProperty(toolName)) {
					console.log("AUTOTEST SANITY CHECK: Test " + test.id + " is using unknown tool " + toolName + " - removing tool from test");
					test.tools.splice(j, 1);
					j--;
				}
			}
			
			if (!test.hasOwnProperty('tools') || test.tools.length == 0) {
				console.log("AUTOTEST SANITY CHECK: Test " + test.id + " has no tools - deleting");
				task.tests.splice(i, 1);
				i--;
			}
		}
		return true;
	}
	
	const populateFeatures = () => {
		let languagesWidget = document.getElementById('supportedLanguages');
		while (languagesWidget.hasChildNodes())
			languagesWidget.firstChild.remove()
		
		for (let i in SupportedLanguages) {
			let language = SupportedLanguages[i];
			let widget = document.createElement('a');
			widget.innerHTML = language;
			widget.classList.add("dropdown-item");
			widget.onclick = function() { $('#languages').tagsinput('add', language); }
			languagesWidget.appendChild(widget);
		}
	}

	const save = () => {
		cleanup();
		//console.log(JSON.stringify(task, null, 4));
		window.localStorage.setItem('.autotest-content', JSON.stringify(task, null, 4))
		dirty = false;
		document.getElementById('saveSuccessMessage').style.display = 'block';
		setTimeout(function() { document.getElementById('saveSuccessMessage').style.display = 'none'; }, 2000);
		if (saveCallback != null) {
			window.opener[saveCallback]();
		} else if (globalSaveCallback != null) {
			globalSaveCallback();
		}
	}
	
	const checkUnsaved = () => {
		if (dirty)
			return "You have unsaved changes. Do you want to leave this page and lose all of your changes?";
	}
	
	const cleanup = () => {
		if (task.hasOwnProperty('name') && task.name.trim() === '')
			delete task.name;
		
		if (task.hasOwnProperty('prepare')) {
			if (task.prepare.length == 0) {
				console.log("CLEANUP: Prepare step is empty - deleting");
				delete task.prepare;
			}
		}
		
		// Remove unneccessary stuff from task specification
		for (let i=0; i<task.tests.length; i++) {
			let test = task.tests.at(i);
			
			if (test.hasOwnProperty('name') && test.name.trim() === '')
				delete test.name;
			if (test.hasOwnProperty('description') && test.description.trim() === '')
				delete test.description;
						
			for (let j=0; j<test.tools.length; j++) {
				let tool = test.tools.at(j);
				if (tool.hasOwnProperty('execute')) {
					if (tool.execute.hasOwnProperty('environment')) {
						if (tool.execute.environment.hasOwnProperty('stdin') && tool.execute.environment.stdin.trim() === '')
							delete tool.execute.environment.stdin;
						if (Object.keys(tool.execute.environment).length == 0)
							delete tool.execute.environment;
					}
					if (tool.execute.hasOwnProperty('expect')) {
						for (let k=0; k<tool.execute.expect.length; k++) {
							if (tool.execute.expect[k].trim() === '') {
								tool.execute.expect.splice(k,1);
								k--;
							}
						}
						if (tool.execute.expect.length == 0)
							delete tool.execute.expect;
					}
					if (Object.keys(tool.execute).length == 0) {
						console.log("WARNING: Test " + test.id + " execute tool is empty!");
						test.tools[j] = "execute";
					}
				}
				if (tool.hasOwnProperty('patch')) {
					for (let k=0; k<tool.patch.length; k++) {
						if (!tool.patch[k].hasOwnProperty('code') || tool.patch[k].code.trim() === '') {
							tool.patch.splice(k,1);
							k--;
						}
						else if (!tool.patch[k].hasOwnProperty('position') || tool.patch[k].position.trim() === '') {
							tool.patch[k].position = 'main';
						}
					}
					if (tool.patch.length == 0) {
						test.tools.splice(j,1);
						j--;
					}
				}
			}
			
			if (!test.hasOwnProperty('tools') || test.tools.length == 0) {
				console.log("CLEANUP: Test " + test.id + " has no tools - deleting");
				task.tests.splice(i, 1);
				i--;
			}
		}
		render();
	}
	
	const render = () => {
		renderSimple();
		renderAdvanced();
	}
	
	const renderSimple = () => {
		if (task.hasOwnProperty('name'))
			document.getElementById('task_name').value = task.name;
		else
			document.getElementById('task_name').value = '';
		
		let testsHtml = '';
		let testTemplateHtml = document.getElementById('atListTemplate').innerHTML;
		let maxId = 0, firstId = 0;
		currentTest = null;
		for (let i=0; i<task.tests.length; i++) {
			let test = task.tests.at(i);
			let id = test.hasOwnProperty('id') ? test.id : maxId+1;
			let name = test.hasOwnProperty('name') ? test.name : "Test " + (i+1);
			if (firstId == 0) firstId = id;
			if (id > maxId) maxId = id;
			let addClass = 'menuClickable';
			if (id == currentTestId) {
				currentTest = test;
				addClass = 'currentTest';
			}
			
			testsHtml += testTemplateHtml.replaceAll("ATID", id).replaceAll("ATNAME", name).replaceAll("ADDCLASS", addClass);
		}
		
		document.getElementById('atList').innerHTML = testsHtml;
		testsHtml = testsHtml.replaceAll("at_up_control_", "adv_at_up_control_");
		document.getElementById('atListAdvanced').innerHTML = testsHtml;
		if (testsHtml === '') {
			document.getElementById('at_warning').style.display = 'block';
			document.getElementById('atWarningAdvanced').style.display = 'block';
		} else {
			document.getElementById('at_warning').style.display = 'none';
			document.getElementById('atWarningAdvanced').style.display = 'none';
		}
		
		if (firstId != 0) {
			document.getElementById('at_up_control_' + firstId).style.display = 'none';
			document.getElementById('adv_at_up_control_' + firstId).style.display = 'none';
		}
		if (currentTestId > 0) {
			document.getElementById('atPreview').style.display = 'block';
			renderTestSimple();
			openTab('atPreviewAdvanced', 'advancedMenu');
			renderTestAdvanced();
		} else {
			document.getElementById('atPreview').style.display = 'none';
			document.getElementById('atPreviewAdvanced').style.display = 'none';
		}
	}

	const renderTestSimple = () => {
		let testDiv = document.getElementById('at_' + currentTestId);
		let displayName = testDiv.children[0].innerHTML;
		
		document.getElementById('at_name').value = displayName;
		
		if (currentTest.hasOwnProperty('description')) 
			document.getElementById('description').value = currentTest.description;
		else
			document.getElementById('description').value = "";
		
		let executeTool = null, patchTool = null;
		for (let i=0; i<currentTest.tools.length; i++) {
			let tool = currentTest.tools.at(i);
			if (tool.hasOwnProperty('execute'))
				executeTool = tool.execute;
			if (tool.hasOwnProperty('patch'))
				patchTool = tool.patch;
		}
		
		let variantHtml = document.getElementById('expected_output_variants_template').innerHTML;
		let expectHtml = '';
		document.getElementById('stdin').value = '';
		if (executeTool != null) {
			if (executeTool.hasOwnProperty('environment') && executeTool.environment.hasOwnProperty('stdin'))
				document.getElementById('stdin').value = executeTool.environment.stdin;
			
			if (executeTool.hasOwnProperty('expect')) {
				for (let i=0; i<executeTool.expect.length; i++) {
					let j=i+1;
					let thisHtml = variantHtml.replaceAll('ORD', j).replaceAll('CODE', executeTool.expect.at(i));
					expectHtml += thisHtml;
				}
			}
		}
		// Always show at least one
		if (expectHtml === '') expectHtml = variantHtml.replaceAll('ORD', 1).replaceAll('CODE', '');
		document.getElementById('expected_output_variants').innerHTML = expectHtml;
		
		let patchHtml = document.getElementById('patches_template').innerHTML;
		let patchesHtml = '';
		let positions = [];
		if (patchTool != null)
		for (let i=0; i<patchTool.length; i++) {
			let patch = patchTool.at(i);
			let j=i+1;
			
			positions[i] = patch.hasOwnProperty('position') ? patch.position : "main";
			
			let thisHtml = patchHtml.replaceAll('ORD', j).replaceAll('CODE', patch.code);
			patchesHtml += thisHtml;
		}
		
		// Always show at least one
		if (patchesHtml === '') patchesHtml = patchHtml.replaceAll('ORD', 1).replaceAll('CODE', '');
		document.getElementById('patches').innerHTML = patchesHtml;
		
		if (patchTool != null)
		for (let i=0; i<patchTool.length; i++) {
			let element = document.getElementById('patch_pos_' + (i+1));
			element.value = positions[i];
		}
		
	}

	const renderAdvanced = () => {
		document.getElementById('task_id').value = task.id;
		
		let languages = JSON.parse(JSON.stringify(task.languages));
		$('#languages').tagsinput('removeAll');
		for (let i in languages)
				$('#languages').tagsinput('add', languages[i]);
		
		if (task.languages.length == 1)
			taskLanguage = task.languages[0];
		else
			taskLanguage = "MULTI";
		
		if (PatchToolSupportedLanguages.includes(taskLanguage)) {
			document.getElementById('patchToolAvailableRow').style.display = 'block';
		} else {
			document.getElementById('patchToolAvailableRow').style.display = 'none';
		}
		
		renderTools('toolsGeneral', task.tools);
		if (task.hasOwnProperty('prepare'))
			renderTools('toolsPrepare', task.prepare);
		else
			renderTools('toolsPrepare', {});
	}
	
	const renderTestAdvanced = () => {
		document.getElementById('adv_at_id').value = currentTestId;
		
		let testDiv = document.getElementById('at_' + currentTestId);
		let displayName = testDiv.children[0].innerHTML;
		
		document.getElementById('adv_at_name').value = displayName;
		if (currentTest.hasOwnProperty('options')) {
			let data = JSON.parse(JSON.stringify(currentTest.options));
			$('#adv_at_options').tagsinput('removeAll');
			for (let i in data)
				$('#adv_at_options').tagsinput('add', data[i]);
		}
		else
			$('#adv_at_options').tagsinput('removeAll');
		
		renderTools('toolsTest', currentTest.tools);
	}
	
	const renderTools = (divId, tools) => {
		let toolTemplateHtml = document.getElementById('toolIconTemplate').innerHTML;
		let finalHtml = '';
		
		for (let i in tools) {
			let name = '';
			if (Array.isArray(tools)) {
				if (typeof tools[i] === 'string' || tools[i] instanceof String) {
					name = tools[i];
				} else {
					for (let j in tools[i]) {
						if (tools[i].hasOwnProperty(j))
							name = j;
					}
				}
			} else if (tools.hasOwnProperty(i)) {
				name = i;
			}
			
			if (name === '') continue;
			
			toolKind = name;
			if (name.indexOf('[') > -1)
				toolKind = name.substring(0, name.indexOf('['));
			let icon = 'question_mark';
			if (KnownToolsIcons.hasOwnProperty(toolKind))
				icon = KnownToolsIcons[toolKind];
			finalHtml += toolTemplateHtml.replaceAll('TOOLNAME', name).replaceAll('TOOLICON', icon).replaceAll('TOOLID', divId + "_" + name);
		}
		
		finalHtml += toolTemplateHtml.replaceAll('TOOLNAME', 'Add tool').replaceAll('TOOLICON', 'add').replaceAll('TOOLID', divId + '_add_tool');
		
		document.getElementById(divId).innerHTML = finalHtml;
		
		let addToolWidget = document.getElementById(divId+"_add_tool");
		addToolWidget.style.background = 'transparent';
		addToolWidget.classList.add('dropdown-toggle');
		addToolWidget.setAttribute('data-toggle', 'dropdown');
		
		let supportedToolsWidget = document.createElement('div');
		supportedToolsWidget.classList.add('dropdown-menu');
		supportedToolsWidget.setAttribute('aria-labelledby', divId+"_add_tool");
		
		let supportedTools = [];
		if (divId === 'toolsGeneral') {
			for (let tool in KnownToolsIcons)
				if (tool !== 'parse' && tool !== 'patch')
					supportedTools.push(tool);
		} else {
			supportedTools = Object.keys(task.tools);
			supportedTools.push('patch');
			supportedTools.push('parse');
		}
		
		for (let i in supportedTools) {
			let widget = document.createElement('a');
			widget.innerHTML = supportedTools[i];
			widget.classList.add("dropdown-item");
			widget.onclick = function() { TaskEditor.testAddTool(divId, supportedTools[i]); }
			supportedToolsWidget.appendChild(widget);
		}
		
		document.getElementById(divId).appendChild(supportedToolsWidget);
	}
	
	const updateCurrentTest = () => {
		let maxId = 0;
		for (let i=0; i<task.tests.length; i++) {
			let test = task.tests.at(i);
			let id = test.hasOwnProperty('id') ? test.id : maxId+1;
			if (id == currentTestId) {
				task.tests[i] = currentTest;
				return;
			}
			if (id > maxId) maxId = id;
		}
	}
	
	/* Onchange events */
	
	const taskNameChanged = () => {
		task.name = document.getElementById('task_name').value;
		dirty = true;
	}
	
	const testNameChanged = (advanced) => {
		let nameField = document.getElementById('at_name');
		if (advanced) nameField = document.getElementById('adv_at_name');
		nameField.classList.remove("validationFailed");
		let name = nameField.value;
		
		if (name.trim() === "") {
			nameField.classList.add("validationFailed");
			return;
		}
		
		let maxId = 0;
		for (let i=0; i<task.tests.length; i++) {
			let test = task.tests.at(i);
			let id = test.hasOwnProperty('id') ? test.id : maxId+1;
			let testName = test.hasOwnProperty('name') ? test.name : "Test " + (i+1);
			if (id != currentTestId && testName == name) {
				nameField.classList.add("validationFailed");
				return;
			}
			if (id > maxId) maxId = id;
		}
		
		let label = document.getElementById('at_' + currentTestId).children[0];
		label.innerHTML = name;
		
		currentTest.name = name;
		dirty = true;
	}
	
	const languagesChanged = () => {
		let langElement = document.getElementById('languages');
		document.getElementById('languagesWarning').style.display = 'none';
		if (langElement.value.trim() === '') {
			document.getElementById('languagesWarning').style.display = 'block';
			return;
		}
		task.languages = langElement.value.split(',');
		if (task.languages.length == 1)
			taskLanguage = task.languages[0];
		else
			taskLanguage = "MULTI";
	}
	
	const testOptionsChanged = () => {
		let options = document.getElementById('adv_at_options').value;
		if (options.trim() === '') {
			delete currentTest.options;
		} else {
			currentTest.options = options.split(',');
		}
	}
	
	const fieldChanged = (e, numeric) => {
		let caller = e.target || e.srcElement;
		let value = caller.value;
		caller.classList.remove("validationFailed");
		if (numeric && isNaN(parseInt(value))) {
			caller.classList.add("validationFailed");
			return;
		}
		
		if (caller.id == 'task_id') {
			task.id = parseInt(value);
			return;
		}
		
		if (!currentTest) {
			alert("No test selected");
			return;
		}
		let executeTool = null, patchTool = null;
		for (let i=0; i<currentTest.tools.length; i++) {
			let tool = currentTest.tools.at(i);
			if ((typeof tool === 'string' || tool instanceof String) && tool === "execute") {
				currentTest.tools[i] = { "execute" : {} };
				i--;
			}
			if (tool.hasOwnProperty('execute'))
				executeTool = tool.execute;
			if (tool.hasOwnProperty('patch'))
				patchTool = tool.patch;
		}

		
		if (caller.id == 'description' || caller.id == 'adv_at_description')
			currentTest.description = caller.value;
		if (caller.id == 'stdin') {
			if (!executeTool.hasOwnProperty('environment'))
				executeTool.environment = {};
			executeTool.environment.stdin = caller.value;
		}
		if (caller.id.length > 8 && caller.id.substring(0,8) == 'variant_') {
			let ord = parseInt(caller.id.substring(8)) - 1;
			if (!executeTool.hasOwnProperty('expect'))
				executeTool.expect = [];
			if (executeTool.expect.length <= ord)
				executeTool.expect.push(caller.value);
			else
				executeTool.expect[ord] = caller.value;
		}
		if (caller.id.length > 8 && caller.id.substring(0,11) == 'patch_code_') {
			let ord = parseInt(caller.id.substring(11)) - 1;
			if (patchTool == null) {
				addPatchToolToTest();
				return fieldChanged(e);
			}
			if (patchTool.length <= ord)
				patchTool.push({ "position": "main", "code": caller.value });
			else
				patchTool[ord].code = caller.value;
		}
		if (caller.id.length > 8 && caller.id.substring(0,10) == 'patch_pos_') {
			let ord = parseInt(caller.id.substring(10)) - 1;
			if (patchTool == null) {
				addPatchToolToTest();
				return fieldChanged(e);
			}
			if (patchTool.length <= ord)
				patchTool.push({ "position": caller.value, "code": "" });
			else
				patchTool[ord].position = caller.value;
		}
		dirty = true;
	}
	
	const jsonChanged = () => {
		let code = jsonEditor.getSession().getValue();
		if (code.trim() === '') return;
		document.getElementById('json_error_display').style.display = 'none';
		try {
			let parsed = JSON.parse(code);
			task = parsed;
			dirty = true;
			render();
		} catch(e) {
			document.getElementById('json_error_display').style.display = 'block';
			console.log(e);
		}
	}
	
	const testAddTool = (divId, toolKind) => {
		if (divId == 'toolsGeneral') {
			let name = toolKind;
			let tag = 1;
			while (task.tools.hasOwnProperty(name)) {
				name = toolKind + '[' + tag + ']';
				tag++;
			}
			task.tools[name] = {};
		}
		if (divId == 'toolsPrepare') {
			if (!task.hasOwnProperty('prepare'))
				task.prepare = [];
			task.prepare.push(toolKind);
		}
		if (divId == 'toolsTest') {
			currentTest.tools.push(toolKind);
		}
		render();
	}
	
	const addPatchToolToTest = () => {
		let insertPos = 0;
		for (let i=0; i<currentTest.tools.length; i++) {
			let tool = currentTest.tools.at(i);
			if (typeof tool === 'string' || tool instanceof String) {
				if (insertPos == 0 && tool.length >= 7 && (tool.substring(0,7) === "compile" || tool.substring(0,7) === "execute")) {
					insertPos = i;
					break;
				}
			} else {
				for (const key in tool) {
					if (tool.hasOwnProperty(key) && key.length >= 7 && (key.substring(0,7) === "compile" || key.substring(0,7) === "execute")) {
						insertPos = i;
						break;
					}
				}
			}
		}
		
		let patchTool = { "patch": [ { "position": "main", "code": "" } ] };
		currentTest.tools.splice(insertPos, 0, patchTool);
		dirty = true;
	}
	
	/* UI callable actions */
	
	const openTab = (tabName, panel) => {
		let tabs = { 
			"general" : [ 'simpleTab', 'advancedTab', 'jsonTab', 'templateChooser' ],
			"advancedMenu" : [ 'commonAdvanced', 'prepareAdvanced', 'atPreviewAdvanced', 'descriptionAdvanced' ]
		};
		let defaultStyle = { 
			"general" : [ 'flex', 'flex', 'block', 'block' ],
			"advancedMenu" : [ 'block', 'block', 'block', 'block' ]
		};
		
		if (tabs.hasOwnProperty(panel)) {
			for (let i=0; i<tabs[panel].length; i++) {
				let p = tabs[panel][i];
				document.getElementById(p).classList.remove('fade', 'active');
				if (p == tabName) {
					document.getElementById(p).style.display = defaultStyle[panel][i];
				} else
					//document.getElementById(p).classList.add('fade');
					document.getElementById(p).style.display = 'none';
			}
		}
		
		if (panel === 'advancedMenu' && tabName !== 'atPreviewAdvanced') {
			currentTestId = 0;
			currentTest = null;
			render();
		}
	}
	
	const updateJsonTab = () => {
		if (!jsonEditor) {
			jsonEditor = ace.edit("json_editor");
			jsonEditor.getSession().setMode("ace/mode/json");
		}
		
		let code = JSON.stringify(task, null, 4)
		jsonEditor.setValue(code);
		jsonEditor.scrollToLine(1, true, true, function () {});
		jsonEditor.gotoLine(1); 
	}
	
	const showTest = (testId) => {
		currentTestId = testId;
		render();
	}
	
	const addTest = () => {
		if (!emptyTestTemplate) {
			alert("Can not add test because empty test template wasn't specified. Use advanced mode to add tests");
			return;
		}
		let newTest = JSON.parse(JSON.stringify(emptyTestTemplate));
		newTest.name = "Test " + (task.tests.length+1);
		let maxId = 0;
		for (let i=0; i<task.tests.length; i++) {
			let test = task.tests.at(i);
			let id = test.hasOwnProperty('id') ? test.id : maxId+1;
			if (id > maxId) maxId = id;
			if (test.name == newTest.name)
				newTest.name = "Test " + (task.tests.length+2);
		}

		newTest.id = maxId+1;
		task.tests.push(newTest);
		dirty = true;
		render();
	}
	
	const deleteTest = (testId) => {
		if (confirm("Are you sure you want to delete test?")) {
			if (currentTestId == testId) {
				currentTestId = 0;
				currentTest = null;
			}
			let maxId = 0;
			for (let i=0; i<task.tests.length; i++) {
				let test = task.tests.at(i);
				let id = test.hasOwnProperty('id') ? test.id : maxId+1;
				if (id == testId) {
					task.tests.splice(i, 1);
					dirty = true;
					render();
					return;
				}
				if (id > maxId) maxId = id;
			}
			console.log("ERROR: Deleting test: Test with id " + testId + " not found");
		}
	}
	
	const moveUpTest = (testId) => {
		let maxId = 0;
		for (let i=0; i<task.tests.length; i++) {
			let test = task.tests.at(i);
			let id = test.hasOwnProperty('id') ? test.id : maxId+1;
			if (id == testId) {
				if (i == 0) {
					console.log("ERROR: Can't move up first element");
				} else {
					task.tests.splice(i-1, 0, task.tests.splice(i, 1)[0]);
					dirty = true;
					render();
				}
				return;
			}
			if (id > maxId) maxId = id;
		}
	}
	
	const addOutputVariant = () => {
		for (let i=0; i<currentTest.tools.length; i++) {
			let tool = currentTest.tools.at(i);
			if (tool.hasOwnProperty('execute')) {
				if (!tool.execute.hasOwnProperty('expect'))
					tool.execute.expect = [];
				tool.execute.expect.push("");
			}
		}
		dirty = true;
		render();
	}
	
	const addPatch = () => {
		let newPatch = { "position": "main", "code": "" };
		let found = false;
		for (let i=0; i<currentTest.tools.length; i++) {
			let tool = currentTest.tools.at(i);
			if (tool.hasOwnProperty('patch')) {
				tool.patch.push(newPatch);
				found = true;
			}
		}
		if (!found) {
			addPatchToolToTest();
		}
		dirty = true;
		render();
	}
	
	
	
	return {
		initialize: initialize,
		loadTemplate: loadTemplate,
		setEmptyTestTemplate: setEmptyTestTemplate,
		load: load,
		save: save,
		checkUnsaved: checkUnsaved,
		render: render,
		
		taskNameChanged: taskNameChanged,
		testNameChanged: testNameChanged,
		languagesChanged: languagesChanged,
		testOptionsChanged: testOptionsChanged,
		fieldChanged: fieldChanged,
		jsonChanged: jsonChanged,
		testAddTool: testAddTool,
		
		openTab: openTab,
		updateJsonTab: updateJsonTab,
		showTest: showTest,
		addTest: addTest,
		deleteTest: deleteTest,
		moveUpTest: moveUpTest,
		addOutputVariant: addOutputVariant,
		addPatch: addPatch
	}
 
})();


window.onbeforeunload = TaskEditor.checkUnsaved;
