<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

class Migration_Video extends CI_Migration
{
	public function up()
	{
/*
		$this->dbforge->add_field([
			'id' => [
				'type' => 'INT',
				'unsigned' => TRUE,
				'auto_increment' => TRUE
			],
			'status' => [
				'type' => 'INT',
				'constraint' => '4',
				'unsigned' => TRUE
			],
			'title' => [
				'type' => 'VARCHAR',
				'constraint' => '160',
			],
			'full_path' => [
				'type' => 'VARCHAR',
				'constraint' => '500',
			],
			'profile' => [
				'type' => 'TEXT',
			],
			'hd_types' => [
				'type' => 'VARCHAR',
				'constraint' => '60',
			],
			'create_date' => [
				'type' => 'TIMESTAMP',
			],
			'publish_date' => [
				'type' => 'TIMESTAMP',
			],
		]);
		$this->dbforge->add_key('id', TRUE);
		$this->dbforge->create_table('videos');
*/
	}

	public function down()
	{
/*
		$this->dbforge->drop_table('videos');
 */
	}
}
