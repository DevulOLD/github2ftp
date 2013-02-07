<?php

// GitHub to FTP deployment script

// Config

$config = array(
	'username/repository' => array(
		'github_username' => '',
		'github_password' => '',
		'ftp_host' => '',
		'ftp_username' => '',
		'ftp_password' => '',
		'ftp_dir' => ''
	)
);

// End Config

// Util functions from http://www.stephenradford.me/blog/tutorials/deploy-via-bitbucket-or-github-service-hooks and php.net (unknown and http://www.php.net/manual/en/function.ip2long.php#92544)

function ftp_mk_dir($ftp_stream, $dir)
{
	if(ftp_is_dir($ftp_stream, $dir) || @ftp_mkdir($ftp_stream, $dir))
	{
		return true;
	}
	
	if(!ftp_mk_dir($ftp_stream, dirname($dir)))
	{
		return false;
	}
	
	return ftp_mkdir($ftp_stream, $dir);
}

function ftp_is_dir($ftp_stream, $dir)
{
	$original_directory = ftp_pwd($ftp_stream);
	
	if(@ftp_chdir($ftp_stream, $dir))
	{
		ftp_chdir($ftp_stream, $original_directory);
		return true;
	}
	else
	{
		return false;
	}
}

function rmdir_tree($dir)
{
	$files = array_diff(scandir($dir), array('.', '..'));
	
	foreach($files as $file)
	{
		(is_dir("$dir/$file")) ? rmdir_tree("$dir/$file") : unlink("$dir/$file");
	}
	
	return rmdir($dir);
}

function ip_in_network($ip, $net_addr, $net_mask)
{
	if($net_mask <= 0)
	{
		return false;
	}
	
	$ip_binary_string = sprintf("%032b", ip2long($ip));
	$net_binary_string = sprintf("%032b", ip2long($net_addr));
	
	return substr_compare($ip_binary_string, $net_binary_string, 0, $net_mask) === 0;
} 

// Let's go!

// Get list of webhook ip addresses from GitHub api

$ch = curl_init('https://api.github.com/meta');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); 
$return = curl_exec($ch);

curl_close($ch);

if(!$return || strlen($return) < 1)
{
	die('Couldn\'t get ip addresses from GitHub!');
}

$github_addresses = json_decode($return);

if(!$github_address->hooks)
{
	die('GitHub api request failed!');
}

// Loop through the addresses and make sure the request is from inside one of the ranges

$found_match = false;

foreach($github_address->hooks as $hook)
{
	list($net_addr, $net_mask) = explode('/', $hook);
	
	if(ip_in_network($_SERVER['REMOTE_ADDR'], $net_addr, $net_mask))
	{
		$found_match = true;
		break;
	}
}

if(!$found)
{
	die('Not from GitHub!');
}

// Check there is a payload

if(!isset($_POST['payload']))
{
	die('No Payload!');
}

$payload = $_POST['payload'];
$commit = json_decode($payload);

$repository = $commit->repository;

// Check we have a config for this repo

if(!isset($config[$repository->owner->name . '/' . $repository->name]))
{
	die('No config for this repository.');
}

$repo = $repository->owner->name . '/' . $repository->name;
$extracted_repo = $repository->name . '-master';

$repo_config = $config[$repo];

// Grab the file from GitHub as an archive

$ch = curl_init('https://github.com/' . $repo . '/archive/master.zip');
curl_setopt($ch, CURLOPT_USERPWD, $repo_config['github_username'] . ':' . $repo_config['github_password']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); 
$return = curl_exec($ch);

curl_close($ch);

// Write it to disk

file_put_contents('temp.zip', $return);

$zip = new ZipArchive();

if($zip->open('temp.zip'))
{
	if(!$zip->extractTo('./temp'))
	{
		die('Couldnt extract zip!');
	}
}
else
{
	die('Couldnt open zip!');
}

// Delete the zip

$zip->close();
unlink('temp.zip');

// Connect to FTP

$conn_id = ftp_connect($repo_config['ftp_host']);
$login_result = ftp_login($conn_id, $repo_config['ftp_username'], $repo_config['ftp_password']);

if(!$login_result)
{
	die('Couldnt connect to ftp!');
}

$zip_dir = './temp/' . $extracted_repo;

// Loop all files and folders

$files = '';

foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($zip_dir, FilesystemIterator::SKIP_DOTS)) as $filename => $item)
{
	$filename = str_replace($zip_dir, '', $filename);
	
	// Delete existing file if it exists
	
	@ftp_delete($conn_id, $filename);
	
	$dirname = dirname($filename);
	
	$chdir = @ftp_chdir($conn_id, $repo_config['ftp_dir'] . $dirname);
	
	if(!$chdir)
	{
		if(!ftp_mk_dir($conn_id, $repo_config['ftp_dir'] . $dirname))
		{
			echo 'Failed to make ' . $repo_config['ftp_dir'] . $dirname . '<br />';
		}
	}
	
	// Write the new file
	
	$temp = tmpfile();
	fwrite($temp, file_get_contents($zip_dir . $filename));
	fseek($temp, 0);
	
	ftp_fput($conn_id, $repo_config['ftp_dir'] . $filename, $temp, FTP_BINARY);
	
	$files .= $filename . "\n";
}

// Delete the unzipped archive

rmdir_tree($zip_dir);

?>