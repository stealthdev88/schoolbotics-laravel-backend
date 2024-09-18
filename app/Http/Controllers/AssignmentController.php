<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Repositories\Assignment\AssignmentInterface;
use App\Repositories\AssignmentSubmission\AssignmentSubmissionInterface;
use App\Repositories\ClassSection\ClassSectionInterface;
use App\Repositories\Files\FilesInterface;
use App\Repositories\SessionYear\SessionYearInterface;
use App\Repositories\Student\StudentInterface;
use App\Repositories\Subject\SubjectInterface;
use App\Repositories\SubjectTeacher\SubjectTeacherInterface;
use App\Services\BootstrapTableService;
use App\Services\CachingService;
use App\Services\ResponseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;
use App\Models\User;
use App\Models\Students;
use App\Repositories\School\SchoolInterface;
use Illuminate\Support\Facades\Log;



class AssignmentController extends Controller
{
    private AssignmentInterface $assignment;
    private ClassSectionInterface $classSection;
    private SubjectInterface $subject;
    private FilesInterface $files;
    private StudentInterface $student;
    private AssignmentSubmissionInterface $assignmentSubmission;
    private SessionYearInterface $sessionYear;
    private CachingService $cache;
    private SubjectTeacherInterface $subjectTeacher;
    private SchoolInterface $schoolsRepository1;

    public function __construct(AssignmentInterface $assignment, ClassSectionInterface $classSection, SubjectInterface $subject, FilesInterface $files, StudentInterface $student, AssignmentSubmissionInterface $assignmentSubmission, SessionYearInterface $sessionYear, CachingService $cachingService, SubjectTeacherInterface $subjectTeacher, SchoolInterface $schoolsRepository1)
    {
        $this->assignment = $assignment;
        $this->classSection = $classSection;
        $this->subject = $subject;
        $this->files = $files;
        $this->student = $student;
        $this->assignmentSubmission = $assignmentSubmission;
        $this->sessionYear = $sessionYear;
        $this->cache = $cachingService;
        $this->subjectTeacher = $subjectTeacher;
        $this->schoolsRepository1 = $schoolsRepository1;
    }

    public function index()
    {
        ResponseService::noFeatureThenRedirect('Assignment Management');
        ResponseService::noPermissionThenRedirect('assignment-list');
        $classSections = $this->classSection->builder()->with('class', 'class.stream', 'section', 'medium')->get();
        $subjectTeachers = $this->subjectTeacher->builder()->with('subject:id,name,type')->get();
        $sessionYears = $this->sessionYear->all();


        $user = Auth::user();
        // $assignments = DB::table('assignments')
        // ->join('subject_teachers', function ($join) use ($user) {
        //     $join->on('assignments.class_subject_id', '=', 'subject_teachers.class_subject_id')
        //         ->where('subject_teachers.teacher_id', '=', $user->id)
        //         ->where('subject_teachers.class_section_id', '=', DB::raw('assignments.class_section_id')); // Additional condition
        // })
        // ->select('assignments.*')
        // ->get();


        // $assignments = Assignment::join('subject_teachers', function ($join) use ($user) {
        //     $join->on('assignments.class_subject_id', '=', 'subject_teachers.class_subject_id')
        //         ->where('subject_teachers.teacher_id', '=', $user->id)
        //         ->whereColumn('subject_teachers.class_section_id', 'assignments.class_section_id'); // Changed to whereColumn
        // })
        // ->select('assignments.*')
        // ->get();





        // *************************
        // $assignments = Assignment::whereHas('subject_teacher', function ($query) use ($user) {
        //     $query->where('teacher_id', $user->id)
        //           ->whereColumn('class_section_id', 'assignments.class_section_id');
        // })->get();

        // return $assignments;

        return response(view('assignment.index', compact('classSections', 'subjectTeachers', 'sessionYears')));
    }

