<?php

namespace App\Repositories\SMSSetting;

use App\Models\SmsSetting;
use App\Repositories\Saas\SaaSRepository;
use App\Services\UploadService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;

class SMSSettingRepository extends SaaSRepository implements SMSSettingInterface
{

    public function __construct(SmsSetting $model)
    {
        parent::__construct($model, 'sms-setting');
    }

    // Using Upsert Code According to System Settings Data
    public function upsert(array $payload, array $uniqueColumns, array $updatingColumn): bool
    {
        $payload = array_map(static function ($d) {
            $d['type'] = Auth::user()->school_id;
            return $d;
        }, $payload);
        $uniqueColumns[] = 'school_id';
        foreach ($payload as $column => $value) {
            // Check that $value['data'] is File , Upload File
            if ($value['data'] instanceof UploadedFile) {
                // Check the Data Exists

                $dataExists = app(SMSSettingInterface::class)->builder()->where('name', $value['name'])->first();
                if ($dataExists) {
                    // Get the Row Attribute Of Data Of Specific $dataExists Row
                    $data = $dataExists->getAttributes()['data'];
                    //Delete the Old File
                    UploadService::delete($data);
                }
                // Upload New File
                $payload[$column]['data'] = UploadService::upload($value['data'], $this->uploadFolder);
            }
        }
        return $this->defaultModel()->upsert($payload, $uniqueColumns, $updatingColumn);
    }
}
