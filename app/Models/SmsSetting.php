<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class SmsSetting extends Model
{
  protected $table = "sms_setting";
  protected $fillable = [
    'id',
    'mark',
    'description',
    'status',
    'sendmark',
    'type',
    'msg0',
    'msg1',
    'msg2',
    'msg3',
    'msg4',
    'created_at',
    'updated_at'
  ];

  public function scopeOwner($query)
  {
    if (Auth::user()->hasRole('Super Admin')) {
      return $query->where('type', 'Super Admin');
    }

    if (Auth::user()->hasRole('Teacher')) {
      return $query->where('type', Auth::user()->school_id);
    }

    return $query;
  }
}
