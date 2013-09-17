<?php
	$options = get_option("pmprois_options");	
	$id = $options['id'];
	$api_key = $options['api_key'];

	$connInfo = array('connectionName:' . $id . ':' . $api_key . ':This is the connection for ' . $id . '.infusionsoft.com');