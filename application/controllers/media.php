<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

class Media extends CI_Controller {
	function __construct() {
		parent::__construct();
		$this->load->library('KeyValueStore');
		$this->store = $this->keyvaluestore;
	}

	public function show() {
		$args = func_get_args();
		$id = array_shift($args);
		$path = implode('/', $args);
		$phar_path = $this->store->get($id);
		if(!$phar_path || ! Phar::isValidPharFilename($phar_path)) {
			show_404();
			return;
		}

		$phar_path = realpath($phar_path);
		if(strpos($path, 'm3u8')) {
			header('Content-Type: application/x-mpegurl');
		}
		if(strpos($path, '.ts')) {
			header('Content-Type: video/mp2t');
		}
		$p = 'phar://'.$phar_path.'/'.$path;
		echo file_get_contents($p);
	}

	public function test() {
		$this->store->set('111231314-uuid', '/tmp/a.phar');
		var_dump($this->store->get('test'));
	}


	public function upload()
	{
		if ($_SERVER['REQUEST_METHOD'] === 'POST') {
			$this->load->model('mediastore');
			echo $this->mediastore->store('upfile', $this->input->post(null, true));
		}
		else {
			$this->load->view('up.php');
		}
	}


	public function batch()
	{
		$this->load->model('mediaprocessor');
		$this->mediaprocessor->execute();
	}
}
