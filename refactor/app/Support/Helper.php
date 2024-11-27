<?php

use Monolog\Logger;
use DTApi\Helpers\TeHelper;
use DTApi\Helpers\DateTimeHelper;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\FirePHPHandler;

if(!function_exists('initializeLogger')) {
	function initializeLogger($name, $storagePath)
	{
		$logger = new Logger($name);
		
		//Configure handlers
		$logPath = storage_path($storagePath);
		$logger->pushHandler(new StreamHandler($logPath, Logger::DEBUG));
		$logger->pushHandler(new FirePHPHandler());
		
		return $logger;
	}
}

if(!function_exists('convertToHoursMins')) {
	function convertToHoursMins($time, $format = '%02dh %02dmin')
	{
		if($time < 60) {
			return $time . 'min';
		}
		else if ($time == 60) {
			return '1h';
		}
		
		$hours = floor($time / 60);
		$minutes = ($time % 60);
		
		return sprintf($format, $hours, $minutes);
	}
}

if(!function_exists('convertToHoursMins')) {
	function isNeedToDelayPush($userId)
	{
		if(!DateTimeHelper::isNightTime()) return false;
		
		$notGetNightTime = TeHelper::getUsermeta($userId, 'not_get_nighttime');
		if($notGetNightTime == 'yes') return true;
		
		return false;
	}
}

if(!function_exists('isNeedToSendPush')) {
	function isNeedToSendPush($userId)
	{
		$notGetNotification = TeHelper::getUsermeta($userId, 'not_get_notification');
		if($notGetNotification == 'yes') return false;
		
		return true;
	}
}

if(!function_exists('getUserTagsStringFromArray')) {
	function getUserTagsStringFromArray($users)
	{
		$first = true;
		$userTags = "[";
		
		foreach($users as $user){
			if($first) {
				$first = false;
			}
			else {
				$userTags .= ',{"operator": "OR"},';
			}
			$userTags .= '{"key": "email", "relation": "=", "value": "' . strtolower($user->email) . '"}';
		}
		
		$userTags .= ']';
		return $userTags;
	}
}