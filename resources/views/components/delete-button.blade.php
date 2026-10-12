@props(['action', 'label' => 'Delete', 'confirm' => 'Are you sure?'])
<form method="POST" action="{{ $action }}" onsubmit="return confirm(@js($confirm))" class="inline">
    @csrf
    @method('DELETE')
    <button {{ $attributes->merge(['class' => 'text-sm font-medium text-red-600 hover:text-red-500']) }}>{{ $label }}</button>
</form>
