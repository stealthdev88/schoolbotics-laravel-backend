<?php

namespace App\Http\Controllers;

use App\Models\Timetable;
use App\Repositories\ClassSchool\ClassSchoolInterface;
use App\Repositories\ClassSection\ClassSectionInterface;
use App\Repositories\Medium\MediumInterface;
use App\Repositories\SchoolSetting\SchoolSettingInterface;
use App\Repositories\Subject\SubjectInterface;
use App\Repositories\SubjectTeacher\SubjectTeacherInterface;
use App\Repositories\Timetable\TimetableInterface;
use App\Repositories\User\UserInterface;
use App\Services\BootstrapTableService;
use App\Services\CachingService;
use App\Services\ResponseService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

use App\Models\User;
use App\Models\Students;
use App\Repositories\School\SchoolInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

class TimetableController extends Controller {
    private SubjectTeacherInterface $subjectTeacher;
    private SubjectInterface $subject;
    private TimetableInterface $timetable;
    private ClassSectionInterface $classSection;
    private UserInterface $user;
    private SchoolSettingInterface $schoolSettings;
    private CachingService $cache;
    private ClassSchoolInterface $class;
    private MediumInterface $medium;
    private SchoolInterface $schoolsRepository1;

    public function __construct(SubjectTeacherInterface $subjectTeacher, SubjectInterface $subject, TimetableInterface $timetable, ClassSectionInterface $classSection, UserInterface $user, SchoolSettingInterface $schoolSettings, CachingService $cache, ClassSchoolInterface $class, MediumInterface $medium, SchoolInterface $schoolsRepository1) {
        $this->subjectTeacher = $subjectTeacher;
        $this->subject = $subject;
        $this->timetable = $timetable;
        $this->classSection = $classSection;
        $this->user = $user;
        $this->schoolSettings = $schoolSettings;
        $this->cache = $cache;
        $this->class = $class;
        $this->medium = $medium;
        $this->schoolsRepository1 = $schoolsRepository1;
    }

    public function index() {
        ResponseService::noFeatureThenRedirect('Timetable Management');
        ResponseService::noPermissionThenRedirect('timetable-list');

        // Get Timetable Settings Data
        $timetableData = $this->schoolSettings->getBulkData([
            'timetable_start_time', 'timetable_end_time', 'timetable_duration'
        ]);

        // Convert Timetable Duration time to number
        $timetableData['timetable_duration'] = Carbon::parse($timetableData['timetable_duration'] ?? "00:00:00")->diffInMinutes(Carbon::parse('00:00:00'));

        $classes = $this->class->builder()->with('stream')->get()->pluck('full_name','id');
        $mediums = $this->medium->builder()->pluck('name','id');
        

        return view('timetable.index', compact('timetableData','classes','mediums'));
    }

