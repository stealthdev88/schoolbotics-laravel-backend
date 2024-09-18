<?php

namespace App\Http\Controllers;

use App\Models\PromoteStudent;
use App\Repositories\ClassSection\ClassSectionInterface;
use App\Repositories\PromoteStudent\PromoteStudentInterface;
use App\Repositories\SessionYear\SessionYearInterface;
use App\Repositories\Student\StudentInterface;
use App\Repositories\User\UserInterface;
use App\Services\CachingService;
use App\Services\ResponseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

use Illuminate\Support\Facades\Log;
use App\Models\User;
use App\Models\Students;
use App\Repositories\School\SchoolInterface;
use Illuminate\Support\Facades\Auth;


class PromoteStudentController extends Controller {

    private ClassSectionInterface $classSection;
    private SessionYearInterface $sessionYear;
    private StudentInterface $student;
    private UserInterface $user;
    private PromoteStudentInterface $promoteStudent;
    private CachingService $cache;
    private SchoolInterface $schoolsRepository1;

    public function __construct(ClassSectionInterface $classSection, SessionYearInterface $sessionYear, StudentInterface $student, UserInterface $user, PromoteStudentInterface $promoteStudent, CachingService $cachingService, SchoolInterface $schoolsRepository1) {
        $this->classSection = $classSection;
        $this->sessionYear = $sessionYear;
        $this->student = $student;
        $this->user = $user;
        $this->promoteStudent = $promoteStudent;
        $this->cache = $cachingService;
        $this->schoolsRepository1 = $schoolsRepository1;        
    }

    public function index() {
        ResponseService::noAnyPermissionThenRedirect(['promote-student-list','transfer-student-list']);
        $classSections = $this->classSection->all(['*'], ['class', 'section', 'medium']);
        $sessionYears = $this->sessionYear->builder()->select(['id', 'name'])->where('default', 0)->get();
        return view('promote_student.index', compact('classSections', 'sessionYears'));
    }

