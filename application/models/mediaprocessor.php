<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

class MediaProcessor extends CI_Model
{
	const STATUS_UNPROCESSED = 0;
	const STATUS_SUCCESS = 1;
	const STATUS_PROCESSING = 2;
	const STATUS_FAILURE = 3;

	private $ffmpeg;

	public function __construct()
	{
		$ffmpeg = $this->config->item("ffmpeg_bin");
		if (is_executable($ffmpeg)) {
			$this->ffmpeg = $ffmpeg;
		}
		else {
			die("count not found ffmpeg\n");
		}

		parent::__construct();
		$this->load->database();
	}

	public function execute()
	{
		$this->db->where('type', 'SEGMENT');
		$this->db->where('status', self::STATUS_UNPROCESSED);
		$this->db->order_by('try_count, create_date');

		$this->log("finding a task to process");
		$task = $this->db->get('tasks', 1)->row();
		if ($task) {
			$this->log("task id={$task->id} found");
			$this->db->query('update tasks set try_count = 1 + try_count, last_try_date = now(), status = ? where id = ?',
				[ self::STATUS_PROCESSING, $task->id ]);

			$ret = $this->process($task->id, json_decode($task->arguments,true));
			if ($ret) {
				$this->db->query('update tasks set status = ? where id = ?',
					[ self::STATUS_SUCCESS, $task->id ]);
			}
		}
	}

	public function process($id, $args)
	{
		$this->load->model('mediastore');

		if (MediaStore::ORGIN_PKG !== $this->mediastore->isValidVideoPackage($args['full_path'])) {
			return $this->fail($id, "file `{$args['full_path']}` is not a valid video package");
		}
		if (! ($work_dir = $this->config->item('video_frags_path'))) {
			$work_dir = '/tmp';
		}
		if (is_dir($work_dir)) {
			if (! is_writable($work_dir)) {
				return $this->revert($id, "can not extract video file from the package to `{$work_dir}`");
			}
		}
		else {
			if (! @mkdir($work_dir, 0777, true)) {
				return $this->revert($id, "failed to mkdir `{$work_dir}`");
			}
		}

		$meta = $this->mediastore->getVideoMetaFromPackage($args['full_path']);
		$fn = $work_dir .'/'. $meta['video_file'];
		try {
			$phar = new Phar($args['full_path']);
			if (! is_file($fn)) {
				$this->log("extract video file into `{$work_dir}`");
				if (! $phar->extractTo($work_dir, [ $meta['video_file'] ])) {
					throw new Exception("can not extract video file from the package to `{$fn}`");
				}
			}
		}
		catch (Exception $ex) {
			return $this->revert($id, $ex->getMessage());
		}

		$this->log("determine available resolutions");
		$resolutions = $this->determineResolutions($fn);
		if (false === $resolutions) {
			return $this->fail($id, "file `{$fn}` is not a valid video file");
		}

		$option = [
			'playlist' => 'playlist',
			'work_dir' => $work_dir,
			'segtime' => $this->config->item('video_segment_time'),
		];
		if ($option['segtime'] < 2) {
			$option['segtime'] = 2;
		}

		$meta['resolutions'] = [ ];
		$file_list = [ ];
		foreach ($resolutions as $width=>$def) {
			$option['height'] = $def['height'];
			$option['width'] = $def['width'];
			$option['ab'] = $def['ab'];
			$option['vb'] = $def['vb'];

			if ($list = $this->convert($fn, $option)) {
				$meta['resolutions'][] = $width;
				$file_list = array_merge($file_list, $list);
			}
			else {
				return false;
			}
		}

		$this->log("build video package into `{$work_dir}`");
		$pkg = $this->mediastore->buildVideoPackage($file_list, $meta, $work_dir);
		if ($pkg) {
			$this->log("save video package as `{$pkg}`");
			$ret = $this->mediastore->saveVideoPackage([ 'full_path' => $pkg ]);
			if ($ret === 'OK') {
				return true;
			}
			else {
				return $this->fail($id, $ret);
			}
		}

		return $this->fail($id, "build video package fails");
	}

