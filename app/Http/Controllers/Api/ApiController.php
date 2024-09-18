<?php

namespace App\Http\Controllers\Api;


use App\Http\Controllers\Controller;
use App\Repositories\ClassSection\ClassSectionInterface;
use App\Repositories\ExamResult\ExamResultInterface;
use App\Repositories\Gallery\GalleryInterface;
use App\Repositories\Grades\GradesInterface;
use App\Repositories\Holiday\HolidayInterface;
use App\Repositories\Leave\LeaveInterface;
use App\Repositories\LeaveDetail\LeaveDetailInterface;
use App\Repositories\LeaveMaster\LeaveMasterInterface;
use App\Repositories\Medium\MediumInterface;
use App\Repositories\PaymentConfiguration\PaymentConfigurationInterface;
use App\Repositories\PaymentTransaction\PaymentTransactionInterface;
use App\Repositories\SchoolSetting\SchoolSettingInterface;
use App\Repositories\SessionYear\SessionYearInterface;
use App\Repositories\Student\StudentInterface;
use App\Repositories\User\UserInterface;
use App\Services\CachingService;
use App\Services\Payment\PaymentService;
use App\Services\ResponseService;
use Auth;
use DB;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use PDF;
use Yabacon\Paystack\Exception\ApiException;
use Stripe\Exception\ApiErrorException;
use Throwable;
use App\Repositories\Files\FilesInterface;
use Illuminate\Support\Facades\Log;

class ApiController extends Controller
{
    private CachingService $cache;
    private HolidayInterface $holiday;
    private StudentInterface $student;
    private PaymentConfigurationInterface $paymentConfiguration;
    private PaymentTransactionInterface $paymentTransaction;
    private GalleryInterface $gallery;
    private SessionYearInterface $sessionYear;
    private LeaveDetailInterface $leaveDetail;
    private LeaveMasterInterface $leaveMaster;
    private LeaveInterface $leave;
    private UserInterface $user;
    private MediumInterface $medium;
    private ClassSectionInterface $classSection;
    private FilesInterface $files;
    private ExamResultInterface $examResult;
    private GradesInterface $grade;
    

    public function __construct(CachingService $cache, HolidayInterface $holiday, StudentInterface $student, PaymentConfigurationInterface $paymentConfiguration, PaymentTransactionInterface $paymentTransaction, GalleryInterface $gallery, SessionYearInterface $sessionYear, LeaveDetailInterface $leaveDetail, LeaveMasterInterface $leaveMaster, LeaveInterface $leave, UserInterface $user, MediumInterface $medium, ClassSectionInterface $classSection, ExamResultInterface $examResult, GradesInterface $grade, FilesInterface $files)

    {
        $this->cache = $cache;
        $this->holiday = $holiday;
        $this->student = $student;
        $this->paymentConfiguration = $paymentConfiguration;
        $this->paymentTransaction = $paymentTransaction;
        $this->gallery = $gallery;
        $this->sessionYear = $sessionYear;
        $this->leaveDetail = $leaveDetail;
        $this->leaveMaster = $leaveMaster;
        $this->leave = $leave;
        $this->user = $user;
        $this->medium = $medium;
        $this->classSection = $classSection;

        $this->files = $files;

        $this->examResult = $examResult;
        $this->grade = $grade;

    }

