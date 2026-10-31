<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CategoryRequest;
use App\Models\Category;
use App\Support\Slug;
use Illuminate\Http\RedirectResponse;

class CategoryController extends Controller
{
    public function index()
    {
        return view('admin.categories.index', [
            'items' => Category::whereNull('parent_id')->with(['children' => fn ($q) => $q->withCount('products')])
                ->withCount('products')->orderBy('sort_order')->orderBy('name')->get(),
        ]);
    }

    public function create()
    {
        return view('admin.categories.form', ['item' => new Category, 'parents' => $this->parents()]);
    }

    public function store(CategoryRequest $request): RedirectResponse
    {
        $data = $request->validated();
        Category::create($data + ['slug' => Slug::unique(Category::class, $data['name']), 'sort_order' => $data['sort_order'] ?? 0]);

        return redirect()->route('admin.categories.index')->with('status', 'Category added.');
    }

    public function edit(Category $category)
    {
        return view('admin.categories.form', ['item' => $category, 'parents' => $this->parents($category)]);
    }

    public function update(CategoryRequest $request, Category $category): RedirectResponse
    {
        $data = $request->validated();

        // Keep two levels: a category that has subcategories cannot become a subcategory.
        if (! empty($data['parent_id']) && $category->children()->exists()) {
            return back()->withInput()->withErrors(['parent_id' => 'This category has subcategories, so it cannot go inside another one.']);
        }

        $category->update($data + ['sort_order' => $data['sort_order'] ?? 0]);

        return redirect()->route('admin.categories.index')->with('status', 'Category saved.');
    }

    public function destroy(Category $category): RedirectResponse
    {
        if ($category->products()->exists() || $category->children()->exists()) {
            return back()->withErrors(['delete' => 'This category has products or subcategories. Move them first.']);
        }

        $category->delete();

        return redirect()->route('admin.categories.index')->with('status', 'Category deleted.');
    }

    /** Only top-level categories can be parents. */
    private function parents(?Category $except = null)
    {
        return Category::whereNull('parent_id')->when($except, fn ($q) => $q->where('id', '!=', $except->id))->orderBy('name')->get();
    }
}
