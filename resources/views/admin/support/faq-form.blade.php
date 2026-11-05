@extends('admin.layout')
@section('title', $item?'Edit FAQ':'Create FAQ')
@section('breadcrumb','Help & Support / FAQ')
@section('heading', $item?'Edit FAQ':'Add FAQ')
@section('description','Approved Help Center content; publication is controlled by an administrator.')
@section('header_actions')
<a class="btn btn-outline" href="{{ route('admin.support.faqs') }}">Back to FAQs</a>
@endsection
@section('content')
<form method="post" action="{{ $item?route('admin.support.faq.update',$item->id):route('admin.support.faq.store') }}">@csrf
@if($item)@method('patch')@endif
<section class="panel"><div class="panel-header"><h2>FAQ Content</h2></div>
<div class="fields">
<div class="field"><label for="locale">Language</label><select id="locale" class="input" name="locale">
@foreach(['en'=>'English','fr'=>'French','de'=>'German','it'=>'Italian'] as $code=>$label)
<option value="{{ $code }}" @selected(old('locale',$item->locale??'en')===$code)>{{ $label }}</option>@endforeach
</select></div>
<div class="field"><label for="category">Category</label><input class="input" id="category" name="category" required maxlength="60" value="{{ old('category',$item->category??'general') }}"></div>
<div class="field"><label for="question">Question</label><input class="input" id="question" name="question" required maxlength="250" value="{{ old('question',$item->question??'') }}"></div>
<div class="field"><label for="sort_order">Sort order</label><input class="input" id="sort_order" name="sort_order" type="number" min="0" max="100000" value="{{ old('sort_order',$item->sort_order??0) }}"></div>
</div>
<div class="field"><label for="answer">Answer</label><textarea class="input" name="answer" id="answer" required rows="8" maxlength="10000">{{ old('answer',$item->answer??'') }}</textarea></div>
<div class="field"><label><input type="checkbox" name="is_published" value="1" @checked(old('is_published',$item->is_published??false))> Published</label></div>
<div class="form-actions"><button class="btn btn-primary">Save FAQ</button></div>
</section></form>
@if($item)<section class="panel"><form method="post" action="{{ route('admin.support.faq.delete',$item->id) }}">@csrf
<button class="btn btn-outline" onclick="return confirm('Delete this FAQ?')">Delete FAQ</button></form></section>@endif
@endsection