<?php


if (!create_instance($GLOBALS['argv'], $errors)) {
	foreach($errors as $index=>$error)
		echo "$error\n";
	echo "Campsite parameters:\n";
	foreach($Campsite as $var_name=>$value)
		echo "$var_name = $value\n";
}


function create_instance($p_arguments, &$p_errors)
{
	global $Campsite, $CampsiteVars;

	$p_errors = array();
	// read parameters
	if (!$defined_parameters = read_parameters($p_arguments, $p_errors))
		return false;

	$etc_dir = $defined_parameters['--etc_dir'];
	// check if etc directory was valid
	if (!is_dir($etc_dir)) {
		echo "Invalid etc directory " . $defined_parameters['--etc_dir'] . "\n";
		return false;
	}

	// check if install_conf.php and parser_conf.php files exist
	if (!is_file($etc_dir . "/install_conf.php")
		|| !is_file($etc_dir . "/parser_conf.php")) {
		echo "Configuration file(s) are missing\n";
		return false;
	}

	require_once($etc_dir . "/install_conf.php");
	require_once($etc_dir . "/parser_conf.php");

	if (!is_array($CampsiteVars['install']) || !is_array($CampsiteVars['parser'])
		|| !is_array($Campsite)) {
		echo "Invalid configuration file(s) format\n";
		return false;
	}

	fill_missing_parameters($defined_parameters);

	if (!($res = create_configuration_files($defined_parameters)) == 0) {
		$p_errors[] = $res;
		return false;
	}

	if (!($res = create_site($defined_parameters)) == 0) {
		$p_errors[] = $res;
		return false;
	}

	if (!($res = create_database($defined_parameters)) == 0) {
		$p_errors[] = $res;
		return false;
	}

	return true;
}


function create_configuration_files($p_defined_parameters)
{
	global $Campsite, $CampsiteVars;

	$global_etc_dir = $Campsite['ETC_DIR'];
	$instance_etc_dir = $global_etc_dir . "/" . $p_defined_parameters['--db_name'];
	if (!is_dir($instance_etc_dir))
		if (!mkdir($instance_etc_dir))
			return "Unable to create configuration directory $instance_etc_dir";

	$html_common_dir = $Campsite['WWW_COMMON_DIR'] . "/html";
	require_once($html_common_dir . "/classes/ModuleConfiguration.php");

	$db_module = new ModuleConfiguration;
	$db_variables = array('DATABASE_NAME'=>$p_defined_parameters['--db_name'],
		'DATABASE_SERVER_ADDRESS'=>$p_defined_parameters['--db_server_address'],
		'DATABASE_SERVER_PORT'=>$p_defined_parameters['--db_server_port'],
		'DATABASE_USER'=>$p_defined_parameters['--db_user'],
		'DATABASE_PASSWORD'=>$p_defined_parameters['--db_password']);
	$db_module->create('database', $db_variables);
	if (!($res = $db_module->save($instance_etc_dir)) == 0)
		return $res;

	$parser_module = new ModuleConfiguration;
	$parser_variables = array('PARSER_PORT'=>$p_defined_parameters['--parser_port'],
		'PARSER_MAX_THREADS'=>$p_defined_parameters['--parser_max_threads']);
	$parser_module->create('parser', $parser_variables);
	if (!($res = $parser_module->save($instance_etc_dir)) == 0)
		return $res;

	$smtp_module = new ModuleConfiguration;
	$smtp_variables = array(
		'SMTP_SERVER_ADDRESS'=>$p_defined_parameters['--smtp_server_address'],
		'SMTP_SERVER_PORT'=>$p_defined_parameters['--smtp_server_port']);
	$smtp_module->create('smtp', $smtp_variables);
	if (!($res = $smtp_module->save($instance_etc_dir)) == 0)
		return $res;

	$apache_module = new ModuleConfiguration;
	$apache_variables = array('APACHE_USER'=>$p_defined_parameters['--apache_user'],
		'APACHE_GROUP'=>$p_defined_parameters['--apache_group']);
	$apache_module->create('apache', $apache_variables);
	if (!($res = $apache_module->save($instance_etc_dir)) == 0)
		return $res;

	$cmd = "chown \"" . $Campsite['APACHE_USER'] . ":" . $Campsite['APACHE_GROUP']
		. "\" \"$instance_etc_dir\" -R 2>&1";
	exec($cmd, $output, $res);
	if ($res != 0)
		return implode("\n", $output);

	return 0;
}


