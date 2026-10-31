@extends('admin.layout')

@section('admin')
    <h1 class="mb-4 text-2xl font-extrabold">{{ $item->exists ? 'Edit category' : 'Add category' }}</h1>

    <form method="POST" class="card max-w-xl space-y-4 p-5" action="{{ $item->exists ? route('admin.categories.update', $item) : route('admin.categories.store') }}">
        @csrf
        @if ($item->exists) @method('PUT') @endif
        <div>
            <label class="label" for="name">Name</label>
            <input id="name" name="name" class="input" required maxlength="80" value="{{ old('name', $item->name) }}">
            @error('name') <p class="field-error">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="label" for="parent_id">Inside</label>
            <select id="parent_id" name="parent_id" class="input">
                <option value="">Nothing (top-level category)</option>
                @foreach ($parents as $parent)
                    <option value="{{ $parent->id }}" @selected((int) old('parent_id', $item->parent_id) === $parent->id)>{{ $parent->name }}</option>
                @endforeach
            </select>
            @error('parent_id') <p class="field-error">{{ $message }}</p> @enderror
        </div>
        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="label" for="emoji">Emoji</label>
                <input id="emoji" name="emoji" class="input" maxlength="8" value="{{ old('emoji', $item->emoji) }}">
            </div>
            <div>
                <label class="label" for="sort_order">Order</label>
                <input id="sort_order" name="sort_order" type="number" min="0" class="input" value="{{ old('sort_order', $item->sort_order) }}">
            </div>
        </div>
        <div class="flex gap-3">
            <button class="btn btn-primary">Save</button>
            <a href="{{ route('admin.categories.index') }}" class="btn btn-outline">Cancel</a>
        </div>
    </form>

    @if ($item->exists)
        <form method="POST" action="{{ route('admin.categories.destroy', $item) }}" class="mt-4 max-w-xl" onsubmit="return confirm('Delete this category?')">
            @csrf @method('DELETE')
            <button class="btn btn-danger btn-sm">Delete category</button>
        </form>
    @endif
@endsection
