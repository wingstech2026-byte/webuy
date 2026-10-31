@extends('seller.layout')

@section('seller')
    <a href="{{ route('seller.products.index') }}" class="mb-3 inline-block text-sm font-semibold text-brand-700">&larr; Products</a>
    <h1 class="mb-1 text-2xl font-extrabold">Add many products from a spreadsheet</h1>
    <p class="mb-4 text-stone-600">Fill in the example file in Excel, Google Sheets or LibreOffice, save it as <strong>CSV</strong>, and upload it. Up to {{ $maxRows }} products per file. They arrive as drafts so you can add photos first.</p>

    <div class="card mb-4 space-y-2 p-4 text-sm">
        <a href="{{ route('seller.products.import.template') }}" class="btn btn-outline btn-sm">⬇️ Download the example file</a>
        <p><strong>Columns:</strong> {{ implode(', ', $columns) }}.</p>
        <p><strong>Needed:</strong> title, price, stock. The rest is optional: unit (default "piece"), min_order_quantity (default 1), category (a category name from the website), condition ("new" or "used"), description, compare_at_price (an old, higher price), sku.</p>
        <p>Prices are in Leones, like <code>650</code> or <code>650.50</code>, without "Le". If any row has a mistake, nothing is added and you see each problem by row number.</p>
    </div>

    @if (session('import_errors'))
        <div class="card mb-4 border-red-200 bg-red-50 p-4 text-sm text-red-900">
            <p class="mb-1 font-bold">Nothing was added. Please fix these and upload again:</p>
            <ul class="list-disc space-y-0.5 pl-5">@foreach (session('import_errors') as $error) <li>{{ $error }}</li> @endforeach</ul>
        </div>
    @endif

    <form method="POST" action="{{ route('seller.products.import.store') }}" enctype="multipart/form-data" class="card max-w-xl space-y-3 p-4">
        @csrf
        <label class="label" for="file">Your CSV file</label>
        <input id="file" name="file" type="file" accept=".csv,text/csv" required class="input">
        @error('file') <p class="field-error">{{ $message }}</p> @enderror
        <button class="btn btn-primary">Upload products</button>
    </form>
@endsection
