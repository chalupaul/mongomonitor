<?php
require_once(join(DIRECTORY_SEPARATOR, [dirname(__FILE__), 'common.php']));
# pour one out for the template homies
require_once(join(DIRECTORY_SEPARATOR, [dirname(__FILE__), 'mustache.php-2.11.1', 'src', 'Mustache', 'Autoloader.php']));
Mustache_Autoloader::register();

$m = new Mustache_Engine(array(
	'loader' => new Mustache_Loader_FilesystemLoader(dirname(__FILE__) . '/views'),
	'partials_loader' => new Mustache_Loader_FilesystemLoader(dirname(__FILE__) . '/views/partials')
));

$config = loadConfig();

# These aren't one big collection of replica sets becaues you shouldn't treat them the same.
$mongos = array(
	'mongos' => $config['mongos']
);
$shards = [];
$configsvrs = [];
# This holds any errors for the html portion.
$failures = [];
error_reporting(E_ERROR | E_PARSE);


# So, you can't currently query things like sh.status() from the php mongodb bindings, so everything
# has to be shell bound. Not really a way around that, so we kind of make a million connections to 
# run this script. 
#
# There is some bug somewhere deep that often causes ECONNREFUSED on these things, when in fact you
# can connect to mongo just fine. Thus we are forced to make terrible retry loops. The default wait
# times are 5 loops of 2 second sleep intervals. Every downed host ends up increasing the run time
# by a minute or so. The false positives only last 5-7 seconds so 10 seems reasonable. Just be
# warned when running this behind apache that you may have to change timeouts to allow for long scripts.
set_time_limit(300);

foreach ($mongos['mongos'] as $ip) {
	# Mainly just populating a total list of hosts to be checked.
	# There's a posibility that a mongos box could be dead, so we try for all and then roll up the results
	foreach([[&$shards, "printjson(sh.status())"], [&$configsvrs, "printjson(db.serverStatus())"]] as $args) {
		$output = commandRunner(makeCommandString($ip, $args[1]));
		$found = findServers($output);
		$args[0] = combineArrays($args[0], $found);
	}
	#	isServerOk($ip, 'mongos');
}

# The next step is a general "ok"ness of servers. Will catch individual servers down, bad config, etc.
# Behold the nastiness of php collections
foreach([$mongos, $shards, $configsvrs] as $cluster) {
	foreach (array_keys($cluster) as $serverType) {
		foreach($cluster[$serverType] as $server) {
			isServerOk($server, $serverType);
		}
	}
}

#Next we check the replication status of the individual shards and config servers
foreach([$shards, $configsvrs] as $cluster) {
	foreach (array_keys($cluster) as $shardName) {
		# Again, in the case that 1 or more is down, we need to run it everywhere. In case of split brain,
		# we're going to make sure we get success on all 3 and only break if there is a failure.
		# This check mainly catches stale sync, clock differences, etc as you can be down to a single server and
		# still have a functioning replica set.
		foreach ($cluster[$shardName] as $server) {
			$output = commandRunner(makeCommandString($server, "rs.status()"));
			$isOK = preg_match('/"ok" : 1/', $output);
			$connFailure = preg_match('/exception: connect failed/', $output);
			if ($isOK != 1 && $connFailure != 1) { 
				# We don't care about connection failures, just replica set failures.
				$failures[$shardName]['replicaSetError'] = $output;
			}
		}
	
	}
}


# everything from here on out is transformation to views. Since php doesn't have hashes, mustache can't tell
# the difference between an array with keys and an array of values. So these functions just massage the data
# for the view below.
function makeMongos() {
	global $mongos;
	global $failures;
	$vals = array();
	foreach($mongos['mongos'] as $host) {
		$hasError = in_array($host, array_keys($failures['mongos']));
		$errorStatus = makeErrorText($hasError);
		array_push($vals, array(
			'url' => $host,
			'errorStatus' => $errorStatus
		));
	}
	return $vals;
}

function makeReplSets() {
	global $shards;
	global $failures;
	$vals = array();
	foreach(array_keys($shards) as $shardName) {
		$v = array();
		$v['name'] = $shardName;
		$v['hosts'] = array();
		if (in_array('replicaSetError', array_keys($failures[$shardName]))) {
			$v['message'] = 'Replica Set Errors Detected!';
		}
		foreach($shards[$shardName] as $host) {
			$hasError = in_array($host, array_keys($failures[$shardName]));
			$errorStatus = makeErrorText($hasError);
			array_push($v['hosts'], array(
				'url' => $host,
				'errorStatus' => $errorStatus
			));
		}
		array_push($vals, $v);
	}
	return $vals;
}

function makeConfigServers() {
	global $configsvrs;
	global $failures;
	$returnVal = array();
	$returnVal["name"] = "Config Servers";
	if (in_array('replicaSetError', array_keys($failures['configsvr']))) {
		$returnVal['message'] = 'Replica Set Errors Detected!';
	}
	$vals = array();
	foreach($configsvrs['configsvr'] as $host) {
		$hasError = in_array($host, array_keys($failures['configsvr']));
		$errorStatus = makeErrorText($hasError);
		array_push($vals, array(
			'url' => $host,
			'errorStatus' => $errorStatus
		));
	}
	$returnVal["hosts"] = $vals;
	
	return $returnVal;
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

# Make everything unilateral for the views
$tpl = array(
	"mongos" => array(
		"name" => "Mongos",
		"hosts" => makeMongos()
	),
	"shards" => makeReplSets(),
	"configsvrs" => makeConfigServers(),
	"failures" => makeLogs(),
	"backups" => makeBackupErrors()
);

if (!empty($tpl['failures']) || !empty($tpl['backups'])) {
	http_response_code(503);
}
# all that for 1 line....
echo $m->render('layout', $tpl);

?>