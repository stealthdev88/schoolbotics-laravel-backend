<?php
namespace App\Services;
use Illuminate\Support\Str;

class GeneralFunctionService
{



    public function wrongNotificationSetup($e)
    {
        $status = 1;
        if (Str::contains($e->getMessage(), [ 'does not exist', 'file_get_contents' ])) {
            $status = 0;
        }
        return $status;
    }
}
