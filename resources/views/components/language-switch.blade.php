@props(['dark' => false])

{{-- English / Urdu switch. Posts to the server so the choice is saved for the user. --}}
<form method="POST" action="{{ route('locale.update') }}" @class([
    'inline-flex items-center rounded-lg p-0.5 text-sm',
    'bg-stone-100 ring-1 ring-stone-200' => ! $dark,
    'bg-white/10 ring-1 ring-white/15' => $dark,
])>
    @csrf
    @foreach (['en' => 'English', 'ur' => 'اردو'] as $code => $label)
        <button
            type="submit"
            name="locale"
            value="{{ $code }}"
            lang="{{ $code }}"
            @if (app()->getLocale() === $code) aria-current="true" @endif
            @class([
                'rounded-md px-2.5 py-1 font-medium transition',
                'font-[family-name:var(--font-urdu)] leading-[1.6]' => $code === 'ur',
                'bg-white text-stone-900 shadow-xs' => app()->getLocale() === $code && ! $dark,
                'text-stone-500 hover:text-stone-800' => app()->getLocale() !== $code && ! $dark,
                'bg-white text-stone-900' => app()->getLocale() === $code && $dark,
                'text-stone-300 hover:text-white' => app()->getLocale() !== $code && $dark,
            ])
        >{{ $label }}</button>
    @endforeach
</form>
