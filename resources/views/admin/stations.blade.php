@extends('admin.layout')

@section('admin')
    <h1 class="mb-1 text-2xl font-extrabold">Pickup stations</h1>
    <p class="mb-4 text-stone-600">Partner shops where sellers drop parcels and buyers collect them. Fees and the partners' share are in Rewards settings → Pickup stations.</p>

    <details class="card mb-4 p-4" @if ($errors->any()) open @endif>
        <summary class="cursor-pointer font-bold">Add a station</summary>
        <form method="POST" action="{{ route('admin.stations.store') }}" class="mt-3 grid gap-3 sm:grid-cols-2">
            @csrf
            <div><label class="label" for="st-phone">Partner's phone (they must have logged in once)</label>
                <input id="st-phone" name="phone" inputmode="tel" required class="input" value="{{ old('phone') }}" placeholder="076 123 456">
                @error('phone') <p class="field-error">{{ $message }}</p> @enderror</div>
            <div><label class="label" for="st-name">Station name</label>
                <input id="st-name" name="name" required maxlength="120" class="input" value="{{ old('name') }}" placeholder="Fatu's Mini Mart">
                @error('name') <p class="field-error">{{ $message }}</p> @enderror</div>
            <div class="sm:col-span-2"><label class="label" for="st-address">Address and landmark</label>
                <input id="st-address" name="address" required maxlength="200" class="input" value="{{ old('address') }}">
                @error('address') <p class="field-error">{{ $message }}</p> @enderror</div>
            <div><label class="label" for="st-district">District</label>
                <select id="st-district" name="district" class="input">
                    @foreach (config('wibuy.districts') as $d) <option value="{{ $d }}" @selected(old('district') === $d)>{{ $d }}</option> @endforeach
                </select></div>
            <div><label class="label" for="st-hours">Opening hours</label>
                <input id="st-hours" name="opening_hours" maxlength="120" class="input" value="{{ old('opening_hours') }}" placeholder="Mon–Sat 8am–8pm"></div>
            <div><label class="label" for="st-lat">Latitude</label>
                <input id="st-lat" name="latitude" required class="input" value="{{ old('latitude') }}" placeholder="8.4840">
                @error('latitude') <p class="field-error">{{ $message }}</p> @enderror</div>
            <div><label class="label" for="st-lng">Longitude</label>
                <input id="st-lng" name="longitude" required class="input" value="{{ old('longitude') }}" placeholder="-13.2299">
                @error('longitude') <p class="field-error">{{ $message }}</p> @enderror</div>
            <p class="text-xs text-stone-500 sm:col-span-2">Tip: stand at the shop, open Google Maps, press and hold on your blue dot and copy the two numbers.</p>
            <div class="sm:col-span-2"><button class="btn btn-primary btn-sm">Add station</button></div>
        </form>
    </details>

    <div class="card overflow-x-auto">
        <table class="w-full text-sm">
            <thead><tr class="text-left"><th class="th">Station</th><th class="th">Partner</th><th class="th">District</th><th class="th"></th></tr></thead>
            <tbody>
                @forelse ($stations as $station)
                    <tr>
                        <td class="td"><strong>{{ $station->name }}</strong><span class="block text-xs text-stone-500">{{ $station->address }}{{ $station->opening_hours ? ' · '.$station->opening_hours : '' }}</span></td>
                        <td class="td">{{ $station->partner->name }}<span class="block text-xs text-stone-500">{{ $station->partner->display_phone }}</span></td>
                        <td class="td">{{ $station->district }}</td>
                        <td class="td">
                            <form method="POST" action="{{ route('admin.stations.toggle', $station) }}">@csrf
                                <button class="badge {{ $station->is_active ? 'bg-brand-100 text-brand-800' : 'bg-stone-200 text-stone-700' }}">{{ $station->is_active ? 'Open · switch off' : 'Off · switch on' }}</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td class="td text-stone-500" colspan="4">No stations yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
