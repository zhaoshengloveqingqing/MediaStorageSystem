<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

class Migration_Task_Queue extends CI_Migration {
	public function up() {
		$this->dbforge->add_field(array(
			'id' => array(
				'type' => 'INT',
				'constraint' => 5,
				'unsigned' => TRUE,
				'auto_increment' => TRUE
			),
			'type' => array(
				'type' => 'VARCHAR',
				'constraint' => '16',
			),
			'arguments' => array(
				'type' => 'VARCHAR',
				'constraint' => '1024'
			),
			'create_date' => array(
				'type' => 'TIMESTAMP'
			),
			'try_count' => array(
				'type' => 'INT',
				'constraint' => 8,
				'unsigned' => TRUE
			),
			'status' => array(
				'type' => 'INT',
				'constraint' => 8,
				'unsigned' => TRUE
			),
			'last_try_date' => array(
				'type' => 'TIMESTAMP'
			),
			'last_error' => array(
				'type' => 'TEXT',
			)
		));
		$this->dbforge->add_key('id', TRUE);
		$this->dbforge->create_table('tasks');
	}

	public function down() {
		$this->dbforge->drop_table('tasks');
	}
}
