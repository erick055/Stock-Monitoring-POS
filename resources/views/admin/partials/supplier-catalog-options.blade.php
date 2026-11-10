@foreach(['category' => ['Category', $categories, 'category'], 'shelf_location' => ['Shelf location', $shelves, 'shelf']] as $field => [$label, $options, $optionLabel])
    <div class="supplier-catalog-option" data-catalog-option>
        <label>{{ $label }}
            <select name="{{ $field }}" data-catalog-option-select>
                <option value="">Select a {{ $optionLabel }}</option>
                @foreach($options as $option)
                    <option value="{{ $option }}">{{ $option }}</option>
                @endforeach
                <option value="__new__">+ Create {{ $optionLabel }}</option>
            </select>
        </label>
        <label hidden data-catalog-option-new>New {{ $optionLabel }}
            <input name="new_{{ $field }}" maxlength="100" placeholder="Enter new {{ $optionLabel }}" disabled>
        </label>
    </div>
@endforeach
