<?php

namespace DTApi\Http\Controllers;

use Exception;
use DTApi\Models\Job;
use DTApi\Models\Distance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use DTApi\Repository\BookingRepository;
use App\Http\Requests\Job\StoreJobEmailRequest;
use App\Http\Requests\Booking\StoreBookingRequest;
use App\Http\Requests\Booking\UpdateBookingRequest;

/**
 * Class BookingController
 * @package DTApi\Http\Controllers
 */
class BookingController extends Controller
{

    /**
     * @var BookingRepository
     */
    protected $bookingRepository;

    /**
     * BookingController constructor.
     * @param BookingRepository $bookingRepository
     */
    public function __construct(BookingRepository $bookingRepository)
    {
        $this->bookingRepository = $bookingRepository;
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function index(Request $request)
    {
	    try {
		    $response = [];
		    
		    //Get role ids from constants file
		    $roles = Config::get('constants.roles');
		    $roleIds = array($roles['SUPER_ADMIN_ID'], $roles['ADMIN_ID']);
		    
		    if($userId = $request->get('user_id')) {
			    //Get data from jobs table against specific user id
			    $response = $this->bookingRepository->getUsersJobs($userId);
		    }
		    else if(in_array($request->authenticatedUser->user_type, $roleIds)) {
			    //Get data from jobs table against user roles
			    $response = $this->bookingRepository->getAll($request);
		    }
		    
		    return response($response);
	    }
		catch(Exception $e) {
			info("Exception occurred: ", [$e->getMessage()]);
			return response('Some error occurred!');
		}
    }

    /**
     * @param $id
     * @return mixed
     */
    public function show($id)
    {
		try {
			//Get specific job data
			$job = $this->bookingRepository->with(['translatorJobRel.user'])->find($id);
			
			return response($job);
		}
		catch(Exception $e) {
			info("Exception occurred: ", [$e->getMessage()]);
			return response('Some error occurred!');
		}
    }

    /**
     * @param StoreBookingRequest $request
     * @return mixed
     */
    public function store(StoreBookingRequest $request)
    {
        try {
	        $data = $request->all();
	        //Store job data
	        $response = $this->bookingRepository->store($request->authenticatedUser, $data);
	        
	        return response($response);
        }
        catch(Exception $e) {
	        info("Exception occurred: ", [$e->getMessage()]);
	        return response('Some error occurred!');
        }
    }

    /**
     * @param $id
     * @param UpdateBookingRequest $request
     * @return mixed
     */
    public function update($id, UpdateBookingRequest $request)
    {
	    try {
		    //Get data from request except some columns
		    $data = $request->except(['_token', 'submit']);
		    $authenticatedUser = $request->authenticatedUser;
		    
		    //Update job data
		    $response = $this->bookingRepository->updateJob($id, $data, $authenticatedUser);
		    
		    return response($response);
	    }
	    catch(Exception $e) {
		    info("Exception occurred: ", [$e->getMessage()]);
		    return response('Some error occurred!');
	    }
    }

    /**
     * @param StoreJobEmailRequest $request
     * @return mixed
     */
    public function immediateJobEmail(StoreJobEmailRequest $request)
    {
	    try {
		    $data = $request->all();
		    //Store job email in jobs table
		    $response = $this->bookingRepository->storeJobEmail($data);
		    
		    return response($response);
	    }
	    catch(Exception $e) {
		    info("Exception occurred: ", [$e->getMessage()]);
		    return response('Some error occurred!');
	    }
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function getHistory(Request $request)
    {
		try {
			if($userId = $request->get('user_id')) {
				//Get user job history
				$response = $this->bookingRepository->getUsersJobsHistory($userId, $request);
				return response($response);
			}
			
			return response(null);
		}
		catch(Exception $e) {
			info("Exception occurred: ", [$e->getMessage()]);
			return response('Some error occurred!');
		}
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function acceptJob(Request $request)
    {
		try {
			$data = $request->all();
			$authenticatedUser = $request->authenticatedUser;
			
			$response = $this->bookingRepository->acceptJob($data, $authenticatedUser);
			
			return response($response);
		}
		catch(Exception $e) {
			info("Exception occurred: ", [$e->getMessage()]);
			return response('Some error occurred!');
		}
    }

    public function acceptJobWithId(Request $request)
    {
		try {
			$data = $request->get('job_id');
			$authenticatedUser = $request->authenticatedUser;
			//Accept job with specific id
			$response = $this->bookingRepository->acceptJobWithId($data, $authenticatedUser);
			
			return response($response);
		}
		catch(Exception $e) {
			info("Exception occurred: ", [$e->getMessage()]);
			return response('Some error occurred!');
		}
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function cancelJob(Request $request)
    {
        try {
	        $data = $request->all();
	        $authenticatedUser = $request->authenticatedUser;
	        
	        $response = $this->bookingRepository->cancelJobAjax($data, $authenticatedUser);
	        
	        return response($response);
        }
        catch(Exception $e) {
	        info("Exception occurred: ", [$e->getMessage()]);
	        return response('Some error occurred!');
        }
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function endJob(Request $request)
    {
		try {
			$data = $request->all();
			$response = $this->bookingRepository->endJob($data);
			
			return response($response);
		}
		catch(Exception $e) {
			info("Exception occurred: ", [$e->getMessage()]);
			return response('Some error occurred!');
		}
    }

    public function customerNotCall(Request $request)
    {
        try {
	        $data = $request->all();
	        $response = $this->bookingRepository->customerNotCall($data);
	        
	        return response($response);
        }
        catch(Exception $e) {
	        info("Exception occurred: ", [$e->getMessage()]);
	        return response('Some error occurred!');
        }
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function getPotentialJobs(Request $request)
    {
		try {
			$authenticatedUser = $request->authenticatedUser;
			$response = $this->bookingRepository->getPotentialJobs($authenticatedUser);
			
			return response($response);
		}
		catch(Exception $e) {
			info("Exception occurred: ", [$e->getMessage()]);
			return response('Some error occurred!');
		}
    }

    public function distanceFeed(Request $request)
    {
		try {
			$data = $request->all();
			//Assign request data to respective variables
			$distance = (isset($data['distance']) && trim($data['distance']) != "") ? $data['distance'] : "";
			$time = (isset($data['time']) && trim($data['time']) != "") ? $data['time'] : "";
			$jobId = (isset($data['job_id']) && trim($data['job_id']) != "") ? $data['job_id'] : null;
			$session = (isset($data['session_time']) && trim($data['session_time']) != "") ? $data['session_time'] : "";
			$adminComment = (isset($data['admin_comment']) && trim($data['admin_comment']) != "") ? $data['admin_comment'] : "";
			$flagged = $data['flagged'] == 'true' ? 'yes' : 'no';
			$manuallyHandled = $data['manually_handled'] == 'true' ? 'yes' : 'no';
			$byAdmin = $data['by_admin'] == 'true' ? 'yes' : 'no';
			
			if($time || $distance) {
				$distanceAffectedRows = Distance::where('job_id', '=', $jobId)->update(array('distance' => $distance, 'time' => $time));
			}
			
			if($adminComment || $session || $flagged || $manuallyHandled || $byAdmin) {
				$jobAffectedRows = Job::where('id', '=', $jobId)->update(array('admin_comments' => $adminComment, 'flagged' => $flagged, 'session_time' => $session, 'manually_handled' => $manuallyHandled, 'by_admin' => $byAdmin));
			}
			
			return response('Record updated!');
		}
		catch(Exception $e) {
			info("Exception occurred: ", [$e->getMessage()]);
			return response('Some error occurred!');
		}
    }

    public function reopen(Request $request)
    {
		try {
			$data = $request->all();
			$response = $this->bookingRepository->reopen($data);
			
			return response($response);
		}
		catch(Exception $e) {
			info("Exception occurred: ", [$e->getMessage()]);
			return response('Some error occurred!');
		}
    }

    public function resendNotifications(Request $request)
    {
		try {
			$data = $request->all();
			//Find job using id
			$job = $this->bookingRepository->find($data['job_id']);
			//Create job data
			$jobData = $this->bookingRepository->jobToData($job);
			//Send notification
			$this->bookingRepository->sendNotificationTranslator($job, '*', $jobData);
			
			return response(['success' => 'Push sent']);
		}
		catch(Exception $e) {
			info("Exception occurred: ", [$e->getMessage()]);
			return response('Some error occurred!');
		}
    }

    /**
     * Sends SMS to Translator
     * @param Request $request
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Symfony\Component\HttpFoundation\Response
     */
    public function resendSMSNotifications(Request $request)
    {
		try {
			$data = $request->all();
			//Find job using id
			$job = $this->bookingRepository->find($data['job_id']);
			//Send SMS notification
            $this->bookingRepository->sendSMSNotificationToTranslator($job);
			
            return response(['success' => 'SMS sent']);
        }
        catch(Exception $e) {
	        info("Exception occurred: ", [$e->getMessage()]);
	        return response('Some error occurred!');
        }
    }

}
