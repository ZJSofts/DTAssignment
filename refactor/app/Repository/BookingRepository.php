<?php

namespace DTApi\Repository;

use Event;
use Exception;
use Carbon\Carbon;
use Monolog\Logger;
use DTApi\Models\Job;
use DTApi\Models\User;
use DTApi\Enums\UserRole;
use DTApi\Enums\JobStatus;
use DTApi\Models\Language;
use DTApi\Models\UserMeta;
use DTApi\Helpers\TeHelper;
use Illuminate\Http\Request;
use DTApi\Models\Translator;
use DTApi\Mailers\AppMailer;
use DTApi\Events\SessionEnded;
use DTApi\Models\UserLanguages;
use DTApi\Events\JobWasCreated;
use DTApi\Services\CURLService;
use DTApi\Events\JobWasCanceled;
use DTApi\Models\UsersBlacklist;
use DTApi\Helpers\SendSMSHelper;
use DTApi\Helpers\DateTimeHelper;
use DTApi\Mailers\MailerInterface;
use Illuminate\Support\Facades\DB;
use Monolog\Handler\StreamHandler;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\FirePHPHandler;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;

/**
 * Class BookingRepository
 * @package DTApi\Repository
 */
class BookingRepository extends BaseRepository
{

    protected $model;
    protected $mailer;
    protected $logger;
	protected $curlService;

    /**
     * @param Job $model
     */
    function __construct(Job $model, MailerInterface $mailer, CURLService $curlService)
    {
        parent::__construct($model);
        $this->mailer = $mailer;
		$this->curlService = $curlService;
        $this->logger = initializeLogger('admin_logger', 'logs/admin/laravel-' . date('Y-m-d') . '.log');
    }

    /**
     * @param $userId
     * @return array
     */
    public function getUsersJobs($userId)
    {
		try {
			$userType = '';
			$jobs = array();
			$normalJobs = array();
			$emergencyJobs = array();
			$user = User::find($userId);
			$jobStatuses = [JobStatus::PENDING->value, JobStatus::ASSIGNED->value, JobStatus::STARTED->value];
			
			if($user) {
				//Check if user is a customer
				if($user->is(UserRole::CUSTOMER->value)) {
					$jobs = $user->jobs()->with(['user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback'])->whereIn('status', $jobStatuses)->orderBy('due', 'asc')->get();
					$userType = UserRole::CUSTOMER->value;
				}
				//Check if user is a translator
				else if($user->is(UserRole::TRANSLATOR->value)) {
					$jobs = Job::getTranslatorJobs($user->id, 'new')->pluck('jobs')->all();
					$userType = UserRole::TRANSLATOR->value;
				}
			}
			
			if($jobs) {
				foreach($jobs as $jobItem) {
					if($jobItem->immediate == 'yes') {
						$emergencyJobs[] = $jobItem;
					}
					else {
						$normalJobs[] = $jobItem;
					}
				}
				$normalJobs = collect($normalJobs)->each(function($item, $key) use($userId) {
					$item['usercheck'] = Job::checkParticularJob($userId, $item);
				})->sortBy('due')->all();
			}
			
			return ['emergencyJobs' => $emergencyJobs, 'normalJobs' => $normalJobs, 'user' => $user, 'usertype' => $userType];
		}
		catch(Exception $e) {
			info("Exception occurred: ", [$e->getMessage()]);
			return [];
		}
    }

    /**
     * @param $userId
     * @return array
     */
    public function getUsersJobsHistory($userId, Request $request)
    {
	    try {
		    $numPages = 0;
		    $userType = '';
		    $noOfRecords = 15;
		    $normalJobs = array();
		    $page = $request->get('page');
		    $pageNumber = $page ?? "1";
		    $user = User::find($userId);
		    $jobStatuses = [JobStatus::COMPLETED->value, JobStatus::WITHDRAW_BEFORE_24->value, JobStatus::WITHDRAW_AFTER_24->value, JobStatus::TIMED_OUT->value];
		    
		    if($user) {
			    //Check if user is a customer
			    if($user->is(UserRole::CUSTOMER->value)) {
				    $jobs = $user->jobs()->with(['user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback', 'distance'])->whereIn('status', $jobStatuses)->orderBy('due', 'desc')->paginate($noOfRecords);
				    $userType = UserRole::CUSTOMER->value;
			    }
			    //Check if user is a translator
			    else if($user->is(UserRole::TRANSLATOR->value)) {
				    $jobsIds = Job::getTranslatorJobsHistoric($user->id, 'historic', $pageNumber);
				    $totalJobs = $jobsIds->total();
				    $numPages = ceil($totalJobs / 15);
				    $userType = UserRole::TRANSLATOR->value;
				    $jobs = $normalJobs = $jobsIds;
			    }
		    }
		    
		    return ['emergencyJobs' => [], 'normalJobs' => $normalJobs, 'jobs' => $jobs, 'user' => $user, 'userType' => $userType, 'numPages' => $numPages, 'pageNumber' => ($userType === UserRole::TRANSLATOR->value ? $pageNumber : 0)];
	    }
	    catch(Exception $e) {
		    info("Exception occurred: ", [$e->getMessage()]);
		    return [];
	    }
    }

    /**
     * @param $user
     * @param $data
     * @return mixed
     */
    public function store($user, $data)
    {
        try {
	        $immediateTime = 5;
	        $consumerType = $user->userMeta->consumer_type;
	        $roles = Config::get('constants.roles');
	        
	        if($user->user_type == $roles['CUSTOMER_ID']) {
		        //Comment here
		        $fields = array('from_language_id', 'due_date', 'due_time', 'customer_phone_type', 'duration');
		        
		        foreach($fields as $field) {
			        if((in_array($field, ['from_language_id']) && !isset($data[$field])) ||
				        ($data['immediate'] == 'no' && in_array($field, ['due_date', 'due_time', 'duration']) && isset($data[$field]) && $data[$field] == '') ||
				        ($data['immediate'] != 'no' && in_array($field, ['duration']) && isset($data[$field]) && $data[$field] == '')){
				        return [
					        'status'        => 'fail',
					        'message'       => 'Du måste fylla in alla fält',
					        'field_name'    => $field
				        ];
			        }
			        else if($data['immediate'] == 'no' && in_array($field, ['customer_phone_type']) && !isset($data[$field]) && !isset($data['customer_physical_type'])) {
				        return [
					        'status'        => 'fail',
					        'message'       => 'Du måste göra ett val här',
					        'field_name'    => $field
				        ];
			        }
		        }
		        
		        $data['customer_phone_type'] = isset($data['customer_phone_type']) ? 'yes' : 'no';
		        $data['customer_physical_type'] = $response['customer_physical_type'] = isset($data['customer_physical_type']) ? 'yes' : 'no';
		        $data['gender'] = in_array('male', $data['job_for']) ? 'male' : (in_array('female', $data['job_for']) ? 'female' : null);
		        
		        //Comment here
		        if($data['immediate'] == 'yes') {
			        $dueCarbon = Carbon::now()->addMinute($immediateTime);
			        $data['due'] = $dueCarbon->format('Y-m-d H:i:s');
			        $data['immediate'] = 'yes';
			        $data['customer_phone_type'] = 'yes';
			        $response['type'] = 'immediate';
		        }
		        else {
			        $due = $data['due_date'] . " " . $data['due_time'];
			        $response['type'] = 'regular';
			        $dueCarbon = Carbon::createFromFormat('m/d/Y H:i', $due);
			        $data['due'] = $dueCarbon->format('Y-m-d H:i:s');
			        if($dueCarbon->isPast()) {
				        $response['status'] = 'fail';
				        $response['message'] = "Can't create booking in past";
				        return $response;
			        }
		        }
		        
		        //Comment here
		        $certifiedMapping = [
			        'normal' => 'normal',
			        'certified' => 'yes',
			        'certified_in_law' => 'law',
			        'certified_in_health' => 'health'
		        ];
		        
		        foreach($certifiedMapping as $key => $value) {
			        if(in_array($key, $data['job_for'])) {
				        $data['certified'] = $value;
				        break;
			        }
		        }
		        
		        //Comment here
		        if(in_array('normal', $data['job_for'])) {
			        if(in_array('certified', $data['job_for'])) {
				        $data['certified'] = 'both';
			        }
			        else if(in_array('certified_in_law', $data['job_for'])) {
				        $data['certified'] = 'n_law';
			        }
			        else if(in_array('certified_in_helth', $data['job_for'])) {
				        $data['certified'] = 'n_health';
			        }
		        }
		        
		        $data['b_created_at'] = date('Y-m-d H:i:s');
		        $data['by_admin'] = isset($data['by_admin']) ? $data['by_admin'] : 'no';
		        $data['will_expire_at'] = isset($due) ? TeHelper::willExpireAt($due, $data['b_created_at']) : null;
		        $data['job_type'] = ($consumerType == 'rwsconsumer' ? 'rws' : ($consumerType == 'ngo' ? 'unpaid' : ($consumerType == 'paid' ? 'paid' : null)));
		        
		        //Create job
		        $job = $user->jobs()->create($data);
		        
		        $response['status'] = 'success';
		        $response['id'] = $job->id;
		        
		        $data['job_for'] = array();
		        //Add gender to job_for
		        if($job->gender != null) {
			        if($job->gender == 'male') {
				        $data['job_for'][] = 'Man';
			        }
			        else if($job->gender == 'female') {
				        $data['job_for'][] = 'Kvinna';
			        }
		        }
		        //Add certification to job_for
		        if($job->certified != null) {
			        if($job->certified == 'both') {
				        $data['job_for'][] = 'normal';
				        $data['job_for'][] = 'certified';
			        }
			        else if($job->certified == 'yes') {
				        $data['job_for'][] = 'certified';
			        }
			        else {
				        $data['job_for'][] = $job->certified;
			        }
		        }
		        
		        $data['customer_town'] = $user->userMeta->city;
		        $data['customer_type'] = $user->userMeta->customer_type;
	        }
	        else {
		        $response['status'] = 'fail';
		        $response['message'] = "Translator can not create booking";
	        }
	        
	        return $response;
        }
        catch(Exception $e) {
	        info("Exception occurred: ", [$e->getMessage()]);
	        return [];
        }
    }

