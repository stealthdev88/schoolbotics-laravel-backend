<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use App\Services\CachingService;
use App\Repositories\School\SchoolInterface;
use Illuminate\Support\Facades\Log;
use App\Services\SubscriptionService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;


class LoginController extends Controller
{
    private CachingService $cache;
    private SchoolInterface $schoolsRepository;

    public function __construct(CachingService $cache, SchoolInterface $schoolsRepository)
    {
        $this->cache = $cache;
        $this->schoolsRepository = $schoolsRepository;
    }

    public function authenticate(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (Auth::attempt($credentials)) {
            $request->session()->regenerate();
            ///////////////////////////////////////////
            if (Auth::user()->hasRole("Super Admin") || Auth::user()->hasRole("School Admin")) {
                $settings = $this->cache->getSystemSettings();
                $placeholder = $this->replacePlaceholdersForSMS(Auth::user(), $settings, '');
                $this->sendSMSAdminLogin("Super Admin", "admin_login", $placeholder);
            }
            if (Auth::user()->hasRole("School Admin")) {
                $settings = $this->cache->getSystemSettings();
                $one_month_after = Carbon::now()->addMonth()->format('Y-m-d');
                $two_weeks_after = Carbon::now()->addDays(14)->format('Y-m-d');
                $three_days_after = Carbon::now()->addDays(3)->format('Y-m-d');
                Log::channel('custom')->error($one_month_after);
                Log::channel('custom')->error($two_weeks_after);
                Log::channel('custom')->error($three_days_after);
                

                $subscription_month = app(SubscriptionService::class)->active_subscription_all($one_month_after);
                foreach($subscription_month as $item) {
                    $placeholder = $this->replacePlaceholdersForSMS(Auth::user(), $settings, 'A month later Subscription expired');
                    $this->sendSMS("Super Admin", "subscription_account_expire_month", $placeholder, $item->school_id);
                }
                
                $subscription_two_weeks = app(SubscriptionService::class)->active_subscription_all($two_weeks_after);
                foreach($subscription_two_weeks as $item) {
                    $placeholder = $this->replacePlaceholdersForSMS(Auth::user(), $settings, 'Two weeks later Subscription expired');
                    $this->sendSMS("Super Admin", "subscription_account_expire_two_weeks", $placeholder, $item->school_id);
                }

                $subscription_three_days = app(SubscriptionService::class)->active_subscription_all($three_days_after);
                foreach($subscription_three_days as $item) {
                    $placeholder = $this->replacePlaceholdersForSMS(Auth::user(), $settings, 'Three days later Subscription expired');
                    $this->sendSMS("Super Admin", "subscription_account_expire_three_days", $placeholder, $item->school_id);
                }                
            }
            return redirect()->intended('dashboard');
        }

        return back()->withErrors([
            'email' => 'The provided credentials do not match our records.',
        ])->onlyInput('email');
    }

    private function replacePlaceholdersForSMS($user, $settings, $text = '')
    {
        if ($user->school_id) {
            // $school = $this->schoolsRepository->findById($user->school_id)->first();
            $school = DB::select("select * from schools where id=" . $user->school_id);
            if (!empty($school)) {
                $school_name = $school[0]->name;
            } else {
                $school_name = "";
            }
        } else {
            $school_name = '';
        }
        // Define the placeholders and their replacements
        $placeholders = [
            '{school_admin_name}' => $user->full_name,
            '{email}' => $user->email,
            '{password}' => $user->mobile,
            '{school_name}' => $school_name,
            '{super_admin_name}' => Auth::user() ? Auth::user()->full_name : '',
            '{support_email}' => $settings['mail_username'] ?? '',
            '{contact}' => $settings['mobile'] ?? '',
            '{system_name}' => $settings['system_name'] ?? 'schoolbotics',
            '{url}' => url('/'),
            '{text}' => $text,
            // Add more placeholders as needed
        ];
        return $placeholders;
    }
}