function create_database($p_defined_parameters)
{
	global $Campsite, $CampsiteVars;

	$db_name = $p_defined_parameters['--db_name'];
	$instance_etc_dir = $p_defined_parameters['--etc_dir'] . "/" . $db_name;
	require_once($instance_etc_dir . "/database_conf.php");
	$db_dir = $Campsite['CAMPSITE_DIR'] . "/instance/database";

	$db_user = $Campsite['DATABASE_USER'];
	$db_password = $Campsite['DATABASE_PASSWORD'];
	$res = mysql_connect($Campsite['DATABASE_SERVER_ADDRESS'] . ":"
		. $Campsite['DATABASE_SERVER_PORT'], $db_user, $db_password);
	if (!$res)
		return "Unable to connect to database server";

	$db_exists = database_exists($db_name);
	if ($db_exists) {
		if (!($res = backup_database($db_name, $p_defined_parameters)) == 0)
			return $res;
		if (!($res = upgrade_database($db_name, $p_defined_parameters)) == 0) {
			restore_database($db_name, $p_defined_parameters);
			return $res . "\nThere was an error when upgrading the database; "
				. "the old database was restored.";
		}
	} else {
		if (!mysql_query("CREATE DATABASE " . $db_name))
			return "Unable to create the database " . $db_name;
		$cmd = "mysql -u $db_user";
		if ($db_password != "")
			$cmd .= " --password=\"$db_password\"";
		$cmd .= " $db_name < \"$db_dir/campsite-db.sql\" 2>&1";
		exec($cmd, $output, $res);
		if ($res != 0)
			return implode("\n", $output);
	}

	return 0;
}


function upgrade_database($p_db_name, $p_defined_parameters)
{
	global $Campsite, $CampsiteVars;
	$campsite_dir = $Campsite['CAMPSITE_DIR'];
	$etc_dir = $Campsite['ETC_DIR'];
	$db_user = $p_defined_parameters['--db_user'];
	$db_password = $p_defined_parameters['--db_password'];

	if (!database_exists($p_db_name))
		return "Can't upgrade database $p_db_name: it doesn't exist";

	if (!($res = detect_database_version($p_db_name, $old_version)) == 0)
		return $res;

	$versions = array("2.0.x", "2.1.x");
	foreach ($versions as $index=>$db_version) {
		if ($old_version > $db_version)
			continue;
		$output = array();
		// create symlinks to configuration files
		$upgrade_dir = $campsite_dir . "/instance/database/upgrade/$db_version/";
		$db_conf_file = $etc_dir . "/$p_db_name/database_conf.php";
		$link = $upgrade_dir . "/database_conf.php";
		if (!is_link($link) && !symlink($db_conf_file, $link))
			return "Unable to create link to database configuration file";
		$install_conf_file = $etc_dir . "/install_conf.php";
		$link = $upgrade_dir . "/install_conf.php";
		if (!is_link($link) && !symlink($install_conf_file, $link))
			return "Unable to create link to install configuration file";

		// run upgrade scripts
		$cmd_prefix = "cd \"$upgrade_dir\"; mysql -u $db_user";
		if ($db_password != "")
			$cmd_prefix .= " --password=\"$db_password\"";
		$cmd_prefix .= " $p_db_name < \"";
		$sql_scripts = array("tables.sql", "data-required.sql", "data-optional.sql");
		foreach ($sql_scripts as $index=>$script) {
			if (!is_file($upgrade_dir . $script))
				continue;
			$cmd = $cmd_prefix . $script . "\" 2>&1";
			exec($cmd, $output, $res);
			if ($res != 0 && $script != "data-optional.sql")
				return "$script ($db_version): " . implode("\n", $output);
		}
	}

	return 0;
}


function detect_database_version($p_db_name, &$version)
{
	if (!mysql_select_db($p_db_name))
		return "Can't select the databae $p_db_name";

	if (!$res = mysql_query("SHOW TABLES"))
		return "Unable to query the database $p_db_name";

	$version = "2.0.x";
	while ($row = mysql_fetch_row($res)) {
		if (in_array($row[0], array("ArticleTopics", "Topics")))
			$version = $version < "2.1.x" ? "2.1.x" : $version;
		if (in_array($row[0], array("URLTypes", "TemplateTypes", "Templates", "Aliases")))
			$version = "2.2.x";
	}

	return 0;
}


function database_exists($p_db_name)
{
	$res = mysql_list_dbs();
	while ($row = mysql_fetch_object($res))
		if ($row->Database == $p_db_name)
			return true;
	return false;
}


function backup_database($p_db_name, $p_defined_parameters)
{
	global $Campsite, $CampsiteVars;

	if (!database_exists($p_db_name))
		return "Can't back up database $p_db_name: it doesn't exist";

	$backup_dir = $Campsite['CAMPSITE_DIR'] . "/backup/$p_db_name";
	if (!is_dir($backup_dir) && !mkdir($backup_dir))
		return "Unable to create database backup directory $backup_dir";

	$cmd = "mysqldump -u " . $Campsite['DATABASE_USER'];
	if ($Campsite['DATABASE_PASSWORD'] != "")
		$cmd .= " --password=\"" . $Campsite['DATABASE_PASSWORD'] . "\"";
	$cmd .= " $p_db_name > \"$backup_dir/$p_db_name-backup.sql\"";
	exec($cmd, $output, $res);
	if ($res != 0)
		return implode("\n", $output);

	return 0;
}


