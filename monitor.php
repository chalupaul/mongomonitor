<?php
require_once(join(DIRECTORY_SEPARATOR, [dirname(__FILE__), 'common.php']));
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



?>
<html>
<body>
<?
# I dislike doing this stuff, but this is supposed to be a "no external deps" sort of project.
foreach([$mongos, $configsvrs, $shards] as $role) {
	$output = '';
	foreach (array_keys($role) as $roleName) {
		$output .= "<h2>$roleName</h2>\n";
		$output .= "<ul>\n";
		if (in_array("replicaSetError", array_keys($failures[$roleName]))) {
			$output .= "<li><h3>Replica Set Errors Detected!</h3></li>\n";
		}
		foreach ($role[$roleName] as $server) {
			$hasError = in_array($server, array_keys($failures[$roleName]));
			if ($hasError) {
				$statusText = 'NOT OK';
			} else {
				$statusText = 'OK';
			}
			$output .= "<li><span>$server</span><span>$statusText</span></li>\n";
		}
		$output .= "</ul>\n";
	}
	print($output);
}
if ($failures != []) {
	print("<h2>Raw Errors</h2>");
	print("<div>\n");
	foreach(array_keys($failures) as $componentName) { # like 'mongos', or 'shard42'
		print("<div>\n");
		print("<h3>$componentName</h3>\n");
		print("<ul>\n");
		foreach(array_keys($failures[$componentName]) as $host) { # like '127.0.0.1:27017' or replicafailures
			print("<li><b>$host</b>\n");
			print("<pre>");
			print_r($failures[$componentName][$host]);
			print("</pre>\n");
			print("</li>\n");
		}
		print("</ul>\n");
		print("</div>\n");
	}
	print("</div>\n");
}
?>
</body>
</html>