<?php

namespace App\Http\Controllers;

use App\Repositories\Attendance\AttendanceInterface;
use App\Repositories\ClassSection\ClassSectionInterface;
use App\Repositories\Student\StudentInterface;
use App\Services\CachingService;
use App\Services\ResponseService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

use App\Models\User;
use App\Models\Students;
use App\Repositories\School\SchoolInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

class AttendanceController extends Controller
{

    private AttendanceInterface $attendance;
    private ClassSectionInterface $classSection;
    private StudentInterface $student;
    private CachingService $cache;
    private SchoolInterface $schoolsRepository1;

    public function __construct(AttendanceInterface $attendance, ClassSectionInterface $classSection, StudentInterface $student, CachingService $cachingService, SchoolInterface $schoolsRepository1)
    {
        $this->attendance = $attendance;
        $this->classSection = $classSection;
        $this->student = $student;
        $this->cache = $cachingService;
        $this->schoolsRepository1 = $schoolsRepository1;
    }


    public function index()
    {
        ResponseService::noFeatureThenRedirect('Attendance Management');
        ResponseService::noAnyPermissionThenRedirect(['class-teacher', 'attendance-list']);
        $classSections = $this->classSection->builder()->ClassTeacher()->with('class', 'class.stream', 'section', 'medium')->get();
        return view('attendance.index', compact('classSections'));
    }


    public function view()
    {
        ResponseService::noFeatureThenRedirect('Attendance Management');
        ResponseService::noAnyPermissionThenRedirect(['class-teacher', 'attendance-list']);
        $class_sections = $this->classSection->builder()->ClassTeacher()->with('class', 'class.stream', 'section', 'medium')->get();
        return view('attendance.view', compact('class_sections'));
    }

    public function getAttendanceData(Request $request)
    {
        ResponseService::noFeatureThenRedirect('Attendance Management');
        $response = $this->attendance->builder()->select('type')->where(['date' => date('Y-m-d', strtotime($request->date)), 'class_section_id' => $request->class_section_id])->pluck('type')->first();
        return response()->json($response);
    }

    public function store(Request $request)
    {
        ResponseService::noFeatureThenRedirect('Attendance Management');
        ResponseService::noAnyPermissionThenRedirect(['class-teacher', 'attendance-create', 'attendance-edit']);
        $request->validate([
            'class_section_id' => 'required',
            'date'             => 'required',
        ]);
        try {
            DB::beginTransaction();
            $attendanceData = array();
            $sessionYear = $this->cache->getDefaultSessionYear();
            $student_ids = array();
            foreach ($request->attendance_data as $value) {
                $data = (object)$value;
                $attendanceData[] = array(
                    "id"               => $data->id,
                    'class_section_id' => $request->class_section_id,
                    'student_id'       => $data->student_id,
                    'session_year_id'  => $sessionYear->id,
                    'type'             => $request->holiday ?? $data->type,
                    'date'             => date('Y-m-d', strtotime($request->date)),
                );

                if ($data->type == 0) {
                    $student_ids[] = $data->student_id;
                }
            }
            $this->attendance->upsert($attendanceData, ["id"], ["class_section_id", "student_id", "session_year_id", "type", "date"]);

            if ($request->absent_notification) {

                Log::channel('custom')->error('4444444');
                Log::channel('custom')->error(json_encode($student_ids));
                Log::channel('custom')->error(implode(',', $student_ids));
                
                $sql = "select * from (select s.*, cs.class_id, concat(c.name, '(', Nvl(st.name, ''), ')', nvl(sh.name, ''), '-', nvl(sh.name, '')) class_name, concat(u.first_name, ' ',u.last_name) full_name, concat(g.first_name, ' ', g.last_name) guardian_name, u.mobile mobile_s, g.mobile mobile_g " . 
                    "from students s " . 
                    "left join users u on s.user_id = u.id " . 
                    "left join users g on s.guardian_id = g.id " . 
                    "left join class_sections cs on cs.id = s.class_section_id " . 
                    "left join classes c on c.id = cs.class_id " . 
                    "left join mediums m on c.medium_id = m.id " . 
                    "left join shifts sh on c.shift_id = sh.id " . 
                    "left join streams st on c.stream_id = st.id " . 
                    ") s where s.class_section_id = " . $request->class_section_id . " and s.user_id in (" . implode(',', $student_ids) . ")";

                Log::channel('custom')->error('sql:' . $sql);
                $students = DB::select($sql);

                Log::channel('custom')->error('students:' . json_encode($students));
                $text = 'student absent on' . $request->date;

                Log::channel('custom')->error('text:' . json_encode($text));

                $settings = app(CachingService::class)->getSystemSettings();

                $placeholder = $this->replacePlaceholdersForSMS(Auth::user(), $settings, $text);
                Log::channel('custom')->error('placeholder:' . json_encode($placeholder));
                
                $this->sendSMSForAttendance(Auth::user()->school_id, "assignment_exams_timetable_attendance_holiday_created", $placeholder, $students);

                $user = $this->student->builder()->whereIn('user_id', $student_ids)->pluck('guardian_id');
                $date = Carbon::parse(date('Y-m-d', strtotime($request->date)))->format('F jS, Y');
                $title = 'Absent';
                $body = 'Your child is absent on ' . $date;
                $type = "attendance";

                
                send_notification($user, $title, $body, $type);

            }

            DB::commit();
            ResponseService::successResponse('Data Stored Successfully');
        } catch (Throwable $e) {
            if (Str::contains($e->getMessage(), [
                'does not exist','file_get_contents'
            ])) {
                DB::commit();
                ResponseService::warningResponse("Data Stored successfully. But App push notification not send.");
            } else {
                DB::rollback();
                ResponseService::logErrorResponse($e, "Attendance Controller -> Store method");
                ResponseService::errorResponse();
            }
        }
    }

