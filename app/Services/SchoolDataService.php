<?php

namespace App\Services;

use App\Models\SchoolSetting;
use App\Models\SessionYear;
use Carbon\Carbon;
use Spatie\Permission\Models\Role;

class SchoolDataService
{

    public function preSettingsSetup($schoolData)
    {

        $this->createPreSetupRole($schoolData);
        $sessionYear = SessionYear::updateOrCreate(
            [
                'name'      => Carbon::now()->format('Y'),
                'school_id' => $schoolData->id
            ],
            [
                'default'    => 1,
                'start_date' => Carbon::now()->startOfYear()->format('Y-m-d'),
                'end_date'   => Carbon::now()->endOfYear()->format('Y-m-d'),
            ]
        );
        // Add School Setting Data
        $schoolSettingData = array(
            [
                'name'      => 'school_name',
                'data'      => $schoolData->name,
                'type'      => 'string',
                'school_id' => $schoolData->id,
            ],
            [
                'name'      => 'school_email',
                'data'      => $schoolData->support_email,
                'type'      => 'string',
                'school_id' => $schoolData->id
            ],
            [
                'name'      => 'school_phone',
                'data'      => $schoolData->support_phone,
                'type'      => 'number',
                'school_id' => $schoolData->id
            ],
            [
                'name'      => 'school_tagline',
                'data'      => $schoolData->tagline,
                'type'      => 'string',
                'school_id' => $schoolData->id
            ],
            [
                'name'      => 'school_address',
                'data'      => $schoolData->address,
                'type'      => 'string',
                'school_id' => $schoolData->id
            ],
            [
                'name'      => 'session_year',
                'data'      => $sessionYear->id,
                'type'      => 'number',
                'school_id' => $schoolData->id,
            ],
            [
                'name'      => 'horizontal_logo',
                'data'      => '',
                'type'      => 'file',
                'school_id' => $schoolData->id,
            ],
            [
                'name'      => 'vertical_logo',
                'data'      => '',
                'type'      => 'file',
                'school_id' => $schoolData->id,
            ],
            [
                'name'      => 'timetable_start_time',
                'data'      => '09:00:00',
                'type'      => 'time',
                'school_id' => $schoolData->id,
            ],
            [
                'name'      => 'timetable_end_time',
                'data'      => '18:00:00',
                'type'      => 'time',
                'school_id' => $schoolData->id,
            ],
            [
                'name'      => 'timetable_duration',
                'data'      => '01:00:00',
                'type'      => 'time',
                'school_id' => $schoolData->id,
            ],
            [
                'name'      => 'auto_renewal_plan',
                'data'      => '1',
                'type'      => 'integer',
                'school_id' => $schoolData->id,
            ],
            [
                'name'      => 'currency_code',
                'data'      => 'INR',
                'type'      => 'string',
                'school_id' => $schoolData->id,
            ],
            [
                'name'      => 'currency_symbol',
                'data'      => '₹',
                'type'      => 'string',
                'school_id' => $schoolData->id,
            ],
            [
                'name'      => 'date_format',
                'data'      => 'd-m-Y',
                'type'      => 'string',
                'school_id' => $schoolData->id,
            ],
            [
                'name'      => 'time_format',
                'data'      => 'h:i A',
                'type'      => 'string',
                'school_id' => $schoolData->id,
            ],
            [
                'name'      => 'domain',
                'data'      => $schoolData->domain,
                'type'      => 'string',
                'school_id' => $schoolData->id,
            ],

            [
                'name' => 'email-template-staff',
                'data' => '&lt;p&gt;Dear {full_name},&lt;/p&gt; &lt;p&gt;Welcome to {school_name}!&lt;/p&gt; &lt;p&gt;We are excited to have you join our team. Below are your registration details to access the {school_name}:&lt;/p&gt; &lt;hr&gt; &lt;p&gt;&lt;strong&gt;Your Registration Details:&lt;/strong&gt;&lt;/p&gt; &lt;ul&gt; &lt;li&gt;&lt;strong&gt;Registration URL:&lt;/strong&gt; {url}&lt;/li&gt; &lt;li&gt;&lt;strong&gt;Email:&lt;/strong&gt; {email}&lt;/li&gt; &lt;li&gt;&lt;strong&gt;Password:&lt;/strong&gt; {password}&lt;/li&gt; &lt;/ul&gt; &lt;hr&gt; &lt;p&gt;&lt;strong&gt;Steps to Complete Your Registration:&lt;/strong&gt;&lt;/p&gt; &lt;ol&gt; &lt;li&gt;Click on the registration URL provided above.&lt;/li&gt; &lt;li&gt;Enter your email and password.&lt;/li&gt; &lt;li&gt;Follow the on-screen instructions to set up your profile.&lt;/li&gt; &lt;/ol&gt; &lt;p&gt;&lt;strong&gt;Important:&lt;/strong&gt;&lt;/p&gt; &lt;ul&gt; &lt;li&gt;For security reasons, please change your password upon your first login.&lt;/li&gt; &lt;li&gt;If you have any questions or need assistance during the registration process, please contact our support team at {support_email} or call {support_contact}.&lt;/li&gt; &lt;/ul&gt; &lt;p&gt;&lt;strong&gt;App Download Links:&lt;/strong&gt;&lt;/p&gt; &lt;ul&gt; &lt;li&gt;&lt;strong&gt;Android:&lt;/strong&gt; {android_app}&lt;/li&gt; &lt;li&gt;&lt;strong&gt;iOS:&lt;/strong&gt; {ios_app}&lt;/li&gt; &lt;/ul&gt; &lt;p&gt;We look forward to a successful academic year with you on our team. Thank you for your commitment to excellence in education.&lt;/p&gt; &lt;p&gt;Best regards,&lt;/p&gt; &lt;p&gt;{school_name}&lt;br&gt;{support_email}&lt;br&gt;{support_contact}&lt;br&gt;{url}&lt;/p&gt;',
                'type' => 'text',
                'school_id' => $schoolData->id
            ],
            [
                'name' => 'email-template-parent',
                'data' => '&lt;p&gt;Dear {parent_name},&lt;/p&gt; &lt;p&gt;We are delighted to welcome {child_name} to {school_name}!&lt;/p&gt; &lt;p&gt;As part of our registration process, we have created accounts for both you and your child in our {school_name}. Below are the registration details you will need to access the system, along with links to download our mobile app for your convenience.&lt;/p&gt; &lt;hr&gt; &lt;p&gt;&lt;strong&gt;Student Credential Details:&lt;/strong&gt;&lt;/p&gt; &lt;ul&gt; &lt;li&gt;&lt;strong&gt;Name:&lt;/strong&gt; {child_name}&lt;/li&gt; &lt;li&gt;&lt;strong&gt;Admission No.: &lt;/strong&gt;{admission_no}&lt;/li&gt; &lt;li&gt;&lt;strong&gt;GR No.:&lt;/strong&gt; {grno}&lt;/li&gt; &lt;li&gt;&lt;strong&gt;Password:&lt;/strong&gt; {child_password}&lt;/li&gt; &lt;/ul&gt; &lt;hr&gt; &lt;p&gt;&lt;strong&gt;Parent Credential Details:&lt;/strong&gt;&lt;/p&gt; &lt;ul&gt; &lt;li&gt;&lt;strong&gt;Name:&lt;/strong&gt; {parent_name}&lt;/li&gt; &lt;li&gt;&lt;strong&gt;Email:&lt;/strong&gt; {email}&lt;/li&gt; &lt;li&gt;&lt;strong&gt;Password:&lt;/strong&gt; {password}&lt;/li&gt; &lt;/ul&gt; &lt;hr&gt; &lt;p&gt;&lt;strong&gt;App Download Links:&lt;/strong&gt;&lt;/p&gt; &lt;ul&gt; &lt;li&gt;&lt;strong&gt;Android:&lt;/strong&gt; {android_app}&lt;/li&gt; &lt;li&gt;&lt;strong&gt;iOS:&lt;/strong&gt; {ios_app}&lt;/li&gt; &lt;/ul&gt; &lt;hr&gt; &lt;p&gt;&lt;strong&gt;Steps to Complete the Registration:&lt;/strong&gt;&lt;/p&gt; &lt;ol&gt; &lt;li&gt;Download the school management app using the links above for easier access on your mobile devices.&lt;/li&gt; &lt;li&gt;Enter the email and password for either the student or parent account.&lt;/li&gt; &lt;li&gt;Follow the on-screen instructions to complete the profile setup.&lt;/li&gt; &lt;/ol&gt; &lt;p&gt;&lt;strong&gt;Important:&lt;/strong&gt;&lt;/p&gt; &lt;ul&gt; &lt;li&gt;For security reasons, please ensure that both the student and parent passwords are changed upon first login.&lt;/li&gt; &lt;li&gt;If you encounter any issues during the registration process, please do not hesitate to contact our support team at {support_email} or call {support_contact}.&lt;/li&gt; &lt;/ul&gt; &lt;p&gt;We look forward to an enriching educational experience for {child_name} at {school_name}. Thank you for entrusting us with your child&#039;s education.&lt;/p&gt; &lt;p&gt;Best regards,&lt;/p&gt; &lt;p&gt;{school_name}&lt;br&gt;{support_email}&lt;/p&gt;',
                'type' => 'text',
                'school_id' => $schoolData->id
            ],
            [
                'name'      => 'senderid',
                'data'      => $schoolData->senderid,
                'type'      => 'string',
                'school_id' => $schoolData->id,
            ],

        );
        SchoolSetting::upsert($schoolSettingData, ["name", "school_id"], ["data", "type"]);
    }

