<?php

namespace DTApi\Http\Requests\Job;

use Illuminate\Foundation\Http\FormRequest;

class StoreJobEmailRequest extends FormRequest
{
	public function authorize()
	{
		return true;
	}
	
	public function rules()
	{
		return [
			'email' => 'required|email',
		];
	}
}