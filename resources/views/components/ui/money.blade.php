{{--
    Shows a Money amount: "Rs 13,800.00". Negative amounts are red (and keep the minus sign,
    so the meaning never depends on colour alone). The number is isolated with <bdi> so it reads
    correctly inside Urdu text.
--}}
@props(['amount', 'muted' => false, 'currency' => true])

<span {{ $attributes->class([
    'tabular whitespace-nowrap',
    'text-red-700' => $amount->isNegative(),
    'text-stone-400' => $muted && $amount->isZero(),
]) }}>@if ($currency){{ __('Rs') }} @endif<bdi dir="ltr">{{ $amount->format() }}</bdi></span>
