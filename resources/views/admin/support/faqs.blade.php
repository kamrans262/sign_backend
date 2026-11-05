@extends('admin.layout')
@section('title','Help Center FAQs')
@section('breadcrumb','Help & Support / FAQs')
@section('heading','Help Center Management')
@section('description','Publish and reorder multilingual FAQ content shown in the app.')
@section('header_actions')
<a class="btn btn-primary" href="{{ route('admin.support.faq.create') }}">Add FAQ</a>
<a class="btn btn-outline" href="{{ route('admin.support.index') }}">Support Tickets</a>
@endsection
@section('content')
<section class="panel"><div class="panel-header"><h2>FAQs ({{ $faqs->total() }})</h2></div>
<div class="table-wrap"><table><thead><tr><th>Question</th><th>Locale</th><th>Category</th><th>Order</th><th>Published</th><th>Action</th></tr></thead><tbody>
@forelse($faqs as $faq)<tr><td>{{ $faq->question }}</td><td>{{ strtoupper($faq->locale) }}</td><td>{{ $faq->category }}</td><td>{{ $faq->sort_order }}</td>
<td><span class="pill">{{ $faq->is_published?'Published':'Draft' }}</span></td>
<td><a class="btn btn-small btn-outline" href="{{ route('admin.support.faq.edit',$faq->id) }}">Manage</a></td></tr>
@empty<tr><td colspan="6">No FAQs configured yet.</td></tr>@endforelse
</tbody></table></div>
@if($faqs->hasPages())<nav class="pagination">{{ $faqs->links() }}</nav>@endif
</section>
@endsection