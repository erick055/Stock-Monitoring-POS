@props(['id', 'name' => 'password'])
<span class="password-field" data-password-field>
    <input type="password" id="{{ $id }}" name="{{ $name }}" {{ $attributes }}>
    <button type="button" class="password-toggle" data-password-toggle aria-controls="{{ $id }}" aria-label="Show password" aria-pressed="false" title="Show password" hidden><i class="bi bi-eye" aria-hidden="true"></i></button>
</span>
