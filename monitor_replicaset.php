<?php
# This file is used for when you have only a replicaset to connect to.
require_once(join(DIRECTORY_SEPARATOR, [dirname(__FILE__), 'common.php']));
# pour one out for the template homies
require_once(join(DIRECTORY_SEPARATOR, [dirname(__FILE__), 'mustache.php-2.11.1', 'src', 'Mustache', 'Autoloader.php']));
Mustache_Autoloader::register();

$m = new Mustache_Engine(array(
	'loader' => new Mustache_Loader_FilesystemLoader(dirname(__FILE__) . '/views'),
	'partials_loader' => new Mustache_Loader_FilesystemLoader(dirname(__FILE__) . '/views/partials')
));

$failures = [];
$config = loadConfig();
$shardName = 'repls';


foreach ($config['mongos'] as $server) {
	global $shardName;
	isServerOk($server, $shardName);
	$output = commandRunner(makeCommandString($server, "rs.status()"));
	$isOK = preg_match('/"ok" : 1/', $output);
	$connFailure = preg_match('/exception: connect failed/', $output);
	if ($isOK != 1 && $connFailure != 1) { 
		# We don't care about connection failures, just replica set failures.
		$failures[$shardName]['replicaSetError'] = $output;
	}
}

function makeHosts() {
	global $failures;
	global $config;
	global $shardName;
	$vals = [];
	foreach ($config['mongos'] as $server) {
		$x = [];
		$x['url'] = $server;
		$failArray = [];
		if (in_array($shardName, $failures)) {
			$failArray = array_keys($failures[$shardName]);
		}
		$hasError = in_array($server, $failArray);
		if ($hasError) {
			$x['errorStatus'] = 'NOT OK';
		} else {
			$x['errorStatus']= 'OK';
		}
		array_push($vals, $x);
	}
	return $vals;
}

# Make everything unilateral for the views
$tpl = array(
	"hosts" => makeHosts(),
	"failures" => makeLogs(),
	"backups" => makeBackupErrors()
);

if (!empty($tpl['failures']) || !empty($tpl['backups'])) {
	http_response_code(503);
}
echo $m->render('replicaset_layout', $tpl);

?>