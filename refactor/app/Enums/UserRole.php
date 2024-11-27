<?php

namespace DTApi\Enums;

enum UserRole: string
{
	case CUSTOMER = 'customer';
	case TRANSLATOR = 'translator';
	case ADMIN = 'admin';
	case SUPER_ADMIN = 'superadmin';
}