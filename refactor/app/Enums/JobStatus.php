<?php

namespace DTApi\Enums;

enum JobStatus: string
{
	case PENDING = 'pending';
	case ASSIGNED = 'assigned';
	case STARTED = 'started';
	case COMPLETED = 'completed';
	case WITHDRAW_BEFORE_24 = 'withdrawbefore24';
	case WITHDRAW_AFTER_24 = 'withdrawafter24';
	case TIMED_OUT = 'timedout';
}