    /**
     * @param $data
     * @return mixed
     */
    public function storeJobEmail($data)
    {
		try {
			$userType = $data['user_type'];
			$job = Job::findOrFail(@$data['user_email_job_id']);
			$job->user_email = @$data['user_email'];
			$job->reference = isset($data['reference']) ? $data['reference'] : '';
			$user = $job->user()->first();
			
			if(isset($data['address'])) {
				$job->address = ($data['address'] != '') ? $data['address'] : $user->userMeta->address;
				$job->instructions = ($data['instructions'] != '') ? $data['instructions'] : $user->userMeta->instructions;
				$job->town = ($data['town'] != '') ? $data['town'] : $user->userMeta->city;
			}
			//Save job
			$job->save();
			
			$name = $user->name;
			$email = !empty($job->user_email) ? $job->user_email : $user->email;
			
			$subject = 'Vi har mottagit er tolkbokning. Bokningsnr: #' . $job->id;
			$send_data = [
				'user' => $user,
				'job'  => $job
			];
			
			$this->mailer->send($email, $name, $subject, 'emails.job-created', $send_data);
			
			$data = $this->jobToData($job);
			//Dispatch JobWasCreated event
			event(new JobWasCreated($job, $data, '*'));
			
			$response['type'] = $userType;
			$response['job'] = $job;
			$response['status'] = 'success';
			
			return $response;
		}
		catch(Exception $e) {
			info("Exception occurred: ", [$e->getMessage()]);
			return [];
		}
    }

    /**
     * @param $job
     * @return array
     */
    public function jobToData($job, $functionName = '')
    {
		try {
			$data = array();
			
			$data['job_id'] = $job->id;
			$data['from_language_id'] = $job->from_language_id;
			$data['immediate'] = $job->immediate;
			$data['duration'] = $job->duration;
			$data['status'] = $job->status;
			$data['gender'] = $job->gender;
			$data['certified'] = $job->certified;
			$data['due'] = $job->due;
			$data['job_type'] = $job->job_type;
			$data['customer_phone_type'] = $job->customer_phone_type;
			$data['customer_physical_type'] = $job->customer_physical_type;
			$data['customer_town'] = ($functionName === 'sendNotificationByAdminCancelJob') ? $job->user->userMeta->city : $job->town;
			$data['customer_type'] = $job->user->userMeta->customer_type;
			
			$due_Date = explode(" ", $job->due);
			$due_date = $due_Date[0];
			$due_time = $due_Date[1];
			
			$data['due_date'] = $due_date;
			$data['due_time'] = $due_time;
			
			$data['job_for'] = array();
			
			if($job->gender != null) {
				if($job->gender == 'male') {
					$data['job_for'][] = 'Man';
				}
				else if($job->gender == 'female') {
					$data['job_for'][] = 'Kvinna';
				}
			}
			//Job certification
			if($job->certified != null) {
				if($job->certified == 'both') {
					$data['job_for'][] = ($functionName === 'sendNotificationByAdminCancelJob') ? 'normal' : 'Godkänd tolk';
					$data['job_for'][] = ($functionName === 'sendNotificationByAdminCancelJob') ? 'certified' : 'Auktoriserad';
				}
				else if($job->certified == 'yes') {
					$data['job_for'][] = ($functionName === 'sendNotificationByAdminCancelJob') ? 'certified' : 'Auktoriserad';
				}
				else if($functionName === '' && $job->certified == 'n_health') {
					$data['job_for'][] = 'Sjukvårdstolk';
				}
				else if($functionName === '' && $job->certified == 'law' || $job->certified == 'n_law') {
					$data['job_for'][] = 'Rätttstolk';
				}
				else {
					$data['job_for'][] = $job->certified;
				}
			}
			
			return $data;
		}
		catch(Exception $e) {
			info("Exception occurred: ", [$e->getMessage()]);
			return [];
		}
    }

    /**
     * Function to get all Potential jobs of user with his ID
     * @param $userId
     * @return array
     */
    public function getPotentialJobIdsWithUserId($userId)
    {
		try {
			$userMeta = UserMeta::where('user_id', $userId)->first();
			$translatorType = $userMeta->translator_type;
			
			$jobType = 'unpaid';
			if($translatorType === 'professional') {
				$jobType = 'paid';
			}
			else if($translatorType === 'rwstranslator') {
				$jobType = 'rws';
			}
			
			// Fetch user languages and convert to an array of language IDs
			$languages = UserLanguages::where('user_id', '=', $userId)->get();
			$userLanguages = collect($languages)->pluck('lang_id')->all();
			
			$gender = $userMeta->gender;
			$translatorLevel = $userMeta->translator_level;
			
			// Retrieve jobs based on user-specific filters
			$jobIds = Job::getJobs($userId, $jobType, 'pending', $userLanguages, $gender, $translatorLevel);
			
			// Filter jobs based on translator's town and customer requirements
			foreach($jobIds as $key => $jobId) {
				$job = Job::find($jobId->id);
				$jobUserId = $job->user_id;
				$townCheck = Job::checkTowns($jobUserId, $userId);
				
				// Remove jobs if they don't match specific conditions
				if(($job->customer_phone_type === 'no' || $job->customer_phone_type === '') &&
					$job->customer_physical_type === 'yes' && !$townCheck) {
					unset($jobIds[$key]);
				}
			}
			
			$jobs = TeHelper::convertJobIdsInObjs($jobIds);
			
			return $jobs;
		}
		catch(Exception $e) {
			info("Exception occurred: ", [$e->getMessage()]);
			return [];
		}
    }

    /**
     * @param $job
     * @param array $data
     * @param $excludeUserId
     */
    public function sendNotificationTranslator($job, $excludeUserId, $data = [])
    {
		try {
			$users = User::all();
			$translatorArray = array();
			$delayTranslatorArray = array();
			
			foreach($users as $user) {
				// user is translator and he is not disabled
				if (in_array($user->user_type, ['1', '2']) && $user->id != $excludeUserId) {
					if(!isNeedToSendPush($user->id)) continue;
					
					$notGetEmergency = TeHelper::getUsermeta($user->id, 'not_get_emergency');
					if($data['immediate'] == 'yes' && $notGetEmergency == 'yes') continue;
					
					// get all potential jobs of this user
					$jobs = $this->getPotentialJobIdsWithUserId($user->id);
					
					foreach($jobs as $jobData) {
						// one potential job is the same with current job
						if($job->id == $jobData->id) {
							$userId = $jobData->id;
							
							$jobForTranslator = Job::assignedToPaticularTranslator($userId, $jobData->id);
							if($jobForTranslator === 'SpecificJob') {
								$jobChecker = Job::checkParticularJob($userId, $jobData);
								
								if(($jobChecker !== 'userCanNotAcceptJob')) {
									if(isNeedToDelayPush($user->id)) {
										$delayTranslatorArray[] = $user;
									}
									else {
										$translatorArray[] = $user;
									}
								}
							}
						}
					}
				}
			}
			
			$data['language'] = TeHelper::fetchLanguageFromJobId($data['from_language_id']);
			$data['notification_type'] = 'suitable_job';
			$msgContents = $data['immediate'] == 'no' ? 'Ny bokning för ' . $data['language'] . 'tolk ' . $data['duration'] . 'min ' . $data['due'] : 'Ny akutbokning för ' . $data['language'] . 'tolk ' . $data['duration'] . 'min';
			$msgText = array("en" => $msgContents);
			
			//Initialize push logger
			$logger = initializeLogger('push_logger', 'logs/push/laravel-' . date('Y-m-d') . '.log');
			$logger->addInfo('Push send for job ' . $job->id, [$translatorArray, $delayTranslatorArray, $msgText, $data]);
			
			// send new booking push to suitable translators(not delay)
			$this->sendPushNotificationToSpecificUsers($translatorArray, $job->id, $data, $msgText, false);
			// send new booking push to suitable translators(need to delay)
			$this->sendPushNotificationToSpecificUsers($delayTranslatorArray, $job->id, $data, $msgText, true);
		}
		catch(Exception $e) {
			info("Exception occurred: ", [$e->getMessage()]);
			return [];
		}
    }