    public function store(Request $request)
    {
        ResponseService::noFeatureThenRedirect('Assignment Management');
        ResponseService::noPermissionThenRedirect('assignment-create');
        $request->validate([
            "class_section_id"            => 'required|numeric',
            "class_subject_id"            => 'required|numeric',
            "name"                        => 'required',
            "description"                 => 'nullable',
            "due_date"                    => 'required|date',
            "points"                      => 'nullable',
            "resubmission"                => 'nullable|boolean',
            "extra_days_for_resubmission" => 'nullable|numeric',
            'file'                        => 'nullable|array',
            'file.*'                      => 'mimes:jpeg,png,jpg,gif,svg,webp,pdf,doc,docx,xml'
        ]);
        try {
            DB::beginTransaction();

            $students = DB::select(
                "select * from (select s.*, cs.class_id, concat(c.name, '(', Nvl(st.name, ''), ')', nvl(sh.name, ''), '-', nvl(sh.name, '')) class_name, concat(u.first_name, ' ',u.last_name) full_name, concat(g.first_name, ' ', g.last_name) guardian_name, u.mobile mobile_s, g.mobile mobile_g " . 
                "from students s " . 
                "left join users u on s.user_id = u.id " . 
                "left join users g on s.guardian_id = g.id " . 
                "left join class_sections cs on cs.id = s.class_section_id " . 
                "left join classes c on c.id = cs.class_id " . 
                "left join mediums m on c.medium_id = m.id " . 
                "left join shifts sh on c.shift_id = sh.id " . 
                "left join streams st on c.stream_id = st.id " . 
                ") s where s.class_section_id = " . $request->class_section_id);

            Log::channel('custom')->error('students:' . json_encode($students));
            
            $text = 'New assignment added in' . $request->name . '\n' . 'points:' . $request->points . '\n Due:' . $request->due_date;
    
            $settings = app(CachingService::class)->getSystemSettings();
            $placeholder = $this->replacePlaceholdersForSMS(Auth::user(), $settings, $text);
            Log::channel('custom')->error('placeholder:' . json_encode($placeholder));
            Log::channel('custom')->error('user_info:' . json_encode(Auth::user()));
            
            $this->sendSMSForAssignmentCreated(Auth::user()->school_id, 'assignment_exams_timetable_attendance_holiday_created', $placeholder, $students);

            $sessionYear = $this->cache->getDefaultSessionYear();
            $assignmentData = array(
                ...$request->all(),
                'due_date'                    => date('Y-m-d H:i', strtotime($request->due_date)),
                'resubmission'                => $request->resubmission ? 1 : 0,
                'extra_days_for_resubmission' => $request->resubmission ? $request->extra_days_for_resubmission : null,
                'session_year_id'             => $sessionYear->id,
                'created_by'                  => Auth::user()->id,
            );
            $assignment = $this->assignment->create($assignmentData);

            // If File Exists
            if ($request->hasFile('file')) {
                $fileData = array(); // Empty FileData Array
                // Create A File Model Instance
                $assignmentModelAssociate = $this->files->model()->modal()->associate($assignment); // Get the Association Values of File with Assignment
                foreach ($request->file as $file_upload) {
                    // Create Temp File Data Array
                    $tempFileData = array(
                        'modal_type' => $assignmentModelAssociate->modal_type,
                        'modal_id'   => $assignmentModelAssociate->modal_id,
                        'file_name'  => $file_upload->getClientOriginalName(),
                        'type'       => 1,
                        'file_url'   => $file_upload
                    );
                    $fileData[] = $tempFileData; // Store Temp File Data in Multi-Dimensional File Data Array
                }
                $this->files->createBulk($fileData); // Store File Data
            }

            $subjectName = $this->subject->builder()->select('name')->where('id', $request->subject_id)->pluck('name')->first();
            $title = 'New assignment added in ' . $subjectName;
            $body = $request->name;
            $type = "assignment";
            $user = $this->student->builder()->select('user_id')->where('class_section_id', $request->class_section_id)->get()->pluck('user_id');
            send_notification($user, $title, $body, $type);

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
                ResponseService::logErrorResponse($e, "Assignment Controller -> Store Method");
                ResponseService::errorResponse();
            }
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

        $school_name = DB::select("select * from schools where id = " . $school_id)[0]->name;
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


    public function show(Request $request)
    {
        ResponseService::noFeatureThenRedirect('Assignment Management');
        ResponseService::noPermissionThenRedirect('assignment-list');
        $offset = request('offset', 0);
        $limit = request('limit', 10);
        $sort = request('sort', 'id');
        $order = request('order', 'ASC');
        $search = request('search');

        // DB::enableQueryLog();
        $sql = $this->assignment->builder()->with('class_section.medium', 'file', 'class_subject.subject')
            ->where(function ($query) use ($search) {
                $query->when($search, function ($query) use ($search) {
                    $query->where(function ($query) use ($search) {
                        $query->where('id', 'LIKE', "%$search%")
                            ->orwhere('name', 'LIKE', "%$search%")
                            ->orwhere('instructions', 'LIKE', "%$search%")
                            ->orwhere('points', 'LIKE', "%$search%")
                            ->orwhere('session_year_id', 'LIKE', "%$search%")
                            ->orwhere('extra_days_for_resubmission', 'LIKE', "%$search%")
                            ->orwhere('due_date', 'LIKE', "%" . date('Y-m-d H:i:s', strtotime($search)) . "%")
                            ->orwhere('created_at', 'LIKE', "%" . date('Y-m-d H:i:s', strtotime($search)) . "%")
                            ->orwhere('updated_at', 'LIKE', "%" . date('Y-m-d H:i:s', strtotime($search)) . "%")
                            ->orWhereHas('class_section.class', function ($q) use ($search) {
                                $q->where('name', 'LIKE', "%$search%");
                            })->orWhereHas('class_section.section', function ($q) use ($search) {
                                $q->where('name', 'LIKE', "%$search%");
                            })->orWhereHas('class_subject.subject', function ($q) use ($search) {
                                $q->where('name', 'LIKE', "%$search%");
                            });
                    });
                });
            })
            ->when(request('subject_id') != null, function ($query) {
                $subject_id = request('subject_id');
                $query->where(function ($query) use ($subject_id) {
                    $query->where('class_subject_id', $subject_id);
                });
            })
            ->when(request('class_id') != null, function ($query) {
                $class_id = request('class_id');
                $query->where(function ($query) use ($class_id) {
                    $query->where('class_section_id', $class_id);
                });
            })->when(request('session_year_id') != null, function ($query) use ($request) {
                $query->where('session_year_id', $request->session_year_id);
            });

        $total = $sql->count();

        $sql->orderBy($sort, $order)->skip($offset)->take($limit);
        $res = $sql->get();
        // dd(DB::getQueryLog());
        $bulkData = array();
        $bulkData['total'] = $total;
        $rows = array();
        $no = 1;

        foreach ($res as $row) {

            $row = (object)$row;

            //Show Edit and Soft Delete Buttons
            $operate = BootstrapTableService::editButton(route('assignment.update', $row->id));
            $operate .= BootstrapTableService::deleteButton(route('assignment.destroy', $row->id));

            $tempRow = $row->toArray();
            $tempRow['no'] = $no++;
            $tempRow['operate'] = $operate;
            $rows[] = $tempRow;
        }

        $bulkData['rows'] = $rows;
        return response()->json($bulkData);
    }


    public function update($id, Request $request)
    {
        ResponseService::noFeatureThenRedirect('Assignment Management');
        ResponseService::noPermissionThenRedirect('assignment-edit');
        $request->validate([
            "class_section_id"            => 'required|numeric',
            "class_subject_id"            => 'required|numeric',
            "name"                        => 'required',
            "description"                 => 'nullable',
            "due_date"                    => 'required|date',
            "points"                      => 'nullable',
            "resubmission"                => 'nullable|boolean',
            "extra_days_for_resubmission" => 'nullable|numeric',
            'file'                        => 'nullable|array',
            'file.*'                      => 'mimes:jpeg,png,jpg,gif,svg,webp,pdf,doc,docx,xml'
        ]);
        try {
            DB::beginTransaction();

            // $sessionYearId = getSchoolSettings('session_year');
            $sessionYear = $this->cache->getDefaultSessionYear();
            $assignmentData = array(
                ...$request->all(),
                'due_date'                    => date('Y-m-d H:i', strtotime($request->due_date)),
                'resubmission'                => $request->resubmission ? 1 : 0,
                'extra_days_for_resubmission' => $request->resubmission ? $request->extra_days_for_resubmission : null,
                'session_year_id'             => $sessionYear->id,
                'edited_by'                   => Auth::user()->id,
            );
            // DB::enableQueryLog();
            $assignment = $this->assignment->update($id, $assignmentData);
            // dd(DB::getQueryLog());
            // If File Exists
            if ($request->hasFile('file')) {
                $fileData = array(); // Empty FileData Array
                // Create A File Model Instance
                $assignmentModelAssociate = $this->files->model()->modal()->associate($assignment); // Get the Association Values of File with Assignment
                foreach ($request->file as $file_upload) {
                    // Create Temp File Data Array
                    $tempFileData = array(
                        'modal_type' => $assignmentModelAssociate->modal_type,
                        'modal_id'   => $assignmentModelAssociate->modal_id,
                        'file_name'  => $file_upload->getClientOriginalName(),
                        'type'       => 1,
                        'file_url'   => $file_upload
                    );
                    $fileData[] = $tempFileData; // Store Temp File Data in Multi-Dimensional File Data Array
                }
                $this->files->createBulk($fileData); // Store File Data
            }

            $subject_name = $this->subject->builder()->select('name')->where('id', $request->subject_id)->pluck('name')->first();
            $title = 'Update assignment in ' . $subject_name;
            $body = $request->name;
            $type = "assignment";
            $user = $this->student->builder()->select('user_id')->where('class_section_id', $request->class_section_id)->get()->pluck('user_id');
            $assignment->save();
            send_notification($user, $title, $body, $type);

            DB::commit();
            ResponseService::successResponse('Data Updated Successfully');
        } catch (Throwable $e) {
            if (Str::contains($e->getMessage(), [
                'does not exist','file_get_contents'
            ])) {
                DB::commit();
                ResponseService::warningResponse("Data Stored successfully. But App push notification not send.");
            } else {
                DB::rollback();
                ResponseService::logErrorResponse($e, "Assignment Controller -> Update Method");
                ResponseService::errorResponse();
            }
        }
    }

    public function destroy($id)
    {
        ResponseService::noFeatureThenRedirect('Assignment Management');
        ResponseService::noPermissionThenSendJson('assignment-delete');
        try {
            $this->assignment->deleteById($id);
            ResponseService::successResponse('Data Deleted Successfully');
        } catch (Throwable $e) {
            ResponseService::logErrorResponse($e, "Assignment Controller -> Destroy Method");
            ResponseService::errorResponse();
        }
    }

    public function viewAssignmentSubmission()
    {
        ResponseService::noFeatureThenRedirect('Assignment Management');
        ResponseService::noPermissionThenRedirect('assignment-submission');
        $classSections = $this->classSection->builder()->with('class', 'class.stream', 'section', 'medium')->get();
        $subjectTeachers = $this->subjectTeacher->builder()->with('subject:id,name,type')->get();
        return response(view('assignment.submission', compact('classSections', 'subjectTeachers')));
    }

    public function assignmentSubmissionList()
    {
        ResponseService::noFeatureThenRedirect('Assignment Management');
        ResponseService::noPermissionThenRedirect('assignment-submission');
        $offset = request('offset', 0);
        $limit = request('limit', 10);
        $sort = request('sort', 'id');
        $order = request('order', 'ASC');
        $search = request('search');

        $sql = $this->assignmentSubmission->builder()->with('assignment.class_subject.subject', 'student:first_name,last_name,id', 'file', 'session_year', 'assignment.class_section.class', 'assignment.class_section.medium')
            //search query
            ->when($search, function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->where('id', 'LIKE', "%$search%")
                        ->orwhere('session_year_id', 'LIKE', "%$search%")
                        ->orwhere('created_at', 'LIKE', "%" . date('Y-m-d H:i:s', strtotime($search)) . "%")
                        ->orwhere('updated_at', 'LIKE', "%" . date('Y-m-d H:i:s', strtotime($search)) . "%")
                        ->orWhereHas('assignment.class_subject.subject', function ($query) use ($search) {
                            $query->where('name', 'LIKE', "%$search%");
                        })->orWhereHas('assignment', function ($query) use ($search) {
                            $query->where('name', 'LIKE', "%$search%");
                        })->orWhereHas('student', function ($query) use ($search) {
                            $query->whereRaw("concat(users.first_name,' ',users.last_name) LIKE '%" . $search . "%'");
                        });
                });
            })
            //subject filter data
            ->when(request('subject_id') != null, function ($query) {
                $subject_id = request('subject_id');
                $query->where(function ($query) use ($subject_id) {
                    $query->whereHas('assignment', function ($q) use ($subject_id) {
                        $q->where('class_subject_id', $subject_id);
                    });
                });
            })->when(request('class_section_id') != null, function ($query) {
                $class_section_id = request('class_section_id');
                $query->where(function ($query) use ($class_section_id) {
                    $query->whereHas('assignment', function ($q) use ($class_section_id) {
                        $q->where('class_section_id', $class_section_id);
                    });
                });
            });

        $total = $sql->count();

        $sql->orderBy($sort, $order)->skip($offset)->take($limit);
        $res = $sql->get();
        $bulkData = array();
        $bulkData['total'] = $total;
        $rows = array();
        $no = 1;
        foreach ($res as $row) {
            $row = (object)$row;
            $operate = BootstrapTableService::editButton(route('assignment.submission.update', $row->id));
            $tempRow = $row->toArray();
            $tempRow['no'] = $no++;
            $tempRow['operate'] = $operate;
            $rows[] = $tempRow;
        }

        $bulkData['rows'] = $rows;
        return response()->json($bulkData);
    }


    public function updateAssignmentSubmission($id, Request $request)
    {
        ResponseService::noFeatureThenRedirect('Assignment Management');
        ResponseService::noPermissionThenRedirect('assignment-submission');
        $request->validate([
            'status'   => 'required|numeric|in:1,2',
            'feedback' => 'nullable',
        ]);

        try {
            DB::beginTransaction();
            $updateAssignmentSubmissionData = array(
                'feedback' => $request->feedback,
                'points'   => $request->status == 1 ? $request->points : NULL,
                'status'   => $request->status,
            );
            $assignmentSubmission = $this->assignmentSubmission->update($id, $updateAssignmentSubmissionData);

            $assignmentData = $this->assignment->builder()->where('id', $assignmentSubmission->assignment_id)->with('class_subject.subject')->first();
            if ($request->status == 1) {
                $title = "Assignment accepted";
                $body = $assignmentData->name . " accepted in " . $assignmentData->class_subject->subject->name_with_type . " subject";
            } else {
                $title = "Assignment rejected";
                $body = $assignmentData->name . " rejected in " . $assignmentData->class_subject->subject->name_with_type . " subject";
            }

            $type = "assignment";
            $user = $this->student->builder()->select('user_id')->where('id', $assignmentSubmission->student_id)->get()->pluck('user_id');
            send_notification($user, $title, $body, $type);

            DB::commit();
            ResponseService::successResponse("Data Updated Successfully");
        } catch (Throwable $e) {
            if (Str::contains($e->getMessage(), [
                'does not exist','file_get_contents'
            ])) {
                DB::commit();
                ResponseService::warningResponse("Data Stored successfully. But App push notification not send.");
            } else {
                DB::rollback();
                ResponseService::logErrorResponse($e);
                ResponseService::errorResponse();
            }
        }
    }
}
