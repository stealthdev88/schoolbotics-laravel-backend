@extends('layouts.master')

@section('title') {{__($name)}} @endsection


@section('content')

<div class="content-wrapper">
  <div class="page-header">
    <h3 class="page-title">
      {{__($name)}}
    </h3>
  </div>
  <div class="row grid-margin">
    <div class="col-lg-12">
      <div class="card">
        <div class="card-body">
            <form id="formdata" class="setting-form" action="{{route('school-settings.update',1)}}" method="POST" novalidate="novalidate">
            @csrf
            <div class="row">
              <div class="form-group col-md-12 col-sm-12">
                  <label for="data">{{__($name)}}</label>
                <input type="hidden" name="name" id="name" value="{{$name}}">
                  <textarea id="tinymce_message" name="data" required id="data" required placeholder="{{__($name)}}">{{$data ?? ''}}</textarea>
              </div>
            </div>
            <input class="btn btn-theme float-right" type="submit" value="{{ __('submit') }}">
          </form>
        </div>
      </div>
    </div>
  </div>
</div>

@endsection
