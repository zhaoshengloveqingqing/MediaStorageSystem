<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

class Welcome extends CI_Controller {

   function __construct() {
        parent::__construct();
		$this->load->model('user');
		$this->load->database();
   }

	public function index() {
		$this->load->view('welcome_message', array('users' => $this->user->list_users()->result()));
	}

   	public function register() {
		$this->user->register_user(
			$this->input->get('username'),
			$this->input->get('password'),
			$this->input->get('password')
		);
	}

	public function test() {
		print_r($this->user->test());
	}
}

/* End of file welcome.php */
/* Location: ./application/controllers/welcome.php */
