<?php

namespace eth_sign;

require('ipfs.class.php');

function makeFolder($foldername) {
	$ipfs = new IPFS();
	return $ipfs->makeFolder($foldername);
}

function deleteFolder($foldername) {
	$ipfs = new IPFS();
	return $ipfs->removeFile($foldername);
}

function saveFile($uploadfile, $folder = '') {
	$ipfs = new IPFS();
	return $ipfs->addFile($uploadfile, $folder);
}

function deleteFile($hash, $folder = '') {
	$ipfs = new IPFS();
	$filedest = ($folder != '' ? $folder . '%2F' : '') . $hash;
	$ipfs->pinRm($filedest);
	return $ipfs->removeFile($hash, $folder);
}

function getFile($hash, $folder = '') {
	$ipfs = new IPFS();
	$filedest = ($folder != '' ? $folder . '%2F' : '') . $hash;
	return $ipfs->cat($filedest);
}
