<?php

namespace App\Http\Controllers\Exam;

use Throwable;
use Illuminate\Http\Request;
use App\Services\CachingService;
use App\Services\ResponseService;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Repositories\Exam\ExamInterface;
use Illuminate\Support\Facades\Validator;
use App\Repositories\ExamTimetable\ExamTimetableInterface;

use Illuminate\Support\Facades\Log;
use App\Models\User;
use App\Models\Students;
use App\Repositories\School\SchoolInterface;
use Illuminate\Support\Facades\Auth;


class ExamTimetableController extends Controller {
    private ExamInterface $exam;
    private ExamTimetableInterface $examTimetable;
    private CachingService $cache;
    private SchoolInterface $schoolsRepository1;

    public function __construct(ExamInterface $exam, ExamTimetableInterface $examTimetable, CachingService $cache, SchoolInterface $schoolsRepository1) {
        $this->exam = $exam;
        $this->examTimetable = $examTimetable;
        $this->cache = $cache;
        $this->schoolsRepository1 = $schoolsRepository1;
    }

    public function edit($examId) {
        ResponseService::noFeatureThenRedirect('Exam Management');
        ResponseService::noPermissionThenRedirect('exam-timetable-list');
        $currentSessionYear = $this->cache->getDefaultSessionYear();
        $currentSemester = $this->cache->getDefaultSemesterData();
        $exam = $this->exam->builder()->where(['id' => $examId])->with(['class.medium', 'class.all_subjects' => function($query) use($currentSemester){
            (isset($currentSemester) && !empty($currentSemester)) ? $query->where('semester_id',$currentSemester->id)->orWhereNull('semester_id') : $query->orWhereNull('semester_id');
        }, 'timetable'])->firstOrFail();
        $disabled = $exam->publish ? 'disabled' : '';
        return response(view('exams.timetable', compact('exam','currentSessionYear','disabled')));
    }

    public function update(Request $request, $examID) {
        ResponseService::noFeatureThenRedirect('Exam Management');
        ResponseService::noPermissionThenSendJson('exam-timetable-create');
        
        $validator = Validator::make($request->all(), [
            'timetable'                 => 'required|array',
            'timetable.*.passing_marks' => 'required|lte:timetable.*.total_marks',
            'timetable.*.end_time'      => 'required|after:timetable.*.start_time',
            'timetable.*.date'          => 'required|date',
        ], [
            'timetable.*.passing_marks.lte' => trans('passing_marks_should_less_than_or_equal_to_total_marks'),
            'timetable.*.end_time.after'    => trans('end_time_should_be_greater_than_start_time')
        ]);
        if ($validator->fails()) {
            ResponseService::errorResponse($validator->errors()->first());
        }
        try {
            DB::beginTransaction();

            foreach ($request->timetable as $timetable) {
                $examTimetable = array(
                    'exam_id'           => $examID,
                    'class_subject_id'  => $timetable['class_subject_id'],
                    'total_marks'       => $timetable['total_marks'],
                    'passing_marks'     => $timetable['passing_marks'],
                    'start_time'        => $timetable['start_time'],
                    'end_time'          => $timetable['end_time'],
                    'date'              => date('Y-m-d', strtotime($timetable['date'])),
                    'session_year_id'   => $request->session_year_id,
                );
                $this->examTimetable->updateOrCreate(['id' => $timetable['id'] ?? null], $examTimetable);
            }

            // Get Start Date & End Date From Exam Timetable
            $examTimetable = $this->examTimetable->builder()->where('exam_id',$examID);
            $startDate = $examTimetable->min('date');
            $endDate = $examTimetable->max('date');

            // Update Start Date and End Date to the particular Exam
            $this->exam->update($examID,['start_date' => $startDate,'end_date' => $endDate]);

            $students = DB::select(
                "select * from (select s.*, e.id exam_id, cs.class_id, concat(c.name, '(', Nvl(st.name, ''), ')', nvl(sh.name, ''), '-', nvl(sh.name, '')) class_name, concat(u.first_name, ' ',u.last_name) full_name, concat(g.first_name, ' ', g.last_name) guardian_name, u.mobile mobile_s, g.mobile mobile_g " . 
                "from students s " . 
                "left join users u on s.user_id = u.id " . 
                "left join users g on s.guardian_id = g.id " . 
                "left join class_sections cs on cs.id = s.class_section_id " . 
                "left join classes c on c.id = cs.class_id " . 
                "left join mediums m on c.medium_id = m.id " . 
                "left join shifts sh on c.shift_id = sh.id " . 
                "left join streams st on c.stream_id = st.id " . 
                "left join exams e on e.class_id = c.id " . 
                ") s where s.exam_id = " . $examID);


            $teachers = DB::select("select DISTINCT ct.teacher_id, u.mobile, u.first_name, u.last_name " . 
                "from class_sections cs " . 
                "left join class_teachers ct on cs.section_id = ct.class_section_id " . 
                "left join users u on u.id = ct.teacher_id " . 
                "left join exams e on e.class_id = cs.class_id " . 
                "where e.id = " . $examID . " and teacher_id is not null");

            $exam_row = DB::select("select e.*, sy.name session_year_name from exams e left join session_years sy on e.session_year_id = sy.id where e.id = " . $examID);
           
            $subjects = DB::select("select GROUP_CONCAT(s.name SEPARATOR ', ') subjects_name from exam_timetables et left join class_subjects cs on cs.id = et.class_subject_id left join subjects s on cs.subject_id = s.id where exam_id = " . $examID);
            
            $text = 'Offline ' . $exam_row[0]->session_year_name .' ' . $exam_row[0]->name . 'date: ' . $startDate . '~' . $endDate . '\n' . $subjects[0]->subjects_name;

            $settings = app(CachingService::class)->getSystemSettings();
            $placeholder = $this->replacePlaceholdersForSMS(Auth::user(), $settings, $text);
            Log::channel('custom')->error('placeholder:' . json_encode($placeholder));
            
            $this->sendSMSForFeeCreated(Auth::user()->school_id, "assignment_exams_timetable_attendance_holiday_created", $placeholder, $students, $teachers);

            DB::commit();
            ResponseService::successResponse('Data Stored Successfully');
        } catch (Throwable $e) {
            DB::rollBack();
            ResponseService::logErrorResponse($e, "Exam Timetable Controller -> Store method");
            ResponseService::errorResponse();
        }
    }

    public function destroy($id) {
        ResponseService::noFeatureThenRedirect('Exam Management');
        ResponseService::noPermissionThenSendJson('exam-timetable-delete');
        try {
            $this->examTimetable->deleteById($id);
            ResponseService::successResponse('Data Deleted Successfully');
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e, "Exam Controller -> DeleteTimetable method");
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
