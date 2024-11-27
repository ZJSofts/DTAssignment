BookingController
_________________
-----------------

-) Amazing Code
--->  Line # 27, use of Dependency Injection. Dependency Injection makes code more flexible and testable. Laravel encourages the
      use of Dependency Injection. Laravel service container manages class dependencies and perform Dependency Injections.


-) Bad Code
--->  There is no use of comments in the whole file which is not a good practice.
--->  There is no use of try catch in the whole file which can cause problems.
--->  Line # 6, unused include. This will generate confusions and make the code harder to read. It will slightly impact on the
      performance.
--->  Line # [20, 28, ...], Property Naming. Property name should be $bookingRepository. If we need multiple repositories, then
      explicit property naming is good approach.
--->  Naming convention should be same in overall project. Like mostly snake naming convention is used for variables but somewhere
      camelCase is used. Global best practices and laravel also used camelCase naming convention for variables.
--->  Spacing between lines should be equal.
--->  Function starting bracket and if else or loop starting brackets should be on different places to make difference between
      them. Like Laravel place starting bracket of a function to the next line where the function name is written. So, we need
      to place the starting bracket of if else or loop in the same line and this is a best practice.
--->  Line # 43, comparison of same thing will multiple values. If a same thing is being compared with multiple values, then its
      better to make the array of that values and check using in_array() method. You can easily add or remove values in that
      array and there is no code repetition.
--->  Line # 66, when we are going to perform store on a table then firstly we need to validate the data that the data is correct
      or not. And I have created a custom request named StoreBookingRequest to validate the data. Creating a custom request and
      passed it in the parameter of the function is to follow the Single Responsibility Principle. I returned boolean true from
      StoreBookingRequest authorize method, which means if validation fails it will automatically returns 422 status code and
      the code of controller will not be executed.
--->  Line # 84, variable names should be more specific to avoid confusions.


-) Terrible Code
--->  Line # 40, use of env for constant type values. env is used for password, secret keys and configurations of the application.
      This is a very bad practice to place constant type values in that. We need to place the ADMIN_ROLE_ID and SUPERADMIN_ROLE_ID
      in some sort of constant files. In Laravel, best practice for constants is a constants.php file under config folder.
--->  Line # 43, use of __(double underscore). Avoid using of __(double underscore) as it refers to the magic methods of PHP.
      PHP uses __(double underscore) in the start of its magic methods, so it will cause confusion that this is a custom or
      built-in magic method.



BookingRepository
_________________
-----------------

-) Amazing Code
--->  Line # 47, use of Dependency Injection. Dependency Injection makes code more flexible and testable. Laravel encourages the
      use of Dependency Injection. Laravel service container manages class dependencies and perform Dependency Injections.


-) Bad Code
--->  There is no use of comments in the whole file which is not a good practice.
--->  There is no use of try catch in the whole file which can cause problems.
--->  Line # 64 & 67, it's better to make constants of the strings which we need to use in multiple places. As if there is
      a change in that string we only need to change in that constant value, otherwise we need to replace that in the whole
      project which cause many problems.


-) Terrible Code
--->  Line # [48, 50, 51], we need to make logger re-usable and centralized. This will also make code clean and manageable.
      We can move this to helper file to make things more generic.
--->  lists() method is used in many places, this method is changed to pluck() in Laravel 5.3 and later versions. So we need
      to use pluck() instead of lists(). Both will get specific column data in the form of an array.
--->  Event::fire() method is deprecated in Laravel 5.8 and replaced by event() helper function. Both methods used to dispatch
      event. I'm replacing Event::fire() with event().
--->  JobToData() method and sendNotificationByAdminCancelJob() method query is mostly same. so, I have made them generic in
      JobToData() method to avoid code repetition.
--->  alerts() method and bookingExpireNoAccepted() method query is mostly same. so, I have made them generic in getJobsData()
      method to avoid code repetition.
--->  endJob() method and jobEnd() method code is 99% same. So, I have removed jobEnd() method as this code is a subset of
      endJob() method code.
--->  Line # 480, sendNotificationTranslator() method all optional parameters should be after the required parameters in
      parameters sequence. The sequence of parameters should be all the required parameters and then optional parameters.
--->  sendChangedDateNotification() method and sendChangedLangNotification() method code is almost same. so, I have created
      a new sendChangedNotification() method to make code more generic.
--->  The chuck of code of sendPushNotificationToSpecificUsers() is duplicated in many methods. So I have created a custom
      method sendCustomNotificationToSpecificUsers() to reduce redundancy and repetition in code.
--->  Curl request in sendPushNotificationToSpecificUsers() method is removed to make it generic and reusable and I have created
      a new service named CURLService and used this service as dependency injection in BookingRepository.




New files created for:
---> config/constants.php for generic constants
---> app/Enums/* for named constants related to models
---> app/Http/Requests/* for custom requests to validate data before creating and updating
---> app/Support/Helper.php for global helper methods to access anywhere in the application
---> app/Services/* for custom services to use as dependency injection
