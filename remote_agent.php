<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2017 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

/* We are not talking to the browser */
$no_http_headers = true;

include('./include/global.php');
include_once('./lib/api_device.php');
include_once('./lib/data_query.php');
include_once('./lib/poller.php');
include_once('./lib/ping.php');
include_once('./lib/snmp.php');
include_once('./lib/rrd.php');

$debug = false;

if (!remote_client_authorized()) {
	print 'FATAL: You are not authorized to use this service';
	exit;
}

set_default_action();

switch (get_request_var('action')) {
	case 'polldata':
		debug('Start: Poling Data for Realtime');
		poll_for_data();
		debug('End: Poling Data for Realtime');

		break;
	case 'runquery':
		debug('Start: Running Data Query');
		run_remote_data_query();
		debug('End: Running Data Query');

		break;
	case 'ping':
		debug('Start: Pinging Device');
		ping_device();
		debug('End: Pinging Device');

		break;
	case 'snmpget':
		debug('Start: Performing SNMP Get Request');
		get_snmp_data();
		debug('End: Performing SNMP Get Request');

		break;
	case 'snmpwalk':
		debug('Start: Performing SNMP Walk Request');
		get_snmp_walk_data();
		debug('End: Performing SNMP Walk Request');

		break;
	case 'graph_json':
		debug('Start: Performing Graph Request');
		get_graph_data();
		debug('End: Performing Graph Request');

		break;
	case 'discover':
		debug('Start:Performing Network Discovery Request');
		run_remote_discovery();
		debug('End:Performing Network Discovery Request');

		break;
	default:
		if (!api_plugin_hook_function('remote_agent', get_request_var('action'))) {
			debug('WARNING: Unknown Agent Request');
			print 'Unknown Agent Request';
		}
}

exit;

function debug($message) {
	global $debug;

	if ($debug) {
		cacti_log("REMOTE DEBUG: " . trim($message), false, 'WEBSVCS');
	}
}

function strip_domain($host) {
	if (strpos($host, '.') !== false) {
		$parts = explode('.', $host);
		return $parts[0];
	}else{
		return $host;
	}
}

function remote_client_authorized() {
	/* don't allow to run from the command line */
	if (!isset($_SERVER['REMOTE_ADDR'])) return false;

	$client_addr = $_SERVER['REMOTE_ADDR'];
	$client_name = strip_domain(gethostbyaddr($client_addr));

	$pollers = db_fetch_assoc('SELECT * FROM poller');

	if (sizeof($pollers)) {
		foreach($pollers as $poller) {
			if (strip_domain($poller['hostname']) == $client_name) {
				return true;
			}elseif ($poller['hostname'] == $client_addr) {
				return true;
			}
		}
	}

	return false;
}

function get_graph_data() {
	get_filter_request_var('graph_start');
	get_filter_request_var('graph_end');
	get_filter_request_var('graph_height');
	get_filter_request_var('graph_width');
	get_filter_request_var('local_graph_id');
	get_filter_request_var('rra_id');
	get_filter_request_var('graph_theme', FILTER_CALLBACK, array('options' => 'sanitize_search_string'));
	get_filter_request_var('effective_user');

	$local_graph_id   = get_filter_request_var('local_graph_id');
	$rra_id           = get_filter_request_var('rra_id');

	$graph_data_array = array();

	/* override: graph start time (unix time) */
	if (!isempty_request_var('graph_start') && get_request_var('graph_start') < 1600000000) {
		$graph_data_array['graph_start'] = get_request_var('graph_start');
	}

	/* override: graph end time (unix time) */
	if (!isempty_request_var('graph_end') && get_request_var('graph_end') < 1600000000) {
		$graph_data_array['graph_end'] = get_request_var('graph_end');
	}

	/* override: graph height (in pixels) */
	if (!isempty_request_var('graph_height') && get_request_var('graph_height') < 3000) {
		$graph_data_array['graph_height'] = get_request_var('graph_height');
	}

	/* override: graph width (in pixels) */
	if (!isempty_request_var('graph_width') && get_request_var('graph_width') < 3000) {
		$graph_data_array['graph_width'] = get_request_var('graph_width');
	}

	/* override: skip drawing the legend? */
	if (!isempty_request_var('graph_nolegend')) {
		$graph_data_array['graph_nolegend'] = get_request_var('graph_nolegend');
	}

	/* print RRDTool graph source? */
	if (!isempty_request_var('show_source')) {
		$graph_data_array['print_source'] = get_request_var('show_source');
	}

	/* disable cache check */
	if (isset_request_var('disable_cache')) {
		$graph_data_array['disable_cache'] = true;
	}

	/* set the theme */
	if (isset_request_var('graph_theme')) {
		$graph_data_array['graph_theme'] = get_request_var('graph_theme');
	}

	/* set the theme */
	if (isset_request_var('effective_user')) {
		$user = get_request_var('effective_user');
	}else{
		$user = 0;
	}

	$graph_data_array['graphv'] = true;

	$xport_options = array();

	print @rrdtool_function_graph($local_graph_id, $rra_id, $graph_data_array, null, $xport_options, $user);

	return true;
}

function get_snmp_data() {
	$host_id = get_filter_request_var('host_id');
	$oid     = get_nfilter_request_var('oid');

	if (!empty($host_id)) {
		$host = db_fetch_row_prepared('SELECT * FROM host WHERE id = ?', array($host_id));
		$session = cacti_snmp_session($host['hostname'], $host['snmp_community'], $host['snmp_version'],
			$host['snmp_username'], $host['snmp_password'], $host['snmp_auth_protocol'], $host['snmp_priv_passphrase'],
			$host['snmp_priv_protocol'], $host['snmp_context'], $host['snmp_engine_id'], $host['snmp_port'],
			$host['snmp_timeout'], $host['ping_retries'], $host['max_oids']);

		if ($session === false) {
			$output = 'U';
		}else{
			$output = cacti_snmp_session_get($session, $oid);
			$session->close();
		}
	}

	print $output;
}

