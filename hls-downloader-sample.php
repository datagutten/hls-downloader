<?php
require 'hls-downloader.class.php';
$hls=new hls_downloader;
$hls->init();
$hls->download(file_get_contents($argv[1]),'test');