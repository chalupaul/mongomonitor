<?php
function loadConfig() {
	$file = join(DIRECTORY_SEPARATOR, [dirname(__FILE__), 'config.ini']);
	if (!file_exists($file)) {
		print("Config file not found: $file\n");
		exit(1);
	}
	return parse_ini_file($file);
}

function makeCommandString($ip, $command) {
	global $config;
    $fixupCommand = '"' . addslashes($command) . '"';
    return join(' ', ['mongo', 
					  "--authenticationDatabase", $config['authdb'], 
					  "-u", $config['username'], 
					  "-p", "'" . addslashes($config['password']) . "'", 
					  "--quiet",
					  $ip, 
					  "--eval", $fixupCommand, 
					  '2>&1']);
}

function findServers($text) {
    # Finds replica sets in a bunch of text. shardname/host:port,hoste2:port...
    $wordMatch = '\w+';
    $ipMatch = '(\d+\.){3}\d+';
    $portMatch = '\d+';
    $matches = [];
    preg_match_all("/$wordMatch\/($ipMatch:$portMatch(,)?)+/", $text, $matches);

    $hosts = array();
    foreach ($matches[0] as $m) {
       $nameSplit = explode('/', $m);
       $hosts[$nameSplit[0]] = explode(',', $nameSplit[1]);
    }
    return $hosts;
}

function isServerOk($ip, $type, $command="db.serverStatus()") {
	# Tests servers for 'OK'ness
	global $failures;
	$output = commandRunner(makeCommandString($ip, $command));
	$status = preg_match('/"ok" : 1/', $output);
	if ($status != 1) {
		$failures[$type][$ip] = $output;
/*		if (!in_array($type, $failures)) {
			$failures[$type] = array();
		}
		$failures[$type][$ip] = $output;
		*/
	}
}

function commandRunner($command, $tries=3) {
	# We get random connection refused, so try a few times.
	for ($x = 0; $x < $tries; $x++) {
		$output = shell_exec($command);
		if (!preg_match('/exception: connect failed/', $output)) {
			break;
		}
		# The usual wait time is like, 6-7 seconds so just go 10 to be safe.
		# If you were to change this to 10 3 second tries, you'd just keep
		# connections permanently in CLOSE_WAIT state and kind of defeat
		# the purpose of waiting for them to clear.
		sleep(2);
	}
	return $output;
}


function combineArrays($a, $b) {
	# merge 2 arrays with only unique vals. There's a big assumption here that associative arrays will all have
	# the same values. Since this is a configured cluster, that should be reliable unless you get into some
	# weird split brain situation during config change.
	return array_merge(
		array_intersect($a, $b),
		array_diff($a, $b),
		array_diff($b, $a)
	);
}

function makeLogs() {
	global $failures;
	$vals = array();
	foreach(array_keys($failures) as $clusterType) {
		$v = array();
		foreach(array_keys($failures[$clusterType]) as $host) {
			array_push($v, array(
				"url" => $host,
				"message" => $failures[$clusterType][$host]
			));
		}
		array_push($vals, array(
			"name" => $clusterType,
			"hosts" => $v
		));
	}
	return $vals;
}

function makeBackupErrors() {
	$backupErrorsFile = locateBackupFile('BACKUP-FAILURES');
	$returnVals = array();
	$vals = array();
	if (file_exists($backupErrorsFile)) {
		$contents = file($backupErrorsFile);
		if (!empty($contents)) {
			foreach ($contents as $datestamp) {
				$datestamp = trim($datestamp);
				$logFile = locateBackupFile('backup-' . $datestamp . '.log');
				$logContents = file_get_contents($logFile);
				array_push($vals, array(
					'date' => $datestamp,
					'message' => $logContents
				));
			}
		}
	}
	if (!empty($vals)) {
		$returnVals = array(
			"message" => "Truncate $backupErrorsFile to acknowldge the errors.",
			"logs" => $vals
		);
	}
	return $returnVals;
}

function makeErrorText($b) {
	# $b is inverse. True is false.
	$text = null;
	if (!$b) {
		$text = 'OK';
	} else {
		$text = 'NOT OK';
	}
	return $text;
}

function locateBackupFile($fileName) {
	global $config;
	# There's so much looking in this directory.
	return join(DIRECTORY_SEPARATOR, [$config['directory'], $fileName]);
}
?>
