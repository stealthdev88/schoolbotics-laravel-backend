<?php

namespace App\Providers;

use App\Models\Gallery;
use App\Models\School;
use App\Models\User;
use App\Services\CachingService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class ViewServiceProvider extends ServiceProvider {
    /**
     * Register services.
     *
     * @return void
     */
    public function register() {
        //
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot() {
        $cache = app(CachingService::class);

        $galleries = '';
        $teachers = '';
        $schoolSettings = '';
        // Get school domain
        $fullDomain = $_SERVER['HTTP_HOST'] ?? '';
        $parts = explode('.', $fullDomain);
        $subdomain = $parts[0];
        $school = School::where('domain',$subdomain)->first();
        
        if ($school) {
            $teachers = User::where('school_id',$school->id)->role('Teacher')->select('id','first_name','last_name','image')->with('staff')->get();
            
            $schoolSettings = $cache->getSchoolSettings('*',$school->id);
            if (isset($schoolSettings['our_mission_points'])) {
                $schoolSettings['our_mission_points'] = explode(",",$schoolSettings['our_mission_points']);    
            }
            $galleries = Gallery::where('school_id',$school->id)->with('file')->withCount(['file' => function($q) {
                $q->where('type',2);
            }])->where('session_year_id',$schoolSettings['session_year'] ?? 1)->get();
        }

        

        /*** Header File ***/
        View::composer('layouts.header', static function (\Illuminate\View\View $view) use ($cache) {
            $view->with('systemSettings', $cache->getSystemSettings());
            $view->with('languages', $cache->getLanguages());

            if (!empty(Auth::user()->school_id)) {
                $view->with('sessionYear', $cache->getDefaultSessionYear());
                $view->with('schoolSettings', $cache->getSchoolSettings());
                $view->with('semester', $cache->getDefaultSemesterData());
            }
        });

        /*** Include File ***/
        View::composer('layouts.include', static function (\Illuminate\View\View $view) use ($cache) {
            $view->with('systemSettings', $cache->getSystemSettings());
            if (!empty(Auth::user()->school_id)) {
                $view->with('schoolSettings', $cache->getSchoolSettings());
            }
        });
        View::composer('auth.login', static function (\Illuminate\View\View $view) use ($cache, $schoolSettings) {
            $view->with('schoolSettings', $schoolSettings);
            $view->with('systemSettings', $cache->getSystemSettings());
        });
        /*** Email  ***/

        View::composer('auth.passwords.email', static function (\Illuminate\View\View $view) use ($cache) {
            $view->with('systemSettings', $cache->getSystemSettings());
        });
        View::composer('auth.passwords.reset', static function (\Illuminate\View\View $view) use ($cache) {
            $view->with('systemSettings', $cache->getSystemSettings());
        });
        View::composer('auth.login', static function (\Illuminate\View\View $view) use ($cache) {
            $view->with('systemSettings', $cache->getSystemSettings());
        });
        View::composer('home', static function (\Illuminate\View\View $view) use ($cache) {
            $view->with('systemSettings', $cache->getSystemSettings());
        });

        View::composer('layouts.master', static function (\Illuminate\View\View $view) use ($cache) {
            $view->with('systemSettings', $cache->getSystemSettings());
        });

        View::composer('layouts.school.master', static function (\Illuminate\View\View $view) use ($cache) {
            $view->with('systemSettings', $cache->getSystemSettings());
        });

        View::composer('layouts.sidebar', static function (\Illuminate\View\View $view) use ($cache) {
            $view->with('systemSettings', $cache->getSystemSettings());
        });

        /*** Footer File ***/
        View::composer('layouts.footer_js', static function (\Illuminate\View\View $view) use ($cache) {
            $view->with('systemSettings', $cache->getSystemSettings());
            if (!empty(Auth::user()->school_id)) {
                $view->with('schoolSettings', $cache->getSchoolSettings());
            }
        });


        /*** School website ***/
        View::composer('school-website.*', static function (\Illuminate\View\View $view) use ($cache, $galleries, $teachers, $schoolSettings) {
            // if ($school) {
            //     $schoolSettings = $cache->getSchoolSettings('*',$school->id);
            //     if (isset($schoolSettings['our_mission_points'])) {
            //         $schoolSettings['our_mission_points'] = explode(",",$schoolSettings['our_mission_points']);    
            //     }
            //     $view->with('schoolSettings', $schoolSettings);
            // }
            $view->with('schoolSettings', $schoolSettings);
            $view->with('teachers', $teachers);
            $view->with('galleries', $galleries);
            $view->with('systemSettings', $cache->getSystemSettings());
        });
    }
}
