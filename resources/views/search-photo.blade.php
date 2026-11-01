@extends('layouts.app')

@section('title', 'Search with a photo')

@section('content')
    <h1 class="mb-1 text-2xl font-extrabold">📷 Search with a photo</h1>
    <p class="mb-4 text-stone-600">Take a photo of any item, or choose one from your phone. We find out what it is and show you who sells it on WiBuy. Your photo is not saved.</p>

    <form method="POST" action="{{ route('search.photo.store') }}" enctype="multipart/form-data" class="card space-y-3 p-4">
        @csrf
        <label for="photo" class="label">Photo of the item</label>
        <input id="photo" name="photo" type="file" accept="image/*" required class="block w-full text-sm">
        @error('photo') <p class="field-error">{{ $message }}</p> @enderror
        <button class="btn btn-primary">Find it</button>
    </form>

    @if ($result)
        @php($u = $result['understood'])
        <div class="mt-6">
            <p class="text-lg font-bold">{{ $u['reply'] ?: ($u['is_item'] ? 'Here is what we found.' : '') }}</p>
            @if (! $u['is_item'])
                <p class="text-stone-600">We could not see an item to buy in that photo. Try a closer photo of just the item.</p>
            @else
                <div class="mt-2 flex flex-wrap gap-2">
                    @foreach ($u['search_words'] as $word)
                        <a href="{{ route('search', ['q' => $word]) }}" class="badge bg-white py-2 text-sm ring-1 ring-stone-200 hover:bg-brand-50">{{ $word }}</a>
                    @endforeach
                </div>

                @if ($result['products']->isNotEmpty())
                    <h2 class="mb-2 mt-5 text-lg font-extrabold">Available on WiBuy</h2>
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                        @foreach ($result['products'] as $product)
                            <x-product-card :product="$product" />
                        @endforeach
                    </div>
                @else
                    <div class="card mt-4 p-4">
                        <p class="font-bold">No seller has "{{ $u['name'] }}" on WiBuy right now.</p>
                        <p class="text-sm text-stone-600">Try one of the words above, or in the WiBuy app use "Request quotes" so sellers can offer it to you.</p>
                    </div>
                @endif
            @endif
            <p class="mt-4 text-xs text-stone-500">{{ $result['left_today'] }} photo searches left today.</p>
        </div>
    @endif
@endsection