    public function store(Request $request) {
        ResponseService::noAnyPermissionThenSendJson(['promote-student-create', 'promote-student-edit']);
        $request->validate([
            'class_section_id' => 'required',
            'promote_data' => 'required'
        ], ['promote_data.required' => "No Student Data Found"]);
        try {
            DB::beginTransaction();

            $promoteStudentData = array();
            foreach ($request->promote_data as $key => $data) {
                $promoteStudentData[$key] = array(
                    'student_id'      => $data['student_id'],
                    'session_year_id' => $request->session_year_id,
                    'result'          => $data['result'],
                    'status'          => $data['status'],
                );

                $allStudentsIds[] = $data['student_id'];

                if ($data['result'] == 1) {
                    // IF Student Then Store New Class Section in Promote Data
                    $promoteStudentData[$key]['class_section_id'] = $request->new_class_section_id;

                    if ($data['status'] == 1) {
                        // IF Student Continues then get students IDs
                        $passStudentsIds[] = $data['student_id'];
                    }
                } else {
                    // IF Students Fails then store Current Class Section in Promote Data
                    $promoteStudentData[$key]['class_section_id'] = $request->class_section_id;

                    if ($data['status'] == 1) {
                        // IF Student Fails then get students IDs
                        $failStudentsIds[] = $data['student_id'];
                    }
                }

                // IF Student Leaves then get Student IDs
                if ($data['status'] == 0) {
                    $leftStudentSIds[] = $data['student_id'];
                }
            }
            Log::channel('custom')->error('passStudentsIds' . json_encode($passStudentsIds));
            Log::channel('custom')->error('failStudentsIds' . json_encode($failStudentsIds));
            Log::channel('custom')->error('leftStudentSIds' . json_encode($leftStudentSIds));
            Log::channel('custom')->error('allStudentsIds' . json_encode($allStudentsIds));
            
            if (!empty($passStudentsIds)) {

                // Get Sort Value and Order Value from Settings
                $sortBy = !empty($this->cache->getSchoolSettings('roll_number_sort_column')) ? $this->cache->getSchoolSettings('roll_number_sort_column') : 'first_name';
                $orderBy = !empty($this->cache->getSchoolSettings('roll_number_sort_order')) ? $this->cache->getSchoolSettings('roll_number_sort_order') : 'asc';

                // Get The Data of Users who is passed with Student Relation and make Array to Update Student Details
                $studentUsers = $this->user->builder()->role('Student')->whereIn('id',$passStudentsIds)->with('student')->orderBy('users.'.$sortBy, $orderBy)->get();
                $studentsData = array();
                foreach ($studentUsers as $key => $user) {
                    $studentsData[] = array(
                        'id' => $user->student->id,
                        'roll_number' => (int)$key + 1,
                        'class_section_id' => $request->new_class_section_id,
                        'session_year_id'  => $request->session_year_id,
                    );
                }

                // Upsert Student Data
                $this->student->upsert($studentsData,['id'],['roll_number','class_section_id','session_year_id']);
            }

            if (!empty($failStudentsIds)) {
                $this->student->builder()->whereIn('user_id', $failStudentsIds)->update(array(
                    'session_year_id' => $request->session_year_id,
                ));
            }

            if (!empty($leftStudentSIds)) {
                $this->user->builder()->whereIn('id', $leftStudentSIds)->update(['status' => 0,'deleted_at' => now()]);
            }
            $this->promoteStudent->upsert($promoteStudentData, ['class_section', 'student_id', 'session_year_id'], ['status', 'result']);
            Log::channel('custom')->error('promoteStudentData' . json_encode($promoteStudentData));
            
            DB::commit();
////////////////////////////////////////////////////////////////////////////////////////////////////
            $sql = "select ps.status, ps.result, u.mobile, ug.mobile guardian_mobile, sy.name, sn.section_name from promote_students ps 
                left join students st on st.user_id = ps.student_id
                left join users u on st.user_id = u.id
                left join users ug on st.guardian_id = ug.id
                left join session_years sy on ps.session_year_id = sy.id
                left join (SELECT  cs.id, concat(cls.name, ' ', se.name, '-',med.name) section_name from class_sections cs 
                left join sections se on se.id = cs.section_id  
                left join classes cls on cls.id = cs.class_id  
                left join mediums med on med.id = cs.medium_id 
                ) sn on sn.id = ps.class_section_id
                where ps.session_year_id = " . $request->session_year_id . " and ps.student_id in (" . implode(',', $allStudentsIds) . ")";

            Log::channel('custom')->error('$sql:' . $sql);
            $students = DB::select($sql);

            Log::channel('custom')->error('students:' . json_encode($students));
            $settings = app(CachingService::class)->getSystemSettings();

            foreach($students as $student) {
                $text = "result: " . ($student->result == 1 ? "Pass" : "Fail") . 
                    "  status: " . ($student->status == 1 ? "Continue" : "Leave") . "\n session:" . $student->name . " class section: " . $student->section_name;

                Log::channel('custom')->error('text:' . $text);
                $placeholder = $this->replacePlaceholdersForSMS(Auth::user(), $settings, $text);
                Log::channel('custom')->error('placeholder:' . json_encode($placeholder));
                
                $this->sendSMSForPromotedCreated(Auth::user()->school_id, "student_promoted_transferred", $placeholder, $student);                    
            }

            ResponseService::successResponse("Data Updated Successfully");

        } catch (Throwable $e) {
            DB::rollBack();
            ResponseService::logErrorResponse($e);
            ResponseService::errorResponse();
        }
    }

    public function getPromoteData(Request $request) {
        $response = PromoteStudent::where(['class_section_id' => $request->class_section_id])->get();
        return response()->json($response);
    }

    public function show(Request $request) {
        ResponseService::noPermissionThenRedirect('promote-student-list');
        $offset = request('offset', 0);
        $limit = request('limit', 10);
        $sort = request('sort', 'id');
        $order = request('order', 'ASC');
        $search = request('search');

        $class_section_id = $request->class_section_id;
        $sessionYear = $this->cache->getDefaultSessionYear(); // Get Current Session Year
        $sql = $this->student->builder()->where(['class_section_id' => $class_section_id, 'session_year_id' => $sessionYear->id])->whereHas('user', function ($query) {
            $query->where('status', 1);
        })->with('user')
            ->where(function ($query) use ($search) {
                $query->when($search, function ($query) use ($search) {
                $query->where('id', 'LIKE', "%$search%")
                ->orWhereHas('user',function($q) use($search){
                    $q->whereRaw("concat(users.first_name,' ',users.last_name) LIKE '%" . $search . "%'");
                });
            });
            });
        $total = $sql->count();
        // $sql->orderBy($sort, $order)->skip($offset)->take($limit);
        $sql->orderBy($sort, $order);
        $res = $sql->get();
        $bulkData = array();
        $bulkData['total'] = $total;
        $rows = array();
        $no = 1;
        foreach ($res as $row) {
            $tempRow = $row->toArray();
            $tempRow['no'] = $offset + $no++;
            $rows[] = $tempRow;
        }
        $bulkData['rows'] = $rows;
        return response()->json($bulkData);
    }

