<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

spl_autoload_register(function ($class) {
	if (strpos($class, '\\') === false) {
		return;
	}

	$prefix = APPPATH .'third_party/';
	$file = $prefix . str_replace('\\', '/', $class) .'.php';

	if (file_exists($file)) {
		require $file;
	}
});


class Yaml extends Symfony\Component\Yaml\Yaml
{

}

