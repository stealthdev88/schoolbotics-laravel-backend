<?php

namespace App\Http\Controllers;

use App\Models\Language;
use App\Models\School;
use App\Models\SchoolSetting;
use App\Models\SmsSetting;
use App\Models\User;
use App\Repositories\Guidance\GuidanceInterface;
use App\Repositories\Package\PackageInterface;
use App\Repositories\School\SchoolInterface;
use App\Repositories\SchoolSetting\SchoolSettingInterface;
use App\Repositories\User\UserInterface;
use App\Services\BootstrapTableService;
use App\Services\CachingService;
use App\Services\ResponseService;
use App\Services\SchoolDataService;
use App\Services\SubscriptionService;
use Auth;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Stripe\Review;
use Throwable;
use TypeError;
use Illuminate\Support\Facades\Log;
use Database\Seeders\DummyDataSeeder;

class SchoolController extends Controller
{

    // Initializing the schools Repository
    private SchoolInterface $schoolsRepository;
    private UserInterface $userRepository;
    private PackageInterface $package;
    private CachingService $cache;
    private SubscriptionService $subscriptionService;
    private SchoolSettingInterface $schoolSettings;
    private GuidanceInterface $guidance;

    public function __construct(SchoolInterface $school, UserInterface $user, PackageInterface $package, CachingService $cache, SubscriptionService $subscriptionService, SchoolSettingInterface $schoolSettings, GuidanceInterface $guidance)
    {
        $this->schoolsRepository = $school;
        $this->userRepository = $user;
        $this->package = $package;
        $this->cache = $cache;
        $this->subscriptionService = $subscriptionService;
        $this->schoolSettings = $schoolSettings;
        $this->guidance = $guidance;
    }


    public function index()
    {
        ResponseService::noPermissionThenRedirect('schools-list');
        $packages = $this->package->builder()->orderBy('rank')->get()->pluck('package_with_type', 'id')->toArray();

        $baseUrl = url('/');
        // Remove the scheme (http:// or https://)
        $baseUrlWithoutScheme = preg_replace("(^https?://)", "", $baseUrl);
        return view('schools.index', compact('packages', 'baseUrlWithoutScheme'));
    }

    public function store(Request $request)
    {
        Log::channel('custom')->error('school admin created');
        ResponseService::noAnyPermissionThenRedirect(['schools-create']);
        $validator = Validator::make($request->all(), [
            'school_name'          => 'required',
            'school_support_email' => 'required|unique:schools,support_email',
            'school_support_phone' => 'required|numeric|digits_between:1,16',
            'school_tagline'       => 'required',
            'school_address'       => 'required',
            'school_image'         => 'required|mimes:jpg,jpeg,png,svg,svg+xml|max:2048',
            'admin_first_name'     => 'required',
            'admin_last_name'      => 'required',
            'admin_contact'        => 'required|digits_between:1,16',
            'admin_email'          => 'required|unique:users,email',
            'admin_image'          => 'required|mimes:jpg,jpeg,png,svg,svg+xml|max:2048',
            'domain'               => 'nullable|unique:schools,domain'
        ]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            DB::beginTransaction();
            Log::channel('custom')->error('school admin created1');
            $school_data = array(
                'name'          => $request->school_name,
                'address'       => $request->school_address,
                'support_email' => $request->school_support_email,
                'support_phone' => $request->school_support_phone,
                'tagline'       => $request->school_tagline,
                'logo'          => $request->file('school_image'),
                'domain'        => $request->domain,
                'senderid'      => $request->senderid
            );
            Log::channel('custom')->error('school admin created1-1 school_data:' . json_encode($school_data));
            // Call store function of Schools Repository
            $schoolData = $this->schoolsRepository->create($school_data);
            Log::channel('custom')->error('school admin created2');
            $admin_data = array(
                'first_name' => $request->admin_first_name,
                'last_name'  => $request->admin_last_name,
                'mobile'     => $request->admin_contact,
                'email'      => $request->admin_email,
                'password'   => Hash::make($request->admin_contact),
                'school_id'  => $schoolData->id,
                'image'      => $request->file('admin_image')
            );

            //Call store function of User Repository and get the admin data
            $user = $this->userRepository->create($admin_data);
            $user->assignRole('School Admin');
            // Update Admin id to School Data
            $schoolData = $this->schoolsRepository->update($schoolData->id, ['admin_id' => $user->id]);
            $schoolService = app(SchoolDataService::class);
            // Add Pre School Settings By Default
            $schoolService->preSettingsSetup($schoolData);
            Log::channel('custom')->error('school admin created3');
            // Assign package
            if ($request->assign_package) {
                // Create subscription plan
                $this->subscriptionService->createSubscription($request->assign_package, $schoolData->id, null, 1);
                $this->cache->removeSchoolCache(config('constants.CACHE.SCHOOL.SETTINGS'), $schoolData->id);
            }

            DummyDataSeeder::createSchoolAdminSmsTemplate($schoolData->id);
            Log::channel('custom')->error('school admin created4');
            DB::commit();

            $settings = $this->cache->getSystemSettings();
            $email_body = $this->replacePlaceholders($request, $user, $settings);
            $data = [
                'subject'     => 'Welcome to ' . $settings['system_name'] ?? 'schoolbotics',
                'email'       => $request->admin_email,
                'email_body'  => $email_body
            ];

            Log::channel('custom')->error('school admin created5');
            $text = $request->school_name . " is added\n" . "school admin: " . $request->admin_first_name . " " . $request->admin_last_name . "\n" . 
                "email: " . $request->admin_email . "\nphone: " . $request->admin_contact . "\n";
            $placeholder = $this->replacePlaceholdersForSMS($request, $user, $settings, $text);
            $this->sendSMS("Super Admin", "school_account_c_r", $placeholder);

            if (env('SEND_MAIL', 0) == 1) {
                Mail::send('schools.email', $data, static function ($message) use ($data) {
                    $message->to($data['email'])->subject($data['subject']);
                });
            }
            ResponseService::successResponse('Data Stored Successfully');
        } catch (Throwable $e) {
            Log::channel('custom')->error('error message' . $e->getmessage());
            if (Str::contains($e->getMessage(), ['Failed', 'Mail', 'Mailer', 'MailManager'])) {
                ResponseService::warningResponse("School Registered successfully. But Email not sent.");
            } else {
                DB::rollBack();
                ResponseService::logErrorResponse($e, "School Controller -> Store method");
                ResponseService::errorResponse();
            }
        }
    }