    public function showTransferStudent(Request $request) {
        // ResponseService::noFeatureThenRedirect('Academics Management');
        ResponseService::noPermissionThenRedirect('transfer-student-list');
        $offset = request('offset', 0);
        $limit = request('limit', 10);
        $sort = request('sort', 'id');
        $order = request('order', 'ASC');
        $search = request('search');

        $class_section_id = $request->current_class_section;
        $sessionYear = $this->cache->getDefaultSessionYear(); // Get Current Session Year
        $sql = $this->student->builder()->where(['class_section_id' => $class_section_id, 'session_year_id' => $sessionYear->id])->whereHas('user', function ($query) {
            $query->where('status', 1);
        })->with('user')
        ->where(function($q) use($search) {
            $q->when($search, function ($query) use ($search) {
                $query->where('id', 'LIKE', "%$search%")
                ->orWhereHas('user',function($q) use($search){
                    $q->whereRaw("concat(users.first_name,' ',users.last_name) LIKE '%" . $search . "%'");
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
            $tempRow['no'] = $offset + $no++;
            $tempRow['student_id'] = $row->id;
            $tempRow['user_id'] = $row->user_id;
            $tempRow['name'] = $row->full_name;
            $rows[] = $tempRow;
        }
        $bulkData['rows'] = $rows;
        return response()->json($bulkData);
    }

    public function storeTransferStudent(Request $request){
        // ResponseService::noFeatureThenRedirect('Academics Management');
        ResponseService::noAnyPermissionThenSendJson(['transfer-student-list', 'transfer-student-edit']);
        $request->validate([
            'current_class_section_id' => 'required',
            'new_class_section_id' => 'required',
            'student_ids' => 'required'
        ]);
        try {
            DB::beginTransaction();
            // $studentIds = json_decode($request->student_ids);
            $studentIds = explode(",",$request->student_ids);
            $roll_number_db = $this->student->builder()->select(DB::raw('max(roll_number)'))->where('class_section_id', $request->new_class_section_id)->first();
            $roll_number_db = $roll_number_db['max(roll_number)'];

            $updateStudent = array();
            foreach ($studentIds as $id) {
                $updateStudent[] = array(
                    'id' => $id,
                    'class_section_id' => $request->new_class_section_id,
                    'roll_number' => (int)$roll_number_db + 1,
                );
            }

            $this->student->upsert($updateStudent,['id'],['class_section_id','roll_number']);

            
            $sql = "select u.mobile, ug.mobile guardian_mobile from students s " . 
                "left join users u on u.id = s.user_id left join users ug on ug.id = s.guardian_id where s.id in (" . implode(',', $studentIds) . ")";

            Log::channel('custom')->error('$sql:' . $sql);
            $students = DB::select($sql);

            Log::channel('custom')->error('students:' . json_encode($students));

            $sql = "SELECT  concat(cls.name, ' ', se.name, '-',med.name) section_name from class_sections cs " . 
                "left join sections se on se.id = cs.section_id  " . 
                "left join classes cls on cls.id = cs.class_id  " . 
                "left join mediums med on med.id = cs.medium_id " . 
                "where cs.id = " . $request->new_class_section_id;

            Log::channel('custom')->error('$sql:' . $sql);
            $new_section_name = DB::select($sql)[0]->section_name;

            Log::channel('custom')->error('new_section_name:' . $new_section_name);

            $sql = "SELECT  concat(cls.name, ' ', se.name, '-',med.name) section_name from class_sections cs " . 
                "left join sections se on se.id = cs.section_id  " . 
                "left join classes cls on cls.id = cs.class_id  " . 
                "left join mediums med on med.id = cs.medium_id " . 
                "where cs.id = " . $request->current_class_section_id;

            Log::channel('custom')->error('$sql:' . $sql);
            $current_section_name = DB::select($sql)[0]->section_name;

            Log::channel('custom')->error('current_section_name:' . $current_section_name);


            $text = $current_section_name . ' -> ' . $new_section_name;

            Log::channel('custom')->error('text:' . $text);

            // $text = "Dear [Parent name] your ward, [student name] with [GR number] has been transferred from [ current class name] to [ new class name] for the academic year [session name]. Thank you!";

            $settings = app(CachingService::class)->getSystemSettings();
            $placeholder = $this->replacePlaceholdersForSMS(Auth::user(), $settings, $text);
            Log::channel('custom')->error('placeholder:' . json_encode($placeholder));
            
            $this->sendSMSForTransforCreated(Auth::user()->school_id, "student_promoted_transferred", $placeholder, $students);

            DB::commit();
            ResponseService::successResponse("Data Updated Successfully");
        } catch (Throwable $e) {
            DB::rollback();
            ResponseService::logErrorResponse($e);
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