function restore_database($p_db_name, $p_defined_parameters)
{
	global $Campsite, $CampsiteVars;

	$backup_file = $Campsite['CAMPSITE_DIR'] . "/backup/$p_db_name/$p_db_name-backup.sql";
	if (!is_file($backup_file))
		return "Can't restore database: backup file not found";

	if (database_exists($p_db_name))
		mysql_query("DROP DATABASE $p_db_name");
	mysql_query("CREATE DATABASE $p_db_name");

	$cmd = "mysql -u " . $Campsite['DATABASE_USER'];
	if ($Campsite['DATABASE_PASSWORD'] != "")
		$cmd .= " --password=\"" . $Campsite['DATABASE_PASSWORD'] . "\"";
	$cmd .= " $p_db_name < \"$backup_file\"";
	exec($cmd, $output, $res);
	if ($res != 0)
		return implode("\n", $output);

	return 0;
}


function create_site($p_defined_parameters)
{
	global $Campsite, $CampsiteVars, $CampsiteOld;

	$db_name = $p_defined_parameters['--db_name'];
	$etc_dir = $p_defined_parameters['--etc_dir'];
	$instance_etc_dir = $etc_dir . "/$db_name";
	require_once($etc_dir . "/install_conf.php");

	$instance_www_dir = $Campsite['WWW_DIR'] . "/$db_name";
	$html_dir = $instance_www_dir . "/html";
	$common_html_dir = $Campsite['WWW_COMMON_DIR'] . "/html";
	$instance_dirs = array('WWW_DIR'=>$instance_www_dir,
		'HTML_DIR'=>$instance_www_dir . "/html",
		'IMAGES_DIR'=>$instance_www_dir . "/html/images",
		'TEMPLATES_DIR'=>$instance_www_dir . "/html/look",
		'CGI_DIR'=>$instance_www_dir . "/cgi-bin",
		'SCRIPT_DIR'=>$instance_www_dir . "/script",
		'INCLUDE_DIR'=>$instance_www_dir . "/include");

	// create directories
	foreach ($instance_dirs as $dir_type=>$dir_name)
		if (!is_dir($dir_name) && !mkdir($dir_name))
			return "Unable to create directory $dir_name";

	if ($CampsiteOld['.MODULES_HTML_DIR'] != "") {
		$cmd = "cp -fr \"" . $CampsiteOld['.MODULES_HTML_DIR'] . "/look\" \"$html_dir\"";
		exec($cmd);
	}
	// create symbolik links to configuration files
	$link_files = array("$etc_dir/install_conf.php"=>"$html_dir/install_conf.php",
		"$instance_etc_dir/database_conf.php"=>"$html_dir/database_conf.php",
		"$instance_etc_dir/parser_conf.php"=>"$html_dir/parser_conf.php",
		"$instance_etc_dir/smtp_conf.php"=>"$html_dir/smtp_conf.php",
		"$instance_etc_dir/apache_conf.php"=>"$html_dir/apache_conf.php",
		"$common_html_dir/index.php"=>"$html_dir/index.php",
		"$common_html_dir/admin.php"=>"$html_dir/admin.php",
		"$common_html_dir/configuration.php"=>"$html_dir/configuration.php",
		"$common_html_dir/classes"=>"$html_dir/classes",
		"$common_html_dir/css"=>"$html_dir/css",
		"$common_html_dir/include"=>"$html_dir/include",
		"$common_html_dir/javascript"=>"$html_dir/javascript"
		);
	foreach ($link_files as $file=>$link) {
		if (!is_link($link) && !symlink($file, $link))
			return "Unable to create symbolic link to $file";
	}

	$cmd = "chown " . $Campsite['APACHE_USER'] . ":" . $Campsite['APACHE_GROUP']
		. " \"$instance_www_dir\" -R";
	exec($cmd);
	$cmd = "chmod ug+w \"$instance_www_dir\" -R";
	exec($cmd);

	return 0;
}