    private function replacePlaceholders($request, $user, $settings)
    {
        $templateContent = $settings['email_template_school_registration'] ?? '';
        // Define the placeholders and their replacements
        $placeholders = [
            '{school_admin_name}' => $user->full_name,
            '{email}' => $user->email,
            '{password}' => $user->mobile,
            '{school_name}' => $request->school_name,

            '{super_admin_name}' => Auth::user() ? Auth::user()->full_name : '',
            '{support_email}' => $settings['mail_username'] ?? '',
            '{contact}' => $settings['mobile'] ?? '',
            '{system_name}' => $settings['system_name'] ?? 'schoolbotics',
            '{url}' => url('/'),
            // Add more placeholders as needed
        ];

        // Replace the placeholders in the template content
        foreach ($placeholders as $placeholder => $replacement) {
            $templateContent = str_replace($placeholder, $replacement, $templateContent);
        }

        return $templateContent;
    }

    private function replacePlaceholdersForSMS($request, $user, $settings, $text = '')
    {
        // Define the placeholders and their replacements
        $placeholders = [
            '{school_admin_name}' => $user->full_name,
            '{email}' => $user->email,
            '{password}' => $user->mobile,
            '{school_name}' => $request->school_name,
            '{super_admin_name}' => Auth::user() ? Auth::user()->full_name : '',
            '{support_email}' => $settings['mail_username'] ?? '',
            '{contact}' => $settings['mobile'] ?? '',
            '{system_name}' => $settings['system_name'] ?? 'schoolbotics',
            '{url}' => url('/'),
            // Add more placeholders as needed
            '{text}' => $text,
        ];
        return $placeholders;
    }