    /**
     * Sends SMS to translators and retuns count of translators
     * @param $job
     * @return int
     */
    public function sendSMSNotificationToTranslator($job)
    {
        try {
	        $translators = $this->getPotentialTranslators($job);
	        $jobPosterMeta = UserMeta::where('user_id', $job->user_id)->first();
	        
	        //Prepare message templates
	        $jobId = $job->id;
	        $city = $job->city ?? $jobPosterMeta->city;
	        $duration = convertToHoursMins($job->duration);
	        $date = date('d.m.Y', strtotime($job->due));
	        $time = date('H:i', strtotime($job->due));
	        
	        $phoneJobMessageTemplate = trans('sms.phone_job', ['date' => $date, 'time' => $time, 'duration' => $duration, 'jobId' => $jobId]);
	        $physicalJobMessageTemplate = trans('sms.physical_job', ['date' => $date, 'time' => $time, 'town' => $city, 'duration' => $duration, 'jobId' => $jobId]);
	        
	        $message = '';
	        //Analyze weather it's phone or physical; if both = default to phone
	        if($job->customer_physical_type == 'yes' && $job->customer_phone_type == 'no') {
		        $message = $physicalJobMessageTemplate; //It's a physical job
	        }
			else if ($job->customer_physical_type == 'no' && $job->customer_phone_type == 'yes') {
		        $message = $phoneJobMessageTemplate; //It's a phone job
	        }
			else if ($job->customer_physical_type == 'yes' && $job->customer_phone_type == 'yes') {
		        $message = $phoneJobMessageTemplate; //It's both, but should be handled as phone job
	        }
	        Log::info($message);
	        
	        //Send messages via sms handler
	        foreach ($translators as $translator) {
		        //Send message to translator
		        $status = SendSMSHelper::send(env('SMS_NUMBER'), $translator->mobile, $message);
		        Log::info('Send SMS to ' . $translator->email . ' (' . $translator->mobile . '), status: ' . print_r($status, true));
	        }
	        
	        return count($translators);
        }
        catch(Exception $e) {
	        info("Exception occurred: ", [$e->getMessage()]);
	        return [];
        }
    }
	
    /**
     * Function to send Onesignal Push Notifications with User-Tags
     * @param $users
     * @param $jobId
     * @param $data
     * @param $msgText
     * @param $isNeedDelay
     */
    public function sendPushNotificationToSpecificUsers($users, $jobId, $data, $msgText, $isNeedDelay)
    {
		try {
			//Initialize logger
			$logger = initializeLogger('push_logger', 'logs/push/laravel-' . date('Y-m-d') . '.log');
			$logger->addInfo('Push send for job ' . $jobId, [$users, $data, $msgText, $isNeedDelay]);
			
			//Get Signal App keys
			$onesignalAppID = env('APP_ENV') == 'prod' ? env('PROD_ONE_SIGNAL_APP_ID') : env('DEV_ONE_SIGNAL_APP_ID');
			$onesignalRestAuthKey = env('APP_ENV') == 'prod' ? sprintf("Authorization: Basic %s", env('app.PROD_ONE_SIGNAL_API_KEY')) : sprintf("Authorization: Basic %s", env('app.DEV_ONE_SIGNAL_API_KEY'));
			
			$userTags = getUserTagsStringFromArray($users);
			
			$data['job_id'] = $jobId;
			
			//Mobile notification sound
			$iosSound = 'default';
			$androidSound = 'default';
			
			if($data['notification_type'] == 'suitable_job') {
				$androidSound = $data['immediate'] == 'no' ? 'normal_booking' : 'emergency_booking';
				$iosSound = $data['immediate'] == 'no' ? 'normal_booking.mp3' : 'emergency_booking.mp3';
			}
			
			//Set notification fields
			$fields = array(
				'app_id'         => $onesignalAppID,
				'tags'           => json_decode($userTags),
				'data'           => $data,
				'title'          => array('en' => 'DigitalTolk'),
				'contents'       => $msgText,
				'ios_badgeType'  => 'Increase',
				'ios_badgeCount' => 1,
				'android_sound'  => $androidSound,
				'ios_sound'      => $iosSound
			);
			
			if($isNeedDelay) {
				$fields['send_after'] = DateTimeHelper::getNextBusinessTimeString();
			}
			
			$headers = [
				'Content-Type: application/json',
				$onesignalRestAuthKey
			];
			
			//Send curl request
			$response = $this->curlService->sendRequest("https://onesignal.com/api/v1/notifications", $headers, json_encode($fields));
			//Add info in logger
			$logger->addInfo('Push send for job ' . $jobId . ' curl answer', [$response]);
		}
		catch(Exception $e) {
			info("Exception occurred: ", [$e->getMessage()]);
			return [];
		}
    }

    /**
     * @param Job $job
     * @return mixed
     */
    public function getPotentialTranslators(Job $job)
    {
		try {
			$jobType = $job->job_type;
			
			//Map job type to translator type
			$translatorType = match($jobType) {
				'paid' => 'professional',
				'rws' => 'rwstranslator',
				'unpaid' => 'volunteer',
			};
			
			//Job attributes
			$jobLanguage = $job->from_language_id;
			$gender = $job->gender;
			$certified = $job->certified;
			
			$translatorLevel = [];
			if(!empty($certified)) {
				if(in_array($certified, ['yes', 'both'])) {
					$translatorLevel = [
						'Certified',
						'Certified with specialisation in law',
						'Certified with specialisation in health care'
					];
				}
				else if(in_array($certified, ['law', 'n_law'])) {
					$translatorLevel[] = 'Certified with specialisation in law';
				}
				else if(in_array($certified, ['health', 'n_health'])) {
					$translatorLevel[] = 'Certified with specialisation in health care';
				}
				else if(in_array($certified, ['normal', 'both'])) {
					$translatorLevel = [
						'Layman',
						'Read Translation courses'
					];
				}
			}
			else {
				$translatorLevel = [
					'Certified',
					'Certified with specialisation in law',
					'Certified with specialisation in health care',
					'Layman',
					'Read Translation courses'
				];
			}
			
			$blacklist = UsersBlacklist::where('user_id', $job->user_id)->get();
			$translatorsId = collect($blacklist)->pluck('translator_id')->all();
			$users = User::getPotentialUsers($translatorType, $jobLanguage, $gender, $translatorLevel, $translatorsId);
			
			return $users;
		}
		catch(Exception $e) {
			info("Exception occurred: ", [$e->getMessage()]);
			return [];
		}
    }

