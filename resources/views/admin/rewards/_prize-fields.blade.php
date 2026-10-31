<div class="sm:col-span-2"><label class="label">Label</label><input name="label" required maxlength="60" class="input" value="{{ $prize?->label }}"></div>
<div><label class="label">Type</label>
    <select name="type" class="input">
        <option value="points" @selected($prize?->type === 'points')>Points</option>
        <option value="coupon" @selected($prize?->type === 'coupon')>Coupon</option>
    </select>
</div>
<div><label class="label">Points</label><input name="points" type="number" min="0" class="input" value="{{ $prize?->points ?? 0 }}"></div>
<div><label class="label">Coupon (Le)</label><input name="coupon_amount" type="number" min="0" step="0.01" class="input" value="{{ $prize?->coupon_amount }}"></div>
<div><label class="label">Min. order (Le)</label><input name="coupon_min_spend" type="number" min="0" step="0.01" class="input" value="{{ $prize?->coupon_min_spend ?? 0 }}"></div>
<div><label class="label">Weight</label><input name="weight" type="number" min="0" required class="input" value="{{ $prize?->weight ?? 10 }}"></div>
<div><label class="choice"><input type="checkbox" name="is_active" value="1" class="size-5 accent-brand-700" @checked($prize?->is_active ?? true)> On</label></div>
<input type="hidden" name="coupon_days" value="{{ $prize?->coupon_days ?? 7 }}">
<input type="hidden" name="sort_order" value="{{ $prize?->sort_order ?? 0 }}">