    public function show()
    {
        ResponseService::noPermissionThenRedirect('schools-list');
        $offset = request('offset', 0);
        $limit = request('limit', 10);
        $sort = request('sort', 'id');
        $order = request('order', 'ASC');
        $search = request('search');
        $package_id = request('package_id');
        $showDeleted = request('show_deleted');
        $today_date = Carbon::now()->format('Y-m-d');

        $sql = $this->schoolsRepository->builder()->with('user:id,first_name,last_name,email,image,mobile')->with(['subscription' => function ($q) use ($today_date) {
            $q->whereDate('start_date', '<=', $today_date)->whereDate('end_date', '>=', $today_date);
        }])->with('subscription.package')
            //search query
            ->where(function ($query) use ($search) {
                $query->when($search, function ($query) use ($search) {
                    $query->where(function ($query) use ($search) {
                        $query->where('name', 'LIKE', "%$search%")
                            ->orWhere('support_email', 'LIKE', "%$search%")
                            ->orWhere('support_phone', 'LIKE', "%$search%")
                            ->orWhere('tagline', 'LIKE', "%$search%")
                            ->orWhere('address', 'LIKE', "%$search%")
                            ->orWhereHas('user', function ($query) use ($search) {
                                $query->whereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", "%$search%");
                            });
                    });
                    $query->where(function ($query) use ($search) {
                        $query->where('name', 'LIKE', "%$search%")
                            ->orWhere('support_email', 'LIKE', "%$search%")
                            ->orWhere('support_phone', 'LIKE', "%$search%")
                            ->orWhere('tagline', 'LIKE', "%$search%")
                            ->orWhere('address', 'LIKE', "%$search%")
                            ->orWhereHas('user', function ($query) use ($search) {
                                $query->whereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", "%$search%");
                            });
                    });
                });
            })->when(!empty($showDeleted), function ($query) {
                $query->onlyTrashed();
            });

        if ($package_id) {
            $sql->whereHas('subscription', function ($q) use ($package_id, $today_date) {
                $q->where('package_id', $package_id)->whereDate('start_date', '<=', $today_date)->whereDate('end_date', '>=', $today_date);
            });
        }


        $total = $sql->count();

        $sql->orderBy($sort, $order)->skip($offset)->take($limit);
        $res = $sql->get();

        $bulkData = array();
        $bulkData['total'] = $total;
        $rows = array();
        $no = 1;

        foreach ($res as $row) {
            $operate = '';
            if ($showDeleted) {
                //Show Restore and Hard Delete Buttons
                $operate = BootstrapTableService::menuRestoreButton('restore', route('schools.restore', $row->id));
                $operate .= BootstrapTableService::menuTrashButton('delete', route('schools.trash', $row->id));
            } else {
                $operate = BootstrapTableService::menuButton('change_admin', "#", [], ['data-toggle' => "modal", 'data-target' => "#editAdminModal"]);

                if ($row->status == 0) {
                    $operate .= BootstrapTableService::menuButton('activate_school', "#", ["change-school-status"], ['data-id' => $row->id]);
                } else {
                    $operate .= BootstrapTableService::menuButton('inactive_school', "#", ["change-school-status"], ['data-id' => $row->id]);
                }
                $operate .= BootstrapTableService::menuEditButton('edit', route('schools.update', $row->id));
                $operate .= BootstrapTableService::menuDeleteButton('delete', route('schools.destroy', $row->id));
            }


            $tempRow = $row->toArray();
            $tempRow['no'] = $no++;
            $tempRow['active_plan'] = '-';
            if (count($row->subscription)) {
                $package = $row->subscription()->whereDate('start_date', '<=', $today_date)->whereDate('end_date', '>=', $today_date)->latest()->first();
                if ($package) {
                    $tempRow['active_plan'] = $package->name;
                }
            } else {
                $tempRow['active_plan'] = '-';
            }

            // $tempRow['operate'] = $operate;
            $tempRow['operate'] = BootstrapTableService::menuItem($operate);
            $rows[] = $tempRow;
        }

        $bulkData['rows'] = $rows;
        return response()->json($bulkData);
    }