    public function store(Request $request) {
        ResponseService::noFeatureThenRedirect('Timetable Management');
        ResponseService::noPermissionThenRedirect(['timetable-create']);
        $request->validate([
            'subject_teacher_id' => 'nullable|numeric',
            'class_section_id'   => 'required|numeric',
            'subject_id'         => 'nullable|numeric',
            'start_time' => 'required',
            'end_time'   => 'required',
            'day'                => 'required',
            'note'               => 'nullable',
        ]);
        try {
            $timetable = $this->timetable->create([
                ...$request->all(),
                'type' => (!empty($request->subject_id)) ? "Lecture" : "Break"
            ]);
            ResponseService::successResponse('Data Stored Successfully', $timetable);
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }

    public function edit($classSectionID) {
        ResponseService::noFeatureThenRedirect('Timetable Management');
        ResponseService::noPermissionThenRedirect('timetable-edit');
        $currentSemester = $this->cache->getDefaultSemesterData();
        $classSection = $this->classSection->findById($classSectionID, ['*'], ['class', 'class.stream', 'section', 'medium']);

        $subjectTeachers = $this->subjectTeacher->builder()->with('subject:id,name,type,bg_color', 'teacher:id,first_name,last_name')->whereHas('class_section', function ($q) use ($classSectionID) {
            $q->where('id', $classSectionID);
        })->whereHas('class_subject',function($q) {
            $q->whereNull('deleted_at');
        })->orderBy('subject_id', 'ASC')->CurrentSemesterData()->get();

        $subjectWithoutTeacherAssigned = $this->subject->builder()->whereHas('class_subjects', function ($q) use ($classSection) {
            $q->where('class_id', $classSection->class_id)->CurrentSemesterData();
        })->select(['id', 'name', 'type', 'bg_color'])->whereNotIn('id', $subjectTeachers->pluck('subject_id'))->get();

        $timetables = $this->timetable->builder()->where('class_section_id', $classSectionID)->with('teacher:users.id,first_name,last_name', 'subject')->CurrentSemesterData()->get();

        // Get Timetable Settings Data
        $timetableSettingsData = $this->schoolSettings->getBulkData([
            'timetable_start_time', 'timetable_end_time', 'timetable_duration'
        ]);
        return view('timetable.edit', compact('subjectTeachers', 'subjectWithoutTeacherAssigned', 'classSection', 'timetables', 'timetableSettingsData', 'currentSemester', 'classSectionID'));
    }

    public function update(Request $request, $id) {
        ResponseService::noFeatureThenRedirect('Timetable Management');
        ResponseService::noPermissionThenRedirect(['timetable-edit']);
        $request->validate([
            'start_time' => 'required',
            'end_time'   => 'required',
            'day'        => 'required',
        ]);
        $start_time = $request->start_time;
        $end_time = $request->end_time;
        $start_time = Carbon::createFromFormat('H:i:s',$start_time)->format('H:i:s');
        $end_time = Carbon::createFromFormat('H:i:s',$end_time)->format('H:i:s');

        $schoolSettings = $this->cache->getSchoolSettings();
        $timetable_start_time = Carbon::createFromFormat('H:i:s',$schoolSettings['timetable_start_time'])->format('H:i:s');
        $timetable_end_time = Carbon::createFromFormat('H:i:s',$schoolSettings['timetable_end_time'])->format('H:i:s');
        
        try {
            if ($timetable_start_time <= $start_time && $timetable_end_time >= $end_time) {
                $this->timetable->updateOrCreate(['id' => $id,], $request->all());
                ResponseService::successResponse('Data Stored Successfully');
            } else {
                ResponseService::errorResponse('Please select a valid time');
            }
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }


    public function show(Request $request) {
        ResponseService::noFeatureThenRedirect('Timetable Management');
        ResponseService::noPermissionThenRedirect('timetable-list');
        $offset = request('offset', 0);
        $limit = request('limit', 10);
        $sort = request('sort', 'id');
        $order = request('order', 'DESC');

        $sql = $this->classSection->builder()->with(['class:id,name,stream_id', 'class.stream', 'section:id,name', 'medium:id,name', 'timetable' => function ($query) {
            $query->CurrentSemesterData()->with('subject:id,name,type');
        }]);
        if (!empty($request->search)) {
            $search = $request->search;
            $sql->where(function ($query) use ($search) {
                $query->orWhereHas('section', function ($q) use ($search) {
                    $q->where('name', 'LIKE', "%$search%");
                })->orWhereHas('medium', function ($q) use ($search) {
                    $q->where('name', 'LIKE', "%$search%");
                })->orWhereHas('class',function($q) use($search){
                    $q->where('name', 'LIKE', "%$search%");
                })->orWhereHas('class.stream',function($q) use($search){
                    $q->where('name', 'LIKE', "%$search%");
                });
            });
        }
        if (!empty($request->medium_id)) {
            $sql = $sql->where('medium_id', $request->medium_id);
        }
        $total = $sql->count();

        $sql->orderBy($sort, $order)->skip($offset)->take($limit);
        $res = $sql->get();

        $bulkData = array();
        $bulkData['total'] = $total;
        $rows = array();
        $no = 1;
        foreach ($res as $row) {
            $operate = BootstrapTableService::editButton(route('timetable.edit', $row->id), false);
            $operate .= BootstrapTableService::button('fa fa-trash', '#', ['delete-class-timetable', 'btn-gradient-danger'], ['title' => trans("Delete Class Timetable"), 'data-id' => $row->id]);
            $tempRow = $row->toArray();
            $timetable = $row->timetable->groupBy('day')->sortBy('start_time');
            $tempRow['no'] = $no++;
            $tempRow['Monday'] = $timetable['Monday'] ?? [];
            $tempRow['Tuesday'] = $timetable['Tuesday'] ?? [];
            $tempRow['Wednesday'] = $timetable['Wednesday'] ?? [];
            $tempRow['Thursday'] = $timetable['Thursday'] ?? [];
            $tempRow['Friday'] = $timetable['Friday'] ?? [];
            $tempRow['Saturday'] = $timetable['Saturday'] ?? [];
            $tempRow['Sunday'] = $timetable['Sunday'] ?? [];
            $tempRow['operate'] = $operate;
            $rows[] = $tempRow;
        }

        $bulkData['rows'] = $rows;
        return response()->json($bulkData);
    }

    public function destroy($id) {
        ResponseService::noFeatureThenRedirect('Timetable Management');
        ResponseService::noPermissionThenSendJson('timetable-delete');
        try {
            Timetable::find($id)->delete();
            ResponseService::successResponse('Data Deleted Successfully');
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }

    public function teacherIndex() {
        ResponseService::noFeatureThenRedirect('Timetable Management');
        ResponseService::noPermissionThenRedirect('timetable-list');

        // Get Timetable Settings Data
        $timetableSettingsData = $this->schoolSettings->getBulkData([
            'timetable_start_time', 'timetable_end_time', 'timetable_duration'
        ]);
        return view('timetable.teacher.index', compact('timetableSettingsData'));
    }

    public function teacherList(Request $request) {
        ResponseService::noFeatureThenRedirect('Timetable Management');
        ResponseService::noPermissionThenRedirect('timetable-list');
        $offset = request('offset', 0);
        $limit = request('limit', 10);
        $sort = request('sort', 'id');
        $order = request('order', 'DESC');
        $sql = $this->user->builder()->role('Teacher')->with(['timetable' => function ($query) {
            $query->CurrentSemesterData()->with('subject:id,name', 'class_section.class', 'class_section.section');
        }]);
        if (!empty($request->search)) {
            $search = $request->search;
            $sql->where(function ($query) use ($search) {
                $query->where('id', 'LIKE', "%$search%")->orwhereRaw("concat(first_name,' ',last_name) LIKE '%" . $search . "%'");
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
            $operate = BootstrapTableService::button('fa fa-eye', route('timetable.teacher.show', $row->id), ['btn-gradient-success'], ['title' => "View Timetable"]);
            $tempRow = $row->toArray();
            $timetable = $row->timetable->groupBy('day')->sortBy('start_time');
            $tempRow['no'] = $no++;
            $tempRow['Monday'] = $timetable['Monday'] ?? [];
            $tempRow['Tuesday'] = $timetable['Tuesday'] ?? [];
            $tempRow['Wednesday'] = $timetable['Wednesday'] ?? [];
            $tempRow['Thursday'] = $timetable['Thursday'] ?? [];
            $tempRow['Friday'] = $timetable['Friday'] ?? [];
            $tempRow['Saturday'] = $timetable['Saturday'] ?? [];
            $tempRow['Sunday'] = $timetable['Sunday'] ?? [];
            $tempRow['operate'] = $operate;
            $rows[] = $tempRow;
        }

        $bulkData['rows'] = $rows;
        return response()->json($bulkData);
    }

    public function teacherShow($teacherID) {
        ResponseService::noFeatureThenRedirect('Timetable Management');
        $teacher = $this->user->findById($teacherID, ['id', 'first_name', 'last_name']);
        $timetables = $this->timetable->builder()->whereHas('subject_teacher', function ($q) use ($teacherID) {
            $q->where('teacher_id', $teacherID);
        })->with('subject:id,name,bg_color', 'class_section.class', 'class_section.section','class_section.medium')->get();

        // Get Timetable Settings Data
        $timetableSettingsData = $this->schoolSettings->getBulkData([
            'timetable_start_time', 'timetable_end_time', 'timetable_duration'
        ]);
        return view('timetable.teacher.view', compact('timetables', 'teacher', 'timetableSettingsData'));
    }

    public function updateTimetableSettings(Request $request) {
        ResponseService::noFeatureThenRedirect('Timetable Management');
        ResponseService::noPermissionThenRedirect('timetable-list');
        try {
            DB::beginTransaction();
            $settings = array(
                'timetable_start_time', 'timetable_end_time', 'timetable_duration'
            );

            $timeTableExistsBeforeStartTime = $this->timetable->builder()->where('start_time', '<', date('H:i:s', strtotime($request->time_table_start_time)))->get();
            if (!empty($timeTableExistsBeforeStartTime->toArray())) {
                ResponseService::errorResponse("Updates are prohibited as there are pre-existing lectures scheduled before " . $request->time_table_start_time);
            }

            $timeTableExistsAfterEndTime = $this->timetable->builder()->where('end_time', '>', date('H:i:s', strtotime($request->time_table_end_time)))->get();
            if (!empty($timeTableExistsAfterEndTime->toArray())) {
                ResponseService::errorResponse("Updates are prohibited as there are pre-existing lectures scheduled after " . $request->time_table_end_time);
            }

            $data = array();
            foreach ($settings as $row) {
                $data[] = [
                    "name" => $row,
                    "data" => $row == 'timetable_duration' ? Carbon::createFromTimestampUTC($request->$row * 60)->format('H:i:s') : date("H:i:s", strtotime($request->$row)),
                    "type" => 'time'
                ];
            }
            $this->schoolSettings->upsert($data, ["name"], ["data", "type"]);
            DB::commit();
            ResponseService::successResponse('Data Updated Successfully');
        } catch (Throwable $e) {
            DB::rollBack();
            ResponseService::logErrorResponse($e, "Timetable Controller -> updateTimetableSettings");
            ResponseService::errorResponse();
        }
    }

    public function deleteClassTimetable($id)
    {
        ResponseService::noFeatureThenRedirect('Timetable Management');
        ResponseService::noPermissionThenSendJson('timetable-delete');
        try {
            $this->timetable->builder()->where('class_section_id',$id)->delete();
            ResponseService::successResponse('Data Deleted Successfully');
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }

    public function sendSMSMessage($id) {
        ResponseService::noFeatureThenRedirect('Timetable Management');
        ResponseService::noPermissionThenRedirect(['timetable-edit']);
        Log::channel('custom')->error('4444444');
        $sql = "select * from (select s.*, cs.class_id, concat(c.name, '(', Nvl(st.name, ''), ')', nvl(sh.name, ''), '-', nvl(sh.name, '')) class_name, concat(u.first_name, ' ',u.last_name) full_name, concat(g.first_name, ' ', g.last_name) guardian_name, u.mobile mobile_s, g.mobile mobile_g " . 
            "from students s " . 
            "left join users u on s.user_id = u.id " . 
            "left join users g on s.guardian_id = g.id " . 
            "left join class_sections cs on cs.id = s.class_section_id " . 
            "left join classes c on c.id = cs.class_id " . 
            "left join mediums m on c.medium_id = m.id " . 
            "left join shifts sh on c.shift_id = sh.id " . 
            "left join streams st on c.stream_id = st.id " . 
            ") s where s.class_section_id = " . $id;

        Log::channel('custom')->error('$sql:' . $sql);
        $students = DB::select($sql);

        Log::channel('custom')->error('students:' . json_encode($students));

        $sql = "select ct.teacher_id, u.mobile, u.first_name, u.last_name from subject_teachers ct left join users u on ct.teacher_id = u.id where ct.class_section_id = " . $id;
        Log::channel('custom')->error('$sql:' . $sql);
        $teachers = DB::select($sql);

        Log::channel('custom')->error('teachers:' . json_encode($teachers));


        $text = 'Schudel change';

        Log::channel('custom')->error('text:' . json_encode($text));

        $settings = app(CachingService::class)->getSystemSettings();
        Log::channel('custom')->error('settings:' . json_encode($settings));

        Log::channel('custom')->error('user:' . json_encode(Auth::user()));

        $placeholder = $this->replacePlaceholdersForSMS(Auth::user(), $settings, $text);
        Log::channel('custom')->error('placeholder:' . json_encode($placeholder));
        
        $this->sendSMSForFeeCreated(Auth::user()->school_id, "assignment_exams_timetable_attendance_holiday_created", $placeholder, $students, $teachers);

        ResponseService::successResponse('SMS Message Send Successfully');
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