	public function convert($file, $option) 
	{
		$prefix = "{$option['work_dir']}/{$option['width']}";
		if (file_exists($prefix)) {
			$this->helper->rm($prefix);
		}
		mkdir($prefix);
		$playlist = $prefix .'/'. $option['playlist'] .'.m3u8';

		$command = sprintf($this->ffmpeg .' -i "%s" -codec:a aac -strict -2 -codec:v libx264 -b:a %dk -b:v %dk -s %dx%d -f segment -segment_time %d -segment_list %s ',
			$file, $option['ab'], $option['vb'], $option['width'], $option['height'], $option['segtime'], $playlist);

		$command .= " {$prefix}/video_%03d.ts";
		$this->log($command);
		exec($command, $output, $retcode);

		if ($retcode == 0) {
			$list = glob("{$prefix}/*.ts");
			$list[] = $playlist;

			$skip = strlen($option['work_dir']);
			return array_map(function($file) use($skip) {
				return substr($file, $skip);
			}, $list);
		}

		$this->log("video convert fails", E_ERROR);
		return false;
	}


	protected function determineResolutions($fn)
	{
		$resolutions = $this->config->item('resolutions');
		if (! $resolutions) {
			$resolutions = [ '640' => [ 'ab' => '80', 'vb' => '1600' ], ];
		}
		$ret = [ ];

		$info = $this->queryVideoInfo($fn);
		if (isset($info['bitrate']) && isset($info['video']['width'])) {
			$br = + $info['bitrate'];
			$min = 1920;
			foreach ($resolutions as $k => $v) {
				if ($br > $v['vb']) {
					$ret[$k] = $v;
				}
				if ($k < $min) {
					$min = $k;
				}
			}
			$ret = count($ret) ? $ret : [ "{$min}" => $resolutions[$min] ];

			$r = $info['video']['width'] / $info['video']['height'];
			foreach ($ret as $k => &$v) {
				$v['height'] = intval($k / $r);
				if ($v['height'] % 2) {		// asure height divisible by 2
					$v['height'] += 1;
				}
				$v['width'] = $k;
			}
			unset($v);

			return $ret;
		}

		return false;
	}

	public function queryVideoInfo($fn) 
	{
		$output = [ ];
		$command = $this->ffmpeg .' -i "'. $fn .'"';

		$fd = proc_open($command, [ 2 => ['pipe','w'] ], $output);
		$stderr = stream_get_contents($output[2]);
		fclose($output[2]);
		proc_close($fd);

		$lines = trim(strstr($stderr, 'Input #0'));
		$info = [ ];
		if ($lines) foreach (explode("\n",$lines) as $row) {
			if ($row = trim($row)) {
				if (strpos($row, 'Input #0') === 0) {
					list($k, $info['format']) = explode(', ', $row);
				}
				else if (strpos($row, 'Duration') === 0) {
					foreach (explode(', ',$row) as $unit) {
						list($k, $v) = explode(': ', $unit);
						$info[strtolower($k)] = $v;
					}
				}
				else if (strpos($row, 'Stream #0:') === 0) {
					list($k, $type, $row) = explode(': ', $row);
					if ($type === 'Video') {
						$info['video'] = self::extractVideoInfo($row);
					}
					else if ($type === 'Audio') {
						$info['audio'] = self::extractAudioInfo($row);
					}
				}
			}
		}

		return $info;
	}

	protected static function extractVideoInfo($string)
	{
		$stream = [ ];
		list($stream['codec'], $stream['color_range'], $k, $fps, $v) = explode(', ',$string);

		preg_match_all("/(?P<w>\d+)x(?<h>\d+)/", $k, $matches);
		$stream['fps'] = preg_replace("/[^.0-9]/", '', $fps);
		$stream['width'] = $matches['w'][0];
		$stream['height'] = $matches['h'][0];

		return $stream;
	}

	protected static function extractAudioInfo($string)
	{
		$stream = [ ];
		list($stream['codec'], $stream['sample'], $stream['stereo'], $v) = explode(', ',$string);

		return $stream;
	}

	private function log($msg, $level=E_NOTICE)
	{
		static $map;
		if (! isset($map)) {
			$map = [
				E_ERROR => 'ERROR',
				E_NOTICE => 'NOTICE',
				E_WARNING => 'WARNING',
			];
		}
		$now = date('Y-m-d H:i:s');

		printf("[%s] [%s]\t%s\n",
			isset($map[$level]) ? $map[$level] : $level,
			$now,
			$msg);
	}

	private function fail($id, $msg)
	{
		$this->db->query('update tasks set status = ?, last_error = ? where id = ?',
			[ self::STATUS_FAILURE, $msg, $id ]);
		$this->log($msg, E_ERROR);

		return false;
	}

	private function revert($id, $msg)
	{
		$this->db->query('update tasks set status = ?, last_error = ? where id = ?',
			[ self::STATUS_UNPROCESSED, $msg, $id ]);
		$this->log($msg, E_ERROR);

		return false;
	}
}