    public function update(Request $request, $id)
    {
        ResponseService::noPermissionThenSendJson(['schools-edit']);
        $validator = Validator::make($request->all(), [
            'edit_school_name'          => 'required',
            'edit_school_support_email' => 'required|unique:schools,support_email,' . $id,
            'edit_school_support_phone' => 'required|numeric|digits_between:1,16',
            'edit_school_tagline'       => 'required',
            'edit_school_address'       => 'required',
            'edit_school_image'         => 'nullable|mimes:jpg,jpeg,png,svg,svg+xml|max:2048',
            'edit_domain'               => 'nullable|unique:schools,domain,' . $id
        ]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            $school_data = array(
                'name'          => $request->edit_school_name,
                'address'       => $request->edit_school_address,
                'support_email' => $request->edit_school_support_email,
                'support_phone' => $request->edit_school_support_phone,
                'tagline'       => $request->edit_school_tagline,
                'domain'        => $request->edit_domain,
                'senderid'      => $request->edit_senderid,
            );

            if ($request->hasFile('edit_school_image')) {
                $school_data['logo'] = $request->file('edit_school_image');
            }

            $this->schoolsRepository->update($request->edit_id, $school_data); // Call update function of Schools Repository
            // Update school settings
            $schoolSettingData = array(
                [
                    'name'      => 'school_name',
                    'data'      => $request->edit_school_name,
                    'type'      => 'string',
                    'school_id' => $request->edit_id,
                ],
                [
                    'name'      => 'school_email',
                    'data'      => $request->edit_school_support_email,
                    'type'      => 'string',
                    'school_id' => $request->edit_id
                ],
                [
                    'name'      => 'school_phone',
                    'data'      => $request->edit_school_support_phone,
                    'type'      => 'number',
                    'school_id' => $request->edit_id
                ],
                [
                    'name'      => 'school_tagline',
                    'data'      => $request->edit_school_tagline,
                    'type'      => 'string',
                    'school_id' => $request->edit_id
                ],
                [
                    'name'      => 'school_address',
                    'data'      => $request->edit_school_address,
                    'type'      => 'string',
                    'school_id' => $request->edit_id
                ],
                [
                    'name'      => 'domain',
                    'data'      => $request->edit_domain,
                    'type'      => 'string',
                    'school_id' => $request->edit_id
                ],
                [
                    'name'      => 'senderid',
                    'data'      => $request->edit_senderid,
                    'type'      => 'string',
                    'school_id' => $request->edit_id
                ]
            );

            if ($request->hasFile('edit_school_image')) {
                $schoolSettingData[] = [
                    'name'      => 'vertical_logo',
                    'data'      => $request->file('edit_school_image')->store('school', 'public'),
                    'type'      => 'file',
                    'school_id' => $request->edit_id
                ];
            }
            SchoolSetting::upsert($schoolSettingData, ['name', 'school_id'], ['data', 'school_id', 'type']);
            $this->cache->removeSchoolCache(config('constants.CACHE.SCHOOL.SETTINGS'), $request->edit_id);

            // Assign package
            if ($request->assign_package) {
                // Create subscription plan
                $this->subscriptionService->createSubscription($request->assign_package, $request->edit_id, null, 1);
                $this->cache->removeSchoolCache(config('constants.CACHE.SCHOOL.SETTINGS'), $request->edit_id);
            }

            ResponseService::successResponse('Data Updated Successfully');
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e, "School Controller -> Update method");
            ResponseService::errorResponse();
        }
    }

    public function destroy($id)
    {
        ResponseService::noPermissionThenSendJson('schools-delete');
        try {
            $school = $this->schoolsRepository->update($id, ['status' => 0]);
            User::withTrashed()->where('id', $school->admin_id)->delete();
            $this->schoolsRepository->deleteById($id);
            ResponseService::successResponse('Data Deleted Successfully');
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e, "School Controller -> Delete method");
            ResponseService::errorResponse();
        }
    }

    public function restore(int $id)
    {
        ResponseService::noPermissionThenSendJson('schools-delete');
        try {
            $this->schoolsRepository->findOnlyTrashedById($id)->restore();
            $school = $this->schoolsRepository->findById($id);
            User::onlyTrashed()->where('id', $school->admin_id)->restore();
            ResponseService::successResponse("Data Restored Successfully");
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }

    public function trash($id)
    {
        ResponseService::noPermissionThenSendJson('schools-delete');
        try {
            ResponseService::errorResponse("cannot_delete_because_data_is_associated_with_other_data");
            $this->schoolsRepository->findOnlyTrashedById($id)->forceDelete();
            ResponseService::successResponse("Data Deleted Permanently");
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e, '', 'cannot_delete_because_data_is_associated_with_other_data');
            ResponseService::errorResponse();
        }
    }

    public function adminSearch(Request $request)
    {
        $adminData = $this->userRepository->getTrashedAdminData($request->email);
        if (!empty($adminData)) {
            $response = ['error' => false, 'data' => $adminData];
        } else {
            $response = ['error' => true, 'message' => trans('no_data_found')];
        }
        return response()->json($response);
    }

    public function updateAdmin(Request $request)
    {
        ResponseService::noAnyPermissionThenRedirect(['schools-edit']);
        $validator = Validator::make($request->all(), [
            "edit_id"               => 'required',
            "edit_admin_email"      => 'required',
            "edit_admin_first_name" => 'required',
            "edit_admin_last_name"  => 'required',
            "edit_admin_contact"    => 'required|digits_between:1,16',
        ]);
        if ($validator->fails()) {
            ResponseService::validationError($validator->errors()->first());
        }
        try {
            DB::beginTransaction();

            // If Email is not the ID then Check the following requirements
            if (!is_numeric($request->edit_admin_email)) {
                $validator = Validator::make(
                    $request->all(),
                    [
                        "edit_admin_email" => 'required|email|unique:users,email',
                    ],
                    [
                        "edit_admin_email.required" => trans('email_is_required'),
                        "edit_admin_email.email"    => trans('enter_valid_email'),
                        "edit_admin_email.unique"   => trans('email_already_in_use'),
                    ]
                );
                if ($validator->fails()) {
                    ResponseService::validationError($validator->errors()->first());
                }
            }

            $admin_data = array(
                'school_id'  => $request->edit_id,
                'email'      => $request->edit_admin_email,
                'first_name' => $request->edit_admin_first_name,
                'last_name'  => $request->edit_admin_last_name,
                'contact'    => $request->edit_admin_contact,
                'reset_password'    => $request->reset_password,
            );
            $this->schoolsRepository->updateSchoolAdmin($admin_data, $request->edit_admin_image); // Call updateSchoolAdmin function of Schools Repository
            DB::commit();
            ResponseService::successResponse('Data Updated Successfully');
        } catch (Throwable $e) {
            DB::rollBack();
            ResponseService::logErrorResponse($e, "School Controller -> Update Admin method");
            ResponseService::errorResponse();
        }
    }

    public function changeStatus($id)
    {
        Log::channel('custom')->error('0000');
        ResponseService::noAnyPermissionThenRedirect(['schools-edit']);
        try {
            DB::beginTransaction();
            $school = $this->schoolsRepository->findById($id);
            $status = ['status' => $school->status == 0 ? 1 : 0];
            $this->schoolsRepository->update($id, $status);

            $school = DB::select("select * from schools where id = " . $id)[0];
            $school_name = $school->name;

            $user = DB::select("select * from users where id = " . $school->admin_id)[0];
            $full_name = $user->first_name . ' ' . $user->last_name;

            $settings = $this->cache->getSystemSettings();
            if ($status['status'] == 0) {
                $today_date = Carbon::now()->format('Y-m-d');
                $sql = "select * from subscriptions where school_id = " . $id . " and school_id is not null and date(start_date) <= '" . $today_date ."' and date(end_date) >= '" . $today_date ."' order by created_at";
                Log::channel('custom')->error("subscription sql:" . $sql);
                $subscriptions = DB::select($sql);
                Log::channel('custom')->error("subscriptions:" . json_encode($subscriptions));
                $text = "";
                if (!empty($subscriptions)) {
                    $text = $subscriptions[0]->name;
                }

                $placeholder = $this->replacePlaceholdersForSMSForInactvait($school_name, $full_name, $user, $settings, $text);
                $this->sendSMS("Super Admin", "school_account_activate_inactivate", $placeholder, $id);    
            }

            DB::commit();
            ResponseService::successResponse('Data updated successfully');
        } catch (Throwable $e) {
            DB::rollBack();
            ResponseService::logErrorResponse($e, "School Controller -> Change Status");
            ResponseService::errorResponse();
        }
    }

    private function replacePlaceholdersForSMSForInactvait($school_name, $full_name, $user, $settings, $text)
    {
        // Define the placeholders and their replacements
        $placeholders = [
            '{school_admin_name}' => $full_name,
            '{email}' => $user->email,
            '{password}' => $user->mobile,
            '{school_name}' => $school_name,
            '{super_admin_name}' => Auth::user() ? Auth::user()->full_name : '',
            '{support_email}' => $settings['mail_username'] ?? '',
            '{contact}' => $settings['mobile'] ?? '',
            '{system_name}' => $settings['system_name'] ?? 'schoolbotics',
            '{url}' => url('/'),
            // Add more placeholders as needed
            '{text}'=> $text
        ];
        return $placeholders;
    }


    public function searchAdmin(Request $request)
    {
        ResponseService::noAnyPermissionThenRedirect(['schools-create', 'schools-edit']);
        $parent = $this->userRepository->builder()->role('School Admin')->withTrashed()->where(function ($query) use ($request) {
            $query->where('email', 'like', '%' . $request->email . '%')
                ->orWhere('first_name', 'like', '%' . $request->email . '%')
                ->orWhere('last_name', 'like', '%' . $request->email . '%');
        })->get();

        if (!empty($parent)) {
            $response = [
                'error' => false,
                'data'  => $parent
            ];
        } else {
            $response = [
                'error'   => true,
                'message' => trans('no_data_found')
            ];
        }
        return response()->json($response);
    }

    public function registrationForm()
    {
        $settings = app(CachingService::class)->getSystemSettings();
        $guidances = $this->guidance->builder()->get();
        $languages = Language::get();

        return view('registration_form', compact('settings', 'guidances', 'languages'));
    }

    public function registration(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'school_name'          => 'required',
            'school_support_email' => 'required',
            'school_support_phone' => 'required|numeric|digits_between:1,16',
            'school_tagline'       => 'required',
            'school_address'       => 'required',
            'admin_first_name'     => 'required',
            'admin_last_name'      => 'required',
            'admin_contact'        => 'required|numeric|digits_between:1,16',
            'admin_email'          => 'required',
        ]);

        if ($validator->fails()) {
            // ResponseService::validationError($validator->errors()->first());
            return Redirect::back()->withErrors($validator);
        }

        $school = $this->schoolsRepository->builder()->where('support_email', $request->school_support_email)->withTrashed()->first();
        $user = $this->userRepository->builder()->where('email', $request->admin_email)->withTrashed()->first();
        if ($school || $user) {
            return redirect('/login')->with('error', 'School or User email already exists');
        }

        try {
            DB::beginTransaction();

            $school_data = array(
                'name'          => $request->school_name,
                'address'       => $request->school_address,
                'support_email' => $request->school_support_email,
                'support_phone' => $request->school_support_phone,
                'tagline'       => $request->school_tagline,
                'logo'          => 'no_image_available.jpg',
                'status'        => 1
            );
            // Call store function of Schools Repository
            $schoolData = $this->schoolsRepository->create($school_data);

            $admin_data = array(
                'first_name' => $request->admin_first_name,
                'last_name'  => $request->admin_last_name,
                'mobile'     => $request->admin_contact,
                'email'      => $request->admin_email,
                'password'   => Hash::make($request->admin_contact),
                'school_id'  => $schoolData->id,
                'image'      => 'dummy_logo.jpg'
            );

            //Call store function of User Repository and get the admin data
            $user = $this->userRepository->create($admin_data);
            $user->assignRole('School Admin');

            // Update Admin id to School Data
            $schoolData = $this->schoolsRepository->update($schoolData->id, ['admin_id' => $user->id]);

            $schoolService = app(SchoolDataService::class);
            // Add Pre School Settings By Default
            $schoolService->preSettingsSetup($schoolData);

            // Assign trail package if selected
            if ($request->trial_package) {
                // Create subscription plan
                $this->subscriptionService->createSubscription($request->trial_package, $schoolData->id, null, 1);
                $this->cache->removeSchoolCache(config('constants.CACHE.SCHOOL.SETTINGS'), $schoolData->id);
            }
            DummyDataSeeder::createSchoolAdminSmsTemplate($schoolData->id);

            DB::commit();

            $settings = $this->cache->getSystemSettings();
            $email_body = $this->replacePlaceholders($request, $user, $settings);

            $data = [
                'subject'     => 'Welcome to ' . $settings['system_name'] ?? 'schoolbotics',
                'email'       => $request->admin_email,
                'email_body'  => $email_body
            ];

            $data = [
                'subject'     => 'Welcome to ' . $request->school_name,
                'email'       => $request->admin_email,
                'email_body'  => $email_body
            ];
            Log::channel('custom')->error('school registor:' . json_encode($school_data) . json_encode($admin_data));
            $text = " [school name:" . $school_data['name'] . " user:" . $admin_data['email'] . " password:" . $admin_data['mobile'] . "] ";
            $placeholder = $this->replacePlaceholdersForSMS($request, $user, $settings, $text);
            Log::channel('custom')->error('replacePlaceholdersForSMS:' . json_encode($placeholder));
            $this->sendSMS("Super Admin", "school_account_c_r", $placeholder, $schoolData->id);

            if (env('SEND_MAIL', 0) == 1) {
                Mail::send('schools.email', $data, static function ($message) use ($data) {
                    $message->to($data['email'])->subject($data['subject']);
                });
            }

            return redirect('/login')->with('success', 'School Registration Successful');
        } catch (Throwable $e) {
            if (Str::contains($e->getMessage(), ['Failed', 'Mail', 'Mailer', 'MailManager'])) {
                return redirect('/login')->with('success', 'School Registration Successful. But Email not sent');
            } else {
                DB::rollBack();
                ResponseService::logErrorResponse($e, "School Controller -> Registration method");
                ResponseService::errorResponse();
            }
        }
    }
}