    public function show(Request $request)
    {
        ResponseService::noFeatureThenRedirect('Attendance Management');
        ResponseService::noAnyPermissionThenRedirect(['class-teacher', 'attendance-list']);

        //        $offset = $request->input('offset', 0);
        //        $limit = $request->input('limit', 10);
        $sort = $request->input('sort', 'roll_number');
        $order = $request->input('order', 'ASC');
        $search = $request->input('search');

        $class_section_id = $request->class_section_id;
        $date = date('Y-m-d', strtotime($request->date));
        $sessionYear = $this->cache->getDefaultSessionYear();

        $attendanceQuery = $this->attendance->builder()->with('user.student')->where(['date' => $date, 'class_section_id' => $class_section_id, 'session_year_id' => $sessionYear->id])->whereHas('user', function ($q) {
            $q->whereNull('deleted_at');
        })->whereHas('user.student', function ($q) use ($sessionYear) {
            $q->where('session_year_id', $sessionYear->id);
        });

        if ($date != '' && $attendanceQuery->count() > 0) {
            $attendanceQuery->when($search, function ($query) use ($search) {
                $query->where('id', 'LIKE', "%$search%")->orWhereHas('user', function ($q) use ($search) {
                    $q->whereRaw("concat(users.first_name,' ',users.last_name) LIKE '%" . $search . "%'");
                });
            })->where('date', $date)->whereHas('user.student', function ($q) use ($sessionYear) {
                $q->where('session_year_id', $sessionYear->id);
            });

            $total = $attendanceQuery->count();
            $attendanceData = $attendanceQuery->get();
        } else if ($class_section_id) {
            $studentQuery = $this->student->builder()->where('session_year_id', $sessionYear->id)->where('class_section_id', $class_section_id)->with('user')
                ->whereHas('user', function ($q) {
                    $q->whereNull('deleted_at');
                })
                ->when($search, function ($query) use ($search) {
                    $query->where('id', 'LIKE', "%$search%")->orWhereHas('user', function ($q) use ($search) {
                        $q->whereRaw("concat(users.first_name,' ',users.last_name) LIKE '%" . $search . "%'")->where('deleted_at', NULL);
                    });
                })->where('session_year_id', $sessionYear->id)->where('class_section_id', $class_section_id);

            $total = $studentQuery->count();
            // $studentQuery->orderBy($sort, $order)->skip($offset)->take($limit);
            $studentQuery->orderBy($sort, $order);
            $attendanceData = $studentQuery->get();
        }

        $rows = [];
        $no = 1;

        foreach ($attendanceData as $row) {
            $type = $row->type ?? NULL;
            // TODO : understand this code
            $rows[] = [
                'id'           => $attendanceQuery->count() ? $row->id : null,
                'no'           => $no,
                'student_id'   => $attendanceQuery->count() ? $row->student_id : $row->user_id,
                'user_id'      => $attendanceQuery->count() ? $row->student_id : $row->user_id,
                'admission_no' => $row->user ? ($row->user->student->admission_no ?? '') : ($row->admission_no ?? ''),
                'roll_no'      => $row->user ? ($row->user->student->roll_number ?? '') : ($row->roll_number ?? ''),
                'name' => '<input type="hidden" value="' . ($row->student_id ? $row->user_id : 'null') . '" name="attendance_data[' . $no . '][id]"><input type="hidden" value="' . ($row->student_id ?? $row->user_id) . '" name="attendance_data[' . $no . '][student_id]">' . ($row->user->first_name ?? '') . ' ' . ($row->user->last_name ?? ''),
                'type'         => $type,
            ];
            $no++;
        }

        $bulkData['total'] = $total;
        $bulkData['rows'] = $rows;

        return response()->json($bulkData);
    }


