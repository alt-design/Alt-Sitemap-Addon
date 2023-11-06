@extends('statamic::layout')

@section('content')
    <div>
        <publish-form
            title="Alt Sitemap"
            action="{{ cp_route('alt-sitemap.update') }}"
            :blueprint='@json($blueprint)'
            :meta='@json($meta)'
            :values='@json($values)'
        ></publish-form>
    </div>
@endsection