function fill_missing_parameters(&$p_defined_parameters)
{
	global $Campsite, $CampsiteVars, $CampsiteOld;
	global $g_instance_parameters, $g_mandatory_parameters, $g_parameters_defaults;
	define_globals();

	foreach ($g_instance_parameters as $param_index=>$param_name)
		if (!array_key_exists($param_name, $p_defined_parameters)) {
			$param_value = $g_parameters_defaults[$param_name];
			if (strncmp($param_value, "___", 3) == 0) {
				$param_value = $Campsite[substr($param_value, 3)];
			}
			$p_defined_parameters[$param_name] = $param_value;
		}

	// read old configuration
	$db_name = $p_defined_parameters['--db_name'];
	if (is_dir("/etc/campsite.d/$db_name"))
		$old_conf_dir = "/etc/campsite.d/$db_name";
	if (is_dir("/usr/local/etc/campsite.d/$db_name"))
		$old_conf_dir = "/usr/local/etc/campsite.d/$db_name";

	$CampsiteOld = array();
	if (read_old_config($old_conf_dir, 'database', $CampsiteOld) == 0) {
		$p_defined_parameters['--db_server_address'] = $CampsiteOld['DATABASE_SERVER'];
		$p_defined_parameters['--db_server_port'] = $CampsiteOld['DATABASE_PORT'];
		$p_defined_parameters['--db_user'] = $CampsiteOld['DATABASE_USER'];
		$p_defined_parameters['--db_password'] = $CampsiteOld['DATABASE_PASSWORD'];
	}
	if (read_old_config($old_conf_dir, 'parser', $CampsiteOld) == 0) {
		$p_defined_parameters['--parser_port'] = $CampsiteOld['PARSER_PORT'];
		$p_defined_parameters['--parser_max_threads'] = $CampsiteOld['PARSER_THREADS'];
	}
	if (read_old_config($old_conf_dir, 'smtp', $CampsiteOld) == 0) {
		$p_defined_parameters['--smtp_server_address'] = $CampsiteOld['SMTP_SERVER'];
	}
	if (read_old_config("$old_conf_dir/install", '.modules', $CampsiteOld) == 0) {
		$p_defined_parameters['--smtp_server_address'] = $CampsiteOld['SMTP_SERVER'];
	}
}


function read_parameters($p_arguments, &$p_errors)
{
	global $g_instance_parameters, $g_mandatory_parameters, $g_parameters_defaults;
	define_globals();

	$p_errors = array();
	for ($arg_n = 1; $arg_n < sizeof($p_arguments); $arg_n++) {
		// read the parameter name
		$param_name = $p_arguments[$arg_n];
		if (!in_array($param_name, $g_instance_parameters)) {
			$p_errors[] = "Invalid parameter '$param_name'";
			continue;
		}
		// read the parameter value
		$arg_n++;
		if ($arg_n >= sizeof($p_arguments)) {
			$p_errors[] = "Value not specified for argument '$param_name'";
			break;
		}
		$param_val = $p_arguments[$arg_n];
	
		// set the parameter value in $defined_parameters array
		$defined_parameters[$param_name] = $param_val;
		if (array_key_exists($param_name, $g_mandatory_parameters))
			$g_mandatory_parameters[$param_name] = true;
	}
	// check if all mandatory parameters were specified
	foreach ($g_mandatory_parameters as $mp_name=>$mp_value)
		if ($mp_value == false)
			$p_errors[] = "Mandatory parameter '$mp_name' was not specified";

	if (sizeof($p_errors) > 0)
		return false;
	return $defined_parameters;
}


function define_globals()
{
	global $g_instance_parameters, $g_mandatory_parameters, $g_parameters_defaults;

	// global variables
	$g_instance_parameters = array('--etc_dir', '--db_server_address', '--db_server_port',
		'--db_name', '--db_user', '--db_password', '--parser_port',
		'--parser_max_threads', '--smtp_server_address',
		'--smtp_server_port', '--apache_user', '--apache_group');
	$g_mandatory_parameters = array('--etc_dir'=>false, '--db_server_address'=>false);
	$g_parameters_defaults = array(
		'--db_server_port'=>'0',
		'--db_name'=>'campsite',
		'--db_user'=>'root',
		'--db_password'=>'',
		'--parser_port'=>'0',
		'--parser_max_threads'=>'0',
		'--smtp_server_address'=>'___DEFAULT_SMTP_SERVER_ADDRESS',
		'--smtp_server_port'=>'___DEFAULT_SMTP_SERVER_PORT',
		'--apache_user'=>'___APACHE_USER',
		'--apache_group'=>'___APACHE_GROUP'
	);
}


function read_old_config($conf_dir, $module_name, &$variables)
{
	$conf_file = "$conf_dir/$module_name.conf";
	if (!$lines = file($conf_file))
		return "Unable to read configuration file $conf_file";

	$module_name = strtoupper($module_name);
	foreach ($lines as $index=>$line) {
		$ids = explode(" ", $line);
		$var_name = trim($ids[0]);
		$value = trim($ids[1]);
		if ($res = strpos($value, "{"))
			$value = trim(substr($value, 0, $res));
		$variables[$module_name . "_" . $var_name] = $value;
	}
	return 0;
}


?>