    public function createPreSetupRole($school)
    {
        Role::updateOrCreate(['name' => 'Guardian', 'school_id' => $school->id], ['custom_role' => 0, 'editable' => 0]);
        Role::updateOrCreate(['name' => 'Student', 'school_id' => $school->id], ['custom_role' => 0, 'editable' => 0]);

        //Add Teacher Role
        $teacher_role = Role::updateOrCreate(['name' => 'Teacher', 'school_id' => $school->id, 'custom_role' => 0, 'editable' => 1]);
        $TeacherHasAccessTo = [
            'student-list',
            'timetable-list',
            //            'attendance-list',
            //            'attendance-create',
            //            'attendance-edit',
            //            'attendance-delete',
            'holiday-list',
            'announcement-list',
            'announcement-create',
            'announcement-edit',
            'announcement-delete',
            'assignment-create',
            'assignment-list',
            'assignment-edit',
            'assignment-delete',
            'assignment-submission',
            'lesson-list',
            'lesson-create',
            'lesson-edit',
            'lesson-delete',
            'topic-list',
            'topic-create',
            'topic-edit',
            'topic-delete',
            'class-section-list',
            'online-exam-create',
            'online-exam-list',
            'online-exam-edit',
            'online-exam-delete',
            'online-exam-questions-create',
            'online-exam-questions-list',
            'online-exam-questions-edit',
            'online-exam-questions-delete',
            'online-exam-result-list',

            'leave-list',
            'leave-create',
            'leave-edit',
            'leave-delete',
        ];
        $teacher_role->syncPermissions($TeacherHasAccessTo);
    }
}
