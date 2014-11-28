<?php

define('APP_NAME', 'naka01');
define('DS', DIRECTORY_SEPARATOR);
define('APP_PATH', __DIR__.DS);
define('CONFIG_PATH', APP_PATH.'config.php');

// Dropbox SDK
require_once(APP_PATH.'dropbox-sdk/lib/Dropbox/autoload.php');

// config
if (is_file(CONFIG_PATH))
{
	main();
}
else
{
	auth();
}

function main()
{
	require_once(CONFIG_PATH);

	( ! isset($config['dropbox_path']) || $config['dropbox_path']==='') AND exit(1);
	( ! isset($config['accessToken']) || $config['accessToken']==='') AND exit(1);

	( ! isset($config['timezone']) || $config['timezone']==='') AND $config['timezone'] = date_default_timezone_get();
	( ! isset($config['upload_path']) || $config['upload_path']==='') AND $config['upload_path'] = APP_PATH.'upload';

	$config['dropbox_path'] = preg_replace('/\/$/', '', $config['dropbox_path']);

	try
	{
		$dbxClient = new \Dropbox\Client($config['accessToken'], APP_NAME);

		$searchFileNames = $dbxClient->searchFileNames($config['dropbox_path'], '.');
	
		if ( ! empty($searchFileNames))
		{
			$timezone = new \DateTimeZone($config['timezone']);
	
			$update_files  = array();
			$dropbox_files = array();
			$removes = array(
				'files' => array(),
				'dirs'  => array(),
			);
	
			$dropbox_path = preg_quote($config['dropbox_path'], '/');

			foreach ($searchFileNames as $v)
			{
				$filename = preg_replace("/^{$dropbox_path}/", $config['upload_path'], $v['path']);
				$filename = preg_replace('/\/\//', '/', $filename);
	
				$dropbox_files[] = $filename;
	
				if (is_file($filename))
				{
					$stat = stat($filename);
	
					if ($stat!==false)
					{
						$date_local  = new \DateTime(date('Y-m-d H:i:s', $stat[9]));
						$date_server = new \DateTime($v['modified']);
	
						// DropBoxはUTC
						$date_server->setTimezone($timezone);
	
						if ($date_server>$date_local)
						{
							$update_files[] = array(
								'from' => $v['path'],
								'to'   => $filename,
							);
						}
					}
				}
				else
				{
					$update_files[] = array(
						'from' => $v['path'],
						'to'   => $filename,
					);
				}
			}

			$umask = umask(000);

			foreach ($update_files as $v)
			{
				$from = $v['from'];
				$to   = $v['to'];
	
				$dirname = dirname($to);
	
				if ( ! is_dir($dirname))
				{
					mkdir($dirname, 0777, true);
				}
	
				$fd = fopen($to, 'wb');
	
				if ($fd)
				{
					$dbxClient->getFile($from, $fd);
					fclose($fd);
				}

				usleep(1);
			}
	
			$fileItelator = new \RecursiveIteratorIterator(
						new \RecursiveDirectoryIterator(
							$config['upload_path'],
							\FilesystemIterator::CURRENT_AS_FILEINFO | \FilesystemIterator::KEY_AS_PATHNAME | \FilesystemIterator::SKIP_DOTS
						),
						\RecursiveIteratorIterator::SELF_FIRST
			);
	
			foreach($fileItelator as $path=>$info)
			{
				$path = preg_replace('/\/\//', '/', $path);
	
				if ($info->isFile())
				{
					// if (preg_match('/.+\.txt$/i', $path))
					// {
						$removes['files'][] = $path;
					// }
				}
				else
				{
					$dir = new \FilesystemIterator($path);
			
					if ( ! $dir->valid())
					{
						$removes['dirs'][] = $path;
					}
				}
			}
	
			foreach (array_diff($removes['files'], $dropbox_files) as $v)
			{
				unlink($v);
				usleep(1);
			}
	
			foreach ($removes['dirs'] as $v)
			{
				rmdir($v);
				usleep(1);
			}
	
			umask($umask);
		}
	}
	catch (\Exception $e)
	{
		fwrite(STDERR, $e->getMessage());
	}
}

function auth()
{
	MSG('No Settings Data. Create Config and OAuth Dropbox');

	MSG_LINE('INPUT Dropbox key: ');
	$key = trim(fgets(STDIN));

	MSG_LINE('INPUT Dropbox secret: ');
	$secret = trim(fgets(STDIN));

	$config = array(
		'dropbox_path' => '/htdocs',
		'upload_path'  => APP_PATH.'htdocs',
	);

	try
	{

		$appInfo = \Dropbox\AppInfo::loadFromJson(array('key'=>$key,'secret'=>$secret));

		$webAuth = new \Dropbox\WebAuthNoRedirect($appInfo, APP_NAME);

		$authorizeUrl = $webAuth->start();

		MSG("Click and Allow {$authorizeUrl}");

		MSG_LINE('INPUT Dropbox authorization code: ');
		$authCode = trim(fgets(STDIN));

		list($config['accessToken']) = $webAuth->finish($authCode);

		$umask = umask(000);

		if (file_put_contents(CONFIG_PATH, CNF($config)))
		{
			MSG('save config: '.CONFIG_PATH);
		}

		umask($umask);
	}
	catch (\Exception $e)
	{
		fwrite(STDERR, $e->getMessage());
	}
}
function MSG($msg='', $LF=PHP_EOL){fwrite(STDOUT, $msg.$LF);}
function MSG_LINE($msg=''){MSG($msg, '');}
function CNF($data){return "<?php\n\n\$config = ".str_replace('  ', "\t", var_export($data, true)).";\n";}
