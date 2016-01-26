<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

class User extends CI_Model {
   function __construct() {
        parent::__construct();
   }

	function list_users() {
		return $this->db->get('users');
	}

    function register_user($username, $password, $password_confirm) {
		echo "$username - $password - $password_confirm";
	}
}