    public function logout(Request $request)
    {
        try {
            $user = $request->user();
            $user->fcm_id = '';
            $user->save();
            $user->currentAccessToken()->delete();
            ResponseService::successResponse('Logout Successfully done');
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }

    public function getHolidays(Request $request)
    {
        try {
            // $query->whereDate('date', '>=',$sessionYear->start_date)
            //     ->whereDate('date', '<=',$sessionYear->end_date);

            $sessionYear = $this->cache->getDefaultSessionYear();
            if ($request->child_id) {
                $child = $this->student->findById($request->child_id);
                $data = $this->holiday->builder()->where('school_id', $child->user->school_id);
            } else {
                $data = $this->holiday->builder();
            }

            $data = $data->whereDate('date', '>=',$sessionYear->start_date)
                ->whereDate('date', '<=',$sessionYear->end_date)->get();
            
            ResponseService::successResponse("Holidays Fetched Successfully", $data);
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }

    public function getSettings(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'type' => 'required|in:privacy_policy,contact_us,about_us,terms_condition,app_settings,fees_settings'
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            $systemSettings = $this->cache->getSystemSettings();
            if ($request->type == "app_settings") {
                $data = array(
                    'app_link'                 => $systemSettings['app_link'] ?? "",
                    'ios_app_link'             => $systemSettings['ios_app_link'] ?? "",
                    'app_version'              => $systemSettings['app_version'] ?? "",
                    'ios_app_version'          => $systemSettings['ios_app_version'] ?? "",
                    'force_app_update'         => $systemSettings['force_app_update'] ?? "",
                    'app_maintenance'          => $systemSettings['app_maintenance'] ?? "",
                    'teacher_app_link'         => $systemSettings['teacher_app_link'] ?? "",
                    'teacher_ios_app_link'     => $systemSettings['teacher_ios_app_link'] ?? "",
                    'teacher_app_version'      => $systemSettings['teacher_app_version'] ?? "",
                    'teacher_ios_app_version'  => $systemSettings['teacher_ios_app_version'] ?? "",
                    'teacher_force_app_update' => $systemSettings['teacher_force_app_update'] ?? "",
                    'teacher_app_maintenance'  => $systemSettings['teacher_app_maintenance'] ?? "",
                    'tagline'                  => $systemSettings['tag_line'] ?? "",
                );
            } else {
                $data = isset($systemSettings[$request->type]) ? htmlspecialchars_decode($systemSettings[$request->type]) : "";
            }
            ResponseService::successResponse("Data Fetched Successfully", $data);
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }

    protected function forgotPassword(Request $request)
    {
        $request->validate([
            'email' => "required|email"
        ]);
        try {
            $response = Password::sendResetLink(['email' => $request->email]);
            if ($response == Password::RESET_LINK_SENT) {
                ResponseService::successResponse("Forgot Password email send successfully");
            } else {
                ResponseService::errorResponse("Cannot send Reset Password Link.Try again later", null, config('constants.RESPONSE_CODE.RESET_PASSWORD_FAILED'));
            }
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }

    protected function changePassword(Request $request)
    {
        $request->validate([
            'current_password'     => 'required',
            'new_password'         => 'required|min:6',
            'new_confirm_password' => 'same:new_password',
        ]);

        try {
            $user = $request->user();
            if (Hash::check($request->current_password, $user->password)) {
                $user->update(['password' => Hash::make($request->new_password)]);
                ResponseService::successResponse("Password Changed successfully.");
            } else {
                ResponseService::errorResponse("Invalid Password", null, config('constants.RESPONSE_CODE.INVALID_PASSWORD'));
            }
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }

    public function getPaymentMethod(Request $request)
    {
        if (Auth::user()->hasRole('Guardian')) {
            $validator = Validator::make($request->all(), [
                'child_id' => 'required|numeric',
            ]);
            if ($validator->fails()) {
                ResponseService::validationError($validator->errors()->first());
            }
        }
        try {

            $response = $this->paymentConfiguration->builder()->select('payment_method', 'status')->pluck('status', 'payment_method');
            ResponseService::successResponse("Payment Details Fetched", $response);
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }

    public function getPaymentConfirmation(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required'
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            $paymentTransaction = app(PaymentTransactionInterface::class)->builder()->where('id', $request->id)->first();
            if (empty($paymentTransaction)) {
                ResponseService::errorResponse("No Data Found");
            }
            
            $data = PaymentService::create($paymentTransaction->payment_gateway, $paymentTransaction->school_id)->retrievePaymentIntent($paymentTransaction->order_id);

            $data = PaymentService::formatPaymentIntent($paymentTransaction->payment_gateway, $data);

            // Success
            ResponseService::successResponse("Payment Details Fetched", $data, ['payment_transaction' => $paymentTransaction]);
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }

    public function getPaymentTransactions(Request $request)
    {
        Log::channel('custom')->error('getPaymentTransactions called');
        $validator = Validator::make($request->all(), [
            'latest_only' => 'nullable|boolean'
        ]);

        if ($validator->fails()) {
            Log::channel('custom')->error('getPaymentTransactions called 1111');
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            Log::channel('custom')->error('getPaymentTransactions called 2222');
            $paymentTransactions = app(PaymentTransactionInterface::class)->builder();
            if ($request->latest_only) {
                $paymentTransactions->where('created_at', '>', Carbon::now()->subMinutes(30)->toDateTimeString());
            }
            $paymentTransactions = $paymentTransactions->with('school')->orderBy('id', 'DESC')->get();
            Log::channel('custom')->error('getPaymentTransactions called 3333');
            $schoolSettings = app(SchoolSettingInterface::class)->builder()
                ->where(function ($q) {
                    $q->where('name', 'currency_code')->orWhere('name', 'currency_symbol');
                })->whereIn('school_id', $paymentTransactions->pluck('school_id'))->get();
            Log::channel('custom')->error('getPaymentTransactions called 4444');

            Log::channel('custom')->error('paymentTransactions called' . json_encode($paymentTransactions));

            $paymentTransactions = collect($paymentTransactions)->map(function ($data) use ($schoolSettings) {

                Log::channel('custom')->error('paymentTransactions data' . json_encode($data));

                $getSchoolSettings = $schoolSettings->filter(function ($settings) use ($data) {
                    return $settings->school_id == $data->school_id;
                })->pluck('data', 'name');
                $data->currency_code = $getSchoolSettings['currency_code'] ?? '';
                $data->currency_symbol = $getSchoolSettings['currency_symbol'] ?? '';
                if ($data->payment_status == "pending") {
                    try {
                        Log::channel('custom')->error('paymentTransactions data->payment_status' . json_encode($data->payment_status));
                        $paymentIntent = PaymentService::create($data->payment_gateway, $data->school_id)->retrievePaymentIntent($data->order_id);
                        Log::channel('custom')->error('payment transactions paymentIntent:' . json_encode($paymentIntent));
                        $paymentIntent = PaymentService::formatPaymentIntent($data->payment_gateway, $paymentIntent);
                        Log::channel('custom')->error('payment transactions format payment intent paymentIntent:' . json_encode($paymentIntent));
                    } catch (ApiErrorException) {
                        Log::channel('custom')->error('payment transactions api error');
                        $this->paymentTransaction->update($data->id, ['payment_status' => "failed", 'school_id' => $data->school_id]);
                        Log::channel('custom')->error('payment transactions api error');
                    } catch (ApiException) {
                        Log::channel('custom')->error('ApiException payment transactions api error');
                        $this->paymentTransaction->update($data->id, ['payment_status' => "failed", 'school_id' => $data->school_id]);
                        Log::channel('custom')->error('payment transactions api error');
                    }

                    Log::channel('custom')->error('payment transactions 1111');
                    if (!empty($paymentIntent) && $paymentIntent['status'] != "pending") {
                        Log::channel('custom')->error('payment transactions 2222');
                        $this->paymentTransaction->update($data->id, ['payment_status' => $paymentIntent['status'] ?? "failed", 'school_id' => $data->school_id]);
                        Log::channel('custom')->error('payment transactions 3333');
                    }
                }
                Log::channel('custom')->error('payment transactions data:' . json_encode($data));
                return $data;
            });

            ResponseService::successResponse("Payment Transactions Fetched", $paymentTransactions);
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }

    public function getGallery(Request $request)
    {
        try {
            if ($request->gallery_id) {
                $data = $this->gallery->builder()->with('file')->where('id', $request->gallery_id)->first();
            } else {
                if ($request->child_id) {
                    $child = $this->student->findById($request->child_id);
                    $data = $this->gallery->builder()->with('file')->where('school_id', $child->user->school_id);
                    if ($request->session_year_id) {
                        $data = $data->where('session_year_id', $request->session_year_id);
                    }
                    $data = $data->get();
                } else {
                    if ($request->session_year_id) {
                        $data = $this->gallery->builder()->with('file')->where('session_year_id', $request->session_year_id);
                    } else {
                        $data = $this->gallery->builder()->with('file');
                    }
                    $data = $data->get();
                }
            }
            ResponseService::successResponse("Gallery Fetched Successfully", $data);
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }

    public function getSessionYear(Request $request)
    {
        try {
            if ($request->child_id) {
                $child = $this->student->findById($request->child_id);
                $data = $this->sessionYear->builder()->where('school_id', $child->user->school_id)->get();
            } else {
                $data = $this->sessionYear->builder()->get();
            }
            ResponseService::successResponse("Session Year Fetched Successfully", $data);
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }

    public function getLeaves(Request $request)
    {
        ResponseService::noFeatureThenSendJson('Staff Leave Management');
        try {
            $leave = $this->leaveDetail->builder()->with('leave:id,user_id', 'leave.user:id,first_name,last_name,image', 'leave.user.roles')
                ->whereHas('leave', function ($q) {
                    $q->where('status', 1);
                });
            if ($request->type == 0 || $request->type == null) {
                $leave->whereDate('date', '<=', Carbon::now()->format('Y-m-d'))->whereDate('date', '>=', Carbon::now()->format('Y-m-d'));
            }
            if ($request->type == 1) {
                $tomorrow_date = Carbon::now()->addDay()->format('Y-m-d');
                $leave->whereDate('date', '<=', $tomorrow_date)->whereDate('date', '>=', $tomorrow_date);
            }
            if ($request->type == 2) {
                $upcoming_date = Carbon::now()->addDays(1)->format('Y-m-d');
                $leave->whereDate('date', '>', $upcoming_date);
            }
            $leave = $leave->orderBy('date', 'ASC')->get()->append(['leave_date']);
            ResponseService::successResponse("Data Fetched Successfully", $leave);
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }

    public function applyLeaves(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'reason'  => 'required',
            'files.*' => 'nullable',
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            DB::beginTransaction();
            $sessionYear = $this->cache->getDefaultSessionYear();
            $leaveMaster = $this->leaveMaster->builder()->where('session_year_id', $sessionYear->id)->first();

            if (!$leaveMaster) {
                ResponseService::errorResponse("Kindly contact the school admin to update settings for continued access.");
            }

            $public_holiday = $this->holiday->builder()->whereDate('date', '>=', $sessionYear->start_date)->whereDate('date', '<=', $sessionYear->end_date)->get()->pluck('date')->toArray();

            $dates = array_column($request->leave_details, 'date');
            $from_date = min($dates);
            $to_date = max($dates);

            $leave_data = [
                'user_id' => Auth::user()->id,
                'reason' => $request->reason,
                'from_date' => $from_date,
                'to_date' => $to_date,
                'status' => 0,
                'leave_master_id' => $leaveMaster->id
            ];

            $holidays = explode(',', $leaveMaster->holiday);

            $leave = $this->leave->create($leave_data);
            foreach ($request->leave_details as $key => $leaves) {
                $day = date('l', strtotime($leaves['date']));
                if (!in_array($day, $holidays) && !in_array($leaves['date'], $public_holiday)) {
                    $data[] = [
                        'leave_id' => $leave->id,
                        'date' => $leaves['date'],
                        'type' => $leaves['type']
                    ];
                }
            }
            if ($request->hasFile('files')) {
                $fileData = []; // Empty FileData Array
                // Create A File Model Instance
                $leaveModelAssociate = $this->files->model()->modal()->associate($leave); // Get the Association Values of File with Assignment
            
                foreach ($request->file('files') as $file_upload) {
                    // Create Temp File Data Array
                    $tempFileData = [
                        'modal_type' => $leaveModelAssociate->modal_type,
                        'modal_id'   => $leaveModelAssociate->modal_id,
                        'file_name'  => $file_upload->getClientOriginalName(),
                        'type'       => 1,
                        'file_url'   => $file_upload->store('files', 'public') // Store file and get the file path
                    ];
                    $fileData[] = $tempFileData; // Store Temp File Data in Multi-Dimensional File Data Array
                }
                $this->files->createBulk($fileData); // Store File Data
            }

            $this->leaveDetail->createBulk($data);

            $user = $this->user->builder()->whereHas('roles.permissions', function ($q) {
                $q->where('name', 'approve-leave');
            })->pluck('id');

            $type = "Leave";
            $title = Auth::user()->full_name . ' has submitted a new leave request.';
            $body = $request->reason;
            send_notification($user, $title, $body, $type);

            DB::commit();
            ResponseService::successResponse("Data Stored Successfully");
        } catch (Throwable $e) {
            if (Str::contains($e->getMessage(), [
                'does not exist','file_get_contents'
            ])) {
                DB::commit();
                ResponseService::warningResponse("Data Stored successfully. But App push notification not send.");
            } else {
                ResponseService::logErrorResponse($e);
                ResponseService::errorResponse();
            }
        }
    }

    public function getMyLeaves(Request $request)
    {
        ResponseService::noFeatureThenSendJson('Staff Leave Management');
        $validator = Validator::make($request->all(), [
            'month'  => 'in:1,2,3,4,5,6,7,8,9,10,11,12',
            'status' => 'in:0,1,2'
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            $sessionYear = $this->cache->getDefaultSessionYear();
            $leaveMaster = $this->leaveMaster->builder();
            $sql = $this->leave->builder()->with('leave_detail')->where('user_id', Auth::user()->id)->withCount(['leave_detail as full_leave' => function ($q) {
                $q->where('type', 'Full');
            }])->withCount(['leave_detail as half_leave' => function ($q) {
                $q->whereNot('type', 'Full');
            }]);

            if ($request->session_year_id) {
                $sql->whereHas('leave_master', function ($q) use ($request) {
                    $q->where('session_year_id', $request->session_year_id);
                });
                $leaveMaster->where('session_year_id', $request->session_year_id);
            } else {
                $sql->whereHas('leave_master', function ($q) use ($sessionYear) {
                    $q->where('session_year_id', $sessionYear->id);
                });
                $leaveMaster->where('session_year_id', $sessionYear->id);
            }
            if (isset($request->status)) {
                $sql->where('status', $request->status);
            }
            if ($request->month) {
                $sql->whereHas('leave_detail', function ($q) use ($request) {
                    $q->whereMonth('date', $request->month);
                });
            }
            $leaveMaster = $leaveMaster->first();
            $sql = $sql->get();
            $sql = $sql->map(function ($sql) {
                $total_leaves = ($sql->half_leave / 2) + $sql->full_leave;
                $sql->days = $total_leaves;
                return $sql;
            });
            $data = [
                'monthly_allowed_leaves' => $leaveMaster->leaves,
                'taken_leaves' => $sql->sum('days'),
                'leave_details' => $sql
            ];

            ResponseService::successResponse("Data Fetched Successfully", $data);
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }

    public function deleteLeaves(Request $request)
    {
        ResponseService::noFeatureThenSendJson('Staff Leave Management');
        $validator = Validator::make($request->all(), [
            'leave_id'  => 'required',
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            DB::beginTransaction();
            $leave = $this->leave->findById($request->leave_id);
            if ($leave->status != 0) {
                ResponseService::errorResponse("You cannot delete this leave");
            }
            $this->leave->deleteById($request->leave_id);
            DB::commit();
            ResponseService::successResponse("Data Deleted Successfully");
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }

    public function getStaffLeaveDetail(Request $request)
    {
        ResponseService::noFeatureThenSendJson('Staff Leave Management');
        $validator = Validator::make($request->all(), [
            'staff_id' => 'required',
            'month'  => 'in:1,2,3,4,5,6,7,8,9,10,11,12',
            'status' => 'in:0,1,2'
        ]);

        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            $sessionYear = $this->cache->getDefaultSessionYear();
            $leaveMaster = $this->leaveMaster->builder();
            $sql = $this->leave->builder()->with('leave_detail','file')->withCount(['leave_detail as full_leave' => function ($q) {
                $q->where('type', 'Full');
            }])->withCount(['leave_detail as half_leave' => function ($q) {
                $q->whereNot('type', 'Full');
            }])->where('user_id', $request->staff_id);

            if ($request->session_year_id) {
                $sql->whereHas('leave_master', function ($q) use ($request) {
                    $q->where('session_year_id', $request->session_year_id);
                });
                $leaveMaster->where('session_year_id', $request->session_year_id);
            } else {
                $sql->whereHas('leave_master', function ($q) use ($sessionYear) {
                    $q->where('session_year_id', $sessionYear->id);
                });
                $leaveMaster->where('session_year_id', $sessionYear->id);
            }
            if (isset($request->status)) {
                $sql->where('status', $request->status);
            }
            if ($request->month) {
                $sql->whereHas('leave_detail', function ($q) use ($request) {
                    $q->whereMonth('date', $request->month);
                });
            }
            $leaveMaster = $leaveMaster->first();
            if (!$leaveMaster) {
                ResponseService::errorResponse("Leave settings not found");
            }
            $sql = $sql->get();
            $sql = $sql->map(function ($sql) {
                $total_leaves = ($sql->half_leave / 2) + $sql->full_leave;
                $sql->days = $total_leaves;
                if ($sql->status == 1) {
                    $sql->taken_leaves = $total_leaves;
                }
                return $sql;
            });
            $data = [
                'monthly_allowed_leaves' => $leaveMaster->leaves,
                'total_leaves' => $sql->sum('days'),
                'taken_leaves' => $sql->sum('taken_leaves'),
                'leave_details' => $sql
            ];

            ResponseService::successResponse("Data Fetched Successfully", $data);
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }

    public function getMedium()
    {
        try {
            $sql = $this->medium->builder()->get();
            ResponseService::successResponse("Data Fetched Successfully", $sql);
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }

    public function getClass(Request $request)
    {
        try {
            $currentSemester = $this->cache->getDefaultSemesterData();

            // Teacher
            if (Auth::user()->hasRole('Teacher')) {
                $user = $request->user()->teacher;
                //Find the class in which teacher is assigns as Class Teacher
                $class_section_ids = [];
                if ($user->class_teacher) {
                    $class_teacher = $user->class_teacher;
                    $class_section_ids = $class_teacher->pluck('class_section_id');
                }
                //Find the Classes in which teacher is taking subjects
                $class_section = $this->classSection->builder()->with('class.stream', 'section', 'medium')->whereNotIn('id', $class_section_ids);

                $class_section = $class_section->whereHas('subject_teachers.class_subject', function ($q) use ($currentSemester) {
                    (!empty($currentSemester)) ? $q->where('semester_id', $currentSemester->id)->orWhereNull('semester_id') : $q->orWhereNull('semester_id');
                })->get();

                // $class_section = $class_section->get();
            } else {
                // Staff
                $class_section = $this->classSection->builder()->with('class', 'section', 'medium', 'class.stream')->get();
            }
            ResponseService::successResponse('Data Fetched Successfully', null, [
                'class_teacher' => $class_teacher ?? [],
                'other'         => $class_section,
                'semester'      => $currentSemester ?? null
            ]);
        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th);
            ResponseService::errorResponse();
        }
    }

    public function updateProfile(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'first_name' => 'required',
            'last_name' => 'required',
            'mobile' => 'required',
            'email' => 'required',
            'dob' => 'required',
            'current_address' => 'required',
            'permanent_address' => 'required',
            'gender' => 'required|in:male,female',
            'image'           => 'nullable|mimes:jpeg,png,jpg,svg|max:5120',
        ]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            $user_data = [
                'first_name' => $request->first_name,
                'last_name' => $request->last_name,
                'mobile' => $request->mobile,
                'email' => $request->email,
                'dob' => $request->dob,
                'current_address' => $request->current_address,
                'permanent_address' => $request->permanent_address,
                'gender' => $request->gender,
            ];

            if ($request->hasFile('image')) {
                $user_data['image'] = $request->file('image');
            }

            $user = $this->user->update(Auth::user()->id, $user_data);

            ResponseService::successResponse('Data Updated Successfully', $user);
        } catch (\Throwable $th) {
            ResponseService::logErrorResponse($th);
            ResponseService::errorResponse();
        }
    }

    public function getExamResultPdf(Request $request)
    {
        ResponseService::noFeatureThenSendJson('Exam Management');

        
        try {

            $validator = Validator::make($request->all(), [
                'exam_id'  => 'required',
                'student_id' => 'required',
            ]);
            if ($validator->fails()) {
                ResponseService::validationError($validator->errors()->first());
            }

            $exam_id = $request->exam_id;
            $student_id = $request->student_id;

            $result = $this->examResult->builder()->with(['exam', 'session_year', 'user' => function ($q) use ($exam_id) {
                $q->with(['student' => function ($q) {
                    $q->with('guardian', 'class_section.class.stream', 'class_section.section', 'class_section.medium');
                }])
                    ->with(['exam_marks' => function ($q) use ($exam_id) {
                        $q->whereHas('timetable', function ($q) use ($exam_id) {
                            $q->where('exam_id', $exam_id);
                        })->with(['class_subject' => function ($q) {
                            $q->withTrashed()->with('subject:id,name,type');
                        }])
                            ->with('timetable');
                    }]);
            }])->where('exam_id', $exam_id)
                ->select('*', DB::raw('DENSE_RANK() OVER (PARTITION BY class_section_id ORDER BY obtained_marks DESC) as rank'))
                ->get()->where('student_id', $student_id)->first();

            if (!$result) {
                return redirect()->back()->with('error', trans('no_records_found'));
            }

            $grades = $this->grade->builder()->orderBy('starting_range', 'ASC')->get();

            $settings = $this->cache->getSchoolSettings('*',$result->school_id);
            $data = explode("storage/", $settings['horizontal_logo'] ?? '');
            $settings['horizontal_logo'] = end($data);

            $pdf = PDF::loadView('exams.exam_result_pdf', compact('result', 'settings', 'grades'))->output();

            return $response = array(
                'error' => false,
                'pdf'   => base64_encode($pdf),
            );
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }

    public function leaveSettings(Request $request)
    {
        try {

            if ($request->session_year_id) {
                $session_year_id = $request->session_year_id;
            } else {
                $sessionYear = $this->cache->getDefaultSessionYear();
                $session_year_id = $sessionYear->id;
            }
            $sql = $this->leaveMaster->builder()->where('session_year_id',$session_year_id)->get();
            ResponseService::successResponse("Data Fetched Successfully", $sql);
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }
}
