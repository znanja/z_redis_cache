<?php defined('SYSPATH') or die('No direct script access.');
return array(
	'redis'	=> array(
		'driver'			=> 'redis',
		'defailt_expire'	=> 3600,
		'servers'			=> array(
			array(
				'host'			=> 'localhost',
				'port'			=> 6379,
				'persistent'	=> FALSE,
				'database'		=> 15,
				'timeout'		=> 0,
			),
		)
	)
);