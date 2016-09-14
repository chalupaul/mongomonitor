<?php
require_once(join(DIRECTORY_SEPARATOR, [dirname(__FILE__), 'common.php']));
$config = loadConfig();
# I dont want to deal with daylight savings
date_default_timezone_set('UTC'); 

# database backups can take a long time.
set_time_limit(0);

# First we get our database list
$dbs = null;
$mongosIp = null;
foreach ($config['mongos'] as $ip) {
	$command = makeCommandString($ip, 'db.adminCommand("listDatabases")');
	$dbs_string = commandRunner($command);
	# Make sure we have at least some sort of read permissions and access to the database
	$dbs = (json_decode($dbs_string, true));
	if ($dbs != null) {
		$mongosIp = $ip;
		break;
	}
}
if ($dbs == null) {
	print("Unable to connect to any mongos for backup.\n");
	exit(1);
}

# Ensure the paths and filenames and stuff are setup
if (!is_dir($config['directory'])) {
	mkdir($config['directory'], 0755, true);
}
$archiveName = 'backup-' .date('Y-m-d_H:i') . '.gz';
$filename = join(DIRECTORY_SEPARATOR, [$config['directory'], $archiveName]);

$options = [
	"--quiet",
	"--host", $mongosIp,
	"-u", $config['username'],
	"-p", $config['password'],
	"--authenticationDatabase", $config['authdb'],
	"--gzip",
	"--archive=$filename",
	'2>&1'
];

$commandParams = combineArrays(['mongodump'], $options); 
$command = join(' ', $commandParams);
#TODO: Uncomment
print(shell_exec($command));

# Now we cleanup the old ones. Anything older than number of days, and only the latest number of files
function getOldBackups() {
	global $config;
	return glob(join(DIRECTORY_SEPARATOR, [$config['directory'], "backup-*.gz"]));
}

if ($config['backup_count'] != null) {
	$files = getOldBackups();
	$number = $config['backup_count'] * -1;
	$to_save = array_slice($files, $number);
	$to_delete = array_diff($files, $to_save);
	foreach ($to_delete as $file) {
		unlink($file);
	}
}


if ($config['retention_days'] != null) {
	$files = getOldBackups();
	foreach($files as $file) {
		if (filemtime($file) < time() - (86400 * $config['retention_days'])) {
			unlink($file);
		}
	}
}
?>