<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

class KeyValueStore {
	public function __construct() {
		$ci = get_instance();
		$this->store = new Memcache;
		$config = $ci->load->config();
		$this->host = @$config['store_address'];
		$this->host = $this->host? $this->host : 'localhost';
		$this->port = @$config['store_port'];
		$this->port = $this->port? $this->port :21201;

		@$this->store->connect($this->host, $this->port);
	}

	public function set($key, $value, $flag = false, $expire = 0) {
		return $this->store->set($key, $value, $flag, $expire);
	}

	public function get($key) {
		return $this->store->get($key);
	}

	public function incr($key, $offset=1)
	{
		return $this->store->increment($key, $offset);
	}
}