    public function attendance_show(Request $request)
    {
        ResponseService::noFeatureThenRedirect('Attendance Management');
        ResponseService::noAnyPermissionThenRedirect(['class-teacher', 'attendance-list']);

        $offset = request('offset', 0);
        $limit = request('limit', 10);
        $sort = request('sort', 'student_id');
        $order = request('order', 'ASC');
        $search = request('search');
        $attendanceType = request('attendance_type');

        $class_section_id = request('class_section_id');
        $date = date('Y-m-d', strtotime(request('date')));

        $validator = Validator::make($request->all(), ['class_section_id' => 'required', 'date' => 'required',]);
        if ($validator->fails()) {
            ResponseService::errorResponse($validator->errors()->first());
        }

        $sessionYear = $this->cache->getDefaultSessionYear();

        $sql = $this->attendance->builder()->where(['date' => $date, 'class_section_id' => $class_section_id])->with('user.student')
            ->where(function ($query) use ($search) {
                $query->when($search, function ($query) use ($search) {
                    $query->where(function ($query) use ($search) {
                        $query->where('id', 'LIKE', "%$search%")
                            ->orwhere('student_id', 'LIKE', "%$search%")
                            ->orWhereHas('user', function ($q) use ($search) {
                                $q->whereRaw("concat(first_name,' ',last_name) LIKE '%" . $search . "%'")
                                    ->orwhere('first_name', 'LIKE', "%$search%")
                                    ->orwhere('last_name', 'LIKE', "%$search%");
                            })->orWhereHas('user.student', function ($q) use ($search) {
                                $q->where('admission_no', 'LIKE', "%$search%")
                                    ->orwhere('id', 'LIKE', "%$search%")
                                    ->orwhere('user_id', 'LIKE', "%$search%")
                                    ->orwhere('roll_number', 'LIKE', "%$search%");
                            });
                    });
                });
            })
            ->when($attendanceType != null, function ($query) use ($attendanceType) {
                $query->where('type', $attendanceType);
            });
        $sql = $sql->whereHas('user.student', function ($q) use ($sessionYear) {
            $q->where('session_year_id', $sessionYear->id);
        });
        $total = $sql->count();

        $sql->orderBy($sort, $order)->skip($offset)->take($limit);
        $res = $sql->get();

        $bulkData = array();
        $bulkData['total'] = $total;
        $rows = array();
        $no = 1;

        foreach ($res as $row) {
            $tempRow = $row->toArray();
            $tempRow['no'] = $no++;
            $rows[] = $tempRow;
        }
        $bulkData['rows'] = $rows;
        return response()->json($bulkData);
    }

    private function replacePlaceholdersForSMS($user, $settings, $text = '')
    {
        Log::channel('custom')->error('replacePlaceholdersForSMS');
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
