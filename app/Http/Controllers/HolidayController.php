<?php

namespace App\Http\Controllers;

use App\Repositories\Holiday\HolidayInterface;
use App\Repositories\SessionYear\SessionYearInterface;
use App\Services\BootstrapTableService;
use App\Services\ResponseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Throwable;

use App\Services\CachingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\User;
use App\Models\Students;
use App\Repositories\School\SchoolInterface;
use Illuminate\Support\Facades\Auth;

class HolidayController extends Controller {

    private HolidayInterface $holiday;
    private SessionYearInterface $sessionYear;
    private SchoolInterface $schoolsRepository1;

    public function __construct(HolidayInterface $holiday, SessionYearInterface $sessionYear, SchoolInterface $schoolsRepository1) {
        $this->holiday = $holiday;
        $this->sessionYear = $sessionYear;
        $this->schoolsRepository1 = $schoolsRepository1;
    }

    public function index() {
        ResponseService::noFeatureThenRedirect('Holiday Management');
        ResponseService::noPermissionThenRedirect('holiday-list');
        $sessionYears = $this->sessionYear->all();
        $months = sessionYearWiseMonth();
        return view('holiday.index', compact('sessionYears','months'));
    }


    public function store(Request $request) {
        ResponseService::noFeatureThenRedirect('Holiday Management');
        ResponseService::noPermissionThenRedirect('holiday-create');
        $validator = Validator::make($request->all(), [
            'date'  => 'required',
            'title' => 'required',
        ]);

        if ($validator->fails()) {
            ResponseService::errorResponse($validator->errors()->first());
        }
        try {
            $this->holiday->create($request->all());

            $text = $request->date . ' is holiday.';

            Log::channel('custom')->error('text:' . json_encode($text));

            $settings = app(CachingService::class)->getSystemSettings();
            $placeholder = $this->replacePlaceholdersForSMS(Auth::user(), $settings, $text);
            Log::channel('custom')->error('placeholder:' . json_encode($placeholder));
            
            $this->sendSMSForHolidayCreated(Auth::user()->school_id, "assignment_exams_timetable_attendance_holiday_created", $placeholder);

            ResponseService::successResponse('Data Stored Successfully');
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e, "Holiday Controller -> Store Method");
            ResponseService::errorResponse();
        }
    }

    public function update($id, Request $request) {
        ResponseService::noFeatureThenRedirect('Holiday Management');
        ResponseService::noPermissionThenSendJson('holiday-edit');
        $validator = Validator::make($request->all(), ['date' => 'required', 'title' => 'required',]);

        if ($validator->fails()) {
            ResponseService::errorResponse($validator->errors()->first());
        }
        try {
            $this->holiday->update($id, $request->all());
            ResponseService::successResponse('Data Updated Successfully');
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e, "Holiday Controller -> Update Method");
            ResponseService::errorResponse();
        }
    }

    // TODO : Remove this if not necessary
    // public function holiday_view()
    // {
    //     return view('holiday.list');
    // }

    public function show(Request $request) {
        ResponseService::noFeatureThenRedirect('Holiday Management');
        ResponseService::noPermissionThenRedirect('holiday-list');
        $offset = request('offset', 0);
        $limit = request('limit', 10);
        $sort = request('sort', 'id');
        $order = request('order', 'DESC');
        $search = request('search');
        $session_year_id = request('session_year_id');
        $month = request('month');

        $sessionYear = $this->sessionYear->findById($session_year_id);

        $sql = $this->holiday->builder()
            ->where(function ($query) use ($search) {
                $query->when($search, function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->where('id', 'LIKE', "%$search%")->orwhere('title', 'LIKE', "%$search%")->orwhere('description', 'LIKE', "%$search%")->orwhere('date', 'LIKE', "%$search%");
                });
                });
            })->when($session_year_id, function ($query) use ($sessionYear) {
                $query->whereDate('date', '>=',$sessionYear->start_date)
                ->whereDate('date', '<=',$sessionYear->end_date);
            })->when($month, function ($query) use ($month) {
                $query->whereMonth('date', $month);
            });

        $total = $sql->count();

        $sql->orderBy($sort, $order)->skip($offset)->take($limit);
        $res = $sql->get();

        $bulkData = array();
        $bulkData['total'] = $total;
        $rows = array();
        $no = 1;
        foreach ($res as $row) {
            $operate = BootstrapTableService::editButton(route('holiday.update', $row->id));
            $operate .= BootstrapTableService::deleteButton(route('holiday.destroy', $row->id));
            $tempRow = $row->toArray();
            $tempRow['no'] = $no++;
            // $tempRow['date'] = format_date($row->date);
            $tempRow['operate'] = $operate;
            $rows[] = $tempRow;
        }
        $bulkData['rows'] = $rows;
        return response()->json($bulkData);
    }

    public function destroy($id) {
        ResponseService::noFeatureThenRedirect('Holiday Management');
        ResponseService::noPermissionThenSendJson('holiday-delete');
        try {
            $this->holiday->deleteById($id);
            ResponseService::successResponse('Data Deleted Successfully');
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e, "Holiday Controller -> Delete Method");
            ResponseService::errorResponse();
        }
    }

    private function replacePlaceholdersForSMS($user, $settings, $text = '')
    {
        $school_id = $user->school_id;
        
        $school_admin = User::role("School Admin")->whereHas('school', function ($q) use ($school_id) {
            $q->whereNull('deleted_at')->where('status', 1)->where('id', $school_id);
        })->get()->first();

        Log::channel('custom')->error('school_admin:' . json_encode($school_admin));

        $school = $this->schoolsRepository1->findById($school_id)->get()->first();
        Log::channel('custom')->error('school:' . json_encode($school));

        $school_name = $school->name;
        // Define the placeholders and their replacements
        $placeholders = [
            '{school_admin_name}' => $school_admin->full_name,
            '{email}' => $user->email,
            '{password}' => $user->mobile,
            '{school_name}' => $school_name,
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
}
