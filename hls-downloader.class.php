<?Php
require_once 'tools/dependcheck.php';
require_once 'tools/video_duration_check.php';
class hls_downloader
{
	public $ch;
	public $cookiefile=false;
	public $cookiejar=false;
	public $retry_limit=3;
	public $cli=true;
	public $dependcheck;
	public $duration_check;

	function __construct()
	{
		if(php_sapi_name() != 'cli')
			$this->cli=false;
	}
	public function init()
	{
		$this->ch=curl_init();
		if(!empty($this->cookiefile) && !file_exists($this->cookiefile))
			throw new exception('Cookie file does not exist: '.$this->cookiefile);
		curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($this->ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($this->ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/48.0.2564.109 Safari/537.36');
		if($this->cookiefile!==false)
			curl_setopt($this->ch, CURLOPT_COOKIEFILE, $this->cookiefile);
		if($this->cookiejar!==false)
			curl_setopt($this->ch, CURLOPT_COOKIEJAR, $this->cookiejar);

		$this->dependcheck=new dependcheck; //Class for checking if command line tools is installed
		$this->duration_check=new video_duration_check;
	}
	public function get($url)
	{
		if(empty($this->ch))
			$this->init();
		curl_setopt($this->ch,CURLOPT_URL,$url);
		$result=curl_exec($this->ch);
		$http_status = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
		if($result===false)
		{
			$this->error="cURL returned error: ".curl_error($this->ch);
			return false;
		}
		elseif($http_status!=200)
		{
			$this->error='HTTP error code '.$http_status;
			return false;	
		}
		return $result;
	}

	//Remove characters which is not supported in file names on windows
	public function clean_filename($filename)
	{
		$filename=html_entity_decode($filename);
		$filename=str_replace(array(':','?','*','|','<','>','/','\\','"'),array(' -','','','','','','-','-',''),$filename);
		if(PHP_OS=='WINNT')
			$filename=utf8_decode($filename);
		return $filename;
	}

	//Parse a m3u8 playlist
	public function parse_m3u8($m3u8)
	{
		$streams_lines=explode("\n",trim($m3u8)); //Remove empty line at the end
		if($streams_lines[0]!='#EXTM3U')
			throw new Exception(sprintf("Data does not look like a m3u8 file, first line is %s\n",$streams_lines[0]));
		else
			unset($streams_lines[0]); //Remove header line

		foreach($streams_lines as $key=>$line)
		{
			if($line[0]!='#')
				continue;
			$line=preg_replace('/(".+?),(.+?")/','$1 $2',$line).','; //Replace comma between quotes and add comma at the end to make next regex work
			preg_match_all('/([A-Z\-]+)=(.+?),/',$line,$properties);
		
			$streams[]=array_merge(array('url'=>$streams_lines[$key+1]),array_combine($properties[1],$properties[2]));
		}
		return $streams;
	}

	//Find the stream with the highest bandwidth
	public function find_best_stream($streams)
	{
		$streams=array_filter($streams,function ($var) { return isset($var['BANDWIDTH']); }); //Remove items without bandwidth
		$streams=array_values($streams); //Reset keys
		$bandwidths=array_column($streams,'BANDWIDTH');
		arsort($bandwidths);
		$keys=array_keys($bandwidths);
		return $streams[$keys[0]];
	}

	//Parse m3u8, find best stream and extract the segments
	public function segments($m3u8)
	{
		if(substr($m3u8,0,4)=='http')
			$m3u8=$this->get($m3u8);
		$streams=$this->parse_m3u8($m3u8);
		$stream=$this->find_best_stream($streams);
		curl_setopt($this->ch, CURLOPT_HEADER,true); //Output header together with data
		$data=$this->get($stream['url']);
		curl_setopt($this->ch, CURLOPT_HEADER,false); //Turn off header for next request
		//Separate header and data
		$header=substr($data,0,strpos($data,"\r\n\r\n")); //Header is before a doble CRLF
		$header=str_replace("\r",'',$header); //Remove CR from header
		$segmentlist=trim(substr($data,strpos($data,"\r\n\r\n"))); //Data starts after double CRLF

		//Check if segment urls are relative and get complete url from set-cookie header
		if(strpos($segmentlist,"\nsegment")!==false && preg_match('/Set\-Cookie:.+path=(.+?); domain=(.+)/',$header,$cookies))
			$segmentlist=str_replace("\nsegment",sprintf("\nhttp://%s%ssegment",$cookies[2],$cookies[1]),$segmentlist);

		if($segmentlist===false) //cURL returned error
			return false;
		if(!preg_match_all('^.+segment.+^',$segmentlist,$segments))
		{
			$this->error=_('No segments found');
			return false;
		}
		return $segments[0];
	}
	//Download segments to a ts file
	public function downloadts($segments,$file)
	{
		$count=count($segments);
		if(file_exists($file.'.tmp'))
			unlink($file.'.tmp');
		
		$fp=fopen($file.'.tmp','x'); //Open file for writing
		if($fp===false)
		{
			$this->error=sprintf('Unable to open %s for writing',$file);
			return false;
		}
		if(empty($segments))
		{
			$this->error=_('No segments found');
			return false;
		}

		foreach($segments as $key=>$segment)
		{
			$num=$key+1;
			if(php_sapi_name() == 'cli')
				echo sprintf("\rDownloading segment %d of %d to %s   ",$num,$count,$file);

			curl_setopt($this->ch, CURLOPT_URL,$segment);

			for($tries=0; $tries<$this->retry_limit; $tries++)
			{
				$data=curl_exec($this->ch);

				if(strlen($data)==curl_getinfo($this->ch,CURLINFO_CONTENT_LENGTH_DOWNLOAD))
					break;
				else
					echo sprintf("\rError downloading segment %d. Retry %d",$num,$tries);
			}
			if($tries==3)
			{
				echo sprintf("\rDownload failed after %d retries          ",$tries);
				return false;
			}

			fwrite($fp,$data);
		}
		echo "\n";
		fclose($fp); //Close the file
		rename($file.'.tmp',$file=$file.'.ts'); //Rename the temporary file to the correct extension
		return $file; //Return the file name with extension
	}

	/*Mux the ts to mkv using mkvmerge
	First argument can be a string with a single file name or an array with multiple files to be merged into one mkv
	When the first argument is array the mkv file name need to be specified as the second argument
	*/
	public function mkvmerge($filename,$mkvfile=false)
	{
		if($this->dependcheck->depend('mkvmerge')!==true)
		{
			$this->error='mkvmerge was not found, unable to create mkv';
			return false;
		}
		if(is_array($filename))
		{
			if($mkvfile===false)
			{
				$this->error='MKV file name need to be specified when using multiple input files';
				return false;
			}

			foreach($filename as $file)
			{
				$file=sprintf('"%s.ts"',$file); //Add double quotes and extension to the file name
				if(!isset($files))
					$files=$file;
				else
					$files.=' + '.$file;
			}
			$cmd=sprintf('mkvmerge -o "%s.mkv" %s',$mkvfile,$files);
		}
		else
		{
			$cmd=sprintf('mkvmerge -o "%1$s.mkv" "%1$s.ts"',$filename);
			$mkvfile=$filename;
		}

		if(file_exists($mkvfile.'.mkv'))
		{
			$this->error=sprintf('%s.mkv exists',$mkvfile);
			return false;
		}
		echo "Creating mkv\n";
		if(file_exists($mkvfile.'.chapters.txt'))
			$cmd.=sprintf(' --chapter-charset UTF-8 --chapters "%s.chapters.txt"',$mkvfile);
		$shellreturn=shell_exec($cmd." 2>&1");
		if($this->cli)
			echo $shellreturn;
		else
			echo nl2br($shellreturn);
	}
	
	//Download a stream and mux to mkv
	public function download($m3u8_or_segments,$filename,$expected_duration=false)
	{
		$file_mkv=$filename.'.mkv';
		$file_ts=$filename.'.ts';

		//Check if the video is already downloaded
		if($expected_duration!==false && $this->duration_check->check_file_duration($file_mkv,$expected_duration)===true)
			return $file_mkv;
		if(file_exists($file_mkv)) //Additional check in case the file could not be checked by duration
			return $file_mkv;
		if(is_array($m3u8_or_segments))
			$segments=$m3u8_or_segments;
		else
			$segments=$this->segments($m3u8_or_segments); //Get segments of best stream
		if($segments===false)
			return false;

		if(!file_exists($file_ts))
			$this->downloadts($segments,$filename);
		//Check if the file was successfully downloaded
		if($expected_duration!==false)
		{
			if($this->duration_check->check_file_duration($file_ts,$expected_duration)===false)
			{
				$this->error=$this->duration_check->error;
				return false;
			}
		}
		if(!file_exists($file_mkv) && $this->mkvmerge($filename)===false)
				return false;

		$duration_ts=$this->duration_check->duration($file_ts);
		$duration_check_result=$this->duration_check->check_file_duration($file_mkv,$duration_ts);
		if($duration_check_result===false) //Check if the file was successfully muxed
		{
			$this->error='muxing to mkv failed';
			return false;
		}
		if($duration_check_result===true) //Delete ts file if the muxed duration was successfully verified
			unlink($file_ts);
		return $filename.'.mkv'; //Return the muxed file name
	}
}