    /**
     * @param $id
     * @param $data
     * @param $user
     * @return mixed
     */
    public function updateJob($id, $data, $user)
    {
		try {
			$job = Job::find($id);
			
			$currentTranslator = $job->translatorJobRel->whereNull('cancel_at')->first();
			if(is_null($currentTranslator))
				$currentTranslator = $job->translatorJobRel->whereNotNull('completed_at')->first();
			
			$logData = [];
			$oldTime = null;
			$oldLang = null;
			$langChanged = false;
			
			//Change translator
			$changeTranslator = $this->changeTranslator($currentTranslator, $data, $job);
			
			if($changeTranslator['translatorChanged']) {
				$logData[] = $changeTranslator['log_data'];
			}
			
			$changeDue = $this->changeDue($job->due, $data['due']);
			if($changeDue['dateChanged']) {
				$oldTime = $job->due;
				$job->due = $data['due'];
				$logData[] = $changeDue['log_data'];
			}
			
			if($job->from_language_id != $data['from_language_id']) {
				//Add old and new language in log
				$logData[] = [
					'old_lang' => TeHelper::fetchLanguageFromJobId($job->from_language_id),
					'new_lang' => TeHelper::fetchLanguageFromJobId($data['from_language_id'])
				];
				
				$oldLang = $job->from_language_id;
				$job->from_language_id = $data['from_language_id'];
				$langChanged = true;
			}
			
			$changeStatus = $this->changeStatus($job, $data, $changeTranslator['translatorChanged']);
			if($changeStatus['statusChanged'])
				$logData[] = $changeStatus['log_data'];
			
			//Update job data
			$job->admin_comments = $data['admin_comments'];
			$job->reference = $data['reference'];
			$job->save();
			
			//Add info in logger
			$this->logger->addInfo('USER #' . $user->id . '(' . $user->name . ')' . ' has been updated booking <a class="openjob" href="/admin/jobs/' . $id . '">#' . $id . '</a> with data:  ', $logData);
			
			if ($job->due <= Carbon::now()) {
				return ['Updated'];
			}
			else {
				if($changeDue['dateChanged']) $this->sendChangedDateNotification($job, $oldTime);
				if($changeTranslator['translatorChanged']) $this->sendChangedTranslatorNotification($job, $currentTranslator, $changeTranslator['new_translator']);
				if($langChanged) $this->sendChangedLangNotification($job, $oldLang);
			}
		}
		catch(Exception $e) {
			info("Exception occurred: ", [$e->getMessage()]);
			return [];
		}
    }

    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return array
     */
    private function changeStatus($job, $data, $changedTranslator)
    {
	    try {
		    $response = array();
		    $oldStatus = $job->status;
		    
		    if($oldStatus != $data['status']) {
			    $statusChanged = match($job->status) {
				    JobStatus::TIMED_OUT->value => $this->changeTimedoutStatus($job, $data, $changedTranslator),
				    JobStatus::COMPLETED->value => $this->changeCompletedStatus($job, $data),
				    JobStatus::STARTED->value => $this->changeStartedStatus($job, $data),
				    JobStatus::PENDING->value => $this->changePendingStatus($job, $data, $changedTranslator),
				    JobStatus::WITHDRAW_AFTER_24->value => $this->changeWithdrawafter24Status($job, $data),
				    JobStatus::ASSIGNED->value => $this->changeAssignedStatus($job, $data),
				    default => false,
			    };
			    
			    if($statusChanged) {
				    $logData = [
					    'old_status' => $oldStatus,
					    'new_status' => $data['status']
				    ];
				    
				    return ['statusChanged' => $statusChanged, 'log_data' => $logData];
			    }
		    }
		    
		    return $response;
	    }
		catch(Exception $e) {
			info("Exception occurred: ", [$e->getMessage()]);
			return [];
		}
    }

    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return bool
     */
    private function changeTimedoutStatus($job, $data, $changedTranslator)
    {
		try {
			$view = '';
			$subject = '';
			$job->status = $data['status'];
			
			$user = $job->user()->first();
			$email = !empty($job->user_email) ? $job->user_email : $user->email;
			$name = $user->name;
			
			$dataEmail = [
				'user' => $user,
				'job'  => $job,
			];
			
			if($data['status'] == JobStatus::PENDING->value) {
				$job->emailsent = 0;
				$job->emailsenttovirpal = 0;
				$job->created_at = date('Y-m-d H:i:s');
				$job->save();
				
				$jobData = $this->jobToData($job);
				
				//Prepare email data
				$view = 'emails.job-change-status-to-customer';
				$subject = 'Vi har nu återöppnat er bokning av ' . TeHelper::fetchLanguageFromJobId($job->from_language_id) . 'tolk för bokning #' . $job->id;
			}
			else if($changedTranslator) {
				$job->save();
				
				//Prepare email data
				$view = 'emails.job-accepted';
				$subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
			}
			
			$this->mailer->send($email, $name, $subject, $view, $dataEmail);
			
			if($data['status'] == JobStatus::PENDING->value || $changedTranslator) {
				if($data['status'] == JobStatus::PENDING->value) {
					$this->sendNotificationTranslator($job, '*', $jobData);
				}
				
				return true;
			}
			
			return false;
		}
		catch(Exception $e) {
			info("Exception occurred: ", [$e->getMessage()]);
			return false;
		}
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeCompletedStatus($job, $data)
    {
		try {
			$job->status = $data['status'];
			
			//Check status is equal to timeout
			if($data['status'] == JobStatus::TIMED_OUT->value) {
				if($data['admin_comments'] == '') return false;
				$job->admin_comments = $data['admin_comments'];
			}
			
			$job->save();
			return true;
		}
		catch(Exception $e) {
			info("Exception occurred: ", [$e->getMessage()]);
			return false;
		}
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeStartedStatus($job, $data)
    {
		try {
			if($data['admin_comments'] == '') return false;
			
			$job->status = $data['status'];
			$job->admin_comments = $data['admin_comments'];
			
			if($data['status'] == JobStatus::COMPLETED->value) {
				$user = $job->user()->first();
				
				if($data['sesion_time'] == '') return false;
				
				$interval = $data['sesion_time'];
				$diff = explode(':', $interval);
				
				//Update job
				$job->end_at = date('Y-m-d H:i:s');
				$job->session_time = $interval;
				$job->save();
				
				$sessionTime = $diff[0] . ' tim ' . $diff[1] . ' min';
				$name = $user->name;
				$email = !empty($job->user_email) ? $job->user_email : $user->email;
				$subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
				
				//Email data
				$dataEmail = [
					'user'         => $user,
					'job'          => $job,
					'session_time' => $sessionTime,
					'for_text'     => 'faktura'
				];
				
				//Send email
				$this->mailer->send($email, $name, $subject, 'emails.session-ended', $dataEmail);
				
				$user = $job->translatorJobRel->whereNull('completed_at')->whereNull('cancel_at')->first();
				
				$name = $user->user->name;
				$email = $user->user->email;
				$subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
				
				//Email data
				$dataEmail = [
					'user'         => $user,
					'job'          => $job,
					'session_time' => $sessionTime,
					'for_text'     => 'lön'
				];
				
				//Send email
				$this->mailer->send($email, $name, $subject, 'emails.session-ended', $dataEmail);
				
			}
			return true;
		}
		catch(Exception $e) {
			info("Exception occurred: ", [$e->getMessage()]);
			return false;
		}
    }

    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return bool
     */
    private function changePendingStatus($job, $data, $changedTranslator)
    {
		try {
			if($data['admin_comments'] == '' && $data['status'] == JobStatus::TIMED_OUT->value) return false;
			
			//Update job
			$job->status = $data['status'];
			$job->admin_comments = $data['admin_comments'];
			$job->save();
			
			//Email data
			$user = $job->user()->first();
			$name = $user->name;
			$email = !empty($job->user_email) ? $job->user_email : $user->email;
			
			$dataEmail = [
				'user' => $user,
				'job'  => $job
			];
			
			if($data['status'] == JobStatus::ASSIGNED->value && $changedTranslator) {
				$subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
				
				//Send email
				$this->mailer->send($email, $name, $subject, 'emails.job-accepted', $dataEmail);
				
				//Get translator details
				$translator = Job::getJobsAssignedTranslatorDetail($job);
				
				//Send email to translator
				$this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-translator-new-translator', $dataEmail);
				
				//Get language
				$language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
				
				//Send notification
				$this->sendSessionStartRemindNotification($user, $job, $language, $job->due, $job->duration);
				$this->sendSessionStartRemindNotification($translator, $job, $language, $job->due, $job->duration);
			}
			else {
				$subject = 'Avbokning av bokningsnr: #' . $job->id;
				
				//Send email
				$this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);
			}
			
			return true;
		}
		catch(Exception $e) {
			info("Exception occurred: ", [$e->getMessage()]);
			return false;
		}
    }

    /*
     * TODO remove method and add service for notification
     * TEMP method
     * send session start remind notification
     */
    public function sendSessionStartRemindNotification($user, $job, $language, $due, $duration)
    {
		try {
			//Initialize logger
			$logger = initializeLogger('cron_logger', 'logs/cron/laravel-' . date('Y-m-d') . '.log');
			$dueExplode = explode(' ', $due);
			
			$msgText = $job->customer_physical_type == 'yes'
				? 'Detta är en påminnelse om att du har en ' . $language . 'tolkning (på plats i ' . $job->town . ') kl ' . $dueExplode[1] . ' på ' . $dueExplode[0] . ' som vara i ' . $duration . ' min. Lycka till och kom ihåg att ge feedback efter utförd tolkning!'
				: 'Detta är en påminnelse om att du har en ' . $language . 'tolkning (telefon) kl ' . $dueExplode[1] . ' på ' . $dueExplode[0] . ' som vara i ' . $duration . ' min.Lycka till och kom ihåg att ge feedback efter utförd tolkning!';
			
			$this->sendCustomNotificationToSpecificUsers('session_start_remind', $job, $user, $msgText);
			$logger->addInfo('sendSessionStartRemindNotification ', ['job' => $job->id]);
		}
		catch(Exception $e) {
			info("Exception occurred: ", [$e->getMessage()]);
			return [];
		}
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeWithdrawafter24Status($job, $data)
    {
		try {
			if($data['status'] == JobStatus::TIMED_OUT->value) {
				$job->status = $data['status'];
				
				if($data['admin_comments'] == '') return false;
				
				$job->admin_comments = $data['admin_comments'];
				$job->save();
				
				return true;
			}
			
			return false;
		}
		catch(Exception $e) {
			info("Exception occurred: ", [$e->getMessage()]);
			return false;
		}
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeAssignedStatus($job, $data)
    {
        try {
	        if(in_array($data['status'], [JobStatus::WITHDRAW_BEFORE_24->value, JobStatus::WITHDRAW_AFTER_24->value, JobStatus::TIMED_OUT->value])) {
		        $job->status = $data['status'];
				
		        if ($data['admin_comments'] == '' && $data['status'] == JobStatus::TIMED_OUT->value) return false;
				
				//Update admin comments
		        $job->admin_comments = $data['admin_comments'];
		        $job->save();
				
		        if(in_array($data['status'], [JobStatus::WITHDRAW_BEFORE_24->value, JobStatus::WITHDRAW_AFTER_24->value])) {
			        $user = $job->user()->first();
			        
			        $name = $user->name;
			        $email = !empty($job->user_email) ? $job->user_email : $user->email;
			        $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
					
					//Email data
			        $dataEmail = [
				        'user' => $user,
				        'job'  => $job
			        ];
			        
					//Send email
			        $this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);
			        
					//Get job translator
			        $user = $job->translatorJobRel->whereNull('completed_at')->whereNull('cancel_at')->first();
			        
			        $name = $user->user->name;
			        $email = $user->user->email;
			        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
					
			        $dataEmail = [
				        'user' => $user,
				        'job'  => $job
			        ];
					
					//Send email
			        $this->mailer->send($email, $name, $subject, 'emails.job-cancel-translator', $dataEmail);
		        }
		        return true;
	        }
	        return false;
        }
		catch(Exception $e) {
			info("Exception occurred: ", [$e->getMessage()]);
			return false;
		}
    }

    /**
     * @param $currentTranslator
     * @param $data
     * @param $job
     * @return array
     */
    private function changeTranslator($currentTranslator, $data, $job)
    {
        try {
	        $translatorChanged = false;
	        
	        if (!is_null($currentTranslator) || (isset($data['translator']) && $data['translator'] != 0) || $data['translator_email'] != '') {
		        $logData = [];
				
		        if(!is_null($currentTranslator) && ((isset($data['translator']) && $currentTranslator->user_id != $data['translator']) || $data['translator_email'] != '') && (isset($data['translator']) && $data['translator'] != 0)) {
			        if($data['translator_email'] != '') $data['translator'] = User::where('email', $data['translator_email'])->first()->id;
			        
					//Update current translator
			        $currentTranslator->cancel_at = Carbon::now();
			        $currentTranslator->save();
					
					$newTranslator = $currentTranslator->toArray();
			        $newTranslator['user_id'] = $data['translator'];
			        unset($newTranslator['id']);
			        
					//Create translator
			        $newTranslator = Translator::create($newTranslator);
					
			        $logData[] = [
				        'old_translator' => $currentTranslator->user->email,
				        'new_translator' => $newTranslator->user->email
			        ];
					
			        $translatorChanged = true;
		        }
				else if(is_null($currentTranslator) && isset($data['translator']) && ($data['translator'] != 0 || $data['translator_email'] != '')) {
			        if($data['translator_email'] != '') $data['translator'] = User::where('email', $data['translator_email'])->first()->id;
					
					//Create translator
			        $newTranslator = Translator::create(['user_id' => $data['translator'], 'job_id' => $job->id]);
					
			        $logData[] = [
				        'old_translator' => null,
				        'new_translator' => $newTranslator->user->email
			        ];
					
			        $translatorChanged = true;
		        }
		        if($translatorChanged)
			        return ['translatorChanged' => $translatorChanged, 'new_translator' => $newTranslator, 'log_data' => $logData];
		        
	        }
	        
	        return ['translatorChanged' => $translatorChanged];
        }
		catch(Exception $e) {
			info("Exception occurred: ", [$e->getMessage()]);
			return [];
		}
    }

    /**
     * @param $oldDue
     * @param $newDue
     * @return array
     */
    private function changeDue($oldDue, $newDue)
    {
		try {
			$response = array();
			$dateChanged = false;
			
			if($oldDue != $newDue) {
				$logData = [
					'old_due' => $oldDue,
					'new_due' => $newDue
				];
				$dateChanged = true;
				$response['log_data'] = $logData;
			}
			
			$response['dateChanged'] = $dateChanged;
			return $response;
		}
		catch(Exception $e) {
			info("Exception occurred: ", [$e->getMessage()]);
			return [];
		}
    }

    /**
     * @param $job
     * @param $currentTranslator
     * @param $newTranslator
     */
    public function sendChangedTranslatorNotification($job, $currentTranslator, $newTranslator)
    {
		try {
			$user = $job->user()->first();
			$name = $user->name;
			$email = !empty($job->user_email) ? $job->user_email : $user->email;
			$subject = 'Meddelande om tilldelning av tolkuppdrag för uppdrag # ' . $job->id . ')';
			
			//Create mail data
			$data = ['user' => $user, 'job'  => $job];
			
			//Send email
			$this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-customer', $data);
			
			if($currentTranslator) {
				$user = $currentTranslator->user;
				$name = $user->name;
				$email = $user->email;
				$data['user'] = $user;
				
				//Send email
				$this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-old-translator', $data);
			}
			
			$user = $newTranslator->user;
			$name = $user->name;
			$email = $user->email;
			$data['user'] = $user;
			
			//Send email
			$this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-new-translator', $data);
		}
		catch(Exception $e) {
			info("Exception occurred: ", [$e->getMessage()]);
			return [];
		}
    }

    /**
     * @param $job
     * @param $oldTime
     */
    public function sendChangedDateNotification($job, $oldTime)
    {
	    try {
		    $this->sendChangedNotification($job, $oldTime);
	    }
	    catch(Exception $e) {
		    info("Exception occurred: ", [$e->getMessage()]);
		    return [];
	    }
    }

    /**
     * @param $job
     * @param $oldLang
     */
    public function sendChangedLangNotification($job, $oldLang)
    {
	    try {
		    $this->sendChangedNotification($job, null, $oldLang);
	    }
		catch(Exception $e) {
			info("Exception occurred: ", [$e->getMessage()]);
			return [];
		}
    }
	
	/**
	 * @param $job
	 */
	public function sendChangedNotification($job, $oldTime = null, $oldLang = null)
	{
		try {
			$user = $job->user()->first();
			$email = !empty($job->user_email) ? $job->user_email : $user->email;
			$name = $user->name;
			//Create subject
			$subject = 'Meddelande om ändring av tolkbokning för uppdrag # ' . $job->id . '';
			//View name
			$view = $oldTime ? 'emails.job-changed-date' : 'emails.job-changed-lang';
			
			$data = [
				'user' => $user,
				'job'  => $job,
			];
			
			if($oldTime) {
				$data['old_time'] = $oldTime;
			}
			if($oldLang) {
				$data['old_lang'] = $oldLang;
			}
			
			//Send email
			$this->mailer->send($email, $name, $subject, $view, $data);
			
			$translator = Job::getJobsAssignedTranslatorDetail($job);
			
			$data = [
				'user'     => $translator,
				'job'      => $job,
			];
			
			if($oldTime) {
				$data['old_time'] = $oldTime;
			}
			if($oldLang) {
				$data['old_lang'] = $oldLang;
			}
			
			//Send email
			$this->mailer->send($translator->email, $translator->name, $subject, $view, $data);
		}
		catch(Exception $e) {
			info("Exception occurred: ", [$e->getMessage()]);
			return [];
		}
	}

    /**
     * Function to send Job Expired Push Notification
     * @param $job
     * @param $user
     */
    public function sendExpiredNotification($job, $user)
    {
		try {
			//Get language from job id
			$language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
			$msgText = 'Tyvärr har ingen tolk accepterat er bokning: (' . $language . ', ' . $job->duration . 'min, ' . $job->due . '). Vänligen pröva boka om tiden.';
			$this->sendCustomNotificationToSpecificUsers('job_expired', $job, $user, $msgText);
		}
		catch(Exception $e) {
			info("Exception occurred: ", [$e->getMessage()]);
			return [];
		}
    }

    /**
     * Function to send the notification for sending the admin job cancel
     * @param $jobId
     */
    public function sendNotificationByAdminCancelJob($jobId)
    {
		try {
			$job = Job::findOrFail($jobId);
			$data = $this->jobToData($job, __FUNCTION__);
			//Send notification to all translators
			$this->sendNotificationTranslator($job, '*', $data);
		}
		catch(Exception $e) {
			info("Exception occurred: ", [$e->getMessage()]);
			return [];
		}
    }

    /**
     * send session start remind notificatio
     * @param $user
     * @param $job
     * @param $language
     * @param $due
     * @param $duration
     */
    private function sendNotificationChangePending($user, $job, $language, $due, $duration)
    {
        try {
	        $msgText = 'Du har nu fått '.($job->customer_physical_type == 'yes' ? 'platstolkningen' : 'telefontolkningen').' för ' . $language . ' kl ' . $duration . ' den ' . $due . '. Vänligen säkerställ att du är förberedd för den tiden. Tack!';
			$this->sendCustomNotificationToSpecificUsers('session_start_remind', $job, $user, $msgText);
        }
		catch(Exception $e) {
			info("Exception occurred: ", [$e->getMessage()]);
			return [];
		}
    }

    /**
     * @param $data
     * @param $user
     */
    public function acceptJob($data, $user)
    {
		try {
			$response = array();
			
			$jobId = $data['job_id'];
			$job = Job::findOrFail($jobId);
			
			if(!Job::isTranslatorAlreadyBooked($jobId, $user->id, $job->due)) {
				if($job->status == JobStatus::PENDING->value && Job::insertTranslatorJobRel($user->id, $jobId)) {
					//Update job
					$job->status = JobStatus::ASSIGNED->value;
					$job->save();
					
					$jobUser = $job->user()->first();
					$name = $jobUser->name;
					$email = !empty($job->user_email) ? $job->user_email : $jobUser->email;
					$subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
					$data = [
						'user' => $jobUser,
						'job'  => $job
					];
					
					//Send email
					$mailer = new AppMailer();
					$mailer->send($email, $name, $subject, 'emails.job-accepted', $data);
					
				}
				/*@todo
					add flash message here.
				*/
				$jobs = $this->getPotentialJobs($user);
				
				$response['list'] = json_encode(['jobs' => $jobs, 'job' => $job], true);
				$response['status'] = 'success';
			}
			else {
				$response['status'] = 'fail';
				$response['message'] = 'Du har redan en bokning den tiden! Bokningen är inte accepterad.';
			}
			
			return $response;
		}
		catch(Exception $e) {
			info("Exception occurred: ", [$e->getMessage()]);
			return [];
		}
    }
	
	/**
	 * @param $jobId
	 * @param $user
	 */
    public function acceptJobWithId($jobId, $user)
    {
        try {
	        $response = array();
	        $job = Job::findOrFail($jobId);
	        
	        if(!Job::isTranslatorAlreadyBooked($jobId, $user->id, $job->due)) {
		        if($job->status == JobStatus::PENDING->value && Job::insertTranslatorJobRel($user->id, $jobId)) {
			       
					//Update job status
					$job->status = JobStatus::ASSIGNED->value;
			        $job->save();
					
			        $jobUser = $job->user()->first();
					
					//Mail data
			        $name = $jobUser->name;
			        $email = !empty($job->user_email) ? $job->user_email : $jobUser->email;
			        $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
					
			        $data = [
				        'user' => $jobUser,
				        'job'  => $job
			        ];
			        
					//Initialize mailer and send email
			        $mailer = new AppMailer();
			        $mailer->send($email, $name, $subject, 'emails.job-accepted', $data);
					
					//Get language from job id
			        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
			        $msgText = 'Din bokning för ' . $language . ' translators, ' . $job->duration . 'min, ' . $job->due . ' har accepterats av en tolk. Vänligen öppna appen för att se detaljer om tolken.';
					
					//Send notification
					$this->sendCustomNotificationToSpecificUsers('job_accepted', $job, $jobUser, $msgText);
					
			        //Your Booking is accepted successfully
			        $response['status'] = 'success';
			        $response['list']['job'] = $job;
			        $response['message'] = 'Du har nu accepterat och fått bokningen för ' . $language . 'tolk ' . $job->duration . 'min ' . $job->due;
		        }
				else {
			        //Booking is already accepted by someone else
			        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
			        $response['status'] = 'fail';
			        $response['message'] = 'Denna ' . $language . 'tolkning ' . $job->duration . 'min ' . $job->due . ' har redan accepterats av annan tolk. Du har inte fått denna tolkning';
		        }
	        }
			else {
		        // You already have a booking the time
		        $response['status'] = 'fail';
		        $response['message'] = 'Du har redan en bokning den tiden ' . $job->due . '. Du har inte fått denna tolkning';
	        }
			
	        return $response;
        }
		catch(Exception $e) {
			info("Exception occurred: ", [$e->getMessage()]);
			return [];
		}
    }

    public function cancelJobAjax($data, $user)
    {
		try {
			/*@todo
				add 24hrs loging here.
				If the cancelation is before 24 hours before the booking tie - supplier will be informed. Flow ended
				if the cancelation is within 24
				if cancelation is within 24 hours - translator will be informed AND the customer will get an addition to his number of bookings - so we will charge of it if the cancelation is within 24 hours
				so we must treat it as if it was an executed session
			*/
			$response = array();
			
			//Find job and job translator
			$jobId = $data['job_id'];
			$job = Job::findOrFail($jobId);
			$translator = Job::getJobsAssignedTranslatorDetail($job);
			
			if($user->is(UserRole::CUSTOMER->value)) {
				$job->withdraw_at = Carbon::now();
				
				if($job->withdraw_at->diffInHours($job->due) >= 24) {
					$job->status = JobStatus::WITHDRAW_BEFORE_24->value;
				}
				else {
					$job->status = JobStatus::WITHDRAW_AFTER_24->value;
				}
				//Update job
				$job->save();
				
				//Dispatch JobWasCanceled event
				event(new JobWasCanceled($job));
				
				$response['status'] = 'success';
				$response['jobStatus'] = 'success';
				
				if($translator) {
					$language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
					$msgText = 'Kunden har avbokat bokningen för ' . $language . 'tolk, ' . $job->duration . 'min, ' . $job->due . '. Var god och kolla dina tidigare bokningar för detaljer.';
					
					//Send notification
					$this->sendCustomNotificationToSpecificUsers('job_cancelled', $job, $translator, $msgText);
				}
			}
			else {
				if($job->due->diffInHours(Carbon::now()) > 24) {
					if($customer = $job->user()->get()->first()) {
						$language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
						$msgText = 'Er ' . $language . 'tolk, ' . $job->duration . 'min ' . $job->due . ', har avbokat tolkningen. Vi letar nu efter en ny tolk som kan ersätta denne. Tack.';
						
						//Send notification
						$this->sendCustomNotificationToSpecificUsers('job_cancelled', $job, $customer, $msgText);
					}
					
					//Update job
					$job->status = JobStatus::PENDING->value;
					$job->created_at = date('Y-m-d H:i:s');
					$job->will_expire_at = TeHelper::willExpireAt($job->due, date('Y-m-d H:i:s'));
					$job->save();
					
					Job::deleteTranslatorJobRel($translator->id, $jobId);
					
					//Send notification to translator
					$data = $this->jobToData($job);
					$this->sendNotificationTranslator($job, $translator->id, $data);
					$response['status'] = 'success';
				}
				else {
					$response['status'] = 'fail';
					$response['message'] = 'Du kan inte avboka en bokning som sker inom 24 timmar genom DigitalTolk. Vänligen ring på +46 73 75 86 865 och gör din avbokning over telefon. Tack!';
				}
			}
			
			return $response;
		}
		catch(Exception $e) {
			info("Exception occurred: ", [$e->getMessage()]);
			return [];
		}
    }

    /*Function to get the potential jobs for paid,rws,unpaid translators*/
    public function getPotentialJobs($user)
    {
	    try {
		    $userMeta = $user->userMeta;
		    $jobType = match($userMeta->translator_type) {
			    'professional' => 'paid', // Professionals get all jobs
			    'rwstranslator' => 'rws', // RWS translators get only RWS jobs
			    'volunteer' => 'unpaid', // Volunteers get only unpaid jobs
			    default => 'unpaid',
		    };
		    
		    //Get user's languages
		    $userLanguages = UserLanguages::where('user_id', $user->id)->pluck('lang_id')->all();
		    $gender = $userMeta->gender;
		    $translatorLevel = $userMeta->translator_level;
		    
		    //Get job ids
		    $jobIds = Job::getJobs($user->id, $jobType, JobStatus::PENDING->value, $userLanguages, $gender, $translatorLevel);
		    
		    foreach($jobIds as $key => $job) {
			    $job->specific_job = Job::assignedToPaticularTranslator($user->id, $job->id);
			    $job->check_particular_job = Job::checkParticularJob($user->id, $job);
			    $checkTown = Job::checkTowns($job->user_id, $user->id);
			    
			    // Remove job from the list
			    if (($job->specific_job === 'SpecificJob' && $job->check_particular_job === 'userCanNotAcceptJob') ||
				    ($job->customer_phone_type === 'no' || empty($job->customer_phone_type)) &&
				    $job->customer_physical_type === 'yes' &&
				    !$checkTown) {
				    unset($jobIds[$key]);
			    }
		    }
		    
		    return $jobIds;
	    }
		catch(Exception $e) {
			info("Exception occurred: ", [$e->getMessage()]);
			return [];
		}
    }

    public function endJob($postData)
    {
		try {
			$completedDate = date('Y-m-d H:i:s');
			$jobId = $postData['job_id'];
			
			//Fetch job details
			$job = Job::with('translatorJobRel')->findOrFail($jobId);
			
			if($job->status !== JobStatus::STARTED->value) {
				return ['status' => 'success'];
			}
			
			// Calculate session time interval
			$start = date_create($job->due);
			$end = date_create($completedDate);
			$diff = date_diff($end, $start);
			$sessionTime = sprintf('%02d:%02d:%02d', $diff->h, $diff->i, $diff->s);
			
			//Update job details
			$job->end_at = $completedDate;
			$job->status = 'completed';
			$job->session_time = $sessionTime;
			$job->save();
			
			//Notify the job user
			$jobUser = $job->user()->first();
			$email = $job->user_email ?: $jobUser->email;
			$name = $jobUser->name;
			$subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
			$sessionTimeFormatted = $diff->h . ' tim ' . $diff->i . ' min';
			
			$data = [
				'user'         => $jobUser,
				'job'          => $job,
				'session_time' => $sessionTimeFormatted,
				'for_text'     => 'faktura'
			];
			
			$mailer = new AppMailer();
			$mailer->send($email, $name, $subject, 'emails.session-ended', $data);
			
			//Update translator
			$translatorRel = $job->translatorJobRel()
				->whereNull('completed_at')
				->whereNull('cancel_at')
				->first();
			
			$translatorRel->completed_at = $completedDate;
			$translatorRel->completed_by = $postData['user_id'];
			$translatorRel->save();
			
			//Notify the translator
			$translator = $translatorRel->user()->first();
			$translatorEmail = $translator->email;
			$translatorName = $translator->name;
			$translatorSubject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
			
			$translatorData = [
				'user'         => $translator,
				'job'          => $job,
				'session_time' => $sessionTimeFormatted,
				'for_text'     => 'lön'
			];
			
			$mailer->send($translatorEmail, $translatorName, $translatorSubject, 'emails.session-ended', $translatorData);
			
			//Fire session ended event
			$notifiedUserId = ($postData['user_id'] == $job->user_id)
				? $translatorRel->user_id
				: $job->user_id;
			
			event(new SessionEnded($job, $notifiedUserId));
			
			return ['status' => 'success'];
		}
		catch(Exception $e) {
			info("Exception occurred: ", [$e->getMessage()]);
			return [];
		}
    }
	
	/**
	 * @param $postData
	 */
    public function customerNotCall($postData)
    {
		try {
			$jobId = $postData["job_id"];
			$completedDate = date('Y-m-d H:i:s');
			
			//Find job using job id and update it
			$job = Job::with('translatorJobRel')->find($jobId);
			$job->end_at = date('Y-m-d H:i:s');
			$job->status = 'not_carried_out_customer';
			$job->save();
			
			//Update translator job
			$tr = $job->translatorJobRel()->whereNull('completed_at')->whereNull('cancel_at')->first();
			$tr->completed_at = $completedDate;
			$tr->completed_by = $tr->user_id;
			$tr->save();
			
			$response['status'] = 'success';
			return $response;
		}
		catch(Exception $e) {
			info("Exception occurred: ", [$e->getMessage()]);
			return [];
		}
    }

    public function getAll(Request $request, $limit = null)
    {
        try {
	        $requestdata = $request->all();
	        $user = $request->authenticatedUser;
	        $consumerType = $user->consumer_type;
	        $roles = Config::get('constants.roles');
	        
	        $allJobs = Job::query();
			
			//Feedback filter
	        if(isset($requestdata['feedback']) && $requestdata['feedback'] != 'false') {
		        $allJobs->where('ignore_feedback', '0');
		        $allJobs->whereHas('feedback', function ($q) {
			        $q->where('rating', '<=', '3');
		        });
		        if (isset($requestdata['count']) && $requestdata['count'] != 'false') return ['count' => $allJobs->count()];
	        }
			
	        //Job id filter
	        if(!empty($requestdata['id'])) {
		        if(is_array($requestdata['id'])) {
			        $allJobs->whereIn('id', $requestdata['id']);
		        }
		        else {
			        $allJobs->where('id', $requestdata['id']);
		        }
	        }
	        
	        //Language filter
	        if(!empty($requestdata['lang'])) {
		        $allJobs->whereIn('from_language_id', $requestdata['lang']);
	        }
	        
	        //Status filter
	        if(!empty($requestdata['status'])) {
		        $allJobs->whereIn('status', $requestdata['status']);
	        }
	        
	        //Job type filter
	        if(!empty($requestdata['job_type'])) {
		        $allJobs->whereIn('job_type', $requestdata['job_type']);
	        }
	        
	        //Time type filter
	        if(isset($requestdata['filter_timetype'])) {
		        if(!empty($requestdata['from'])) {
			        $allJobs->where(($requestdata['filter_timetype'] == 'created' ? 'created_at' : 'due'), '>=', $requestdata["from"]);
		        }
		        if(!empty($requestdata['to'])) {
			        $to = $requestdata["to"] . " 23:59:00";
			        $allJobs->where(($requestdata['filter_timetype'] == 'created' ? 'created_at' : 'due'), '<=', $to);
		        }
		        $allJobs->orderBy(($requestdata['filter_timetype'] == 'created' ? 'created_at' : 'due'), 'desc');
	        }
	        
			//Super admin only filters
	        if($user && $user->user_type == $roles['SUPER_ADMIN_ID']) {
		        
		        //Expired at filter
		        if(isset($requestdata['expired_at']) && $requestdata['expired_at'] != '') {
			        $allJobs->where('expired_at', '>=', $requestdata['expired_at']);
		        }
		        
		        //Will expire at filter
		        if(isset($requestdata['will_expire_at']) && $requestdata['will_expire_at'] != '') {
			        $allJobs->where('will_expire_at', '>=', $requestdata['will_expire_at']);
		        }
		        
		        //Customer email filter
		        if(isset($requestdata['customer_email']) && count($requestdata['customer_email']) && $requestdata['customer_email'] != '') {
			        $users = DB::table('users')->whereIn('email', $requestdata['customer_email'])->get();
			        if($users) {
				        $allJobs->whereIn('user_id', collect($users)->pluck('id')->all());
			        }
		        }
		        
		        //Translator email filter
		        if(isset($requestdata['translator_email']) && count($requestdata['translator_email'])) {
			        $users = DB::table('users')->whereIn('email', $requestdata['translator_email'])->get();
			        if($users) {
				        $allJobIDs = DB::table('translator_job_rel')->whereNull('cancel_at')->whereIn('user_id', collect($users)->pluck('id')->all())->lists('job_id');
				        $allJobs->whereIn('id', $allJobIDs);
			        }
		        }
		        
		        //Physical filter
		        if(isset($requestdata['physical'])) {
			        $allJobs->where('customer_physical_type', $requestdata['physical']);
			        $allJobs->where('ignore_physical', 0);
		        }
		        
		        //Phone filter
		        if(isset($requestdata['phone'])) {
			        $allJobs->where('customer_phone_type', $requestdata['phone']);
			        if(isset($requestdata['physical']))
				        $allJobs->where('ignore_physical_phone', 0);
		        }
		        
		        //Flagged filter
		        if(isset($requestdata['flagged'])) {
			        $allJobs->where('flagged', $requestdata['flagged']);
			        $allJobs->where('ignore_flagged', 0);
		        }
		        
		        //Distance filter
		        if(isset($requestdata['distance']) && $requestdata['distance'] == 'empty') {
			        $allJobs->whereDoesntHave('distance');
		        }
		        
		        //Salary filter
		        if(isset($requestdata['salary']) &&  $requestdata['salary'] == 'yes') {
			        $allJobs->whereDoesntHave('user.salaries');
		        }
		        
		        if(isset($requestdata['count']) && $requestdata['count'] == 'true') {
			        return ['count' => $allJobs->count()];
		        }
		        
		        //Consumer type filter
		        if(isset($requestdata['consumer_type']) && $requestdata['consumer_type'] != '') {
			        $allJobs->whereHas('user.userMeta', function($q) use ($requestdata) {
				        $q->where('consumer_type', $requestdata['consumer_type']);
			        });
		        }
		        
		        //Booking type filter
		        if(isset($requestdata['booking_type'])) {
			        if($requestdata['booking_type'] == 'physical')
				        $allJobs->where('customer_physical_type', 'yes');
			        if($requestdata['booking_type'] == 'phone')
				        $allJobs->where('customer_phone_type', 'yes');
		        }
		        
	        }
			else {
		        $allJobs->where('job_type', '=', ($consumerType == 'RWS' ? 'rws' : 'unpaid'));
		        
		        if(isset($requestdata['customer_email']) && $requestdata['customer_email'] != '') {
			        if($user = DB::table('users')->where('email', $requestdata['customer_email'])->first()) {
				        $allJobs->where('user_id', '=', $user->id);
			        }
		        }
	        }
	        
	        $allJobs->with(['user', 'language', 'feedback.user', 'translatorJobRel.user', 'distance']);
	        $allJobs->orderBy('created_at', 'desc');
			
	        return $limit === 'all' ? $allJobs->get() : $allJobs->paginate(15);
        }
        catch(Exception $e) {
	        info("Exception occurred: ", [$e->getMessage()]);
	        return [];
        }
    }

    public function alerts()
    {
		try {
			$i = 0;
			$diff = [];
			$jobIds = [];
			$sesJobs = [];
			$jobs = Job::all();
			
			foreach($jobs as $job) {
				$sessionTime = explode(':', $job->session_time);
				if(count($sessionTime) >= 3) {
					$diff[$i] = (($sessionTime[0] * 60) + $sessionTime[1]) + ($sessionTime[2] / 60);
					if($diff[$i] >= $job->duration) {
						if($diff[$i] >= $job->duration * 2) {
							$sesJobs[$i] = $job;
						}
					}
					$i++;
				}
			}
			
			//Get session job ids
			foreach($sesJobs as $job) {
				$jobIds [] = $job->id;
			}
			
			$user = Auth::user();
			$requestData = Request::all();
			$allCustomers = User::where('user_type', '1')->pluck('email');
			$allTranslators = User::where('user_type', '2')->pluck('email');
			$languages = Language::where('active', '1')->orderBy('language')->get();
			
			
			if ($user && $user->is(UserRole::SUPER_ADMIN->value)) {
				//Generic query to use in multiple functions
				$allJobs = $this->getJobsData($requestData, __FUNCTION__, $jobIds);
			}
			
			return ['allJobs' => $allJobs, 'languages' => $languages, 'allCustomers' => $allCustomers, 'allTranslators' => $allTranslators, 'requestData' => $requestData];
		}
		catch(Exception $e) {
			info("Exception occurred: ", [$e->getMessage()]);
			return [];
		}
    }

    public function userLoginFailed()
    {
		try {
			$throttles = Throttles::where('ignore', 0)->with('user')->paginate(15);
			
			return ['throttles' => $throttles];
		}
		catch(Exception $e) {
			info("Exception occurred: ", [$e->getMessage()]);
			return [];
		}
    }

    public function bookingExpireNoAccepted()
    {
		try {
			$user = Auth::user();
			$requestData = Request::all();
			$allCustomers = User::where('user_type', '1')->pluck('email');
			$allTranslators = User::where('user_type', '2')->pluck('email');
			$languages = Language::where('active', '1')->orderBy('language')->get();
			
			if($user && ($user->is(UserRole::SUPER_ADMIN->value) || $user->is(UserRole::ADMIN->value))) {
				//Generic query to use in multiple functions
				$allJobs = $this->getJobsData($requestData, __FUNCTION__);
			}
			return ['allJobs' => $allJobs, 'languages' => $languages, 'allCustomers' => $allCustomers, 'allTranslators' => $allTranslators, 'requestData' => $requestData];
		}
		catch(Exception $e) {
			info("Exception occurred: ", [$e->getMessage()]);
			return [];
		}
    }

    public function ignoreExpiring($id)
    {
		try {
			$job = Job::find($id);
			$job->ignore = 1;
			$job->save();
			
			return ['success', 'Changes saved'];
		}
		catch(Exception $e) {
			info("Exception occurred: ", [$e->getMessage()]);
			return [];
		}
    }

    public function ignoreExpired($id)
    {
		try {
			$job = Job::find($id);
			$job->ignore_expired = 1;
			$job->save();
			
			return ['success', 'Changes saved'];
		}
		catch(Exception $e) {
			info("Exception occurred: ", [$e->getMessage()]);
			return [];
		}
    }

    public function ignoreThrottle($id)
    {
        try {
	        $throttle = Throttles::find($id);
	        $throttle->ignore = 1;
	        $throttle->save();
			
	        return ['success', 'Changes saved'];
        }
        catch(Exception $e) {
	        info("Exception occurred: ", [$e->getMessage()]);
	        return [];
        }
    }

    public function reopen($request)
    {
		try {
			$data = array();
			$jobId = $request['jobid'];
			$userId = $request['userid'];
			
			$job = Job::find($jobId)?->toArray();
			
			$data['created_at'] = date('Y-m-d H:i:s');
			$data['will_expire_at'] = TeHelper::willExpireAt($job['due'], $data['created_at']);
			$data['updated_at'] = date('Y-m-d H:i:s');
			$data['user_id'] = $userId;
			$data['job_id'] = $jobId;
			$data['cancel_at'] = Carbon::now();
			
			$dataReOpen = array();
			$dataReOpen['status'] = JobStatus::PENDING->value;
			$dataReOpen['created_at'] = Carbon::now();
			$dataReOpen['will_expire_at'] = TeHelper::willExpireAt($job['due'], $dataReOpen['created_at']);
			
			if($job['status'] != JobStatus::TIMED_OUT->value) {
				//Update job data
				$affectedRows = Job::where('id', '=', $jobId)->update($dataReOpen);
				$newJobId = $jobId;
			}
			else {
				$job['status'] = 'pending';
				$job['created_at'] = Carbon::now();
				$job['updated_at'] = Carbon::now();
				$job['will_expire_at'] = TeHelper::willExpireAt($job['due'], date('Y-m-d H:i:s'));
				$job['updated_at'] = date('Y-m-d H:i:s');
				$job['cust_16_hour_email'] = 0;
				$job['cust_48_hour_email'] = 0;
				$job['admin_comments'] = 'This booking is a reopening of booking #' . $jobId;
				
				//Create new job
				$affectedRows = Job::create($job);
				$newJobId = $affectedRows['id'];
			}
			
			//Add cancel_at date time
			Translator::where('job_id', $jobId)->whereNull('cancel_at')->update(['cancel_at' => $data['cancel_at']]);
			//Create new translator
			$Translator = Translator::create($data);
			
			if(isset($affectedRows)) {
				//Send notification to admin on job cancellation
				$this->sendNotificationByAdminCancelJob($newJobId);
				return ["Tolk cancelled!"];
			}
			else {
				return ["Please try again!"];
			}
		}
		catch(Exception $e) {
			info("Exception occurred: ", [$e->getMessage()]);
			return [];
		}
    }
	
	private function getJobsData($requestData, $functionName, $jobIds = array())
	{
		return DB::table('jobs')
			->join('languages', 'jobs.from_language_id', '=', 'languages.id')
			->select('jobs.*', 'languages.language')
			->when($functionName === 'bookingExpireNoAccepted', function($query) {
				$query->where('jobs.status', 'pending')
					->where('jobs.ignore_expired', 0)
					->where('jobs.due', '>=', Carbon::now());
			})
			->when($functionName === 'alerts', function($query) use($jobIds) {
				$query->whereIn('jobs.id', $jobIds)
					->where('jobs.ignore', 0);
			})
			->when(!empty($requestData['lang']), function($query) use($requestData) {
				$query->whereIn('jobs.from_language_id', $requestData['lang']);
			})
			->when(!empty($requestData['status']), function($query) use($requestData) {
				$query->whereIn('jobs.status', $requestData['status']);
			})
			->when(!empty($requestData['customer_email']), function($query) use($requestData) {
				if($userId = DB::table('users')->where('email', $requestData['customer_email'])->value('id')) {
					$query->where('jobs.user_id', $userId);
				}
			})
			->when(!empty($requestData['translator_email']), function($query) use($requestData) {
				$userId = DB::table('users')->where('email', $requestData['translator_email'])->value('id');
				if($userId) {
					$jobIds = DB::table('translator_job_rel')->where('user_id', $userId)->pluck('job_id');
					$query->whereIn('jobs.id', $jobIds);
				}
			})
			->when(!empty($requestData['filter_timetype']), function($query) use($requestData) {
				$timeField = $requestData['filter_timetype'] === "created" ? 'jobs.created_at' : 'jobs.due';
				if (!empty($requestData['from'])) {
					$query->where($timeField, '>=', $requestData['from']);
				}
				if (!empty($requestData['to'])) {
					$query->where($timeField, '<=', $requestData['to'] . " 23:59:00");
				}
				$query->orderBy($timeField, 'desc');
			})
			->when(!empty($requestData['job_type']), function($query) use($requestData) {
				$query->whereIn('jobs.job_type', $requestData['job_type']);
			})
			->when(empty($requestData['filter_timetype']), function($query) {
				$query->orderBy('jobs.created_at', 'desc');
			})
			->paginate(15);
	}
	
	private function sendCustomNotificationToSpecificUsers($type, $job, $user, $text)
	{
		try {
			$data = array();
			$data['notification_type'] = $type;
			$msgText = array(
				"en" => $text
			);
			
			if(isNeedToSendPush($user->id)) {
				$this->sendPushNotificationToSpecificUsers(array($user), $job->id, $data, $msgText, isNeedToDelayPush($user->id));
			}
		}
		catch(Exception $e) {
			info("Exception occurred: ", [$e->getMessage()]);
			return [];
		}
	}
}