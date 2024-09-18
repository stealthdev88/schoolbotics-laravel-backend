<?php

namespace App\Http\Controllers;

use GuzzleHttp\Client;
use App\Repositories\SystemSetting\SystemSettingInterface;
use App\Services\CachingService;
use Illuminate\Support\Facades\Log;

class SMSController extends Controller
{
  private SystemSettingInterface $systemSettings;

  public function __construct(SystemSettingInterface $systemSettings)
  {
    $this->$systemSettings = $systemSettings;
  }
  static function sendRequest($msisdn, $senderid, $msg)
  {
    $client = new Client(); //GuzzleHttp\Client

    try {
      $sms_key = app(CachingService::class)->getSystemSettings('sms_key');
      Log::channel('custom')->error('sms_key' . $sms_key);
      
      $url = app(CachingService::class)->getSystemSettings('sms_url');

      Log::channel('custom')->error('url' . $url);

      $data = [
        "key" => $sms_key,
        "msisdn" => implode(",", $msisdn),
        "message" => $msg,
        "sender_id" => $senderid,
      ];
      Log::channel('custom')->error('send_sms_data' . json_encode($data));

      $response = $client->post($url, [
        'headers' => ['Content-Type' => 'application/json'],
        'json' => $data,
      ]);

      $statusCode = $response->getStatusCode();
      $body = $response->getBody()->getContents();

      Log::channel('custom')->error('sms_response statusCode:' . $statusCode . "content" . json_encode($body));
    } catch (\Exception $e) {
      Log::channel('custom')->error('Failed to send SMS', ['error' => $e->getMessage()]);
    }
  }
}
