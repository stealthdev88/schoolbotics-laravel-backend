<?php

namespace App\Http\Controllers;

use dacoto\EnvSet\Facades\EnvSet;
use dacoto\LaravelWizardInstaller\Controllers\InstallFolderController;
use dacoto\LaravelWizardInstaller\Controllers\InstallServerController;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class InstallerController extends Controller {
    public function purchaseCodeIndex() {
        if ((new InstallServerController())->check() === false || (new InstallFolderController())->check() === false) {
            return redirect()->route('LaravelWizardInstaller::install.folders');
        }
        return view('vendor.installer.steps.purchase-code');
    }


    public function checkPurchaseCode(Request $request) {
       
            $app_url = (string)url('/');
            $app_url = preg_replace('#^https?://#i', '', $app_url);

           
            $response = 'ok';
            if ($response == 'ok') {
                EnvSet::setKey('APPSECRET', $request->input('purchase_code'));
            EnvSet::save();
            return redirect()->route('LaravelWizardInstaller::install.database');
            }

            
        
    }

}
