@extends('layouts.master')

@section('title')
{{ __('sms_settings') }}
@endsection


@section('content')
<!-- {{-- student App Settings --}} -->
<div class="content-wrapper">
    <div class="page-header">
        <h3 class="page-title">
            {{ __('sms_settings') }}
        </h3>
    </div>
    <div class="row grid-margin">
        <div class="col-lg-12">
            <div class="card">
                <div class="card-body">
                    <form id="formdata" class="create-form-without-reset" action="{{ route('system-settings.sms.update') }}" novalidate="novalidate">
                        @csrf
                        <h4 class="card-title">
                            @if(Auth::user()->hasRole('Super Admin'))
                            {{ __('SUPERADMIN/ SMS SETTING PAGE') }}
                            @else
                            {{ __('SCHOOLADMIN/ SMS SETTING PAGE') }}
                            @endif

                        </h4>
                        <div class="row">
                            @foreach ($settings as $setting)
                            <div class="border border-secondary rounded-lg my-4 mx-1 col-md-3 col-sm-5">
                                <div class="form-group col-md-12 col-sm-12">
                                    <div class="form-check">
                                        <label class="form-check-label">
                                            <input type="checkbox" class="form-check-input" {{ $setting['status'] == '1' ? 'checked' : '' }} value="{{ $setting['status']}}" id="{{$setting['mark']}}">{{ __($setting->description) }}
                                            <i class="input-helper"></i>
                                        </label>
                                    </div>
                                    <input type="hidden" name="{{ $setting['mark']}}" id="txt_{{ $setting['mark']}}" value="{{ $setting['status'] }}">
                                </div>
                                <div>
                                </div>
                                <div class="row" style="padding-left:50px;">
                                    @foreach(config('constants.USER_TYPE') as $user_name)
                                    <div class="form-group col-md-12 col-sm-12">
                                        <div class="row">
                                            <div class="form-check">
                                                <label class="form-check-label">
                                                    <input type="checkbox" class="form-check-input" {{ $setting['status'] == '1' ? '' : 'disabled' }} {{ $setting['sendmark'][$user_name['key']] == '1' ? 'checked' : '' }} value="{{ $setting['sendmark'][$user_name['key']] }}" id="{{ $setting['mark'] . $user_name['key']}}">{{ __($user_name['name']) }}
                                                    <i class="input-helper"></i>
                                                </label>
                                            </div>
                                            <div class="fa fa-edit" style="margin-top: 18px; margin-left: 5px;" id="{{ 'edit_' . $setting['mark'] . $user_name['key']}}" onclick="edit_click('{{ $setting['mark']}}', '{{$user_name['key']}}')"></div>
                                        </div>
                                        <div class="col-md-12 col-sm-12" style="display:none;" id="{{ 'div_' . $setting['mark'] . $user_name['key']}}">
                                            <textarea class="col-md-12 col-sm-12" style="height: 200px" maxlength="250" name="{{ 'msg_' . $setting['mark'] . $user_name['key']}}" id="msg_{{ $setting['mark'] . $user_name['key']}}">{{ $setting['msg' . $user_name['key']] }}</textarea>
                                            <div class="row">
                                                <img style="padding: 9px; width:36px; height:36px" src="{{ asset('assets/school/images/school_admin.png') }}" title="School Admin Name" onclick="insertSpecial('msg_{{ $setting['mark'] . $user_name['key']}}', '{school_admin_name}')" />
                                                <i class="fa fa-envelope" title="Email" style="padding: 10px;" onclick="insertSpecial('msg_{{ $setting['mark'] . $user_name['key']}}', '{email}')"></i>
                                                <i class="fa fa-building" title="School Name" style="padding: 10px;" onclick="insertSpecial('msg_{{ $setting['mark'] . $user_name['key']}}', '{school_name}')"></i>
                                                <img style="padding: 9px; width:36px; height:36px" src="{{ asset('assets/school/images/superadmin.png') }}" title="Super Admin Name" onclick="insertSpecial('msg_{{ $setting['mark'] . $user_name['key']}}', '{super_admin_name}')" />
                                                <img style="padding: 9px; width:36px; height:36px" src="{{ asset('assets/school/images/email.png') }}" title="Support Email" onclick="insertSpecial('msg_{{ $setting['mark'] . $user_name['key']}}', '{support_email}')" />
                                                <i class="fa fa-address-book" title="Contact" style="padding: 10px;" onclick="insertSpecial('msg_{{ $setting['mark'] . $user_name['key']}}', '{contact}')"></i>
                                                <img style="padding: 9px; width:36px; height:36px" src="{{ asset('assets/school/images/system.png') }}" title="System Name" onclick="insertSpecial('msg_{{ $setting['mark'] . $user_name['key']}}', '{system_name}')" />
                                                <img style="padding: 9px; width:36px; height:36px" src="{{ asset('assets/school/images/link.png') }}" title="URL" onclick="insertSpecial('msg_{{ $setting['mark'] . $user_name['key']}}', '{url}')" />
                                            </div>
                                        </div>
                                        <input type="hidden" name="{{ $setting['mark'] . $user_name['key']}}" id="txt_{{ $setting['mark'] . $user_name['key']}}" value="{{ $setting['sendmark'][$user_name['key']] }}" />
                                    </div>
                                    @endforeach
                                </div>
                            </div>
                            @endforeach
                        </div>
                        <hr>
                        <input class="btn btn-theme" type="submit" value="Submit">
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@section('js')
<script>
    function insertSpecial(id, key) {
        if (document.getElementById(id).value.length + key.length < 150)
            document.getElementById(id).value = document.getElementById(id).value + key;
    }

    function edit_click(mark, key) {
        if (document.getElementById('edit_' + mark + key).className == "fa fa-edit") {
            document.getElementById('div_' + mark + key).style = 'display:block';
            document.getElementById('edit_' + mark + key).className = 'fa fa-save';
        } else {
            document.getElementById('div_' + mark + key).style = 'display:none';
            document.getElementById('edit_' + mark + key).className = 'fa fa-edit';
        }
    }

    document.getElementById('formdata').addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            if (event.target.tagName == "TEXTAREA") {
                e.preventDefault();
                if (event.target.value.length < 150) {
                    event.target.value = event.target.value + "\n";
                }
            } else {
                e.preventDefault();
                return false;
            }
        }
    });

    $(document).on('change', '.form-check-input', function(event) {
        if (event.target.value == 1) {
            event.target.value = 0;
            $('#txt_' + event.target.getAttribute('id')).val(0);
        } else {
            event.target.value = 1;
            $('#txt_' + event.target.getAttribute('id')).val(1);
        }

        let elements = document.querySelectorAll('[id^="' + event.target.getAttribute('id') + '"]');
        if (elements.length > 1) {
            for (var i = 0; i < elements.length; i++) {
                if (elements[i].getAttribute('id') != event.target.getAttribute('id')) {
                    if (event.target.value == 1)
                        elements[i].disabled = false;
                    else
                        elements[i].disabled = true;
                }
            }
        }
    });
</script>
@endsection