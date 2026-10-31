@extends('admin.layout')

@section('admin')
    <div class="mb-4 flex items-center justify-between gap-3">
        <h1 class="text-2xl font-extrabold">Categories</h1>
        <a href="{{ route('admin.categories.create') }}" class="btn btn-primary btn-sm">Add category</a>
    </div>

    <div class="space-y-3">
        @forelse ($items as $category)
            <div class="card p-4">
                <div class="flex items-center justify-between gap-2">
                    <a href="{{ route('admin.categories.edit', $category) }}" class="font-bold">{{ $category->emoji }} {{ $category->name }}</a>
                    <span class="text-xs text-stone-500">{{ $category->products_count }} products</span>
                </div>
                @if ($category->children->isNotEmpty())
                    <ul class="mt-2 flex flex-wrap gap-2">
                        @foreach ($category->children as $child)
                            <li><a href="{{ route('admin.categories.edit', $child) }}" class="badge bg-stone-100 py-1.5 text-sm">{{ $child->name }} ({{ $child->products_count }})</a></li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @empty
            <x-empty title="No categories yet" action="Add one" :href="route('admin.categories.create')" />
        @endforelse
    </div>
@endsection
