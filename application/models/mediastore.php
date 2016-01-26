<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

class MediaStore extends CI_Model
{
	const ORGIN_PKG = 1;		// 上传的源视频及其 meta 信息
	const FINAL_PKG = 2;		// 最终可供播放的 phar

	public function __construct()
	{
		parent::__construct();
		$this->load->database();
		$this->load->library('yaml');
		$this->load->library('helper');
	}

	public function store($fieldname, $data=null)
	{
		if (! ($save_path = $this->config->item('video_upload_path'))) {
			$save_path = '/tmp';
		}
		if (is_dir($save_path)) {
			if (! is_writable($save_path)) {
				return "can not save uploaded files into `{$save_path}`";
			}
		}
		else {
			if (! @mkdir($save_path, 0777, true)) {
				return "failed to mkdir `{$save_path}`";
			}
		}

		if (! ($types = $this->config->item('allowed_video_types'))) {
			$types = '*';
		}
		$this->load->library('upload', [
			'upload_path' => $save_path,
			'allowed_types' => $types,
		]);
		if (! $this->upload->do_upload($fieldname)) {
			return $this->upload->display_errors();
		}

		$finfo = $this->upload->data();
		$args = array_merge($data, [
			'full_path' => $finfo['full_path'],
		]);

		if (false === $this->isValidVideoPackage($finfo['full_path'])) {
			$ret = $this->buildOrginalPackage($args);
			if ('OK' !== $ret) {
				return $ret;
			}
		}

		return $this->saveVideoPackage($args);
	}


	public function generateFilename($ext = '.phar')
	{
		return $this->helper->hash() .'_'. $this->helper->guid() .$ext;
	}


	public function isValidVideoPackage($fn)
	{
		if (! Phar::isValidPharFilename($fn)) {
			return false;
		}

		$meta = $this->getVideoMetaFromPackage($fn);
		if ($meta && isset($meta['title'])) {
			if (isset($meta['resolutions']) && is_array($meta['resolutions']) && count($meta['resolutions'])) {
				return self::FINAL_PKG;
			}
			else if (isset($meta['video_file']) && ! empty($meta['video_file'])) {
				return self::ORGIN_PKG;
			}
		}
		return false;
	}

	public function getVideoMetaFromPackage($fn)
	{
		static $meta = [ ];
		if (is_file($fn) && ! isset($meta[$fn=realpath($fn)])) {
			$text = file_get_contents("phar://{$fn}/video.yaml");
			$meta[$fn] = $this->yaml->parse($text);
		}

		return isset($meta[$fn]) ? $meta[$fn] : null;
	}

	/**
	 * 生成最终的视频包
	 */
	public function buildVideoPackage($file_list, $args, $prefix)
	{
		$flags = FilesystemIterator::CURRENT_AS_FILEINFO | FilesystemIterator::KEY_AS_FILENAME;

		$from_phar = $args['full_path'];
		$dest_phar = $this->helper->tmpFilename($prefix, '.phar');
		$meta = [
			'title' => $args['title'],
			'resolutions' => $args['resolutions'],
		];
		try {
			$phar = new Phar($dest_phar, $flags);
			foreach ($file_list as $fn) {
				$phar->addFile("{$prefix}/{$fn}", $fn);
			}
			// todo, copy other files from $from_phar
			//
			$phar['video.yaml'] = $this->yaml->dump($meta);
		} catch (Exception $ex) {
			return false;
		}

		return $dest_phar;

	}

	/**
	 * 打包上传的视频及相关信息
	 */
	protected function buildOrginalPackage(&$args)
	{
		$flags = FilesystemIterator::CURRENT_AS_FILEINFO | FilesystemIterator::KEY_AS_FILENAME;
		$pictures = 'png|jpg|jpeg|jpe|gif';

		$fn = $args['full_path'];
		$dest_phar = $this->helper->tmpFilename(dirname($fn), '.phar');

		if ($dest_phar) {
			$this->upload->set_allowed_types($pictures);

			$meta = $args;
			$meta['video_file'] = basename($fn);
			$meta['full_path'] = $dest_phar;

			try {
				$phar = new Phar($dest_phar, $flags);

				// todo, add posters
				$meta['posters'] = [ ];
				// todo, add screenshots
				$meta['screenshots'] = [ ];
				// add video file
				$phar->addFile($fn, $meta['video_file']);

				// update meta index
				$phar['video.yaml'] = $this->yaml->dump($meta);
				$phar = null;

				// return dest_phar
				$args['full_path'] = $dest_phar;
				return 'OK';
			} catch (Exception $ex) {
				// rollback 
				$args['full_path'] = $fn;
				return $ex->getMessage();
			}
		}

		return 'package upload files error';
	}

	protected function enqueueTask($args)
	{
		$data = [
			'id' => 0,
			'type' => 'SEGMENT',
			'status' => 0,
			'arguments' => json_encode($args),
			'try_count' => 0,
			'last_try_date' => '',
		];

		if (! $this->db->insert('tasks', $data)) {
			return 'enqueue error #1';
		}

		return "OK";
	}

	public function saveVideoPackage($args)
	{
		$ret = $this->isValidVideoPackage($args['full_path']);
		if ($ret === false) {
			return 'invalid video package';
		}

		$meta = $this->getVideoMetaFromPackage($args['full_path']);
		if ($ret === self::ORGIN_PKG) {
			return $this->enqueueTask(array_merge($meta,$args));
		}

		if (! ($dest_path = $this->config->item('video_store_path'))) {
			$dest_path = '/tmp';
		}
		if (is_dir($dest_path)) {
			if (! is_writable($dest_path)) {
				return "destination `{$dest_path}` not writable";
			}
		}
		else {
			if (! @mkdir($dest_path, 0777, true)) {
				return "failed to mkdir `{$dest_path}`";
			}
		}

		$phar_path = realpath($dest_path) .'/'. $this->generateFilename();
		if (! rename($args['full_path'], $phar_path)) {
			return "move video package file to `{$phar_path}` failed";
		}

		$this->load->library('KeyValueStore');
		if (! $this->keyvaluestore->get('G_VIDEO_COUNTER')) {
			$this->keyvaluestore->set('G_VIDEO_COUNTER', 0);
		}
		$this->keyvaluestore->incr('G_VIDEO_COUNTER');
		$id = $this->keyvaluestore->get('G_VIDEO_COUNTER');

		// save video package info into memcache
		if ($this->keyvaluestore->set($id, $phar_path)) {
			$this->pushVideoPackage($id, $phar_path);
		}
		else {
			return "can not push video info onto memcahe";
		}

		return 'OK';
	}

	/**
	 * 通知其他系统视频已就绪
	 */
	protected function pushVideoPackage($id, $info)
	{
		// todo
		echo "\n--> ";
		var_dump($id, $info);
	}
}