function get_snmp_data_walk() {
	$host_id = get_filter_request_var('host_id');
	$oid     = get_nfilter_request_var('oid');

	if (!empty($host_id)) {
		$host = db_fetch_row_prepared('SELECT * FROM host WHERE id = ?', array($host_id));
		$session = cacti_snmp_session($host['hostname'], $host['snmp_community'], $host['snmp_version'],
			$host['snmp_username'], $host['snmp_password'], $host['snmp_auth_protocol'], $host['snmp_priv_passphrase'],
			$host['snmp_priv_protocol'], $host['snmp_context'], $host['snmp_engine_id'], $host['snmp_port'],
			$host['snmp_timeout'], $host['ping_retries'], $host['max_oids']);

		if ($session === false) {
			$output = 'U';
		}else{
			$output = cacti_snmp_session_walk($session, $oid);
			$session->close();
		}
	}

	if (sizeof($output)) {
		print json_encode($output);
	}else{
		print 'U';
	}
}

function ping_device() {
	$host_id = get_filter_request_var('host_id');
	api_device_ping_device($host_id, true);
}

function poll_for_data() {
	$local_data_id = get_filter_request_var('local_data_id');
	$host_id       = get_filter_request_var('host_id');

	$item = db_fetch_row_prepared('SELECT * 
		FROM poller_item 
		WHERE host_id = ? 
		AND local_data_id = ?', 
		array($host_id, $local_data_id));

	if (sizeof($item)) {
		switch ($item['action']) {
		case POLLER_ACTION_SNMP: /* snmp */
			if (($item['snmp_version'] == 0) || (($item['snmp_community'] == '') && ($item['snmp_version'] != 3))) {
				$output = 'U';
			}else {
				$host = db_fetch_row_prepared('SELECT ping_retries, max_oids FROM host WHERE hostname = ?', array($item['hostname']));
				$session = cacti_snmp_session($item['hostname'], $item['snmp_community'], $item['snmp_version'],
					$item['snmp_username'], $item['snmp_password'], $item['snmp_auth_protocol'], $item['snmp_priv_passphrase'],
					$item['snmp_priv_protocol'], $item['snmp_context'], $item['snmp_engine_id'], $item['snmp_port'],
					$item['snmp_timeout'], $host['ping_retries'], $host['max_oids']);

				if ($session === false) {
					$output = 'U';
				}else{
					$output = cacti_snmp_session_get($session, $item['arg1']);
					$session->close();
				}

				if (prepare_validate_result($output) === false) {
					if (strlen($output) > 20) {
						$strout = 20;
					} else {
						$strout = strlen($output);
					}

					$output = 'U';
				}
			}

			break;
		case POLLER_ACTION_SCRIPT: /* script (popen) */
			$output = trim(exec_poll($item['arg1']));

			if (prepare_validate_result($output) === false) {
				if (strlen($output) > 20) {
					$strout = 20;
				} else {
					$strout = strlen($output);
				}

				$output = 'U';
			}

			break;
		case POLLER_ACTION_SCRIPT_PHP: /* script (php script server) */
			$cactides = array(
				0 => array('pipe', 'r'), // stdin is a pipe that the child will read from
				1 => array('pipe', 'w'), // stdout is a pipe that the child will write to
				2 => array('pipe', 'w')  // stderr is a pipe to write to
			);

			if (function_exists('proc_open')) {
				$cactiphp = proc_open(read_config_option('path_php_binary') . ' -q ' . $config['base_path'] . '/script_server.php realtime', $cactides, $pipes);
				$output = fgets($pipes[1], 1024);
				$using_proc_function = true;
			}else {
				$using_proc_function = false;
			}

			if ($using_proc_function == true) {
				$output = trim(str_replace("\n", '', exec_poll_php($item['arg1'], $using_proc_function, $pipes, $cactiphp)));

				if (prepare_validate_result($output) === false) {
					if (strlen($output) > 20) {
						$strout = 20;
					} else {
						$strout = strlen($output);
					}

					$output = 'U';
				}
			}else{
				$output = 'U';
			}

			if (($using_proc_function == true) && ($script_server_calls > 0)) {
				/* close php server process */
				fwrite($pipes[0], "quit\r\n");
				fclose($pipes[0]);
				fclose($pipes[1]);
				fclose($pipes[2]);

				$return_value = proc_close($cactiphp);
			}

			break;
		}
	}

	print $output;
}

function run_remote_data_query() {
	$host_id = get_filter_request_var('host_id');
	$data_query_id = get_filter_request_var('data_query_id');

	if ($host_id > 0 && $data_query_id > 0) {
		run_data_query($host_id, $data_query_id);
	}
}

function run_remote_discovery() {
	global $config;

	$poller_id = $config['poller_id'];
	$network   = get_filter_request_var('network');
	$php       = read_config_option('path_php_binary');
	$path      = read_config_option('path_webroot') . '/poller_automation.php';

	$options   = ' --poller=' . $poller_id . ' --network=' . $network . ' --force';
	if (isset_request_var('debug')) {
		$options .= ' --debug';
	}

	exec_background($php, '-q ' . $path . $options);

	sleep(2);

	return;
}
